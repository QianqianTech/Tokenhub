<?php

declare(strict_types=1);

namespace TokenHub\Support;

final readonly class HttpResponse
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public string $body,
        public int $status = 200,
        public array $headers = [],
    ) {
    }

    public function withHeader(string $name, string $value): self
    {
        return new self($this->body, $this->status, array_merge($this->headers, [$name => $value]));
    }

    public function send(): void
    {
        http_response_code($this->status);

        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }

        echo $this->body;
    }
}
