<?php

declare(strict_types=1);

namespace TokenHub\Services\Mcp;

final class McpToolResult
{
    /**
     * @param array<string, mixed>|null $structured
     */
    private function __construct(
        private readonly string $text,
        private readonly ?array $structured,
        private readonly bool $isError,
    ) {
    }

    /**
     * Structured data plus its JSON serialization as text, as the spec
     * recommends for clients that do not read structuredContent.
     *
     * @param array<string, mixed> $data
     */
    public static function structured(array $data, ?string $text = null): self
    {
        return new self($text ?? self::json($data), $data, false);
    }

    public static function error(string $message): self
    {
        return new self($message, null, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = ['content' => [['type' => 'text', 'text' => $this->text]]];
        if ($this->structured !== null) {
            // structuredContent must be a JSON object, never a list.
            $result['structuredContent'] = $this->structured === [] || array_is_list($this->structured)
                ? (object) ['items' => $this->structured]
                : $this->structured;
        }
        $result['isError'] = $this->isError;

        return $result;
    }

    /**
     * @param array<mixed> $data
     */
    private static function json(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
    }
}
