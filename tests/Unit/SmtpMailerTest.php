<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\SmtpMailer;

if (!function_exists('pcntl_fork')) {
    echo "SKIP pcntl unavailable\n";
    return;
}

// A loopback SMTP peer exercises the transport without an external mailbox.
// Server replies deliberately include sensitive-looking data; errors must not.
$certificateBase = sys_get_temp_dir() . '/tokenhub-smtp-' . bin2hex(random_bytes(6));
$certificateConfig = $certificateBase . '.cnf';
$certificateFile = $certificateBase . '.crt';
$certificatePem = $certificateBase . '.pem';
file_put_contents($certificateConfig, "[req]\ndistinguished_name=dn\n[dn]\n[v3]\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,keyCertSign,digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\nsubjectAltName=IP:127.0.0.1\n");
$privateKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$csr = openssl_csr_new(['commonName' => '127.0.0.1'], $privateKey, ['config' => $certificateConfig, 'digest_alg' => 'sha256']);
$certificate = openssl_csr_sign($csr, null, $privateKey, 1, ['config' => $certificateConfig, 'digest_alg' => 'sha256', 'x509_extensions' => 'v3']);
openssl_x509_export($certificate, $certificateText);
openssl_pkey_export($privateKey, $privateText);
file_put_contents($certificateFile, $certificateText);
file_put_contents($certificatePem, $certificateText . $privateText);
chmod($certificatePem, 0600);
$oldCa = getenv('SSL_CERT_FILE');
$runSmtpCase = static function (string $scenario, ?string $expectedError = null) use ($certificateFile, $certificatePem): void {
    putenv('SSL_CERT_FILE=' . ($scenario === 'untrusted-starttls' ? '/dev/null' : $certificateFile));
    $serverContext = stream_context_create(['ssl' => ['local_cert' => $certificatePem, 'verify_peer' => false]]);
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $serverContext);
    if ($server === false) throw new RuntimeException('Could not start test SMTP peer.');
    $port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
    $pid = pcntl_fork();
    if ($pid === -1) throw new RuntimeException('Could not fork test SMTP peer.');
    if ($pid === 0) {
        $connection = stream_socket_accept($server, 5);
        if ($connection === false) exit(2);
        stream_set_timeout($connection, 5);
        if ($scenario === 'smtps' && @stream_socket_enable_crypto($connection, true, STREAM_CRYPTO_METHOD_TLS_SERVER) !== true) exit(4);
        $line = static fn (): string => trim((string) fgets($connection, 2048));
        $reply = static fn (string $text): int|false => fwrite($connection, $text . "\r\n");
        $require = static function (bool $condition): void { if (!$condition) exit(3); };
        $reply('220 fixture SMTP');
        $require($line() === 'EHLO tokenhub.local');
        if (in_array($scenario, ['starttls', 'untrusted-starttls'], true)) {
            $reply("250-fixture\r\n250 STARTTLS");
            $require($line() === 'STARTTLS');
            $reply('220 ready for TLS');
            $secured = @stream_socket_enable_crypto($connection, true, STREAM_CRYPTO_METHOD_TLS_SERVER);
            if ($scenario === 'untrusted-starttls') {
                $require($secured !== true);
                fclose($connection); fclose($server); exit(0);
            }
            $require($secured === true);
            $require($line() === 'EHLO tokenhub.local');
        }
        $reply("250-fixture\r\n250-AUTH " . ($scenario === 'login' ? 'LOGIN' : 'PLAIN LOGIN') . "\r\n250 SIZE 1000000");
        if ($scenario === 'no-starttls') {
            // The client must abort instead of silently downgrading encryption.
            $require($line() === '');
            fclose($connection); fclose($server); exit(0);
        }
        if ($scenario === 'login') {
            $require($line() === 'AUTH LOGIN');
            $reply('334 ' . base64_encode('Username:'));
            $require($line() === base64_encode('fixture-user'));
            $reply('334 ' . base64_encode('Password:'));
            $require($line() === base64_encode('fixture-pass'));
        } else {
            $require($line() === 'AUTH PLAIN ' . base64_encode("\0fixture-user\0fixture-pass"));
        }
        if ($scenario === 'auth-rejected') {
            $reply('535 rejected fixture-pass and private server diagnostics');
            fclose($connection); fclose($server); exit(0);
        }
        $reply('235 authenticated');
        $require($line() === 'MAIL FROM:<sender@example.test>');
        $reply('250 sender accepted');
        $require($line() === 'RCPT TO:<receiver@example.test>');
        $reply('251 recipient forwarded');
        $require($line() === 'DATA');
        $reply('354 continue');
        $message = '';
        while (($chunk = fgets($connection, 2048)) !== false && $chunk !== ".\r\n") $message .= $chunk;
        [$headers, $encoded] = explode("\r\n\r\n", $message, 2);
        $require(str_contains($headers, 'Content-Type: text/plain; charset=UTF-8'));
        $require(str_contains($headers, 'Subject: =?UTF-8?B?' . base64_encode('测试邮件')));
        $require(base64_decode($encoded, true) === "中文邮件正文\n.不能截断邮件\n");
        $reply('250 queued');
        // Closing before a QUIT reply must not cause a second send.
        fclose($connection); fclose($server); exit(0);
    }
    fclose($server);
    try {
        (new SmtpMailer())->send([
            'host' => '127.0.0.1', 'port' => $port, 'encryption' => $scenario === 'smtps' ? 'ssl' : (in_array($scenario, ['starttls', 'no-starttls', 'untrusted-starttls'], true) ? 'starttls' : 'none'),
            'auth_enabled' => true, 'username' => 'fixture-user', 'password' => 'fixture-pass',
            'timeout_seconds' => 3, 'from_address' => 'sender@example.test', 'from_name' => '测试发件人',
        ], 'receiver@example.test', '测试邮件', "中文邮件正文\n.不能截断邮件\n");
        assert_same(null, $expectedError, 'Expected SMTP rejection.');
    } catch (RuntimeException $error) {
        assert_same($expectedError, $error->getMessage(), 'SMTP errors should be fixed codes without credentials or server replies.');
    } finally {
        pcntl_waitpid($pid, $status);
        assert_same(0, pcntl_wexitstatus($status), 'Test SMTP peer should observe the expected protocol.');
    }
};

try {
    $runSmtpCase('plain');
    $runSmtpCase('login');
    $runSmtpCase('starttls');
    $runSmtpCase('smtps');
    $runSmtpCase('untrusted-starttls', 'SMTP_TLS_FAILED');
    $runSmtpCase('auth-rejected', 'SMTP_AUTH_FAILED');
    $runSmtpCase('no-starttls', 'SMTP_TLS_FAILED');
} finally {
    putenv($oldCa === false ? 'SSL_CERT_FILE' : 'SSL_CERT_FILE=' . $oldCa);
    unlink($certificateConfig); unlink($certificateFile); unlink($certificatePem);
}

// Invalid recipients are rejected before a connection can be made.
try {
    (new SmtpMailer())->send([], "receiver@example.test\r\nBcc: other@example.test", 'test', 'body');
    throw new RuntimeException('Expected injection rejection.');
} catch (RuntimeException $error) {
    assert_same('SMTP_SEND_FAILED', $error->getMessage(), 'SMTP envelope injection must be rejected.');
}
