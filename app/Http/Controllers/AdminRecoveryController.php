<?php

declare(strict_types=1);

namespace TokenHub\Http\Controllers;

use TokenHub\Services\AdminPasswordResetService;
use TokenHub\Services\AuditService;
use TokenHub\Services\ExternalRateLimitService;
use TokenHub\Services\SmtpMailer;
use TokenHub\Services\SmtpSettingsService;
use TokenHub\Support\App;
use TokenHub\Support\Request;
use TokenHub\Support\Response;

final class AdminRecoveryController
{
    /**
     * Public login-page options. Only says whether self-service password
     * recovery by e-mail is available, so the login page can hide "忘记密码"
     * when SMTP is not configured. Reveals nothing about the SMTP settings.
     */
    public function options(Request $request): Response
    {
        return Response::ok(['recovery_enabled' => (new SmtpSettingsService())->recoveryEnabled()])
            ->withHeader('Cache-Control', 'no-store');
    }

    public function forgotPassword(Request $request): Response
    {
        $limited = $this->limit('admin-recovery-request-ip', $request->clientIp(), 10);
        if ($limited !== null) {
            return $limited;
        }
        $email = AdminPasswordResetService::normalizeEmail($request->json()['email'] ?? null);
        (new SmtpSettingsService())->transportSettings();
        $attempt = (new ExternalRateLimitService())->attempt('admin-recovery-email', $email, 5, 3600);
        if ($attempt['allowed']) {
            $requestId = bin2hex(random_bytes(16));
            // A delayed or retried older job cannot supersede the newest link.
            App::redis()->set(AdminPasswordResetService::latestRequestKey($email), $requestId, 3600);
            App::redis()->push((string) App::config()->get('queue.name', 'tokenhub:jobs'), [
                'type' => 'admin.password-reset.email',
                'request_id' => $requestId,
                'requested_at' => time(),
                'email' => $email,
            ]);
        }
        return Response::ok(['message' => AdminPasswordResetService::REQUEST_MESSAGE])->withHeader('Cache-Control', 'no-store');
    }

    public function resetPassword(Request $request): Response
    {
        $limited = $this->limit('admin-recovery-reset-ip', $request->clientIp(), 10);
        if ($limited !== null) {
            return $limited;
        }
        $data = $request->json();
        (new AdminPasswordResetService())->reset($data['token'] ?? null, $data['password'] ?? null, $data['password_confirmation'] ?? null);
        return Response::ok(['password_reset' => true])->withHeader('Cache-Control', 'no-store');
    }

    public function testSmtp(Request $request): Response
    {
        $service = new SmtpSettingsService();
        $service->requireSuperAdmin();
        $limited = $this->limit('admin-smtp-test', (string) (App::context()->adminUser()['id'] ?? ''), 3);
        if ($limited !== null) {
            return $limited;
        }
        $recipient = AdminPasswordResetService::normalizeEmail($request->json()['recipient'] ?? null);
        $settings = $service->transportSettings();
        try {
            (new SmtpMailer())->send($settings, $recipient, 'TokenHub SMTP 测试邮件', "这是一封来自 TokenHub 的测试邮件。\n\n收到此邮件表示当前 SMTP 配置已成功发送邮件。\n");
        } catch (\RuntimeException $exception) {
            $code = $exception->getMessage();
            if (!in_array($code, ['SMTP_CONNECT_FAILED', 'SMTP_TLS_FAILED', 'SMTP_AUTH_FAILED', 'SMTP_SEND_FAILED'], true)) {
                $code = 'SMTP_SEND_FAILED';
            }
            return Response::error($code, '邮件发送失败，请检查 SMTP 配置', 502);
        }
        (new AuditService())->write('smtp.test', 'admin_smtp_settings', 1);
        return Response::ok(['sent' => true])->withHeader('Cache-Control', 'no-store');
    }

    private function limit(string $scope, string $identity, int $limit): ?Response
    {
        $attempt = (new ExternalRateLimitService())->attempt($scope, $identity, $limit);
        return $attempt['allowed'] ? null : Response::error('ADMIN_RECOVERY_RATE_LIMITED', '操作过于频繁，请稍后重试', 429)
            ->withHeader('Retry-After', (string) $attempt['retry_after']);
    }
}
