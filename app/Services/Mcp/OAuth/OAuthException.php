<?php

declare(strict_types=1);

namespace TokenHub\Services\Mcp\OAuth;

/**
 * An OAuth protocol error (RFC 6749 section 4.1.2.1 / 5.2). When
 * $redirectable is false the client or redirect URI could not be verified,
 * so the error is shown to the user instead of being sent to the redirect URI
 * (sending it there would make TokenHub an open redirector).
 */
final class OAuthException extends \RuntimeException
{
    public function __construct(
        public readonly string $error,
        public readonly string $description,
        public readonly int $status = 400,
        public readonly bool $redirectable = false,
    ) {
        parent::__construct($error . ': ' . $description);
    }
}
