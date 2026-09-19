<?php
/**
 * ورودی مشترک همه نقاط پایان API.
 * امنیت، CORS同源، CSRF و محدودسازی نرخ را یک‌جا اعمال می‌کند.
 *
 * @author Milad Yaghoobi — Meelano Studio Design
 */

declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

use Meelano\Config;
use Meelano\Db;
use Meelano\Security;

Security::secureHeaders();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, noarchive');

/** ورودی JSON یا form را یک‌جا می‌خواند. */
function m_input(): array
{
    $raw = file_get_contents('php://input');
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return $_POST ?: [];
}

/** همه نقاط پایان به ورود مدیر و CSRF نیاز دارند (مگر health). */
function m_guard(bool $needCsrf = true, string $rateBucket = 'api'): void
{
    if ((bool)Config::get('app.require_admin', true) && !Security::isLoggedIn()) {
        m_json(['ok' => false, 'error' => 'برای این عملیات ابتدا وارد بخش تنظیمات شوید.'], 401);
    }
    Security::requireRateLimit($rateBucket);
    if ($needCsrf) {
        Security::requireCsrf();
    }
}

/** دیتابیس آماده (اتصال + جدول‌ها) یا خطای راهنما. */
function m_ready_db(): Db
{
    $db = Db::make();
    if (!$db->isConnected()) {
        m_json([
            'ok' => false,
            'error' => 'اتصال به پایگاه‌داده برقرار نیست.',
            'hint' => 'از بخش تنظیمات، اطلاعات دیتابیس را تست و سپس جدول‌ها را بسازید.',
            'action' => 'open_settings',
        ], 503);
    }
    return $db;
}
