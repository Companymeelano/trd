<?php
namespace Meelano\Crypto\Notify;

use Meelano\Ai\CurlTransport;
use Meelano\Ai\Transport;
use Throwable;

/**
 * کانال بله — پیام‌رسان ایرانی؛ API ربات آن با Bot API تلگرام سازگار است
 * (sendMessage/getMe با همان ساختار پارامترها).
 * سازندهٔ ربات: @BotFather بله → توکن؛ مقصد: شناسهٔ کانال/گروه.
 *
 * @author Milad Yaghoobi — Meelano Studio Design
 */
final class Bale implements Channel
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
        $this->base = rtrim(trim((string)($cfg['api_base'] ?? 'https://tapi.bale.ai')), '/');
    }

    public function id(): string
    {
        return 'bale';
    }

    public function label(): string
    {
        return 'بله';
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
            $res = $this->http->request('POST', $this->base . '/bot' . rawurlencode($this->token) . '/sendMessage', [
                'headers' => ['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json'],
                'body' => http_build_query([
                    'chat_id' => $this->chatId,
                    'text' => $text,
                ]),
                'timeout' => 15,
            ]);
            $parsed = json_decode((string)$res['body'], true);
            // بله ساختار پاسخ تلگرام‌مانند برمی‌گرداند؛ HTTP 200 بدون خطای صریح = موفق
            $hasErr = is_array($parsed) && isset($parsed['ok']) && $parsed['ok'] === false;
            $desc = is_array($parsed) ? (string)($parsed['description'] ?? '') : '';
            if ($res['status'] === 200 && !$hasErr) {
                return ['ok' => true, 'error' => null];
            }
            return ['ok' => false, 'error' => $desc !== '' ? $desc : ('HTTP ' . $res['status'] . ($res['error'] !== '' ? ' — ' . $res['error'] : ''))];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function check(): array
    {
        if (!$this->configured()) {
            return ['ok' => false, 'configured' => false, 'error' => 'توکن ربات یا شناسهٔ مقصد تنظیم نشده است.'];
        }
        try {
            $res = $this->http->request('GET', $this->base . '/bot' . rawurlencode($this->token) . '/getMe', [
                'headers' => ['Accept' => 'application/json'],
                'timeout' => 12,
            ]);
            $parsed = json_decode((string)$res['body'], true);
            if ($res['status'] === 200 && (empty($parsed['ok']) || $parsed['ok'] === true)) {
                $u = is_array($parsed) ? (array)($parsed['result'] ?? []) : [];
                return ['ok' => true, 'configured' => true, 'error' => null,
                    'info' => ['bot' => (string)($u['username'] ?? 'ربات بله')]];
            }
            return ['ok' => false, 'configured' => true, 'error' => 'HTTP ' . $res['status'] . ($res['error'] !== '' ? ' — ' . $res['error'] : '')];
        } catch (Throwable $e) {
            return ['ok' => false, 'configured' => true, 'error' => $e->getMessage()];
        }
    }
}
