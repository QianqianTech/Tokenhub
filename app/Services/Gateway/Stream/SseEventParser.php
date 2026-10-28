<?php

declare(strict_types=1);

namespace TokenHub\Services\Gateway\Stream;

/**
 * Incremental Server-Sent Events parser (WHATWG event-stream rules).
 *
 * Network chunks may split an event anywhere, including inside a UTF-8
 * sequence or between "\r" and "\n". Bytes are buffered until a blank line
 * terminates an event; comment lines (":keep-alive") are ignored; multiple
 * "data:" lines are joined with "\n". finish() flushes a trailing event that
 * was not followed by a blank line and reports whether bytes were left over.
 */
final class SseEventParser
{
    private string $buffer = '';
    private string $eventType = '';
    /** @var list<string> */
    private array $dataLines = [];
    private ?string $lastEventId = null;
    private bool $hasFields = false;

    /**
     * @return list<array{event: string, data: string, id: ?string}>
     */
    public function push(string $chunk): array
    {
        $this->buffer .= $chunk;
        $events = [];

        while (true) {
            $line = $this->nextLine();
            if ($line === null) {
                break;
            }
            $event = $this->consumeLine($line);
            if ($event !== null) {
                $events[] = $event;
            }
        }

        return $events;
    }

    /**
     * @return array{events: list<array{event: string, data: string, id: ?string}>, incomplete: bool}
     */
    public function finish(): array
    {
        $events = [];
        $incomplete = false;
        if ($this->buffer !== '') {
            $event = $this->consumeLine(rtrim($this->buffer, "\r"));
            $this->buffer = '';
            if ($event !== null) {
                $events[] = $event;
            }
        }
        if ($this->hasFields) {
            $incomplete = true;
            $flushed = $this->dispatch();
            if ($flushed !== null) {
                $events[] = $flushed;
            }
        }

        return ['events' => $events, 'incomplete' => $incomplete];
    }

    private function nextLine(): ?string
    {
        $length = strlen($this->buffer);
        for ($i = 0; $i < $length; $i++) {
            $char = $this->buffer[$i];
            if ($char === "\n") {
                $line = substr($this->buffer, 0, $i);
                $this->buffer = (string) substr($this->buffer, $i + 1);
                return $line;
            }
            if ($char === "\r") {
                if ($i + 1 >= $length) {
                    // Wait for the next chunk: this may be the first half of "\r\n".
                    return null;
                }
                $line = substr($this->buffer, 0, $i);
                $skip = $this->buffer[$i + 1] === "\n" ? 2 : 1;
                $this->buffer = (string) substr($this->buffer, $i + $skip);
                return $line;
            }
        }

        return null;
    }

    /**
     * @return array{event: string, data: string, id: ?string}|null
     */
    private function consumeLine(string $line): ?array
    {
        if ($line === '') {
            return $this->dispatch();
        }
        if ($line[0] === ':') {
            return null;
        }

        $colon = strpos($line, ':');
        if ($colon === false) {
            $field = $line;
            $value = '';
        } else {
            $field = substr($line, 0, $colon);
            $value = substr($line, $colon + 1);
            if (str_starts_with($value, ' ')) {
                $value = substr($value, 1);
            }
        }

        switch ($field) {
            case 'event':
                $this->eventType = $value;
                $this->hasFields = true;
                break;
            case 'data':
                $this->dataLines[] = $value;
                $this->hasFields = true;
                break;
            case 'id':
                if (!str_contains($value, "\0")) {
                    $this->lastEventId = $value;
                }
                break;
            default:
                break;
        }

        return null;
    }

    /**
     * @return array{event: string, data: string, id: ?string}|null
     */
    private function dispatch(): ?array
    {
        if (!$this->hasFields) {
            $this->eventType = '';
            return null;
        }
        $event = [
            'event' => $this->eventType === '' ? 'message' : $this->eventType,
            'data' => implode("\n", $this->dataLines),
            'id' => $this->lastEventId,
        ];
        $this->eventType = '';
        $this->dataLines = [];
        $this->hasFields = false;

        return $event;
    }
}
