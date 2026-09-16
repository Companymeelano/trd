<?php
/**
 * GET /api/market.php — نمای کلی بازار برای نوار پالس داشبورد.
 * خروجی: برترین صعودی/نزولی، عرض بازار، رژیم بیت‌کوین و اسپارک‌لاین برترین‌ها.
 * پاسخ با کش بازار (TTL کوتاه) سرو می‌شود تا rate-limit صرافی مصرف نشود.
 */

require __DIR__ . '/bootstrap.php';

use Meelano\Config;
use Meelano\Crypto\Context;
use Meelano\Crypto\MarketData;
use Meelano\Crypto\Regime;
use Meelano\Crypto\SignalEngine;
use Meelano\Db;
use Meelano\Security;

m_guard(false, 'market');
Security::requireRateLimit('market', 30);

$marketCfg = (array)Config::get('market', []);
$market = new MarketData(null, $marketCfg);

$db = null;
try {
    $candidate = Db::make();
    $db = $candidate->isConnected() ? $candidate : null;
} catch (Throwable $e) {
    $db = null;
}

$limit = max(20, min(120, (int)($_GET['limit'] ?? 60)));
$tickers = $market->tickers($limit);
if (!$tickers) {
    m_json(['ok' => false, 'error' => 'دادهٔ بازار در دسترس نیست — کمی بعد دوباره تلاش کنید.'], 503);
}

// عرض بازار
$up = 0;
$down = 0;
foreach ($tickers as $t) {
    if ((float)$t['change_pct'] > 0.5) { $up++; }
    elseif ((float)$t['change_pct'] < -0.5) { $down++; }
}
$total = max(1, $up + $down);
$breadth = [
    'up' => $up,
    'down' => $down,
    'ratio' => round($up / $total, 2),
    'mood' => $up / $total >= 0.65 ? 'greedy' : ($up / $total <= 0.35 ? 'fearful' : 'neutral'),
];

// برترین‌ها
usort($tickers, static function ($a, $b) {
    return $b['change_pct'] <=> $a['change_pct'];
});
$gainers = array_slice($tickers, 0, 6);
$losers = array_slice(array_reverse($tickers), 0, 6);

// حجم‌های برتر برای اسپارک‌لاین
usort($tickers, static function ($a, $b) {
    return $b['quote_volume'] <=> $a['quote_volume'];
});
$top = array_slice($tickers, 0, 8);
$sparks = [];
foreach ($top as $t) {
    $cres = $market->candles($t['symbol'], '1h', 24);
    if (!empty($cres['ok'])) {
        $closes = array_column($cres['candles'], 'close');
        if (count($closes) >= 12) {
            $sparks[] = [
                'symbol' => $t['symbol'],
                'base' => $t['base'],
                'price' => (float)end($closes),
                'change_pct' => $t['change_pct'],
                'quote_volume' => $t['quote_volume'],
                'spark' => array_map(static function ($c) {
                    return round((float)$c, 8);
                }, $closes),
            ];
        }
    }
}

// رژیم بیت‌کوین (با موتور مشترک تا کش مشترک باشد)
$btc = null;
try {
    $engineCfg = (array)Config::get('trading', []);
    $engine = new SignalEngine($market, null, $db, $engineCfg);
    $btc = $engine->btcRegime();
} catch (Throwable $e) {
    $btc = null;
}

m_json([
    'ok' => true,
    'breadth' => $breadth,
    'btc' => $btc,
    'gainers' => array_map(static function ($t) {
        return ['symbol' => $t['symbol'], 'base' => $t['base'], 'price' => $t['last'], 'change_pct' => $t['change_pct'], 'quote_volume' => $t['quote_volume']];
    }, $gainers),
    'losers' => array_map(static function ($t) {
        return ['symbol' => $t['symbol'], 'base' => $t['base'], 'price' => $t['last'], 'change_pct' => $t['change_pct'], 'quote_volume' => $t['quote_volume']];
    }, $losers),
    'top' => $sparks,
    'ts' => time(),
]);
