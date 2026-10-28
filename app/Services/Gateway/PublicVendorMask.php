<?php

declare(strict_types=1);

namespace TokenHub\Services\Gateway;

/**
 * Downstream clients always see TokenHub as the model vendor. The real
 * upstream vendor (OpenRouter's "provider", the channel's provider name) is
 * kept in usage records and admin diagnostics only.
 */
final class PublicVendorMask
{
    public const VENDOR = 'TokenHub';

    /**
     * Top-level "provider" in a non-streaming response body.
     *
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    public function response(array $response): array
    {
        if (array_key_exists('provider', $response) && (is_scalar($response['provider']) || $response['provider'] === null)) {
            $response['provider'] = self::VENDOR;
        }

        return $response;
    }

    /**
     * Rewrites "provider" in SSE data lines. Network chunks can end mid-line,
     * so only complete lines are emitted; the rest waits for the next chunk
     * or for flush().
     *
     * @param array<string, mixed> $state
     */
    public function streamChunk(string $chunk, array &$state): string
    {
        $chunk = (string) ($state['buffer'] ?? '') . $chunk;
        $lastNewline = strrpos($chunk, "\n");
        if ($lastNewline === false) {
            $state['buffer'] = $chunk;
            return '';
        }
        $state['buffer'] = (string) substr($chunk, $lastNewline + 1);
        $complete = substr($chunk, 0, $lastNewline + 1);
        if (!str_contains($complete, '"provider"')) {
            return $complete;
        }

        $lines = explode("\n", $complete);
        foreach ($lines as $i => $line) {
            $lines[$i] = $this->line($line);
        }

        return implode("\n", $lines);
    }

    /**
     * Emits whatever is still buffered (a final line without a newline).
     *
     * @param array<string, mixed> $state
     */
    public function flush(array &$state): string
    {
        $rest = (string) ($state['buffer'] ?? '');
        $state['buffer'] = '';

        return $rest === '' ? '' : $this->line($rest);
    }

    private function line(string $line): string
    {
        if (!str_starts_with($line, 'data:') || !str_contains($line, '"provider"')) {
            return $line;
        }
        $eol = str_ends_with($line, "\r") ? "\r" : '';
        $data = trim(substr($line, 5));
        $decoded = json_decode($data, true);
        if (!is_array($decoded) || array_is_list($decoded) || !array_key_exists('provider', $decoded)) {
            return $line;
        }
        $masked = $this->response($decoded);
        if ($masked === $decoded) {
            return $line;
        }

        return 'data: ' . json_encode($masked, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . $eol;
    }
}
