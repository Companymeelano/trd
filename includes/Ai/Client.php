<?php
namespace Meelano\Ai;

use Meelano\Config;
use Meelano\Db;
use Meelano\Logger;
use Throwable;

/**
 * کلاینت یکپارچه هوش مصنوعی — لایهٔ متنی بات ترید کریپتو.
 *
 * مسئولیت: فراخوانی وظایف تحلیلی (سیگنال، بازبینی ریسک، رژیم، گزارش) روی
 * ارائه‌دهندگان سازگار با ۳ پروتکل (OpenAI / Gemini / Cloudflare) با
 * مسیریابی خودکار، زنجیرهٔ پشتیبان و کارنامه‌نویسی کامل در جدول ai_runs.
 *
 * نسخهٔ ۵: لایه‌های تصویر/ویدیو/امبدینگِ پروژهٔ قبلی حذف شدند؛ این کلاس
 * فقط وظایف متنیِ مرتبط با تحلیل بازار را انجام می‌دهد.
 *
 * @author Milad Yaghoobi — Meelano Studio Design
 */
final class Client
{
    /** @var Transport */
    private $transport;
    /** @var Router */
    private $router;
    /** @var Db|null */
    private $db;
    /** @var array */
    private $config;

    public function __construct(?Transport $transport = null, ?Router $router = null, ?Db $db = null, ?array $config = null)
    {
        $this->config = $config ?: Config::all();
        $this->transport = $transport ?: new CurlTransport();
        $this->router = $router ?: new Router($this->config);
        $this->db = $db;
    }

    public function router(): Router
    {
        return $this->router;
    }

    public function setHealth(array $health): void
    {
        $this->router = new Router($this->config, $health);
    }

    /* ══════════════════════════════════════════════════════════════════
     *  متن / JSON
     * ══════════════════════════════════════════════════════════════════ */

    /**
     * فراخوانی متنی با مسیریابی و زنجیرهٔ پشتیبان.
     *
     * @return array{ok:bool,text:string,provider:?string,model:?string,latency_ms:float,error:?string,attempts:array}
     */
    public function complete(string $task, string $prompt, array $opts = []): array
    {
        $taskDef = Registry::task($task) ?: ['max_tokens' => 800, 'requires' => [Registry::CAP_CHAT]];
        $system = $opts['system'] ?? $this->defaultSystem($task);
        $jsonMode = $opts['json'] ?? in_array(Registry::CAP_JSON, $taskDef['requires'], true);
        $maxTokens = (int)($opts['max_tokens'] ?? $taskDef['max_tokens'] ?? 800);
        $temperature = isset($opts['temperature']) ? (float)$opts['temperature'] : (float)$this->config['ai']['temperature'];

        $chain = [];
        if (!empty($opts['provider'])) {
            $chain = [(string)$opts['provider']];
        } else {
            $chain = $this->router->fallbackChain($task, 3);
        }
        if (!$chain) {
            return $this->fail('هیچ ارائه‌دهنده فعالی برای وظیفه «' . $task . '» پیدا نشد. کلیدها را در تنظیمات بررسی کنید.', $task);
        }

        $attempts = [];
        foreach ($chain as $providerId) {
            $meta = Registry::provider($providerId);
            $cfg = $this->config['ai']['providers'][$providerId] ?? [];
            $model = $this->modelFor($providerId);

            $started = m_microtime();
            try {
                $res = $this->dispatchText($meta['protocol'], $cfg, $model, $system, $prompt, $maxTokens, $temperature, $jsonMode);
            } catch (Throwable $e) {
                $res = ['ok' => false, 'text' => '', 'error' => $e->getMessage(), 'http_code' => 0, 'usage' => []];
            }
            $latency = round((m_microtime() - $started) * 1000, 1);

            $attempts[] = ['provider' => $providerId, 'ok' => $res['ok'], 'ms' => $latency, 'error' => $res['error'] ?? null];
            $this->log($task, $providerId, $model, $res, $latency, $prompt);

            if ($res['ok'] && trim((string)$res['text']) !== '') {
                return [
                    'ok' => true,
                    'text' => (string)$res['text'],
                    'provider' => $providerId,
                    'provider_label' => $meta['label'],
                    'model' => $model,
                    'latency_ms' => $latency,
                    'usage' => $res['usage'] ?? [],
                    'error' => null,
                    'attempts' => $attempts,
                ];
            }
            Logger::write('ai', sprintf('وظیفه %s روی %s ناموفق: %s', $task, $providerId, $res['error'] ?? 'پاسخ خالی'), 'warning');
        }

        return $this->fail('همه ارائه‌دهندگان برای «' . $task . '» پاسخ ناموفق دادند.', $task, $attempts);
    }

    /** فراخوانی با خروجی JSON ساخت‌یافته + پارس مقاوم. */
    public function json(string $task, string $prompt, array $opts = []): array
    {
        $res = $this->complete($task, $prompt, array_merge($opts, ['json' => true]));
        if (!$res['ok']) {
            return $res + ['data' => null];
        }
        $data = self::extractJson($res['text']);
        if ($data === null) {
            $res['ok'] = false;
            $res['error'] = 'پاسخ مدل JSON معتبر نبود.';
            $res['data'] = null;
            return $res;
        }
        $res['data'] = $data;
        return $res;
    }

    /* ══════════════════════════════════════════════════════════════════
     *  پروتکل‌های متنی
     * ══════════════════════════════════════════════════════════════════ */

    private function dispatchText(string $protocol, array $cfg, string $model, string $system, string $prompt, int $maxTokens, float $temp, bool $jsonMode): array
    {
        switch ($protocol) {
            case 'openai':
                return $this->textOpenAi($cfg, $model, $system, $prompt, $maxTokens, $temp, $jsonMode);
            case 'gemini':
                return $this->textGemini($cfg, $model, $system, $prompt, $maxTokens, $temp, $jsonMode);
            case 'cloudflare':
                return $this->textCloudflare($cfg, $model, $system, $prompt, $maxTokens, $temp);
            default:
                return ['ok' => false, 'text' => '', 'error' => 'پروتکل متنی پشتیبانی‌نشده: ' . $protocol, 'http_code' => 0, 'usage' => []];
        }
    }

    private function textOpenAi(array $cfg, string $model, string $system, string $prompt, int $maxTokens, float $temp, bool $jsonMode): array
    {
        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $prompt],
            ],
            'max_tokens' => $maxTokens,
            'temperature' => $temp,
        ];
        if ($jsonMode) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        $url = rtrim((string)($cfg['base_url'] ?? 'https://api.openai.com/v1'), '/') . '/chat/completions';
        $res = $this->transport->request('POST', $url, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . ($cfg['api_key'] ?? ''),
            ],
            'body' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'timeout' => (int)($this->config['ai']['default_timeout'] ?? 45),
        ]);

        $data = json_decode($res['body'], true);
        if ($res['status'] >= 200 && $res['status'] < 300 && isset($data['choices'][0]['message']['content'])) {
            return [
                'ok' => true,
                'text' => (string)$data['choices'][0]['message']['content'],
                'error' => null,
                'http_code' => $res['status'],
                'usage' => [
                    'prompt_tokens' => (int)($data['usage']['prompt_tokens'] ?? 0),
                    'completion_tokens' => (int)($data['usage']['completion_tokens'] ?? 0),
                ],
            ];
        }
        return [
            'ok' => false, 'text' => '',
            'error' => $this->extractError($data, $res),
            'http_code' => $res['status'], 'usage' => [],
        ];
    }

    private function textGemini(array $cfg, string $model, string $system, string $prompt, int $maxTokens, float $temp, bool $jsonMode): array
    {
        $payload = [
            'system_instruction' => ['parts' => [['text' => $system]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
            'generationConfig' => [
                'temperature' => $temp,
                'maxOutputTokens' => $maxTokens,
            ],
        ];
        if ($jsonMode) {
            $payload['generationConfig']['responseMimeType'] = 'application/json';
        }

        $base = rtrim((string)($cfg['base_url'] ?? 'https://generativelanguage.googleapis.com/v1beta'), '/');
        $url = $base . '/models/' . rawurlencode($model) . ':generateContent?key=' . urlencode((string)($cfg['api_key'] ?? ''));

        $res = $this->transport->request('POST', $url, [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'timeout' => (int)($this->config['ai']['default_timeout'] ?? 45),
        ]);
        $data = json_decode($res['body'], true);
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if ($res['status'] >= 200 && $res['status'] < 300 && $text !== null) {
            return [
                'ok' => true,
                'text' => (string)$text,
                'error' => null,
                'http_code' => $res['status'],
                'usage' => [
                    'prompt_tokens' => (int)($data['usageMetadata']['promptTokenCount'] ?? 0),
                    'completion_tokens' => (int)($data['usageMetadata']['candidatesTokenCount'] ?? 0),
                ],
            ];
        }
        return ['ok' => false, 'text' => '', 'error' => $this->extractError($data, $res), 'http_code' => $res['status'], 'usage' => []];
    }

    private function textCloudflare(array $cfg, string $model, string $system, string $prompt, int $maxTokens, float $temp): array
    {
        $account = (string)($cfg['account_id'] ?? '');
        $url = rtrim((string)($cfg['base_url'] ?? 'https://api.cloudflare.com/client/v4/accounts'), '/')
            . '/' . rawurlencode($account) . '/ai/run/' . $model;
        $payload = [
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $prompt],
            ],
            'max_tokens' => $maxTokens,
            'temperature' => $temp,
        ];
        $res = $this->transport->request('POST', $url, [
            'headers' => ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . ($cfg['api_token'] ?? '')],
            'body' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'timeout' => (int)($this->config['ai']['default_timeout'] ?? 45),
        ]);
        $data = json_decode($res['body'], true);
        if ($res['status'] >= 200 && $res['status'] < 300 && isset($data['result']['response'])) {
            return ['ok' => true, 'text' => (string)$data['result']['response'], 'error' => null, 'http_code' => $res['status'], 'usage' => []];
        }
        return ['ok' => false, 'text' => '', 'error' => $this->extractError($data, $res), 'http_code' => $res['status'], 'usage' => []];
    }

    /* ══════════════════════════════════════════════════════════════════
     *  ابزارهای مشترک
     * ══════════════════════════════════════════════════════════════════ */

    private function modelFor(string $providerId): string
    {
        $cfg = $this->config['ai']['providers'][$providerId] ?? [];
        return (string)($cfg['model'] ?? '');
    }

    /** پیام سیستمی هر وظیفه — پرسونای معامله‌گر نهادی. */
    private function defaultSystem(string $task): string
    {
        $base = 'تو یک معامله‌گر نهادی کریپتو با بیش از دو دهه تجربهٔ مدیریت ریسک هستی. '
            . 'فارسی سلیس و فنی بنویس. هرگز عدد یا قیمت ساختگی به‌عنوان قطعی ارائه نده؛ '
            . 'اگر شواهد کافی نیست، سیگنال NEUTRAL بده و در reasoning دلیلش را بنویس. '
            . 'مدیریت سرمایه برایت از سیگنال‌گیری مهم‌تر است.';
        $taskDef = Registry::task($task);
        if ($taskDef && in_array(Registry::CAP_JSON, $taskDef['requires'] ?? [], true)) {
            $base .= ' خروجی را فقط به‌صورت یک آبجکت JSON معتبر و بدون هیچ متن اضافه یا markdown برگردان.';
        }
        return $base;
    }

    /** استخراج JSON از پاسخ مدل (حتی اگر داخل ```json باشد). */
    public static function extractJson(string $text)
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        if (preg_match('/```(?:json)?\s*(\{.*?\}|\[.*?\])\s*```/s', $text, $m)) {
            $decoded = json_decode($m[1], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return null;
    }

    private function extractError($data, array $res): string
    {
        if (is_array($data)) {
            foreach (['error.message', 'error.msg', 'message', 'detail', 'error'] as $path) {
                $v = m_get($data, $path);
                if (is_string($v) && $v !== '') {
                    return $v;
                }
            }
        }
        if (!empty($res['error'])) {
            return (string)$res['error'];
        }
        return 'HTTP ' . (int)$res['status'] . ' — ' . substr(strip_tags((string)$res['body']), 0, 200);
    }

    private function fail(string $message, string $task, array $attempts = []): array
    {
        Logger::write('ai', $message, 'error', ['task' => $task]);
        return [
            'ok' => false, 'text' => '', 'provider' => null, 'model' => null,
            'latency_ms' => 0, 'error' => $message, 'attempts' => $attempts,
        ];
    }

    /** ثبت کارنامهٔ اجرا در جدول ai_runs — اگر دیتابیس در دسترس باشد. */
    private function log(string $task, string $provider, string $model, array $res, float $latency, string $prompt): void
    {
        if ($this->db === null) {
            return;
        }
        try {
            if (!$this->db->tableExists('ai_runs')) {
                return;
            }
            $this->db->insert('ai_runs', [
                'task' => $task,
                'provider' => $provider,
                'model' => $model !== '' ? $model : null,
                'status' => !empty($res['ok']) ? 'ok' : 'error',
                'latency_ms' => (int)round($latency),
                'prompt_tokens' => (int)($res['usage']['prompt_tokens'] ?? 0),
                'completion_tokens' => (int)($res['usage']['completion_tokens'] ?? 0),
                'http_code' => (int)($res['http_code'] ?? 0),
                'error' => isset($res['error']) ? mb_substr((string)$res['error'], 0, 180) : null,
                'input_hash' => sha1($prompt),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            Logger::write('ai', 'ثبت کارنامه ناموفق: ' . $e->getMessage(), 'warning');
        }
    }
}
