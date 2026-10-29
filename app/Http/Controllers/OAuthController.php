<?php

declare(strict_types=1);

namespace TokenHub\Http\Controllers;

use TokenHub\Services\Mcp\OAuth\OAuthException;
use TokenHub\Services\Mcp\OAuth\OAuthServer;
use TokenHub\Support\App;
use TokenHub\Support\Request;
use TokenHub\Support\Response;

/**
 * HTTP side of the built-in MCP OAuth server: discovery documents, client
 * registration, the sign-in page, and the token/revocation endpoints.
 * See OAuthServer for the protocol rules and docs/mcp.md for client setup.
 */
final class OAuthController
{
    private const BROWSER_COOKIE_SECURE = '__Host-tokenhub_oauth';
    private const BROWSER_COOKIE_PLAIN = 'tokenhub_oauth';

    public function protectedResource(Request $request): Response
    {
        if (($disabled = $this->disabled()) !== null) {
            return $disabled;
        }

        return $this->json($this->server($request)->protectedResourceMetadata());
    }

    public function serverMetadata(Request $request): Response
    {
        if (($disabled = $this->disabled()) !== null) {
            return $disabled;
        }

        return $this->json($this->server($request)->authorizationServerMetadata());
    }

    public function register(Request $request): Response
    {
        if (($disabled = $this->disabled()) !== null) {
            return $disabled;
        }
        try {
            return $this->json($this->server($request)->register($request->json()), 201);
        } catch (OAuthException $exception) {
            return $this->oauthError($exception);
        }
    }

    public function authorize(Request $request): Response
    {
        if (($disabled = $this->disabled()) !== null) {
            return $disabled;
        }
        $server = $this->server($request);
        [$browserToken, $isNewBrowser] = $this->browserToken($request);
        try {
            $started = $server->startAuthorization($request->query, $browserToken, $request->clientIp());
        } catch (OAuthException $exception) {
            if ($exception->redirectable && isset($request->query['redirect_uri'])) {
                // client_id and redirect_uri were verified before this error.
                return $this->redirect($server->redirectWith((string) $request->query['redirect_uri'], [
                    'error' => $exception->error,
                    'error_description' => $exception->description,
                    'state' => isset($request->query['state']) ? (string) $request->query['state'] : null,
                ]));
            }

            return $this->page($request, $exception->description, null, null, $exception->status);
        }

        $response = $this->page($request, null, $started['request_id'], ['client' => $started['client'], 'redirect_uri' => $started['redirect_uri']]);

        return $isNewBrowser ? $this->withBrowserCookie($response, $request, $browserToken) : $response;
    }

    public function approve(Request $request): Response
    {
        if (($disabled = $this->disabled()) !== null) {
            return $disabled;
        }
        $server = $this->server($request);
        [$browserToken] = $this->browserToken($request);
        $form = $request->body;
        $requestId = (string) ($form['request_id'] ?? '');
        try {
            $pending = $server->pendingRequest($requestId, $browserToken);
        } catch (OAuthException $exception) {
            return $this->page($request, $exception->description, null, null, 400);
        }
        $context = ['client' => $pending['client'], 'redirect_uri' => (string) $pending['redirect_uri']];
        try {
            $result = $server->completeAuthorization($requestId, $browserToken, $form, $request->clientIp());
        } catch (OAuthException $exception) {
            return $this->page($request, $exception->description, $requestId, $context, $exception->status === 429 ? 429 : 200, (string) ($form['method'] ?? ''));
        }
        if ($result['status'] === 'choose_key') {
            return $this->page($request, null, $requestId, $context + ['keys' => $result['keys'], 'username' => $result['username']]);
        }

        return $this->redirect($result['redirect']);
    }

    public function token(Request $request): Response
    {
        if (($disabled = $this->disabled()) !== null) {
            return $disabled;
        }
        [$user, $password] = $this->basicCredentials($request);
        try {
            return $this->json($this->server($request)->token($request->body, $user, $password, $request->clientIp()));
        } catch (OAuthException $exception) {
            $response = $this->oauthError($exception);
            if ($exception->error === 'invalid_client' && $user !== null) {
                $response = $response->withHeader('WWW-Authenticate', 'Basic realm="tokenhub"');
            }

            return $response;
        }
    }

    public function revoke(Request $request): Response
    {
        if (($disabled = $this->disabled()) !== null) {
            return $disabled;
        }
        [$user, $password] = $this->basicCredentials($request);
        $server = $this->server($request);
        try {
            $client = $server->authenticateClient($request->body, $user, $password);
            $server->revokeToken($client, (string) ($request->body['token'] ?? ''));
        } catch (OAuthException $exception) {
            return $this->oauthError($exception);
        }

        return $this->json([]);
    }

    // ------------------------------------------------------------------ helpers

    public static function requestOrigin(Request $request): string
    {
        $https = strtolower((string) ($request->server['HTTPS'] ?? '')) === 'on'
            || strtolower((string) ($request->server['REQUEST_SCHEME'] ?? '')) === 'https'
            || (string) ($request->server['SERVER_PORT'] ?? '') === '443';
        $host = trim((string) $request->header('host', ''));
        if ($host === '' || preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $host) !== 1) {
            $host = (string) ($request->server['SERVER_NAME'] ?? 'localhost');
        }

        return ($https ? 'https' : 'http') . '://' . strtolower($host);
    }

    public static function serverFor(Request $request): OAuthServer
    {
        return new OAuthServer(OAuthServer::issuerFor(self::requestOrigin($request)));
    }

    private function server(Request $request): OAuthServer
    {
        return self::serverFor($request);
    }

    private function disabled(): ?Response
    {
        if (\TokenHub\Middleware\McpAuthMiddleware::enabled() && OAuthServer::enabled()) {
            return null;
        }

        return $this->json(['error' => 'not_found', 'error_description' => 'OAuth sign-in for MCP is disabled on this TokenHub'], 404);
    }

    private function json(array $data, int $status = 200): Response
    {
        $body = json_encode($data === [] ? new \stdClass() : $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return Response::raw((string) $body, $status, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store',
            'Pragma' => 'no-cache',
            // Discovery and token endpoints carry no cookies; browser-based MCP clients may call them.
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    private function oauthError(OAuthException $exception): Response
    {
        $response = $this->json(['error' => $exception->error, 'error_description' => $exception->description], $exception->status);

        return $exception->status === 429 ? $response->withHeader('Retry-After', '60') : $response;
    }

    private function redirect(string $location): Response
    {
        return Response::raw('', 303, ['Location' => $location, 'Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer']);
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function basicCredentials(Request $request): array
    {
        $header = (string) $request->header('authorization', '');
        if (preg_match('/^Basic\s+([A-Za-z0-9+\/=]+)$/i', $header, $match) !== 1) {
            return [null, null];
        }
        $decoded = base64_decode($match[1], true);
        if (!is_string($decoded) || !str_contains($decoded, ':')) {
            return [null, null];
        }
        [$user, $password] = explode(':', $decoded, 2);

        return [$user, $password];
    }

    /**
     * Random per-browser value that binds a pending sign-in to the browser
     * that started it (a leaked request id cannot be completed elsewhere).
     *
     * @return array{0: string, 1: bool}
     */
    private function browserToken(Request $request): array
    {
        foreach ([self::BROWSER_COOKIE_SECURE, self::BROWSER_COOKIE_PLAIN] as $name) {
            $value = (string) ($_COOKIE[$name] ?? '');
            if (preg_match('/^[a-f0-9]{64}$/', $value) === 1) {
                return [$value, false];
            }
        }

        return [bin2hex(random_bytes(32)), true];
    }

    private function withBrowserCookie(Response $response, Request $request, string $token): Response
    {
        $secure = str_starts_with(self::requestOrigin($request), 'https://');

        // __Host- requires Secure; plain-http intranet installs fall back to a host-only cookie.
        return $response->withCookie($secure ? self::BROWSER_COOKIE_SECURE : self::BROWSER_COOKIE_PLAIN, $token, time() + 3600, '/', $secure, true, 'Lax');
    }

    /**
     * @param array{client: array<string, mixed>, redirect_uri: string, keys?: list<array<string, mixed>>, username?: string}|null $context
     */
    private function page(Request $request, ?string $error, ?string $requestId, ?array $context, int $status = 200, string $activeMethod = ''): Response
    {
        $h = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $passwordLogin = $this->flag('password_login');
        $apiKeyLogin = $this->flag('api_key_login');
        $body = '';
        if ($context !== null && $requestId !== null) {
            $client = $context['client'];
            $redirectHost = (string) (parse_url($context['redirect_uri'], PHP_URL_HOST) ?: parse_url($context['redirect_uri'], PHP_URL_SCHEME));
            $name = $client['client_name'] ?? null;
            $body .= '<p class="lead"><strong>' . $h($name ?: '一个 MCP 客户端') . '</strong> 请求连接到 TokenHub。</p>';
            $body .= '<dl class="facts"><dt>授权后跳转到</dt><dd>' . $h($redirectHost) . '</dd>';
            if (str_starts_with((string) $client['client_id'], 'https://')) {
                $body .= '<dt>客户端标识</dt><dd>' . $h(parse_url((string) $client['client_id'], PHP_URL_HOST)) . '</dd>';
            }
            $body .= '<dt>可以做什么</dt><dd>以你选择的 API Key 的权限使用 TokenHub 的 MCP 工具（知识库检索、模型、额度与用量查询），不会获得管理权限。</dd></dl>';
            if ($error !== null) {
                $body .= '<p class="error" role="alert">' . $h($error) . '</p>';
            }
            $hidden = '<input type="hidden" name="request_id" value="' . $h($requestId) . '">';
            if (isset($context['keys'])) {
                $body .= '<form method="post" action="/oauth/authorize">' . $hidden . '<input type="hidden" name="method" value="password">';
                $body .= '<fieldset><legend>' . $h($context['username'] ?? '') . '，选择要授权的 API Key</legend>';
                foreach ($context['keys'] as $index => $key) {
                    $body .= '<label class="choice"><input type="radio" name="api_key_id" value="' . $h($key['id']) . '"' . ($index === 0 ? ' checked' : '') . '> <span>' . $h($key['name']) . ' <code>' . $h($key['display']) . '</code></span></label>';
                }
                $body .= '</fieldset><div class="actions"><button type="submit">授权</button><button type="submit" name="decision" value="deny" class="secondary" formnovalidate>拒绝</button></div></form>';
            } else {
                $useKey = $activeMethod === 'api_key' || !$passwordLogin;
                if ($passwordLogin) {
                    $body .= '<details' . ($useKey ? '' : ' open') . '><summary>用账号密码登录</summary><form method="post" action="/oauth/authorize">' . $hidden . '<input type="hidden" name="method" value="password">'
                        . '<label>用户名<input name="username" autocomplete="username" required></label>'
                        . '<label>密码<input name="password" type="password" autocomplete="current-password" required></label>'
                        . '<div class="actions"><button type="submit">登录并授权</button></div></form></details>';
                }
                if ($apiKeyLogin) {
                    $body .= '<details' . ($useKey ? ' open' : '') . '><summary>粘贴 API Key</summary><form method="post" action="/oauth/authorize">' . $hidden . '<input type="hidden" name="method" value="api_key">'
                        . '<label>API Key<input name="api_key" type="password" autocomplete="off" spellcheck="false" required></label>'
                        . '<div class="actions"><button type="submit">授权</button></div></form></details>';
                }
                $body .= '<form method="post" action="/oauth/authorize" class="deny">' . $hidden . '<input type="hidden" name="decision" value="deny"><button type="submit" class="secondary">拒绝</button></form>';
            }
        } else {
            $body .= '<p class="error" role="alert">' . $h($error ?? '无效的授权请求') . '</p><p class="muted">请回到 MCP 客户端重新发起连接。</p>';
        }

        $html = '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="referrer" content="no-referrer"><title>授权连接 · TokenHub</title><style>'
            . ':root{color-scheme:light dark;--bg:#f4f6f8;--card:#fff;--text:#151a21;--muted:#5b6573;--line:#d9dee5;--brand:#34d399;--brand-ink:#0b7a57;--danger:#b42318}'
            . '@media (prefers-color-scheme:dark){:root{--bg:#0f1318;--card:#151a21;--text:#e8edf2;--muted:#9aa5b1;--line:#2a313a;--brand-ink:#34d399;--danger:#f97066}}'
            . '*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;background:var(--bg);color:var(--text);font:15px/1.6 Inter,"PingFang SC","Noto Sans SC",system-ui,sans-serif;padding:16px}'
            . 'main{width:100%;max-width:440px;background:var(--card);border:1px solid var(--line);border-radius:14px;padding:28px}'
            . 'h1{font-size:20px;margin:0 0 4px}.brand{color:var(--brand-ink);font-weight:700;letter-spacing:.02em}.lead{margin:16px 0 8px}'
            . '.facts{display:grid;grid-template-columns:auto 1fr;gap:4px 12px;margin:0 0 16px;font-size:13px}.facts dt{color:var(--muted)}.facts dd{margin:0;word-break:break-all}'
            . 'details{border:1px solid var(--line);border-radius:10px;padding:10px 14px;margin:10px 0}summary{cursor:pointer;font-weight:600}'
            . 'label{display:block;margin:10px 0;font-size:13px;color:var(--muted)}input:not([type=radio]){display:block;width:100%;margin-top:4px;padding:9px 10px;border:1px solid var(--line);border-radius:8px;background:transparent;color:var(--text);font:inherit}'
            . '.choice{display:flex;gap:8px;align-items:center;color:var(--text)}fieldset{border:0;padding:0;margin:0}legend{font-weight:600;margin-bottom:6px}'
            . '.actions{display:flex;gap:8px;margin-top:12px}button{padding:9px 16px;border-radius:8px;border:0;background:var(--brand);color:#06120d;font:inherit;font-weight:600;cursor:pointer}'
            . 'button.secondary{background:transparent;color:var(--text);border:1px solid var(--line)}.deny{margin-top:12px}'
            . '.error{color:var(--danger);font-weight:600}.muted{color:var(--muted)}code{font-family:"JetBrains Mono",ui-monospace,monospace;font-size:12px}'
            . '</style></head><body><main><div class="brand">TokenHub</div><h1>授权 MCP 连接</h1>' . $body . '</main></body></html>';

        return Response::raw($html, $status, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-store',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
            // No script at all; frame-ancestors stops clickjacking of the consent form.
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'none'; base-uri 'none'",
        ]);
    }

    private function flag(string $name): bool
    {
        $value = App::config()->get('app.mcp.oauth.' . $name, true);

        return $value === true || in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }
}
