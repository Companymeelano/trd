<?php
namespace Meelano\Ai;

use Meelano\Db;
use Throwable;

/**
 * تست سلامت ارائه‌دهندگان هوش مصنوعی.
 *
 * برای هر ارائه‌دهنده سبک‌ترین نقطه پایان ممکن صدا زده می‌شود (معمولاً /models)
 * تا بدون مصرف توکن، اعتبار کلید و تأخیر شبکه اندازه‌گیری شود.
 * نتیجه در جدول ai_health ذخیره می‌شود و خوراک مسیریاب خودکار است.
 *
 * @author Milad Yaghoobi — Meelano Studio Design
 */
final class Health
{
    /** @var Transport */
    private $transport;
    /** @var array */
    private $config;
    /** @var Db|null */
    private $db;

    public function __construct(?Transport $transport = null, ?array $config = null, ?Db $db = null)
    {
        $this->transport = $transport ?: new CurlTransport();
        $this->config = $config ?: \Meelano\Config::all();
        $this->db = $db;
    }

    /**
     * تعریف نقطه پایان تست برای هر ارائه‌دهنده.
     * deep=false یعنی اعتبارسنجی عمیق بدون مصرف اعتبار ممکن نیست.
     */
    public static function probes(): array
    {
        return [
            'openai' => ['method' => 'GET', 'path' => '/models', 'auth' => 'bearer', 'ok_codes' => [200], 'deep' => true],
            'gemini' => ['method' => 'GET', 'path' => '/models', 'auth' => 'query_key', 'ok_codes' => [200], 'deep' => true],
            'groq' => ['method' => 'GET', 'path' => '/models', 'auth' => 'bearer', 'ok_codes' => [200], 'deep' => true],
            'deepseek' => ['method' => 'GET', 'path' => '/models', 'auth' => 'bearer', 'ok_codes' => [200], 'deep' => true],
            'gapgpt' => ['method' => 'GET', 'path' => '/models', 'auth' => 'bearer', 'ok_codes' => [200], 'deep' => true],
            'maxrouter' => ['method' => 'GET', 'path' => '/models', 'auth' => 'bearer', 'ok_codes' => [200], 'deep' => true],
            'cloudflare' => ['method' => 'GET', 'path' => '/ai/models/search', 'auth' => 'bearer', 'ok_codes' => [200], 'deep' => true, 'account_scoped' => true],
        ];
    }

    /** تست یک ارائه‌دهنده. */
    public function check(string $providerId): array
    {
        $meta = Registry::provider($providerId);
        $probes = self::probes();
        if ($meta === null || !isset($probes[$providerId])) {
            return $this->result($providerId, false, 0, 0, 'ارائه‌دهنده ناشناخته', '', false);
        }
        $probe = $probes[$providerId];
        $cfg = $this->config['ai']['providers'][$providerId] ?? [];
        $keyField = $meta['key_field'] ?? 'api_key';
        $key = trim((string)($cfg[$keyField] ?? ''));

        if ($key === '') {
            return $this->result($providerId, false, 0, 0, 'کلید API وارد نشده است.', '', (bool)$probe['deep']);
        }
        foreach ($meta['requires_extra'] ?? [] as $extra) {
            if (trim((string)($cfg[$extra] ?? '')) === '') {
                return $this->result($providerId, false, 0, 0, 'فیلد ' . $extra . ' وارد نشده است.', '', (bool)$probe['deep']);
            }
        }

        $base = rtrim((string)($cfg['base_url'] ?? ''), '/');
        $url = $base;
        if (!empty($probe['account_scoped'])) {
            $url .= '/' . rawurlencode((string)($cfg['account_id'] ?? ''));
        }
        $url .= $probe['path'];

        $headers = ['Accept' => 'application/json'];
        switch ($probe['auth']) {
            case 'bearer':
                $headers['Authorization'] = 'Bearer ' . $key;
                break;
            case 'header_api_key':
                $headers['api-key'] = $key;
                break;
            case 'header_x_api_key':
                $headers['X-API-KEY'] = $key;
                break;
            case 'fal_key':
                $headers['Authorization'] = 'Key ' . $key;
                break;
            case 'query_key':
                $url .= (strpos($url, '?') === false ? '?' : '&') . 'key=' . urlencode($key);
                break;
        }

        try {
            $res = $this->transport->request($probe['method'], $url, ['headers' => $headers, 'timeout' => 20]);
        } catch (Throwable $e) {
            return $this->result($providerId, false, 0, 0, 'خطای شبکه: ' . $e->getMessage(), '', (bool)$probe['deep']);
        }

        $ok = in_array($res['status'], $probe['ok_codes'], true);
        $body = json_decode($res['body'], true);
        $message = $ok
            ? ($probe['deep'] ? 'کلید معتبر است و سرویس پاسخ داد.' : 'سرویس در دسترس است؛ اعتبارسنجی عمیق نیازمند یک فراخوانی واقعی است.')
            : $this->explainStatus($res['status'], $body);

        // شمارش مدل‌های در دسترس (در صورت وجود)
        $models = 0;
        if (is_array($body)) {
            $list = m_get($body, 'data') ?: m_get($body, 'models') ?: m_get($body, 'result');
            if (is_array($list)) {
                $models = count($list);
            }
        }
        $modelTested = (string)($cfg['model'] ?? $cfg['image_model'] ?? '');

        return $this->result($providerId, $ok, (int)$res['status'], (int)round($res['elapsed_ms']), $message, $modelTested, (bool)$probe['deep'], $models);
    }

    /** تست همه ارائه‌دهندگان. */
    public function checkAll(?callable $onEach = null): array
    {
        $out = [];
        foreach (array_keys(Registry::providers()) as $id) {
            $row = $this->check($id);
            $out[$id] = $row;
            $this->persist($id, $row);
            if ($onEach !== null) {
                $onEach($id, $row);
            }
        }
        return $out;
    }

    /** خواندن آخرین وضعیت سلامت از دیتابیس (برای مسیریاب). */
    public static function cached(Db $db): array
    {
        try {
            if (!$db->tableExists('ai_health')) {
                return [];
            }
            $rows = $db->select('SELECT provider, ok, latency_ms, http_code, model_tested, message, tested_at FROM ' . $db->table('ai_health'));
            $out = [];
            foreach ($rows as $r) {
                $out[$r['provider']] = $r;
            }
            return $out;
        } catch (Throwable $e) {
            return [];
        }
    }

    private function persist(string $id, array $row): void
    {
        if ($this->db === null) {
            return;
        }
        try {
            if (!$this->db->tableExists('ai_health')) {
                return;
            }
            $payload = [
                'ok' => $row['ok'] ? 1 : 0,
                'latency_ms' => (int)$row['latency_ms'],
                'http_code' => (int)$row['http_code'],
                'model_tested' => $row['model_tested'] !== '' ? $row['model_tested'] : null,
                'capabilities_json' => json_encode($row['capabilities'] ?? [], JSON_UNESCAPED_UNICODE),
                'message' => mb_substr((string)$row['message'], 0, 490),
                'tested_at' => date('Y-m-d H:i:s'),
            ];
            $affected = $this->db->update('ai_health', $payload, 'provider = ?', [$id]);
            if ($affected === 0) {
                $this->db->insert('ai_health', ['provider' => $id] + $payload);
            }
        } catch (Throwable $e) {
            \Meelano\Logger::write('ai', 'ثبت سلامت ' . $id . ' ناموفق: ' . $e->getMessage(), 'warning');
        }
    }

    private function result(string $id, bool $ok, int $httpCode, int $latency, string $message, string $model, bool $deep, int $models = 0): array
    {
        $meta = Registry::provider($id);
        return [
            'provider' => $id,
            'label' => $meta['label'] ?? $id,
            'ok' => $ok,
            'http_code' => $httpCode,
            'latency_ms' => $latency,
            'message' => $message,
            'model_tested' => $model,
            'deep' => $deep,
            'models_available' => $models,
            'capabilities' => $meta['capabilities'] ?? [],
            'tested_at' => date('Y-m-d H:i:s'),
        ];
    }

    private function explainStatus(int $status, $body): string
    {
        $detail = '';
        if (is_array($body)) {
            foreach (['error.message', 'message', 'detail', 'error'] as $path) {
                $v = m_get($body, $path);
                if (is_string($v) && $v !== '') {
                    $detail = ' — ' . $v;
                    break;
                }
            }
        }
        switch ($status) {
            case 401:
                return 'کلید API نامعتبر یا منقضی است (401).' . $detail;
            case 403:
                return 'دسترسی با این کلید مجاز نیست (403) — احتمالاً سهمیه یا منطقه جغرافیایی.' . $detail;
            case 404:
                return 'مسیر API پیدا نشد (404) — base_url را بررسی کنید.' . $detail;
            case 429:
                return 'سقف درخواست پر شده است (429).' . $detail;
            case 0:
                return 'پاسخی از سرور دریافت نشد — اتصال خروجی سرور را بررسی کنید.';
        }
        return 'پاسخ غیرمنتظره HTTP ' . $status . '.' . $detail;
    }
}
