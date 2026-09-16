<?php
/**
 * POST /api/scan.php — اجرای موتور سیگنال کریپتو (نسخهٔ ۵).
 * ورودی: {symbol?: "BTCUSDT"} برای تحلیل تک‌ارز؛ بدون symbol → رصد کل بازار.
 * خروجی بازار: سیگنال‌ها + عرض بازار + رژیم بیت‌کوین + آمار خنک‌کردن.
 */

require __DIR__ . '/bootstrap.php';

use Meelano\Ai\Client;
use Meelano\Ai\Health;
use Meelano\Config;
use Meelano\Crypto\MarketData;
use Meelano\Crypto\SignalEngine;
use Meelano\Db;
use Meelano\Security;

m_guard(false, 'scan');
$in = m_input();
Security::requireRateLimit('scan', 12);

$db = null;
try {
    $candidate = Db::make();
    $db = $candidate->isConnected() ? $candidate : null;
} catch (Throwable $e) {
    $db = null;
}

$ai = null;
try {
    $client = new Client(null, null, $db);
    if ($db !== null) {
        $client->setHealth(Health::cached($db));
    }
    $ai = $client;
} catch (Throwable $e) {
    $ai = null;
}

$marketCfg = array_merge((array)Config::get('market', []), [
    'min_quote_volume' => (float)Config::get('trading.min_quote_volume', 5000000),
]);
$engine = new SignalEngine(new MarketData(null, $marketCfg), $ai, $db);

$symbol = strtoupper(m_clean_string($in['symbol'] ?? '', 20));
if ($symbol !== '') {
    $signal = $engine->analyzeSymbol($symbol);
    $btc = $signal['btc'] ?? null;
    m_json([
        'ok' => true,
        'mode' => 'single',
        'signal' => $signal,
        'is_signal' => !empty($signal['is_signal']) && empty($signal['suppressed']),
        'btc' => $btc ?: $engine->btcRegime(),
    ]);
}

$result = $engine->scanMarket();

// ── قلاب معامله‌گر خودکار: اجرای سیگنال‌ها روی کیف تست/زنده (نسخهٔ ۵٫۲) ──
$auto = null;
try {
    if ($db !== null && $db->tableExists('trade_accounts')
        && (bool)Config::get('trading.auto_trade_enabled', false)) {
        $auto = (new \Meelano\Crypto\AutoTrader(
            $db,
            new MarketData(null, $marketCfg),
            null,
            array_merge((array)Config::get('trading', []), (array)Config::get('market', []))
        ))->afterScan($result['signals']);
    }
} catch (Throwable $e) {
    $auto = ['ok' => false, 'errors' => ['auto: ' . $e->getMessage()]];
}

m_json([
    'ok' => (bool)$result['ok'],
    'mode' => 'market',
    'scanned' => $result['scanned'],
    'signals' => $result['signals'],
    'signal_count' => count($result['signals']),
    'suppressed' => $result['suppressed'],
    'ai_used' => $result['ai_used'],
    'breadth' => $result['breadth'],
    'btc' => $result['btc'],
    'portfolio' => $result['portfolio'] ?? null,
    'fear_greed' => $result['fear_greed'] ?? null,
    'auto_trade' => $auto,
    'duration_ms' => $result['duration_ms'],
    'errors' => $result['errors'],
]);
