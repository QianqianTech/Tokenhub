<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;

/**
 * Server-side semantic validation for provider and model configuration.
 *
 * The admin UI already validates forms, but the API is the contract: an
 * invalid base URL, an unknown protocol or a logical model name that maps to
 * channels with incompatible capabilities must be rejected here, whatever
 * client sent it.
 */
final class ConfigWriteValidator
{
    private const PROTOCOLS = ['openai', 'anthropic', 'gemini'];
    private const CAPABILITIES = ['text_generation', 'vision_understanding', 'video_understanding', 'audio_understanding', 'embedding', 'asr', 'tts', 'image_generation', 'video_generation', 'rerank', 'moderation', 'music_generation', 'realtime'];

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $existing
     * @return list<array{field: string, code: string, message: string}>
     */
    public function provider(array $data, ?array $existing = null): array
    {
        $errors = [];
        $merged = [...($existing ?? []), ...$data];
        if (array_key_exists('name', $data) || $existing === null) {
            if (trim((string) ($merged['name'] ?? '')) === '') {
                $errors[] = $this->error('name', 'REQUIRED', 'Provider name is required');
            }
        }
        if (array_key_exists('protocol', $data) || $existing === null) {
            $protocol = (string) ($merged['protocol'] ?? '');
            if (!in_array($protocol, [...self::PROTOCOLS, 'native'], true)) {
                $errors[] = $this->error('protocol', 'INVALID', 'protocol must be openai, anthropic or gemini');
            } elseif ($protocol === 'native' && ($existing['protocol'] ?? null) !== 'native') {
                $errors[] = $this->error('protocol', 'UNSUPPORTED', 'native providers have no execution backend; configure an openai or anthropic compatible endpoint');
            }
        }
        if (array_key_exists('base_url', $data) || $existing === null) {
            $url = trim((string) ($merged['base_url'] ?? ''));
            $parts = parse_url($url);
            if ($url === '' || !is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || empty($parts['host'])) {
                $errors[] = $this->error('base_url', 'INVALID', 'base_url must be an absolute http(s) URL');
            } elseif (isset($parts['user']) || isset($parts['pass'])) {
                $errors[] = $this->error('base_url', 'CREDENTIALS_IN_URL', 'Put credentials in the secret field, not in the URL');
            } elseif (isset($parts['query']) || isset($parts['fragment'])) {
                $errors[] = $this->error('base_url', 'INVALID', 'base_url must not contain a query string or fragment');
            } elseif (preg_match('~/(chat/completions|completions|messages|responses|embeddings|rerank|moderations)/?$~i', (string) ($parts['path'] ?? '')) === 1) {
                $errors[] = $this->error('base_url', 'ENDPOINT_IN_BASE_URL', 'base_url must be the API root, not a full endpoint path');
            }
        }
        if (array_key_exists('timeout_ms', $data) && ((int) $data['timeout_ms'] < 100 || (int) $data['timeout_ms'] > 900000)) {
            $errors[] = $this->error('timeout_ms', 'OUT_OF_RANGE', 'timeout_ms must be between 100 and 900000');
        }
        if (array_key_exists('retry_count', $data) && ((int) $data['retry_count'] < 0 || (int) $data['retry_count'] > 5)) {
            $errors[] = $this->error('retry_count', 'OUT_OF_RANGE', 'retry_count must be between 0 and 5');
        }
        if (array_key_exists('default_headers', $data) && $data['default_headers'] !== null && $data['default_headers'] !== '') {
            $headers = is_array($data['default_headers']) ? $data['default_headers'] : json_decode((string) $data['default_headers'], true);
            if (!is_array($headers) || array_is_list($headers) && $headers !== []) {
                $errors[] = $this->error('default_headers', 'INVALID', 'default_headers must be a JSON object');
            } else {
                foreach ($headers as $name => $value) {
                    $name = (string) $name;
                    if (str_starts_with(strtolower($name), 'tokenhub_')) {
                        continue;
                    }
                    if (preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/', $name) !== 1 || !is_scalar($value) || preg_match('/[\r\n]/', (string) $value) === 1) {
                        $errors[] = $this->error('default_headers', 'INVALID_HEADER', 'Header ' . substr($name, 0, 40) . ' has an invalid name or value');
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $existing
     * @return list<array{field: string, code: string, message: string}>
     */
    public function model(array $data, ?array $existing = null): array
    {
        $errors = [];
        $merged = [...($existing ?? []), ...$data];
        if ($existing === null || array_key_exists('provider_id', $data)) {
            if ((int) ($merged['provider_id'] ?? 0) <= 0 || App::db()->first('SELECT id FROM providers WHERE id = ?', [(int) $merged['provider_id']]) === null) {
                $errors[] = $this->error('provider_id', 'NOT_FOUND', 'provider_id must reference an existing provider');
            }
        }
        foreach (['name', 'external_name'] as $field) {
            if (($existing === null || array_key_exists($field, $data)) && trim((string) ($merged[$field] ?? '')) === '') {
                $errors[] = $this->error($field, 'REQUIRED', $field . ' is required');
            }
        }
        if (($existing === null || array_key_exists('capability', $data)) && !in_array((string) ($merged['capability'] ?? ''), self::CAPABILITIES, true)) {
            $errors[] = $this->error('capability', 'INVALID', 'Unknown capability');
        }
        if (array_key_exists('route_priority', $data) && ((int) $data['route_priority'] < 0 || (int) $data['route_priority'] > 100000)) {
            $errors[] = $this->error('route_priority', 'OUT_OF_RANGE', 'route_priority must be between 0 and 100000');
        }
        $name = trim((string) ($merged['name'] ?? ''));
        $capability = (string) ($merged['capability'] ?? '');
        if ($name !== '' && in_array($capability, self::CAPABILITIES, true)) {
            // One logical model name may have several channels, but they must
            // be interchangeable: a different capability would make routing
            // depend on whichever channel happens to be first.
            $conflict = App::db()->first(
                'SELECT m.id, m.capability, p.name AS provider_name FROM models m JOIN providers p ON p.id = m.provider_id WHERE m.name = ? AND m.capability <> ? AND m.status <> "retired" AND m.id <> ? LIMIT 1',
                [$name, $capability, (int) ($existing['id'] ?? 0)]
            );
            if ($conflict !== null && !$this->compatible($capability, (string) $conflict['capability'])) {
                $errors[] = $this->error('name', 'ALIAS_CAPABILITY_CONFLICT', 'Model name is already used by provider ' . $conflict['provider_name'] . ' with capability ' . $conflict['capability']);
            }
        }

        return $errors;
    }

    private function compatible(string $a, string $b): bool
    {
        $text = \TokenHub\Services\Gateway\InputModalities::TEXT_FAMILY;

        return in_array($a, $text, true) && in_array($b, $text, true);
    }

    /**
     * @param list<array{field: string, code: string, message: string}> $errors
     */
    public function abortIfInvalid(array $errors): void
    {
        if ($errors === []) {
            return;
        }
        $first = $errors[0];
        abort_json('ADMIN_VALIDATION_FAILED', $first['field'] . ': ' . $first['message'] . (count($errors) > 1 ? ' (+' . (count($errors) - 1) . ' more)' : ''), 422);
    }

    /**
     * @return array{field: string, code: string, message: string}
     */
    private function error(string $field, string $code, string $message): array
    {
        return ['field' => $field, 'code' => $code, 'message' => $message];
    }
}
