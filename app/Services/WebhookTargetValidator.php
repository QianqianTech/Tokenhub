<?php

declare(strict_types=1);

namespace TokenHub\Services;

final class WebhookTargetValidator
{
    /** @var callable(string): list<string> */
    private $resolver;

    public function __construct(?callable $resolver = null)
    {
        $this->resolver = $resolver ?? static function (string $host): array {
            if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
                return [$host];
            }
            return gethostbynamel($host) ?: [];
        };
    }

    public function isAllowed(string $url): bool
    {
        // Same rules as OutboundUrlPolicy::PURPOSE_WEBHOOK: https + public addresses only.
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $addresses = ($this->resolver)((string) $parts['host']);
        if ($addresses === []) {
            return false;
        }
        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        }
        return true;
    }
}
