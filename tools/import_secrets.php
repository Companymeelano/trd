<?php
/**
 * ابزار یک‌بارمصرف: واردسازی انبوه کلیدها از یک متن/JSON.
 *
 * استفاده (از مسیر پروژه، فقط روی سرور امن):
 *   php tools/import_secrets.php secrets.json
 *
 * قالب ورودی (کلیدها اختیاری‌اند):
 * {
 *   "openai_api_key": "...", "gemini_api_key": "...", "groq_api_key": "...",
 *   "deepseek_api_key": "...", "gapgpt_api_key": "...", "maxrouter_api_key": "...",
 *   "cloudflare_api_token": "...", "cloudflare_account_id": "...",
 *   "db_host":"localhost","db_name":"...","db_user":"...","db_pass":"..."
 * }
 *
 * هشدار امنیتی: کلیدهایی که در چت/ایمیل/گیت به‌صورت متن آشکار رد و بدل شده‌اند
 * باید «باطل و دوباره صادر» شوند؛ این ابزار فقط برای واردسازی کلیدهای جدید است.
 *
 * @author Milad Yaghoobi — Meelano Studio Design
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "این ابزار فقط از خط فرمان اجرا می‌شود.\n");
    exit(1);
}

define('MEELANO_ROOT', dirname(__DIR__));
require MEELANO_ROOT . '/includes/bootstrap.php';

use Meelano\Config;

$path = $argv[1] ?? null;
if ($path === null || !is_file($path)) {
    fwrite(STDERR, "روش اجرا: php tools/import_secrets.php <path-to-json>\n");
    exit(1);
}

$raw = (string)file_get_contents($path);
$data = json_decode($raw, true);
if (!is_array($data)) {
    fwrite(STDERR, "فایل JSON معتبر نیست.\n");
    exit(1);
}

$mapping = [
    'openai_api_key' => 'ai.providers.openai.api_key',
    'gemini_api_key' => 'ai.providers.gemini.api_key',
    'groq_api_key' => 'ai.providers.groq.api_key',
    'deepseek_api_key' => 'ai.providers.deepseek.api_key',
    'gapgpt_api_key' => 'ai.providers.gapgpt.api_key',
    'maxrouter_api_key' => 'ai.providers.maxrouter.api_key',
    'cloudflare_api_token' => 'ai.providers.cloudflare.api_token',
    'cloudflare_account_id' => 'ai.providers.cloudflare.account_id',
    'openai_model' => 'ai.providers.openai.model',
    'gemini_model' => 'ai.providers.gemini.model',
    'groq_model' => 'ai.providers.groq.model',
    'deepseek_model' => 'ai.providers.deepseek.model',
    'gapgpt_base_url' => 'ai.providers.gapgpt.base_url',
    'db_host' => 'db.host',
    'db_name' => 'db.name',
    'db_user' => 'db.user',
    'db_pass' => 'db.pass',
];

$count = 0;
foreach ($mapping as $from => $to) {
    if (!empty($data[$from])) {
        Config::set($to, (string)$data[$from]);
        $count++;
    }
}

if (!Config::save()) {
    fwrite(STDERR, "ذخیره تنظیمات ناموفق بود.\n");
    exit(1);
}

fwrite(STDOUT, "{$count} مقدار با موفقیت وارد و به‌صورت رمزنگاری‌شده ذخیره شد.\n");
fwrite(STDOUT, "نکته: فایل {$path} را همین حالا حذف کنید.\n");
@unlink($path);
exit(0);
