<?php
/**
 * POST /api/paper.php — پنل معامله‌گر (کیف تست + اجرای خودکار).
 *
 * action=state   وضعیت کامل: کیف، پوزیشن‌های زنده، کارنامه، آمار، منحنی سرمایه
 * action=reset   {initial_usdt} بازنشانی کیف (تاریخچه پاک می‌شود)
 * action=config  {auto_*} ذخیرهٔ پیکربندی معاملهٔ خودکار
 * action=open    {symbol, usdt} معاملهٔ دستی کاغذی
 * action=close   {position_id} بستن دستی پوزیشن
 * action=update  فقط پایش خروج/قیمت‌ها
 */

require __DIR__ . '/bootstrap.php';

use Meelano\Config;
use Meelano\Crypto\AutoTrader;
use Meelano\Crypto\MarketData;
use Meelano\Db;
use Meelano\Security;

m_guard(false, 'paper');
$in = m_input();
$action = m_clean_string($in['action'] ?? 'state', 12);
Security::requireRateLimit('paper', 60);

$db = Db::make();
if (!$db->isConnected()) {
    m_json(['ok' => false, 'error' => 'پایگاه‌داده در دسترس نیست — از تب «پایگاه‌داده» راه‌اندازی کنید.'], 503);
}
if (!$db->tableExists('trade_accounts')) {
    m_json(['ok' => false, 'error' => 'جداول معامله‌گر ساخته نشده‌اند — «ساخت جداول» را در تنظیمات بزنید.'], 503);
}

$cfg = array_merge((array)Config::get('trading', []), (array)Config::get('market', []));
$trader = new AutoTrader($db, new MarketData(null, (array)Config::get('market', [])), null, $cfg);

switch ($action) {
    case 'reset':
        $initial = (float)($in['initial_usdt'] ?? ($cfg['paper_initial_usdt'] ?? 10000));
        m_json($trader->reset($initial) + ['state' => $trader->state(false)]);
        break;

    case 'config':
        $payload = (array)($in['config'] ?? $in);
        unset($payload['action']);
        m_json($trader->saveConfig($payload));
        break;

    case 'open':
        $symbol = strtoupper(m_clean_string($in['symbol'] ?? '', 20));
        $usdt = max(10.0, min(1000000.0, (float)($in['usdt'] ?? 100)));
        if ($symbol === '') {
            m_json(['ok' => false, 'error' => 'نماد لازم است (مثل BTCUSDT).'], 422);
        }
        // قیمت جاری + پلن ریسک از موتور — معاملهٔ دستی هم استاپ/TP می‌گیرد
        $engine = new \Meelano\Crypto\SignalEngine(new MarketData(null, (array)Config::get('market', [])), null, $db, $cfg);
        $sig = $engine->analyzeSymbol($symbol);
        $sig['usdt'] = $usdt;
        $res = $trader->openPosition($sig, 'manual');
        m_json($res + ['state' => $trader->state(false)]);
        break;

    case 'close':
        $pid = (int)($in['position_id'] ?? 0);
        if ($pid <= 0) {
            m_json(['ok' => false, 'error' => 'شناسهٔ پوزیشن لازم است.'], 422);
        }
        $res = $trader->closePosition($pid, 'manual');
        m_json($res + ['state' => $trader->state(false)]);
        break;

    case 'update':
        m_json($trader->updatePrices() + ['state' => $trader->state(false)]);
        break;

    case 'state':
    default:
        m_json($trader->state(true));
        break;
}
