<?php

declare(strict_types=1);

namespace TokenHub\Services;

use RuntimeException;

/** Minimal UTF-8 text mail transport. Never includes server replies or secrets in errors. */
final class SmtpMailer
{
    public function send(array $settings, string $recipient, string $subject, string $body): void
    {
        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false || preg_match('/[\r\n\x00]/', $recipient . $subject) === 1) {
            throw new RuntimeException('SMTP_SEND_FAILED');
        }
        $host = (string) $settings['host'];
        $address = str_contains($host, ':') ? '[' . $host . ']' : $host;
        $tls = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
        $context = stream_context_create(['ssl' => [
            'verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false,
            'peer_name' => $host, 'crypto_method' => $tls,
        ]]);
        $scheme = $settings['encryption'] === 'ssl' ? 'tls' : 'tcp';
        $socket = @stream_socket_client($scheme . '://' . $address . ':' . (int) $settings['port'], $errno, $errstr, (float) $settings['timeout_seconds'], STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            throw new RuntimeException('SMTP_CONNECT_FAILED');
        }
        stream_set_timeout($socket, (int) $settings['timeout_seconds']);
        try {
            $this->expect($socket, [220]);
            $capabilities = $this->command($socket, 'EHLO tokenhub.local', [250]);
            if ($settings['encryption'] === 'starttls') {
                if (stripos($capabilities, 'STARTTLS') === false) {
                    throw new RuntimeException('SMTP_TLS_FAILED');
                }
                $this->command($socket, 'STARTTLS', [220]);
                if (@stream_socket_enable_crypto($socket, true, $tls) !== true) {
                    throw new RuntimeException('SMTP_TLS_FAILED');
                }
                $capabilities = $this->command($socket, 'EHLO tokenhub.local', [250]);
            }
            if ($settings['auth_enabled']) {
                if (preg_match('/^250[ -]AUTH[^\r\n]*\bPLAIN\b/im', $capabilities) === 1) {
                    $this->command($socket, 'AUTH PLAIN ' . base64_encode("\0" . $settings['username'] . "\0" . $settings['password']), [235], 'SMTP_AUTH_FAILED');
                } else {
                    $this->command($socket, 'AUTH LOGIN', [334], 'SMTP_AUTH_FAILED');
                    $this->command($socket, base64_encode($settings['username']), [334], 'SMTP_AUTH_FAILED');
                    $this->command($socket, base64_encode($settings['password']), [235], 'SMTP_AUTH_FAILED');
                }
            }
            $from = (string) $settings['from_address'];
            $this->command($socket, 'MAIL FROM:<' . $from . '>', [250]);
            $this->command($socket, 'RCPT TO:<' . $recipient . '>', [250, 251]);
            $this->command($socket, 'DATA', [354]);
            $headers = [
                'Date: ' . gmdate('D, d M Y H:i:s +0000'),
                'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . substr(strrchr($from, '@'), 1) . '>',
                'From: =?UTF-8?B?' . base64_encode($settings['from_name']) . '?= <' . $from . '>',
                'To: <' . $recipient . '>',
                'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: base64',
            ];
            $this->write($socket, implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($body), 76, "\r\n") . ".\r\n");
            $this->expect($socket, [250]);
            // Once DATA is accepted, a dropped QUIT reply must not trigger a duplicate mail.
            @fwrite($socket, "QUIT\r\n");
        } finally {
            fclose($socket);
        }
    }

    private function command($socket, string $command, array $codes, string $error = 'SMTP_SEND_FAILED'): string
    {
        $this->write($socket, $command . "\r\n");
        return $this->expect($socket, $codes, $error);
    }

    private function write($socket, string $data): void
    {
        while ($data !== '') {
            $written = @fwrite($socket, $data);
            if ($written === false || $written === 0) {
                throw new RuntimeException('SMTP_SEND_FAILED');
            }
            $data = substr($data, $written);
        }
    }

    private function expect($socket, array $codes, string $error = 'SMTP_SEND_FAILED'): string
    {
        $reply = '';
        for ($i = 0; $i < 100; $i++) {
            $line = @fgets($socket, 2048);
            if ($line === false || preg_match('/^(\d{3})([ -])/', $line, $match) !== 1) {
                throw new RuntimeException($error);
            }
            $reply .= $line;
            if ($match[2] === ' ') {
                if (!in_array((int) $match[1], $codes, true)) {
                    throw new RuntimeException($error);
                }
                return $reply;
            }
        }
        throw new RuntimeException($error);
    }
}
