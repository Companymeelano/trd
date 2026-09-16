<?php
/**
 * POST /api/backtest.php — بک‌تست استراتژی تکنیکال روی یک نماد.
 * ورودی: {symbol: "BTCUSDT", interval?: "1h", bars?: 500}
 * خروجی: تعداد معامله، وین‌ریت، امید ریاضی (R)، پروفایت فاکتور،
 *         حداکثر افت سرمایه و منحنی سرمایه + ۴۰ معاملهٔ آخر.
 *
 * شفافیت: فقط لایهٔ تکنیکال (بدون اجماع AI که در گذشته قابل بازتولید نیست)؛
 * ورود در کندل بعدی، استاپ اولویت دارد، کارمزد لحاظ شده است.
 */

require __DIR__ . '/bootstrap.php';

use Meelano\Config;
use Meelano\Crypto\Backtest;
use Meelano\Crypto\MarketData;
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

$cfg = array_merge((array)Config::get('trading', []), (array)Config::get('market', []));
$bt = new Backtest(new MarketData(null, (array)Config::get('market', [])), $cfg);
$result = $bt->run($symbol, $interval, $bars);

m_json($result);
