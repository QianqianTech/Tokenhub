<?php

declare(strict_types=1);

namespace TokenHub\Services;

/**
 * Optional masking of personal data before indexing (RAG-022).
 *
 * Applied to preview items when an upload is parsed, so the masked text is
 * what gets chunked, embedded, indexed and shown in retrieval results. The
 * original file stays in upload storage (governed by retention policies).
 *
 * Types (python-rag-worker/tokenhub_rag_worker/redaction.py mirrors them):
 *   phone      mainland mobile numbers      13812345678     -> 138****5678
 *   email      e-mail addresses             zhang.san@x.com -> z***@x.com
 *   id_card    18-digit resident ID, checksum validated     -> 110101********123X
 *   bank_card  13-19 digits, Luhn validated (spaces/dashes)
 *              (a Luhn-valid order number of that length is masked too) -> ************4242
 *   ipv4       IPv4 addresses               10.2.33.4       -> 10.2.*.*
 *
 * Selection, most specific first: upload processing.redaction (list of
 * types, or false to switch off), knowledge base chunking_profile.redaction
 * = {"types": [...]} (or a list), env RAG_REDACTION_TYPES (comma list).
 */
final class RagRedactionService
{
    public const TYPES = ['phone', 'email', 'id_card', 'bank_card', 'ipv4'];

    /**
     * @param array<string, mixed> $processing upload processing options
     * @param mixed $kbChunkingProfile rag_knowledge_bases.chunking_profile (JSON string or array)
     * @return list<string>
     */
    public static function resolveTypes(array $processing, mixed $kbChunkingProfile): array
    {
        if (array_key_exists('redaction', $processing)) {
            return self::normalizeTypes($processing['redaction']);
        }
        $profile = is_array($kbChunkingProfile) ? $kbChunkingProfile : (is_string($kbChunkingProfile) && $kbChunkingProfile !== '' ? (json_decode($kbChunkingProfile, true) ?: []) : []);
        if (array_key_exists('redaction', $profile)) {
            return self::normalizeTypes($profile['redaction']);
        }

        return self::normalizeTypes((string) env_value('RAG_REDACTION_TYPES', ''));
    }

    /** @return list<string> */
    public static function normalizeTypes(mixed $value): array
    {
        if ($value === false || $value === null || $value === '') {
            return [];
        }
        if (is_array($value) && array_key_exists('types', $value)) {
            if (($value['enabled'] ?? true) === false) {
                return [];
            }
            $value = $value['types'];
        }
        if ($value === true || $value === 'all') {
            return self::TYPES;
        }
        $types = is_array($value) ? $value : explode(',', (string) $value);
        $types = array_map(static fn ($type): string => strtolower(trim((string) $type)), $types);
        $unknown = array_values(array_diff(array_filter($types), self::TYPES));
        if ($unknown !== []) {
            abort_json('RAG_REDACTION_INVALID', 'Unknown redaction type(s): ' . implode(', ', $unknown) . '; allowed: ' . implode(', ', self::TYPES), 422);
        }

        return array_values(array_intersect(self::TYPES, $types));
    }

    /**
     * @param list<string> $types
     * @return array{text: string, counts: array<string, int>}
     */
    public function redact(string $text, array $types): array
    {
        $counts = [];
        if ($types === [] || $text === '') {
            return ['text' => $text, 'counts' => $counts];
        }
        // Order matters: ID cards before phone/bank numbers so their digits are not split up.
        foreach (['email', 'id_card', 'bank_card', 'phone', 'ipv4'] as $type) {
            if (!in_array($type, $types, true)) {
                continue;
            }
            $count = 0;
            $text = (string) preg_replace_callback($this->pattern($type), function (array $match) use ($type, &$count): string {
                $masked = $this->mask($type, $match[0]);
                if ($masked !== $match[0]) {
                    $count++;
                }
                return $masked;
            }, $text);
            if ($count > 0) {
                $counts[$type] = $count;
            }
        }

        return ['text' => $text, 'counts' => $counts];
    }

    /**
     * Redacts the text fields of a parsed preview item and records the counts in its metadata.
     *
     * @param array<string, mixed> $item
     * @param list<string> $types
     * @return array<string, mixed>
     */
    public function redactItem(array $item, array $types): array
    {
        if ($types === []) {
            return $item;
        }
        $total = [];
        foreach (['title', 'content', 'question', 'answer', 'description', 'detailed_description'] as $field) {
            if (!isset($item[$field]) || !is_string($item[$field]) || $item[$field] === '') {
                continue;
            }
            $result = $this->redact($item[$field], $types);
            $item[$field] = $result['text'];
            foreach ($result['counts'] as $type => $count) {
                $total[$type] = ($total[$type] ?? 0) + $count;
            }
        }
        $metadata = is_array($item['metadata'] ?? null) ? $item['metadata'] : [];
        if (isset($metadata['similar_questions']) && is_string($metadata['similar_questions'])) {
            $metadata['similar_questions'] = $this->redact($metadata['similar_questions'], $types)['text'];
        }
        $metadata['redaction'] = ['types' => $types, 'counts' => $total];
        $item['metadata'] = $metadata;

        return $item;
    }

    private function pattern(string $type): string
    {
        return match ($type) {
            'phone' => '/(?<![\d*])(?:\+?86[ -]?)?1[3-9]\d(?:[ -]?\d{4}){2}(?![\d*])/u',
            'email' => '/(?<![A-Za-z0-9_.%+-])[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,}/u',
            'id_card' => '/(?<![0-9A-Za-z*])[1-9]\d{5}(?:18|19|20)\d{2}(?:0[1-9]|1[0-2])(?:0[1-9]|[12]\d|3[01])\d{3}[\dXx](?![0-9A-Za-z*])/u',
            'bank_card' => '/(?<![\d*])\d{4}(?:[ -]?\d{4}){2,3}(?:[ -]?\d{1,3})?(?![\d*])/u',
            'ipv4' => '/(?<![\d.])(?:(?:25[0-5]|2[0-4]\d|1?\d?\d)\.){3}(?:25[0-5]|2[0-4]\d|1?\d?\d)(?![\d.])/u',
        };
    }

    private function mask(string $type, string $value): string
    {
        switch ($type) {
            case 'phone':
                $digits = preg_replace('/\D/', '', $value) ?? '';
                $local = substr($digits, -11);
                return substr($local, 0, 3) . '****' . substr($local, -4);
            case 'email':
                [$local, $domain] = explode('@', $value, 2);
                return mb_substr($local, 0, 1) . '***@' . $domain;
            case 'id_card':
                return $this->validIdCard($value) ? substr($value, 0, 6) . '********' . substr($value, -4) : $value;
            case 'bank_card':
                $digits = preg_replace('/\D/', '', $value) ?? '';
                if (strlen($digits) < 13 || strlen($digits) > 19 || !$this->luhn($digits)) {
                    return $value;
                }
                return str_repeat('*', strlen($digits) - 4) . substr($digits, -4);
            case 'ipv4':
                $parts = explode('.', $value);
                return $parts[0] . '.' . $parts[1] . '.*.*';
        }

        return $value;
    }

    private function validIdCard(string $id): bool
    {
        $weights = [7, 9, 10, 5, 8, 4, 2, 1, 6, 3, 7, 9, 10, 5, 8, 4, 2];
        $sum = 0;
        for ($i = 0; $i < 17; $i++) {
            $sum += (int) $id[$i] * $weights[$i];
        }

        return '10X98765432'[$sum % 11] === strtoupper($id[17]);
    }

    private function luhn(string $digits): bool
    {
        $sum = 0;
        $double = false;
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $digit = (int) $digits[$i];
            if ($double) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            $sum += $digit;
            $double = !$double;
        }

        return $sum % 10 === 0;
    }
}
