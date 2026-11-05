<?php

declare(strict_types=1);

namespace TokenHub\Services;

use RuntimeException;
use SimpleXMLElement;

/**
 * Minimal Tencent Cloud COS (对象存储) XML API client: PUT / GET / DELETE
 * object and list by prefix, signed with the COS request signature
 * (q-sign-algorithm=sha1, https://cloud.tencent.com/document/product/436/7778).
 * No SDK dependency; HTTP goes through curl (or an injected transport in tests).
 */
final class TencentCosClient
{
    /** @var null|callable(string, string, array<string, string>, string): array{0: int, 1: string} */
    private static $transport = null;

    public function __construct(
        private readonly string $secretId,
        private readonly string $secretKey,
        private readonly string $bucket,
        private readonly string $region,
        private readonly string $endpoint = '',
        private readonly int $timeout = 60,
    ) {
    }

    /**
     * Tests: replace HTTP with fn (method, url, headers, body): [status, body].
     *
     * @param null|callable(string, string, array<string, string>, string): array{0: int, 1: string} $transport
     */
    public static function useTransport(?callable $transport): void
    {
        self::$transport = $transport;
    }

    public function host(): string
    {
        if ($this->endpoint !== '') {
            return (string) parse_url($this->endpoint, PHP_URL_HOST);
        }

        return $this->bucket . '.cos.' . $this->region . '.myqcloud.com';
    }

    public function put(string $key, string $body, string $storageClass = ''): void
    {
        $headers = ['Content-Length' => (string) strlen($body), 'Content-MD5' => base64_encode(md5($body, true)), 'Content-Type' => 'application/octet-stream'];
        if ($storageClass !== '' && $storageClass !== 'STANDARD') {
            $headers['x-cos-storage-class'] = $storageClass;
        }
        [$status, $response] = $this->request('PUT', $key, [], $headers, $body);
        if ($status !== 200) {
            throw new RuntimeException('COS 上传失败：' . $this->errorText($status, $response));
        }
    }

    /**
     * Uploads a local file without reading it into memory (media files).
     */
    public function putFile(string $key, string $file, string $storageClass = ''): void
    {
        $size = @filesize($file);
        $md5 = @md5_file($file, true);
        if ($size === false || $md5 === false) {
            throw new RuntimeException('COS 上传失败：无法读取本地文件');
        }
        $headers = ['Content-Length' => (string) $size, 'Content-MD5' => base64_encode($md5), 'Content-Type' => 'application/octet-stream'];
        if ($storageClass !== '' && $storageClass !== 'STANDARD') {
            $headers['x-cos-storage-class'] = $storageClass;
        }
        [$status, $response] = $this->request('PUT', $key, [], $headers, '', $file);
        if ($status !== 200) {
            throw new RuntimeException('COS 上传失败：' . $this->errorText($status, $response));
        }
    }

    /**
     * Downloads an object into a local file (streamed). False when it does not exist.
     */
    public function getToFile(string $key, string $file): bool
    {
        [$status, $response] = $this->request('GET', $key, [], [], '', null, $file);
        if ($status === 404) {
            @unlink($file);
            return false;
        }
        if ($status !== 200) {
            @unlink($file);
            throw new RuntimeException('COS 下载失败：' . $this->errorText($status, $response));
        }

        return true;
    }

    /**
     * Object content, or null when it does not exist.
     */
    public function get(string $key): ?string
    {
        [$status, $response] = $this->request('GET', $key);
        if ($status === 404) {
            return null;
        }
        if ($status !== 200) {
            throw new RuntimeException('COS 下载失败：' . $this->errorText($status, $response));
        }

        return $response;
    }

    public function delete(string $key): void
    {
        [$status, $response] = $this->request('DELETE', $key);
        if ($status !== 204 && $status !== 200 && $status !== 404) {
            throw new RuntimeException('COS 删除失败：' . $this->errorText($status, $response));
        }
    }

    /**
     * All objects under a prefix (key => size), following pagination.
     *
     * @return array<string, int>
     */
    public function list(string $prefix): array
    {
        $objects = [];
        $marker = '';
        for ($page = 0; $page < 10000; $page++) {
            $params = ['prefix' => $prefix, 'max-keys' => '1000'];
            if ($marker !== '') {
                $params['marker'] = $marker;
            }
            [$status, $response] = $this->request('GET', '', $params);
            if ($status !== 200) {
                throw new RuntimeException('COS 列出文件失败：' . $this->errorText($status, $response));
            }
            $xml = @simplexml_load_string($response);
            if (!$xml instanceof SimpleXMLElement) {
                throw new RuntimeException('COS 返回的列表无法解析');
            }
            $last = '';
            foreach ($xml->Contents as $item) {
                $last = (string) $item->Key;
                $objects[$last] = (int) $item->Size;
            }
            if ((string) $xml->IsTruncated !== 'true') {
                break;
            }
            $marker = (string) ($xml->NextMarker ?? '') !== '' ? (string) $xml->NextMarker : $last;
            if ($marker === '') {
                break;
            }
        }

        return $objects;
    }

    /**
     * HttpString of the COS signature (exposed for the documented test vectors).
     *
     * @param array<string, string> $params
     * @param array<string, string> $headers
     * @return array{0: string, 1: string, 2: string} HttpString, UrlParamList, HeaderList
     */
    public static function httpString(string $method, string $path, array $params, array $headers): array
    {
        [$paramList, $paramString] = self::canonical($params);
        [$headerList, $headerString] = self::canonical($headers);

        return [strtolower($method) . "\n" . $path . "\n" . $paramString . "\n" . $headerString . "\n", $paramList, $headerList];
    }

    /**
     * @param array<string, string> $params
     * @param array<string, string> $headers
     */
    public function authorization(string $method, string $path, array $params, array $headers, int $start, int $end): string
    {
        $keyTime = $start . ';' . $end;
        [$httpString, $paramList, $headerList] = self::httpString($method, $path, $params, $headers);
        $signKey = hash_hmac('sha1', $keyTime, $this->secretKey);
        $stringToSign = "sha1\n" . $keyTime . "\n" . sha1($httpString) . "\n";
        $signature = hash_hmac('sha1', $stringToSign, $signKey);

        return 'q-sign-algorithm=sha1&q-ak=' . $this->secretId
            . '&q-sign-time=' . $keyTime . '&q-key-time=' . $keyTime
            . '&q-header-list=' . $headerList . '&q-url-param-list=' . $paramList
            . '&q-signature=' . $signature;
    }

    /**
     * @param array<string, string> $params
     * @param array<string, string> $headers
     * @return array{0: int, 1: string}
     */
    private function request(string $method, string $key, array $params = [], array $headers = [], string $body = '', ?string $uploadFile = null, ?string $downloadFile = null): array
    {
        if (preg_match('#^[A-Za-z0-9/_.\-]*$#', $key) !== 1) {
            throw new RuntimeException('COS 对象路径含有不支持的字符：' . $key);
        }
        $path = '/' . ltrim($key, '/');
        $headers['Host'] = $this->host();
        $signed = [];
        foreach (['Host', 'Content-Length', 'Content-MD5'] as $name) {
            if (isset($headers[$name])) {
                $signed[$name] = $headers[$name];
            }
        }
        $now = time();
        $headers['Authorization'] = $this->authorization($method, $path, $params, $signed, $now - 60, $now + 900);
        $query = $params === [] ? '' : '?' . implode('&', array_map(static fn (string $k, string $v): string => rawurlencode($k) . '=' . rawurlencode($v), array_keys($params), $params));
        $scheme = $this->endpoint !== '' && str_starts_with($this->endpoint, 'http://') ? 'http' : 'https';
        $url = $scheme . '://' . $this->host() . $path . $query;

        if (self::$transport !== null) {
            [$status, $response] = (self::$transport)($method, $url, $headers, $uploadFile !== null ? (string) file_get_contents($uploadFile) : $body);
            if ($downloadFile !== null && $status === 200) {
                file_put_contents($downloadFile, $response);
                $response = '';
            }

            return [$status, $response];
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP curl 扩展不可用');
        }
        $handle = curl_init($url);
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $lines[] = 'Expect:';
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $upload = null;
        $download = null;
        if ($method === 'PUT' && $uploadFile !== null) {
            $upload = fopen($uploadFile, 'rb');
            if ($upload === false) {
                throw new RuntimeException('COS 上传失败：无法读取本地文件');
            }
            curl_setopt_array($handle, [CURLOPT_UPLOAD => true, CURLOPT_INFILE => $upload, CURLOPT_INFILESIZE => (int) filesize($uploadFile)]);
        } elseif ($method === 'PUT') {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }
        if ($downloadFile !== null) {
            $download = fopen($downloadFile, 'wb');
            if ($download === false) {
                throw new RuntimeException('COS 下载失败：本地文件不可写');
            }
            // Error bodies (XML) are small; the object body goes to the file.
            curl_setopt($handle, CURLOPT_FILE, $download);
            curl_setopt($handle, CURLOPT_RETURNTRANSFER, false);
        }
        if ($method === 'HEAD') {
            curl_setopt($handle, CURLOPT_NOBODY, true);
        }
        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if ($upload !== null) {
            fclose($upload);
        }
        if ($download !== null) {
            fclose($download);
            if ($response !== false && $status !== 200) {
                // The error document landed in the file.
                $response = (string) @file_get_contents($downloadFile, false, null, 0, 65536);
            } elseif ($response !== false) {
                $response = '';
            }
        }
        if ($response === false) {
            throw new RuntimeException('无法连接 COS（' . $this->host() . '）：' . $error);
        }

        return [$status, (string) $response];
    }

    /**
     * @param array<string, string> $pairs
     * @return array{0: string, 1: string} key list, key=value string
     */
    private static function canonical(array $pairs): array
    {
        $encoded = [];
        foreach ($pairs as $key => $value) {
            $encoded[strtolower(rawurlencode((string) $key))] = rawurlencode((string) $value);
        }
        ksort($encoded, SORT_STRING);
        $list = implode(';', array_keys($encoded));
        $string = implode('&', array_map(static fn (string $k, string $v): string => $k . '=' . $v, array_keys($encoded), $encoded));

        return [$list, $string];
    }

    private function errorText(int $status, string $response): string
    {
        $xml = @simplexml_load_string($response);
        if ($xml instanceof SimpleXMLElement && isset($xml->Code)) {
            return (string) $xml->Code . '（HTTP ' . $status . '）' . (isset($xml->Message) ? '：' . (string) $xml->Message : '');
        }

        return 'HTTP ' . $status;
    }
}
