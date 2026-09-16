<?php
/**
 * POST /api/backtest.php — بک‌تست و اعتبارسنجی استراتژی روی یک نماد.
 * ورودی: {symbol: "BTCUSDT", interval?: "1h", bars?: 500, mode?: "standard|walkforward|montecarlo"}
 *
 * حالت‌ها (نسخهٔ ۵٫۱):
 *   standard    — بک‌تست کامل با کارمزد + اسلیپیج + تفکیک رژیمی
 *   walkforward — پنجره‌های زمانی متوالی؛ پایداری استراتژی
 *   montecarlo  — ۵۰۰ بازچینی تصادفی ترتیب معاملات؛ توزیع واقعی ریسک
 *
 * شفافیت: فقط لایهٔ تکنیکال (بدون اجماع AI که در گذشته قابل بازتولید نیست)؛
 * ورود در کندل بعدی، استاپ اولویت دارد، کارمزد و اسلیپیج لحاظ شده است.
 */

require __DIR__ . '/bootstrap.php';

use Meelano\Config;
use Meelano\Crypto\Backtest;
use Meelano\Crypto\MarketData;
use Meelano\Crypto\Robustness;
use Meelano\Security;

m_guard(false, 'backtest');
$in = m_input();
Security::requireRateLimit('backtest', 6);

$symbol = strtoupper(m_clean_string($in['symbol'] ?? '', 20));
if ($symbol === '') {
    m_json(['ok' => false, 'error' => 'نماد لازم است (مثل BTCUSDT).'], 422);
}

$allowedTf = ['15m', '30m', '1h', '2h', '4h', '6h', '12h', '1d'];
$interval = m_clean_string($in['interval'] ?? '1h', 8);
if (!in_array($interval, $allowedTf, true)) {
    $interval = '1h';
}
$bars = max(220, min(1000, (int)($in['bars'] ?? 500)));
$mode = m_clean_string($in['mode'] ?? 'standard', 16);
if (!in_array($mode, ['standard', 'walkforward', 'montecarlo'], true)) {
    $mode = 'standard';
}

$market = new MarketData(null, (array)Config::get('market', []));
$cfg = array_merge((array)Config::get('trading', []), (array)Config::get('market', []));

if ($mode === 'walkforward') {
    $rob = new Robustness($market, $cfg);
    m_json($rob->walkForward($symbol, $interval, max(600, $bars), 4));
}

$bt = new Backtest($market, $cfg);
$result = $bt->run($symbol, $interval, $bars);

if ($mode === 'montecarlo' && !empty($result['ok'])) {
    $rob = new Robustness($market, $cfg);
    $rs = array_column($result['trades_list'], 'r');
    $result['monte_carlo'] = $rob->monteCarlo($rs, 500);
}

m_json($result);
