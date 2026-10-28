<?php

declare(strict_types=1);

namespace TokenHub\Support;

/**
 * Raised by abort_json() instead of terminating the process.
 *
 * Deep service code used to call exit, which skipped every caller's cleanup
 * (quota reservations, concurrency counters, usage records). Throwing lets the
 * request boundary render the same JSON error while finally blocks and
 * lifecycle finalizers still run. Code that catches Throwable around calls
 * that may abort must rethrow this exception.
 */
final class HttpAbortException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 400,
    ) {
        parent::__construct($message, $status);
    }

    public function response(): Response
    {
        return Response::error($this->errorCode, $this->getMessage(), $this->status);
    }

    public static function rethrowIfAbort(\Throwable $throwable): void
    {
        if ($throwable instanceof self) {
            throw $throwable;
        }
    }
}
