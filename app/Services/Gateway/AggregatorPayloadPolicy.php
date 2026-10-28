<?php

declare(strict_types=1);

namespace TokenHub\Services\Gateway;

/**
 * Guards aggregator "second hop" routing parameters (OpenRouter style).
 *
 * TokenHub authorizes one logical model and one provider channel. Aggregators
 * accept body fields that can silently move execution elsewhere:
 *   models[] / route   fallback to other models
 *   provider{...}      choose/allow upstream vendors, data collection, prices
 *   plugins[]          web search and other third-party data flows
 * and auto routers (openrouter/auto) choose the model themselves.
 *
 * Provider controls (providers.default_headers, never sent upstream):
 *   tokenhub_client_routing   "deny" (default) rejects client provider/plugins/
 *                             route fields; "restricted" lets clients narrow
 *                             the server policy but never widen it.
 *   tokenhub_provider_policy  server-enforced provider object, e.g.
 *                             {"data_collection":"deny","allow_fallbacks":false,
 *                              "only":["openai","anthropic"],"max_price":{"prompt":5}}
 *   tokenhub_allowed_plugins  plugin ids clients may request, e.g. ["web"]
 *   tokenhub_allow_auto_router true to allow routes whose upstream id is an
 *                             auto router.
 *
 * models[]: every entry must be a model the API key may use on the SAME
 * provider; entries are rewritten to their upstream ids. Anything else is
 * rejected before any upstream call.
 */
final class AggregatorPayloadPolicy
{
    private const AUTO_ROUTERS = ['openrouter/auto', 'auto', 'openrouter/auto:free'];

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $route
     * @param array<string, mixed> $controls decoded provider default_headers
     * @param callable(string): ?array<string, mixed> $authorizedSibling resolves a
     *        client-supplied model name to an authorized route on the same provider
     * @return array{ok: bool, payload?: array<string, mixed>, code?: string, message?: string, applied?: array<string, mixed>}
     */
    public function apply(array $payload, array $route, array $controls, callable $authorizedSibling): array
    {
        $mode = strtolower((string) ($controls['tokenhub_client_routing'] ?? 'deny'));
        $applied = [];

        $upstreamModel = strtolower((string) ($route['external_name'] ?? $route['name'] ?? ''));
        if (in_array($upstreamModel, self::AUTO_ROUTERS, true) && !filter_var($controls['tokenhub_allow_auto_router'] ?? false, FILTER_VALIDATE_BOOL)) {
            return $this->deny('ROUTING_AUTO_ROUTER_FORBIDDEN', 'Auto routing models are not enabled for this channel');
        }

        if (array_key_exists('models', $payload)) {
            if (!is_array($payload['models'])) {
                return $this->deny('ROUTING_PARAMETER_INVALID', 'models must be an array of model names');
            }
            $rewritten = [];
            foreach ($payload['models'] as $candidate) {
                if (!is_string($candidate) || $candidate === '') {
                    return $this->deny('ROUTING_PARAMETER_INVALID', 'models must be an array of model names');
                }
                $sibling = $authorizedSibling($candidate);
                if ($sibling === null || (int) ($sibling['provider_id'] ?? 0) !== (int) ($route['provider_id'] ?? -1)) {
                    return $this->deny('MODEL_FORBIDDEN', 'Fallback model is not authorized on this channel');
                }
                $external = (string) (($sibling['external_name'] ?? '') !== '' ? $sibling['external_name'] : $sibling['name']);
                if (in_array(strtolower($external), self::AUTO_ROUTERS, true)) {
                    return $this->deny('ROUTING_AUTO_ROUTER_FORBIDDEN', 'Auto routing models are not enabled for this channel');
                }
                $rewritten[] = $external;
            }
            $payload['models'] = $rewritten;
            $applied['models'] = $rewritten;
        }

        if (array_key_exists('route', $payload) && !array_key_exists('models', $payload)) {
            unset($payload['route']);
            $applied['route'] = 'removed';
        }

        $allowedPlugins = $this->stringList($controls['tokenhub_allowed_plugins'] ?? []);
        if (array_key_exists('plugins', $payload)) {
            if ($mode === 'deny' && $allowedPlugins === []) {
                return $this->deny('ROUTING_PARAMETER_FORBIDDEN', 'plugins are not allowed for this channel');
            }
            foreach ((array) $payload['plugins'] as $plugin) {
                $id = is_array($plugin) ? (string) ($plugin['id'] ?? '') : (string) $plugin;
                if (!in_array($id, $allowedPlugins, true)) {
                    return $this->deny('ROUTING_PARAMETER_FORBIDDEN', 'plugin is not allowed for this channel');
                }
            }
        }

        $server = $controls['tokenhub_provider_policy'] ?? null;
        if (is_string($server)) {
            $server = json_decode($server, true);
        }
        $server = is_array($server) ? $server : [];
        $client = $payload['provider'] ?? null;
        if ($client !== null) {
            if ($mode === 'deny') {
                return $this->deny('ROUTING_PARAMETER_FORBIDDEN', 'provider routing parameters are not allowed for this channel');
            }
            if (!is_array($client)) {
                return $this->deny('ROUTING_PARAMETER_INVALID', 'provider must be an object');
            }
        }
        $merged = $this->mergeProvider($server, is_array($client) ? $client : []);
        if ($merged === null) {
            return $this->deny('ROUTING_PARAMETER_FORBIDDEN', 'provider routing parameters exceed the channel policy');
        }
        if ($merged !== []) {
            $payload['provider'] = $merged;
            $applied['provider'] = $merged;
        } else {
            unset($payload['provider']);
        }

        foreach (['transforms'] as $field) {
            if (array_key_exists($field, $payload) && $mode === 'deny') {
                unset($payload[$field]);
                $applied[$field] = 'removed';
            }
        }

        return ['ok' => true, 'payload' => $payload, 'applied' => $applied];
    }

    /**
     * Client values may only narrow the server policy. Returns null when the
     * client asks for something the server forbids.
     *
     * @param array<string, mixed> $server
     * @param array<string, mixed> $client
     * @return array<string, mixed>|null
     */
    public function mergeProvider(array $server, array $client): ?array
    {
        $merged = $server;

        if (isset($client['only'])) {
            $clientOnly = $this->stringList($client['only']);
            if (isset($server['only'])) {
                $serverOnly = $this->stringList($server['only']);
                $outside = array_diff($clientOnly, $serverOnly);
                if ($outside !== []) {
                    return null;
                }
            }
            $merged['only'] = array_values($clientOnly);
        }
        if (isset($client['order'])) {
            $order = $this->stringList($client['order']);
            if (isset($merged['only']) && array_diff($order, $this->stringList($merged['only'])) !== []) {
                return null;
            }
            $ignored = $this->stringList($server['ignore'] ?? []);
            if (array_intersect($order, $ignored) !== []) {
                return null;
            }
            $merged['order'] = $order;
        }
        if (isset($client['ignore'])) {
            $merged['ignore'] = array_values(array_unique([...$this->stringList($server['ignore'] ?? []), ...$this->stringList($client['ignore'])]));
        }
        if (array_key_exists('allow_fallbacks', $client)) {
            $clientValue = (bool) $client['allow_fallbacks'];
            if (array_key_exists('allow_fallbacks', $server) && $server['allow_fallbacks'] === false && $clientValue) {
                return null;
            }
            $merged['allow_fallbacks'] = array_key_exists('allow_fallbacks', $server) ? ((bool) $server['allow_fallbacks'] && $clientValue) : $clientValue;
        }
        if (isset($client['data_collection'])) {
            if (($server['data_collection'] ?? null) === 'deny' && $client['data_collection'] !== 'deny') {
                return null;
            }
            $merged['data_collection'] = $client['data_collection'] === 'deny' ? 'deny' : ($server['data_collection'] ?? 'allow');
        }
        foreach (['zdr', 'require_parameters'] as $flag) {
            if (array_key_exists($flag, $client)) {
                if (($server[$flag] ?? false) === true && $client[$flag] !== true) {
                    return null;
                }
                $merged[$flag] = (bool) (($server[$flag] ?? false) || $client[$flag]);
            }
        }
        if (isset($client['max_price']) && is_array($client['max_price'])) {
            $serverPrice = is_array($server['max_price'] ?? null) ? $server['max_price'] : [];
            $price = $serverPrice;
            foreach ($client['max_price'] as $dimension => $value) {
                if (!is_numeric($value)) {
                    return null;
                }
                $price[$dimension] = isset($serverPrice[$dimension]) ? min((float) $serverPrice[$dimension], (float) $value) : (float) $value;
            }
            $merged['max_price'] = $price;
        }
        foreach (array_keys($client) as $key) {
            if (!in_array($key, ['only', 'order', 'ignore', 'allow_fallbacks', 'data_collection', 'zdr', 'require_parameters', 'max_price', 'sort', 'quantizations'], true)) {
                return null;
            }
        }
        foreach (['sort', 'quantizations'] as $passThrough) {
            if (isset($client[$passThrough])) {
                $merged[$passThrough] = $client[$passThrough];
            }
        }

        return $merged;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [$value];
        }
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_map(static fn (mixed $item): string => strtolower(trim((string) $item)), array_filter($value, 'is_scalar'))));
    }

    /**
     * @return array{ok: false, code: string, message: string}
     */
    private function deny(string $code, string $message): array
    {
        return ['ok' => false, 'code' => $code, 'message' => $message];
    }
}
