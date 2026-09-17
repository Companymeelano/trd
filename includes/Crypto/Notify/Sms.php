<?php
namespace Meelano\Crypto\Notify;

use Meelano\Ai\CurlTransport;
use Meelano\Ai\Transport;
use Throwable;

/**
 * کانال پیامک — سرویس کاوه‌نگار (Kavenegar) با REST API استاندارد.
 * کلید API از پنل کاوه‌نگار؛ «receptor» = شمارهٔ گیرنده (09xxxxxxxxx یا 989…).
 * برای پیامک، نسخهٔ فشردهٔ پیام ارسال می‌شود (هزینه/طول محدود).
 *
 * @author Milad Yaghoobi — Meelano Studio Design
 */
final class Sms implements Channel
{
    /** @var Transport */
    private $http;
    /** @var string */
    private $apiKey;
    /** @var string */
    private $receptor;
    /** @var string */
    private $sender;
    /** @var string */
    private $base;

    public function __construct(?Transport $transport = null, array $cfg = [])
    {
        $this->http = $transport ?: new CurlTransport();
        $this->apiKey = trim((string)($cfg['api_key'] ?? ''));
        $this->receptor = trim((string)($cfg['receptor'] ?? ''));
        $this->sender = trim((string)($cfg['sender'] ?? ''));
        $this->base = rtrim(trim((string)($cfg['api_base'] ?? 'https://api.kavenegar.com')), '/');
    }

    public function id(): string
    {
        return 'sms';
    }

    public function label(): string
    {
        return 'پیامک (کاوه‌نگار)';
    }

    public function configured(): bool
    {
        return $this->apiKey !== '' && $this->receptor !== '';
    }

    public function send(string $text): array
    {
        if (!$this->configured()) {
            return ['ok' => false, 'error' => 'کلید API یا شمارهٔ گیرنده تنظیم نشده است.'];
        }
        try {
            $params = ['receptor' => $this->receptor, 'message' => $text];
            if ($this->sender !== '') {
                $params['sender'] = $this->sender;
            }
            $res = $this->http->request('POST', $this->base . '/v1/' . rawurlencode($this->apiKey) . '/sms/send.json', [
                'headers' => ['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json'],
                'body' => http_build_query($params),
                'timeout' => 15,
            ]);
            $parsed = json_decode((string)$res['body'], true);
            $status = is_array($parsed) ? (int)($parsed['return']['status'] ?? 0) : 0;
            if ($res['status'] === 200 && $status === 200) {
                return ['ok' => true, 'error' => null];
            }
            $msg = is_array($parsed) ? (string)($parsed['return']['message'] ?? '') : '';
            return ['ok' => false, 'error' => $msg !== '' ? $msg : ('HTTP ' . $res['status'] . ($res['error'] !== '' ? ' — ' . $res['error'] : ''))];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function check(): array
    {
        if (!$this->configured()) {
            return ['ok' => false, 'configured' => false, 'error' => 'کلید API یا شمارهٔ گیرنده تنظیم نشده است.'];
        }
        try {
            $res = $this->http->request('GET', $this->base . '/v1/' . rawurlencode($this->apiKey) . '/account/info.json', [
                'headers' => ['Accept' => 'application/json'],
                'timeout' => 12,
            ]);
            $parsed = json_decode((string)$res['body'], true);
            $status = is_array($parsed) ? (int)($parsed['return']['status'] ?? 0) : 0;
            if ($res['status'] === 200 && $status === 200) {
                return ['ok' => true, 'configured' => true, 'error' => null];
            }
            $msg = is_array($parsed) ? (string)($parsed['return']['message'] ?? '') : '';
            return ['ok' => false, 'configured' => true, 'error' => $msg !== '' ? $msg : ('HTTP ' . $res['status'])];
        } catch (Throwable $e) {
            return ['ok' => false, 'configured' => true, 'error' => $e->getMessage()];
        }
    }
}
