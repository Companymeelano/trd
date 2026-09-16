<?php
namespace Meelano\Crypto\Notify;

use Meelano\Ai\CurlTransport;
use Meelano\Ai\Transport;
use Throwable;

/**
 * کانال روبیکا — پیام‌رسان ایرانی (API ربات).
 * نشانی پایه و مسیرها در پیکربندی قابل تغییرند (api_base) تا با نسخه‌های
 * مختلف Bot API روبیکا هماهنگ بماند.
 *
 * @author Milad Yaghoobi — Meelano Studio Design
 */
final class Rubika implements Channel
{
    /** @var Transport */
    private $http;
    /** @var string */
    private $token;
    /** @var string */
    private $chatId;
    /** @var string */
    private $base;

    public function __construct(?Transport $transport = null, array $cfg = [])
    {
        $this->http = $transport ?: new CurlTransport();
        $this->token = trim((string)($cfg['bot_token'] ?? ''));
        $this->chatId = trim((string)($cfg['chat_id'] ?? ''));
        $this->base = rtrim(trim((string)($cfg['api_base'] ?? 'https://botapi.rubika.ir')), '/');
    }

    public function id(): string
    {
        return 'rubika';
    }

    public function label(): string
    {
        return 'روبیکا';
    }

    public function configured(): bool
    {
        return $this->token !== '' && $this->chatId !== '';
    }

    public function send(string $text): array
    {
        if (!$this->configured()) {
            return ['ok' => false, 'error' => 'توکن ربات یا شناسهٔ مقصد تنظیم نشده است.'];
        }
        try {
            $payload = json_encode([
                'chat_id' => $this->chatId,
                'text' => $text,
            ], JSON_UNESCAPED_UNICODE);
            $res = $this->http->request('POST', $this->base . '/v1/' . rawurlencode($this->token) . '/sendMessage', [
                'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
                'body' => (string)$payload,
                'timeout' => 15,
            ]);
            $parsed = json_decode((string)$res['body'], true);
            $hasErr = is_array($parsed) && (
                (isset($parsed['status']) && is_string($parsed['status']) && strcasecmp($parsed['status'], 'ok') !== 0 && $parsed['status'] !== '200')
                || !empty($parsed['error'])
            );
            if ($res['status'] === 200 && !$hasErr) {
                return ['ok' => true, 'error' => null];
            }
            $msg = is_array($parsed) ? (string)($parsed['error'] ?? ($parsed['message'] ?? '')) : '';
            return ['ok' => false, 'error' => $msg !== '' ? $msg : ('HTTP ' . $res['status'] . ($res['error'] !== '' ? ' — ' . $res['error'] : ''))];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function check(): array
    {
        if (!$this->configured()) {
            return ['ok' => false, 'configured' => false, 'error' => 'توکن ربات یا شناسهٔ مقصد تنظیم نشده است.'];
        }
        // اعتبارسنجی محلی: ساختار توکن (طول کافی)؛ صحت واقعی با «ارسال تست» بررسی می‌شود
        if (strlen($this->token) < 16) {
            return ['ok' => false, 'configured' => true, 'error' => 'طول توکن روبیکا معتبر به نظر نمی‌رسد.'];
        }
        return ['ok' => true, 'configured' => true, 'error' => null, 'info' => ['note' => 'برای اطمینان کامل «ارسال پیام تست» را بزنید.']];
    }
}
