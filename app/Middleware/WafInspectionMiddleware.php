<?php

declare(strict_types=1);

namespace TokenHub\Middleware;

use TokenHub\Services\SystemSettingsService;
use TokenHub\Services\WafRequestInspector;
use TokenHub\Services\WafService;
use TokenHub\Support\App;
use TokenHub\Support\Request;
use TokenHub\Support\Response;

final class WafInspectionMiddleware
{
    /** @param null|array<string, mixed> $settings @param null|list<array<string, mixed>> $rules */
    public function __construct(
        private readonly ?WafRequestInspector $inspector = null,
        private readonly mixed $waf = null,
        private readonly ?array $settings = null,
        private readonly ?array $rules = null,
    ) {
    }

    public function __invoke(Request $request, callable $next): Response
    {
        try {
            $settings = $this->settings ?? (new SystemSettingsService())->loadSection('waf_detection_settings');
            $waf = $this->waf ?? new WafService();
            $rules = $this->rules ?? $waf->attackRules();
            $result = ($this->inspector ?? new WafRequestInspector())->inspect($request, $rules, $settings);
            $mode = (string) ($settings['mode'] ?? 'observe');
            if ($result['matched']) {
                $waf->recordInspection($request, $result, $mode);
            }
            if ($mode === 'block' && $result['blocked']) {
                return Response::error('WAF_BLOCKED', 'Request blocked by web application firewall', 403);
            }
        } catch (\Throwable $error) {
            App::logger()->warning('security', 'waf inspection failed', ['request_id' => $request->context->requestId(), 'error' => $error->getMessage()]);
        }

        return $next($request);
    }
}
