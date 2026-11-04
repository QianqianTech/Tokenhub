<?php

declare(strict_types=1);

namespace TokenHub\Services\Gateway\Stream;

/**
 * PROTO-003: Gemini ":streamGenerateContent" without "?alt=sse" answers one
 * streamed JSON array ("[{...},\r\n{...}]") instead of Server-Sent Events.
 * The gateway always asks the upstream for SSE (one parser for usage and
 * terminal state) and re-frames the events here for clients that did not ask
 * for SSE.
 */
final class GeminiJsonArrayStream
{
    private SseEventParser $parser;
    private bool $opened = false;
    private bool $closed = false;

    public function __construct()
    {
        $this->parser = new SseEventParser();
    }

    public function push(string $chunk): string
    {
        $out = '';
        foreach ($this->parser->push($chunk) as $event) {
            $out .= $this->element($event['data']);
        }

        return $out;
    }

    /**
     * Closing bracket (plus any trailing event). Idempotent.
     */
    public function finish(): string
    {
        if ($this->closed) {
            return '';
        }
        $out = '';
        foreach ($this->parser->finish()['events'] as $event) {
            $out .= $this->element($event['data']);
        }
        $this->closed = true;

        return $out . ($this->opened ? "\r\n]" : '[]');
    }

    /**
     * Appends an error object as the last array element and closes the array.
     *
     * @param array<string, mixed> $error
     */
    public function error(array $error): string
    {
        if ($this->closed) {
            return '';
        }
        $out = $this->element((string) json_encode(['error' => $error], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->closed = true;

        return $out . "\r\n]";
    }

    public function started(): bool
    {
        return $this->opened;
    }

    private function element(string $data): string
    {
        $data = trim($data);
        if ($data === '' || $data === '[DONE]' || $this->closed) {
            return '';
        }
        $prefix = $this->opened ? ",\r\n" : '[';
        $this->opened = true;

        return $prefix . $data;
    }
}
