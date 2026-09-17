<?php
namespace Meelano\Crypto\Notify;

use Meelano\Ai\CurlTransport;
use Meelano\Ai\Transport;
use Throwable;

/**
 * کانال تلگرام — Bot API رسمی.
 * سازندهٔ ربات: @BotFather → توکن؛ مقصد: شناسهٔ کانال (-100…) یا گروه یا chat_id.
 * ربات باید عضو کانال با حق «ارسال پیام» باشد.
 *
 * @author Milad Yaghoobi — Meelano Studio Design
 */
final class Telegram implements Channel
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
        $this->base = rtrim(trim((string)($cfg['api_base'] ?? 'https://api.telegram.org')), '/');
    }

    public function id(): string
    {
        return 'telegram';
    }

    public function label(): string
    {
        return 'تلگرام';
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
                    'disable_web_page_preview' => 'true',
                ]),
                'timeout' => 15,
            ]);
            $parsed = json_decode((string)$res['body'], true);
            if ($res['status'] === 200 && !empty($parsed['ok'])) {
                return ['ok' => true, 'error' => null, 'info' => ['message_id' => $parsed['result']['message_id'] ?? null]];
            }
            $msg = is_array($parsed) ? (string)($parsed['description'] ?? '') : '';
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
        try {
            $res = $this->http->request('GET', $this->base . '/bot' . rawurlencode($this->token) . '/getMe', [
                'headers' => ['Accept' => 'application/json'],
                'timeout' => 12,
            ]);
            $parsed = json_decode((string)$res['body'], true);
            if ($res['status'] === 200 && !empty($parsed['ok'])) {
                $u = (array)($parsed['result'] ?? []);
                return ['ok' => true, 'configured' => true, 'error' => null,
                    'info' => ['bot' => '@' . (string)($u['username'] ?? '')]];
            }
            $msg = is_array($parsed) ? (string)($parsed['description'] ?? '') : '';
            return ['ok' => false, 'configured' => true, 'error' => $msg !== '' ? $msg : ('HTTP ' . $res['status'])];
        } catch (Throwable $e) {
            return ['ok' => false, 'configured' => true, 'error' => $e->getMessage()];
        }
    }
}
