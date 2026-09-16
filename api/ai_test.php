<?php
/**
 * POST /api/ai_test.php — تست سلامت کلید یک یا همه ارائه‌دهندگان.
 * ورودی: {provider?: "openai", api_key?, base_url?, account_id?, all?: true}
 */

require __DIR__ . '/bootstrap.php';

use Meelano\Ai\Health;
use Meelano\Ai\Registry;
use Meelano\Config;
use Meelano\Security;

m_guard(true, 'ai_test');
$in = m_input();

// اگر کاربر کلید را در همین لحظه تایپ کرده، قبل از تست ذخیره‌اش کن
$providerId = isset($in['provider']) ? (string)$in['provider'] : '';
$override = [];
if ($providerId !== '' && Registry::provider($providerId) !== null) {
    $meta = Registry::provider($providerId);
    $keyField = $meta['key_field'] ?? 'api_key';
    foreach ([$keyField, 'api_key', 'api_token', 'account_id', 'base_url', 'model'] as $field) {
        if (!empty($in[$field])) {
            $override[$field] = m_clean_string((string)$in[$field], 300);
        }
    }
    if ($override) {
        foreach ($override as $field => $value) {
            Config::set("ai.providers.{$providerId}.{$field}", $value);
        }
        Config::save();
        Security::audit('ai', 'key_saved', 'کلید ' . $providerId . ' ذخیره شد');
    }
}

$config = Config::all();
$db = null;
try {
    $db = \Meelano\Db::make();
    if (!$db->isConnected()) {
        $db = null;
    }
} catch (Throwable $e) {
    $db = null;
}

$health = new Health(null, $config, $db);

if (!empty($in['all']) || $providerId === '') {
    $results = [];
    foreach (array_keys(Registry::providers()) as $id) {
        $results[$id] = $health->check($id);
    }
    // ذخیره در دیتابیس برای مسیریاب
    if ($db !== null) {
        foreach ($results as $id => $row) {
            persistHealth($db, $id, $row);
        }
    }
    $okCount = count(array_filter($results, static function ($r) {
        return !empty($r['ok']);
    }));
    m_json([
        'ok' => true,
        'all' => true,
        'results' => $results,
        'summary' => ['ok' => $okCount, 'total' => count($results)],
        'message' => sprintf('%d از %d ارائه‌دهنده سالم هستند.', $okCount, count($results)),
    ]);
}

$row = $health->check($providerId);
if ($db !== null) {
    persistHealth($db, $providerId, $row);
}
m_json(['ok' => (bool)$row['ok'], 'result' => $row, 'all' => false]);

/** ذخیره نتیجه تست در جدول ai_health. */
function persistHealth(\Meelano\Db $db, string $id, array $row): void
{
    try {
        if (!$db->tableExists('ai_health')) {
            return;
        }
        $payload = [
            'ok' => !empty($row['ok']) ? 1 : 0,
            'latency_ms' => (int)($row['latency_ms'] ?? 0),
            'http_code' => (int)($row['http_code'] ?? 0),
            'model_tested' => ($row['model_tested'] ?? '') !== '' ? (string)$row['model_tested'] : null,
            'capabilities_json' => json_encode($row['capabilities'] ?? [], JSON_UNESCAPED_UNICODE),
            'message' => mb_substr((string)($row['message'] ?? ''), 0, 490),
            'tested_at' => date('Y-m-d H:i:s'),
        ];
        if ($db->update('ai_health', $payload, 'provider = ?', [$id]) === 0) {
            $db->insert('ai_health', ['provider' => $id] + $payload);
        }
    } catch (Throwable $e) {
        // اختیاری
    }
}
