<?php
/**
 * POST /api/notify.php — سامانهٔ اطلاع‌رسانی چندکاناله (نسخهٔ ۵٫۳).
 *
 * action=status   وضعیت کامل کانال‌ها + تاریخچهٔ ارسال (بدون افشای راز)
 * action=save     {config} ذخیرهٔ پیکربندی (راز خالی = حفظ قبلی)
 * action=check    {channel} تست سلامت اتصال (بدون ارسال پیام)
 * action=test     {channel} ارسال پیام تست واقعی
 * action=preview  {event} پیش‌نمایش متن پیام با دادهٔ نمونه
 * action=report   ارسال فوری گزارش کیف و سود/زیان کل
 */

require __DIR__ . '/bootstrap.php';

use Meelano\Config;
use Meelano\Crypto\AutoTrader;
use Meelano\Crypto\MarketData;
use Meelano\Crypto\Notifier;
use Meelano\Db;
use Meelano\Security;

m_guard(false, 'notify');
$in = m_input();
$action = m_clean_string($in['action'] ?? 'status', 12);
Security::requireRateLimit('notify', 60);

$db = Db::make();
if (!$db->isConnected()) {
    m_json(['ok' => false, 'error' => 'پایگاه‌داده در دسترس نیست — از تب «پایگاه‌داده» راه‌اندازی کنید.'], 503);
}
if (!$db->tableExists('notify_log')) {
    m_json(['ok' => false, 'error' => 'جدول‌های اطلاع‌رسانی ساخته نشده‌اند — «ساخت جداول» را در تنظیمات بزنید.'], 503);
}

$notifier = new Notifier($db);

switch ($action) {
    case 'save':
        $payload = (array)($in['config'] ?? $in);
        unset($payload['action']);
        m_json($notifier->save($payload));
        break;

    case 'check': {
        $ch = m_clean_string($in['channel'] ?? '', 16);
        if ($ch === '') {
            m_json(['ok' => false, 'error' => 'شناسهٔ کانال لازم است.'], 422);
        }
        m_json($notifier->checkChannel($ch));
        break;
    }

    case 'test': {
        $ch = m_clean_string($in['channel'] ?? '', 16);
        if ($ch === '') {
            m_json(['ok' => false, 'error' => 'شناسهٔ کانال لازم است.'], 422);
        }
        m_json($notifier->sendTest($ch));
        break;
    }

    case 'preview': {
        $ev = m_clean_string($in['event'] ?? '', 16);
        m_json($notifier->preview($ev));
        break;
    }

    case 'report': {
        if (!$db->tableExists('trade_accounts')) {
            m_json(['ok' => false, 'error' => 'جداول معامله‌گر ساخته نشده‌اند — ابتدا از تنظیمات، جداول را بسازید.'], 503);
        }
        $cfg = array_merge((array)Config::get('trading', []), (array)Config::get('market', []));
        $trader = new AutoTrader($db, new MarketData(null, (array)Config::get('market', [])), null, $cfg);
        $state = $trader->state(false);
        $res = $notifier->notifyWallet($state, true); // force: ارسال دستی حتی اگر کلید اصلی خاموش باشد
        m_json($res + [
            'summary' => [
                'equity_usdt' => $state['account']['equity_usdt'] ?? null,
                'total_pnl_usdt' => $state['account']['total_pnl_usdt'] ?? null,
                'total_pnl_pct' => $state['account']['total_pnl_pct'] ?? null,
                'open_count' => $state['account']['open_count'] ?? 0,
            ],
        ]);
        break;
    }

    case 'status':
    default:
        m_json($notifier->status());
        break;
}
