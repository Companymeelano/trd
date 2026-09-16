<?php
namespace Meelano\Crypto\Notify;

use Meelano\Ai\CurlTransport;
use Meelano\Ai\Transport;
use Throwable;

/**
 * کانال واتساپ — WhatsApp Business Cloud API رسمی (Meta).
 * پیش‌نیاز: حساب Meta for Developers + WhatsApp Business + شمارهٔ تلفن ثبت‌شده.
 * «phone_number_id» و «access_token» از پنل Meta؛ «to» = شمارهٔ گیرنده با کد کشور
 * (مثال: 989123456789). گیرنده باید پیام قبلی به کسب‌وکار داده باشد (پنجرهٔ ۲۴ ساعته).
 *
 * @author Milad Yaghoobi — Meelano Studio Design
 */
final class WhatsApp implements Channel
{
    /** @var Transport */
    private $http;
    /** @var string */
    private $phoneId;
    /** @var string */
    private $accessToken;
    /** @var string */
    private $to;
    /** @var string */
    private $base;
    /** @var string */
    private $version;

    public function __construct(?Transport $transport = null, array $cfg = [])
    {
        $this->http = $transport ?: new CurlTransport();
        $this->phoneId = trim((string)($cfg['phone_number_id'] ?? ''));
        $this->accessToken = trim((string)($cfg['access_token'] ?? ''));
        $this->to = trim((string)($cfg['to'] ?? ''));
        $this->base = rtrim(trim((string)($cfg['api_base'] ?? 'https://graph.facebook.com')), '/');
        $this->version = trim((string)($cfg['api_version'] ?? 'v21.0'), '/');
    }

    public function id(): string
    {
        return 'whatsapp';
    }

    public function label(): string
    {
        return 'واتساپ';
    }

    public function configured(): bool
    {
        return $this->phoneId !== '' && $this->accessToken !== '' && $this->to !== '';
    }

    private function endpoint(): string
    {
        return $this->base . '/' . $this->version . '/' . rawurlencode($this->phoneId);
    }

    public function send(string $text): array
    {
        if (!$this->configured()) {
            return ['ok' => false, 'error' => 'شناسهٔ شماره، توکن دسترسی یا شمارهٔ گیرنده تنظیم نشده است.'];
        }
        try {
            $payload = json_encode([
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $this->to,
                'type' => 'text',
                'text' => ['preview_url' => false, 'body' => $text],
            ], JSON_UNESCAPED_UNICODE);
            $res = $this->http->request('POST', $this->endpoint() . '/messages', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->accessToken,
                    'Content-Type' => 'application/json',
                ],
                'body' => (string)$payload,
                'timeout' => 15,
            ]);
            $parsed = json_decode((string)$res['body'], true);
            if ($res['status'] === 200 && is_array($parsed) && !empty($parsed['messages'])) {
                return ['ok' => true, 'error' => null, 'info' => ['id' => $parsed['messages'][0]['id'] ?? null]];
            }
            $msg = is_array($parsed) ? (string)($parsed['error']['message'] ?? '') : '';
            return ['ok' => false, 'error' => $msg !== '' ? $msg : ('HTTP ' . $res['status'] . ($res['error'] !== '' ? ' — ' . $res['error'] : ''))];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function check(): array
    {
        if (!$this->configured()) {
            return ['ok' => false, 'configured' => false, 'error' => 'شناسهٔ شماره، توکن دسترسی یا شمارهٔ گیرنده تنظیم نشده است.'];
        }
        try {
            $res = $this->http->request('GET', $this->endpoint() . '?fields=id,display_phone_number,verified_name', [
                'headers' => ['Authorization' => 'Bearer ' . $this->accessToken, 'Accept' => 'application/json'],
                'timeout' => 12,
            ]);
            $parsed = json_decode((string)$res['body'], true);
            if ($res['status'] === 200 && is_array($parsed) && !empty($parsed['id'])) {
                return ['ok' => true, 'configured' => true, 'error' => null,
                    'info' => ['phone' => (string)($parsed['display_phone_number'] ?? '')]];
            }
            $msg = is_array($parsed) ? (string)($parsed['error']['message'] ?? '') : '';
            return ['ok' => false, 'configured' => true, 'error' => $msg !== '' ? $msg : ('HTTP ' . $res['status'])];
        } catch (Throwable $e) {
            return ['ok' => false, 'configured' => true, 'error' => $e->getMessage()];
        }
    }
}
