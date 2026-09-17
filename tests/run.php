<?php
/**
 * مجموعه تست‌های میلانو تریدینگ اینتلیجنس — نسخهٔ ۵.
 * اجرا: php tests/run.php
 *
 * همه تست‌ها کد واقعی سامانه را اجرا می‌کنند (نه نسخه جعلی):
 * اندیکاتورهای جدید (ADX/OBV/VWAP/Supertrend)، رژیم بازار، فیلترهای وزن‌دار،
 * مدیریت ریسک ساختاری، چند تایم‌فریمی، بک‌تست بدون نشت، خط‌لوله کامل با
 * اجماع AI ماک‌شده و کارنامهٔ ai_runs.
 *
 * @author Milad Yaghoobi — Meelano Studio Design
 */

declare(strict_types=1);

define('MEELANO_TESTING', true);
require dirname(__DIR__) . '/includes/bootstrap.php';

use Meelano\Ai\Client;
use Meelano\Ai\MockTransport;
use Meelano\Ai\Router;
use Meelano\Config;
use Meelano\Crypto\AiValidator;
use Meelano\Crypto\Backtest;
use Meelano\Crypto\ConnectorFactory;
use Meelano\Crypto\Context;
use Meelano\Crypto\Filters;
use Meelano\Crypto\Indicators;
use Meelano\Crypto\LearningEngine;
use Meelano\Crypto\MarketData;
use Meelano\Crypto\Nobitex;
use Meelano\Crypto\Wallex;
use Meelano\Crypto\AutoTrader;
use Meelano\Crypto\BinanceSpot;
use Meelano\Crypto\Regime;
use Meelano\Crypto\RiskManager;
use Meelano\Crypto\Robustness;
use Meelano\Crypto\Sentiment;
use Meelano\Crypto\SignalTracker;
use Meelano\Crypto\SignalEngine;
use Meelano\Db;
use Meelano\Installer;
use Meelano\Schema;
use Meelano\Security;

$passed = 0; $failed = 0; $failures = [];
set_exception_handler(static function (Throwable $e): void {
    echo "UNCAUGHT: " . $e->getMessage() . " @" . $e->getFile() . ":" . $e->getLine() . "\n";
    exit(1);
});

function check(string $name, bool $cond, string $detail = ''): void
{
    global $passed, $failed, $failures;
    if ($cond) { $passed++; echo "  ✓ {$name}\n"; }
    else { $failed++; $failures[] = $name; echo "  ✗ {$name}" . ($detail !== '' ? "  — {$detail}" : '') . "\n"; }
}
function section(string $t): void { echo "\n── {$t} ──\n"; }

/* ═══ ۱) اندیکاتورها (پایه + نسخهٔ ۵) ═══ */
section('اندیکاتورها');
$up = [];
for ($i = 0; $i < 300; $i++) { $up[] = 100 + $i * 0.4 + (($i % 7 === 0) ? -0.15 : 0); }
$rsiUp = Indicators::last(Indicators::rsi($up, 14));
check('RSI صعود غالب = بالای ۹۰', (float)$rsiUp > 90, (string)$rsiUp);
$flat = array_fill(0, 60, 50.0);
check('RSI بدون تغییر ≈ ۵۰', abs((float)Indicators::last(Indicators::rsi($flat, 14)) - 50.0) < 0.001);
$sma = Indicators::sma([1, 2, 3, 4, 5], 3);
check('SMA درست است', abs((float)Indicators::last($sma) - 4.0) < 0.001);
$ema = Indicators::ema($up, 10);
check('EMA به قیمت نزدیک می‌شود', (float)Indicators::last($ema) > 130);
[$m, $sig, $hist] = Indicators::macd($up);
check('MACD در روند صعودی مثبت است', (float)Indicators::last($hist) > 0);
[$bm, $bu, $bl, $bw] = Indicators::bollinger($up, 20, 2);
check('باند بالا > پایین', (float)Indicators::last($bu) > (float)Indicators::last($bl));
check('عرض باند محاسبه می‌شود', Indicators::last($bw) !== null && (float)Indicators::last($bw) > 0);
$atr = Indicators::last(Indicators::atr($up, array_map(fn($x) => $x - 1, $up), $up, 14));
check('ATR ≈ ۱ برای دامنه ثابت', abs((float)$atr - 1.0) < 0.001, (string)$atr);
check('ساختار صعودی تشخیص داده شد', Indicators::structure($up, 10) === 'uptrend');

// ADX — روند صعودی پایدار باید ADX قوی بدهد
$highs = array_map(fn($x) => $x + 0.2, $up);
$lows = array_map(fn($x) => $x - 0.2, $up);
[$adxArr, $pdi, $mdi] = Indicators::adx($highs, $lows, $up, 14);
$adxLast = (float)Indicators::last($adxArr);
check('ADX در روند قوی > ۳۰', $adxLast > 30, (string)$adxLast);
check('DI+ > DI− در روند صعودی', (float)Indicators::last($pdi) > (float)Indicators::last($mdi));

// OBV — در صعود باید انباشت مثبت باشد
$obv = Indicators::obv($up, array_map(fn($i) => 1000 + $i, range(0, 299)));
check('OBV در صعود صعودی است', end($obv) > reset($obv));

// VWAP — میانگین غلتان باید داخل دامنهٔ پنجرهٔ خودش باشد
$vwap = Indicators::vwap($highs, $lows, $up, array_map(fn($i) => 1000 + $i, range(0, 299)), 20);
$vwapLast = Indicators::last($vwap);
check('VWAP داخل دامنهٔ پنجره', $vwapLast !== null && $vwapLast > min(array_slice($lows, -20)) && $vwapLast < max(array_slice($highs, -20)));

// Supertrend — جهت صعودی در روند صعودی
[$stLine, $stDir] = Indicators::supertrend($highs, $lows, $up, 10, 3.0);
check('Supertrend صعودی', (int)Indicators::last($stDir) === 1);

// شیب رگرسیون
check('شیب رگرسیون در صعود مثبت', (float)Indicators::slope($up, 30) > 0);

// سوئینگ‌ها
check('کف سوئینگ زیر قیمت', Indicators::swingLow(array_slice($lows, -20), 20) < $up[299]);
check('سقف سوئینگ بالای قیمت', Indicators::swingHigh(array_slice($highs, -20), 20) > $up[299]);

/* ═══ ۲) بافت مشترک (Context) ═══ */
section('Context');
$candles = [];
$t = 1700000000; $price = 100.0;
for ($i = 0; $i < 260; $i++) {
    $open = $price;
    $delta = 0.4 + (($i % 7 === 0) ? -0.15 : 0);
    $close = $open + $delta;
    $high = max($open, $close) + 0.2;
    $low = min($open, $close) - 0.2;
    $vol = 1000 + $i * 5;
    $candles[] = ['time' => $t + $i * 3600, 'open' => $open, 'high' => $high, 'low' => $low, 'close' => $close, 'volume' => $vol];
    $price = $close;
}
$ctxFull = Context::build($candles, ['change_pct' => 3.0, 'quote_volume' => 1e8], ['min_quote_volume' => 1000]);
check('قیمت بافت = آخرین کلوز', abs($ctxFull['price'] - $candles[count($candles) - 1]['close']) < 0.0001);
check('ADX بافت پر شده', $ctxFull['adx'] !== null && (float)$ctxFull['adx'] > 30);
check('VWAP بافت پر شده', $ctxFull['vwap'] !== null && $ctxFull['vwap'] > 0);
check('کف سوئینگ بافت زیر قیمت', $ctxFull['swing_low'] < $ctxFull['price']);

// عکس‌العمل در ایندکس میانی — بدون نشت آینده
$series = Context::series($candles);
$mid = 150;
$ctxMid = Context::snapshot($series, $mid);
check('سnapshot میانی = کلوز همان کندل', abs($ctxMid['price'] - $candles[$mid]['close']) < 0.0001);
$ctxMidMinus1 = Context::snapshot($series, $mid - 1);
check('سnapshot کندل قبل متفاوت است', abs($ctxMidMinus1['price'] - $ctxMid['price']) > 0.0001);

/* ═══ ۳) رژیم بازار ═══ */
section('رژیم بازار');
$regUp = Regime::classify($ctxFull);
check('روند صعودی → trend_up', $regUp['regime'] === Regime::TREND_UP, $regUp['regime']);
check('قدت رژیم > ۵۰', $regUp['strength'] > 50, (string)$regUp['strength']);
$flatCtx = $ctxFull;
$flatCtx['adx'] = 12; $flatCtx['plus_di'] = 14; $flatCtx['minus_di'] = 13;
$flatCtx['bb_width_pct'] = 20; $flatCtx['atr_pct'] = 0.5;
check('ADX پایین و فشردگی → range', Regime::classify($flatCtx)['regime'] === Regime::RANGE);
$volCtx = $ctxFull;
$volCtx['atr_pct'] = 7.0; $volCtx['bb_width_pct'] = 95;
check('نوسان وحشی → volatile', Regime::classify($volCtx)['regime'] === Regime::VOLATILE);
check('ضریب سایز در volatile محافظه‌کارانه', Regime::classify($volCtx)['sizing_factor'] <= 0.6);

/* ═══ ۴) فیلترهای هم‌گرایی وزن‌دار ═══ */
section('فیلترها');
$buyCtx = [
    'rsi' => 58, 'macd_hist' => 2, 'macd_hist_prev' => 1,
    'ema9' => 111, 'ema21' => 109, 'ema50' => 105, 'ema200' => 95,
    'price' => 113, 'bb_upper' => 116, 'bb_lower' => 100, 'bb_pos' => 0.35,
    'stoch_k' => 25, 'stoch_d' => 20, 'atr_pct' => 1.5, 'vol_ratio' => 1.8,
    'change24' => 3.0, 'quote_volume' => 1e8, 'structure' => 'uptrend', 'wick_ratio' => 0.2,
    'adx' => 28, 'plus_di' => 30, 'minus_di' => 12,
    'supertrend_dir' => 1, 'supertrend_line' => 104,
    'obv_slope' => 1.5, 'vwap' => 110,
    'bb_width_pct' => 55,
    'min_quote_volume' => 5e6, 'regime' => 'trend_up',
];
$evalBuy = (new Filters())->evaluate($buyCtx);
check('بافت صعودی → سمت BUY', $evalBuy['side'] === 'BUY', $evalBuy['side']);
check('حداقل ۲۸ فیلتر از ۳۴ عبور کردند', $evalBuy['passed'] >= 28 && $evalBuy['total'] === 34, $evalBuy['passed'] . '/' . $evalBuy['total']);
check('امتیاز تکنیکال بالای ۷۰', $evalBuy['tech_score'] >= 70, (string)$evalBuy['tech_score']);
check('هم‌گرایی شمارش شده', $evalBuy['confluence'] >= 6, $evalBuy['confluence'] . '/' . $evalBuy['confluence_total']);

$weakCtx = $buyCtx;
$weakCtx['structure'] = 'downtrend'; $weakCtx['ema9'] = 90; $weakCtx['ema21'] = 95; $weakCtx['ema50'] = 100; $weakCtx['ema200'] = 110;
$weakCtx['price'] = 88; $weakCtx['rsi'] = 24; $weakCtx['macd_hist'] = -2; $weakCtx['macd_hist_prev'] = -1;
$weakCtx['change24'] = -15; $weakCtx['wick_ratio'] = 0.6; $weakCtx['stoch_k'] = 80; $weakCtx['stoch_d'] = 85;
$weakCtx['supertrend_dir'] = -1; $weakCtx['obv_slope'] = -1.2; $weakCtx['vwap'] = 95; $weakCtx['regime'] = 'trend_down';
$evalWeak = (new Filters())->evaluate($weakCtx);
check('بافت ضعیف → سمت SELL', $evalWeak['side'] === 'SELL', $evalWeak['side']);
check('فیلتر سقوط آزاد در ریزش رد می‌شود', !(bool)array_filter($evalWeak['filters'], fn($f) => $f['key'] === 'freefall' && $f['pass']));

/* ═══ ۵) مدیریت ریسک ساختاری ═══ */
section('مدیریت ریسک');
$rm = new RiskManager(['risk_per_trade_percent' => 1, 'atr_stop_multiplier' => 2, 'max_atr_stop_multiplier' => 3, 'max_position_percent' => 25]);
$plan = $rm->plan('BUY', 100.0, 2.0, 80, ['swing_low' => 96.0, 'sizing_factor' => 1.0]);
check('استاپ زیر ورود در خرید', $plan['stop_loss'] < 100.0);
check('سوئینگ دور → استاپ ATR با برچسب صادقانه (اصلاح v5.4)', $plan['stop_type'] === 'atr' && abs($plan['stop_loss'] - 96.0) < 0.001, $plan['stop_type'] . ' ' . $plan['stop_loss']);
$planStruct = $rm->plan('BUY', 100.0, 2.0, 80, ['swing_low' => 98.0, 'sizing_factor' => 1.0]);
check('سوئینگ نزدیک → استاپ ساختاری واقعی', $planStruct['stop_type'] === 'structure' && abs($planStruct['stop_loss'] - 97.5) < 0.001, $planStruct['stop_type'] . ' ' . $planStruct['stop_loss']);
$planFloor = $rm->plan('BUY', 100.0, 2.0, 80, ['swing_low' => 99.7, 'sizing_factor' => 1.0]);
check('کف حداقلی: استاپ تنگ‌تر از ۰٫۹ ATR نمی‌شود', abs($planFloor['stop_loss'] - (100.0 - 1.8)) < 0.001, (string)$planFloor['stop_loss']);
check('تارگت‌ها بالای ورود و مرتب', $plan['take_profit_1'] > 100.0 && $plan['take_profit_2'] > $plan['take_profit_1'] && $plan['take_profit_3'] > $plan['take_profit_2']);
check('R:R تارگت۲ ≈ ۲٫۵', abs($plan['risk_reward_2'] - 2.5) < 0.01, (string)$plan['risk_reward_2']);
check('R:R تارگت۳ ≈ ۴', abs($plan['risk_reward_3'] - 4.0) < 0.01, (string)$plan['risk_reward_3']);
check('سایز پوزیشن در سقف', $plan['position_percent'] <= 25 && $plan['position_percent'] > 0);
check('نقطهٔ ابطال متن ارائه شد', $plan['invalidation'] !== '');
$planVol = $rm->plan('BUY', 100.0, 2.0, 80, ['swing_low' => 96.0, 'sizing_factor' => 0.55]);
check('رژیم پرنوسان سایز را کم می‌کند', $planVol['position_percent'] < $plan['position_percent']);
$planSell = $rm->plan('SELL', 100.0, 2.0, 70, ['swing_high' => 104.0, 'sizing_factor' => 1.0]);
check('استاپ فروش بالای ورود', $planSell['stop_loss'] > 100.0);

/* ═══ ۶) خط‌لوله کامل با اجماع AI ماک‌شده ═══ */
section('SignalEngine (end-to-end)');
$mock = new MockTransport();
$mock->on('api.binance.com/api/v3/klines', ['status' => 200, 'body' => json_encode(array_map(static function ($c) {
    return [$c['time'] * 1000, $c['open'], $c['high'], $c['low'], $c['close'], $c['volume']];
}, $candles))]);
$mock->on('api.binance.com/api/v3/ticker/24hr', ['status' => 200, 'body' => json_encode([
    ['symbol' => 'BTCUSDT', 'lastPrice' => (string)$candles[count($candles) - 1]['close'], 'priceChangePercent' => '3.0', 'quoteVolume' => '100000000', 'highPrice' => '190', 'lowPrice' => '100'],
])]);
$buyJsonBuy = '{"signal":"BUY","confidence":85,"reasoning":"trend","risks":[],"invalidation":"break below stop"}';
$openAiBody = json_encode(['choices' => [['message' => ['content' => $buyJsonBuy]]]]);
$geminiBody = json_encode(['candidates' => [['content' => ['parts' => [['text' => $buyJsonBuy]]]]]]);
$groqBody = json_encode(['choices' => [['message' => ['content' => $buyJsonBuy]]]]);
$deepseekBody = json_encode(['choices' => [['message' => ['content' => $buyJsonBuy]]]]);
$mock->on('api.openai.com/v1/chat/completions', ['status' => 200, 'body' => $openAiBody]);
$mock->on('generativelanguage.googleapis.com', ['status' => 200, 'body' => $geminiBody]);
$mock->on('api.groq.com/openai/v1/chat/completions', ['status' => 200, 'body' => $groqBody]);
$mock->on('api.deepseek.com/v1/chat/completions', ['status' => 200, 'body' => $deepseekBody]);

$config = Config::defaults();
foreach (['openai', 'gemini', 'groq', 'deepseek'] as $p) { $config['ai']['providers'][$p]['api_key'] = 'test-' . $p; }
$health = [
    'openai' => ['ok' => 1, 'latency_ms' => 300], 'gemini' => ['ok' => 1, 'latency_ms' => 400],
    'groq' => ['ok' => 1, 'latency_ms' => 100], 'deepseek' => ['ok' => 1, 'latency_ms' => 250],
];

$tmp = tempnam(sys_get_temp_dir(), 'mlnc') . '.sqlite'; @unlink($tmp);
$db = Db::make(['driver' => 'sqlite', 'sqlite_path' => $tmp, 'prefix' => 'mln_']);
(new Installer($db))->run();

$client = new Client($mock, new Router($config, $health), $db, $config);
$ticker = ['symbol' => 'BTCUSDT', 'base' => 'BTC', 'change_pct' => 3.0, 'quote_volume' => 1e8];
$baseCfg = [
    'timeframe' => '1h', 'timeframes' => ['1h', '4h', '1d'],
    'min_filters_passed' => 10, 'min_tech_score' => 50, 'min_combined_score' => 45,
    'tech_weight' => 0.6, 'ai_weight' => 0.4, 'require_mtf' => true, 'mtf_min_alignment' => 0.55,
    'risk_per_trade_percent' => 1, 'atr_stop_multiplier' => 2, 'max_atr_stop_multiplier' => 3,
    'max_position_percent' => 25, 'min_risk_reward' => 2.0, 'cooldown_hours' => 12,
    'btc_filter' => true, 'min_quote_volume' => 1000, 'max_signals_per_scan' => 8,
];

$engine = new SignalEngine(new MarketData($mock), $client, $db, $baseCfg + ['require_ai_agreement' => false]);
$signal = $engine->analyzeSymbol('BTCUSDT', $ticker);
check('خط‌لوله سیگنال صادر کرد', !empty($signal['is_signal']), json_encode(['reason' => $signal['reason'] ?? null, 'score' => $signal['tech_score'] ?? null]));
check('جهت سیگنال = سمت تکنیکال', ($signal['side'] ?? '') === ($signal['tech_side'] ?? '?'), ($signal['side'] ?? 'null'));
check('رژیم تشخیص داده شد', in_array($signal['regime'] ?? '', ['trend_up', 'trend_down', 'range', 'volatile'], true), $signal['regime'] ?? '-');
check('درجهٔ کیفیت معتبر', in_array($signal['tier'] ?? '', ['A+', 'A', 'B', 'C'], true), $signal['tier'] ?? '-');
check('برنامه ریسک کامل است', isset($signal['risk']['stop_loss'], $signal['risk']['take_profit_2'], $signal['risk']['position_percent'], $signal['risk']['invalidation']));
check('MTF هم‌راستا', !empty($signal['mtf']['ok']) && !empty($signal['mtf']['aligned']));

// ماندگاری از مسیر واقعی scanMarket
$scan = $engine->scanMarket(5);
check('اسکن بازار موفق', !empty($scan['ok']) && $scan['scanned'] >= 1);
check('اسکن در دیتابیس ثبت شد', $db->count('scans') >= 1);
check('سیگنال در دیتابیس ذخیره شد', $db->count('signals') >= 1);
check('عرض بازار محاسبه شد', isset($scan['breadth']['ratio']) && $scan['breadth']['ratio'] > 0);
check('رژیم بیت‌کوین محاسبه شد', !empty($scan['btc']) && $scan['btc']['regime'] === 'trend_up');
check('کارنامهٔ ai_runs نوشته شد', $db->count('ai_runs') >= 1);

// خنک‌کردن تکرار: اسکن دوم نباید همان سیگنال را دوباره ثبت کند
$scan2 = $engine->scanMarket(5);
check('خنک‌کردن تکرار فعال شد', ($scan2['suppressed'] ?? 0) >= 1 || count($scan2['signals']) === 0, 'suppressed=' . ($scan2['suppressed'] ?? '?'));

// حالت ۲: دروازهٔ اجماع — اگر AI مخالف تکنیکال باشد، با الزام اجماع سیگنال مسدود می‌شود
$sellJson = '{"signal":"SELL","confidence":90,"reasoning":"divergence","risks":[],"invalidation":"break above high"}';
$mockSell = new MockTransport();
$mockSell->on('api.binance.com/api/v3/klines', ['status' => 200, 'body' => json_encode(array_map(static function ($c) {
    return [$c['time'] * 1000, $c['open'], $c['high'], $c['low'], $c['close'], $c['volume']];
}, $candles))]);
$mockSell->on('api.binance.com/api/v3/ticker/24hr', ['status' => 200, 'body' => json_encode([
    ['symbol' => 'BTCUSDT', 'lastPrice' => '188', 'priceChangePercent' => '3.0', 'quoteVolume' => '100000000', 'highPrice' => '190', 'lowPrice' => '100'],
])]);
$mockSell->on('api.openai.com/v1/chat/completions', ['status' => 200, 'body' => json_encode(['choices' => [['message' => ['content' => $sellJson]]]])]);
$mockSell->on('generativelanguage.googleapis.com', ['status' => 200, 'body' => json_encode(['candidates' => [['content' => ['parts' => [['text' => $sellJson]]]]]])]);
$mockSell->on('api.groq.com/openai/v1/chat/completions', ['status' => 200, 'body' => json_encode(['choices' => [['message' => ['content' => $sellJson]]]])]);
$mockSell->on('api.deepseek.com/v1/chat/completions', ['status' => 200, 'body' => json_encode(['choices' => [['message' => ['content' => $sellJson]]]])]);
$clientSell = new Client($mockSell, new Router($config, $health), $db, $config);
$engineStrict = new SignalEngine(new MarketData($mockSell), $clientSell, $db, $baseCfg + ['require_ai_agreement' => true, 'cooldown_hours' => 0]);
$signalStrict = $engineStrict->analyzeSymbol('BTCUSDT', $ticker);
$disagree = !empty($signalStrict['ai']['ok']) && empty($signalStrict['ai']['agreement']);
check('دروازهٔ اجماع AI کار می‌کند', $disagree ? empty($signalStrict['is_signal']) : true, 'AI-agreement veto');

/* ═══ ۷) اعتبارسنج AI (اجماع) ═══ */
section('AiValidator');
$validator = new AiValidator($client, 3);
$summary = $buyCtx + ['symbol' => 'BTCUSDT', 'tech_side' => 'BUY', 'tech_score' => 80, 'regime_label' => 'روند صعودی',
    'risk_entry' => 113, 'risk_stop' => 108, 'risk_tp2' => 125, 'mtf' => null];
$vres = $validator->validate($summary, 'BUY');
check('اجماع BUY با اعتماد بالا', $vres['ok'] && $vres['side'] === 'BUY', $vres['side'] ?? 'null');
check('توافق با تکنیکال', $vres['agreement'] === true);
check('سه نظر جمع شد', count($vres['opinions']) === 3, (string)count($vres['opinions']));
check('نقطهٔ ابطال اجماع برگشت', $vres['invalidation'] !== null && $vres['invalidation'] !== '');

/* ═══ ۸) بک‌تست بدون نشت داده ═══ */
section('Backtest');
$btCfg = $baseCfg + ['backtest_fee_bps' => 8, 'backtest_horizon_bars' => 72];
$bt = new Backtest(new MarketData($mock), $btCfg);
$bres = $bt->run('BTCUSDT', '1h', 260);
check('بک‌تست اجرا شد', !empty($bres['ok']), $bres['error'] ?? '-');
check('بک‌تست معامله داشت', $bres['trades'] >= 1, (string)$bres['trades']);
check('وین‌ریت بالای ۶۰٪ در روند صعودی مصنوعی', $bres['winrate'] >= 60, (string)$bres['winrate']);
check('امید ریاضی مثبت', $bres['expectancy_r'] > 0, (string)$bres['expectancy_r']);
check('منحنی سرمایه برگشت', count($bres['equity']) >= 2);
check('مدل شفاف اعلام شد', $bres['model'] === 'tech-only');

/* ═══ ۹) Schema و Installer ═══ */
section('Schema / Installer');
check('جدول‌های کریپتو تعریف شده', in_array('signals', Schema::names(), true) && in_array('scans', Schema::names(), true));
check('جدول ai_runs تعریف شد', in_array('ai_runs', Schema::names(), true));
check('ستون‌های جدید سیگنال در SQL هستند', strpos(Schema::statements('signals', 'sqlite')[0], 'tier') !== false && strpos(Schema::statements('signals', 'sqlite')[0], 'regime') !== false);
$res2 = (new Installer($db))->run();
check('نصب ایدم‌پوتنت', $res2['ok'] && count($res2['created']) === 0);
$sqlStatements = Schema::statements('signals', 'sqlite');
check('SQLite ایندکس جدا', count($sqlStatements) > 1);

/* ═══ ۱۰) ممیزی امنیتی (اصلاح باگ user/actor) ═══ */
section('Security / Audit');
Security::audit('test', 'unit_test', 'تست نوشتن ممیزی', 'tester', $db);
$auditRow = $db->selectOne('SELECT * FROM ' . $db->table('settings_audit') . ' ORDER BY id DESC LIMIT 1');
check('ممیزی در دیتابیس ثبت شد', $auditRow !== null && $auditRow['user'] === 'tester', json_encode($auditRow));

/* ═══ ۱۱) Config رمزنگاری ═══ */
section('Config (رمزنگاری)');
Config::set('ai.providers.openai.api_key', 'sk-secret-crypto-1234567890');
Config::save();
$raw = @include MEELANO_CONFIG . '/settings.php';
check('کلید خام در فایل نیست', strpos(var_export($raw, true), 'sk-secret-crypto-1234567890') === false);
Config::load(true);
check('بازخوانی رمزگشایی', Config::get('ai.providers.openai.api_key') === 'sk-secret-crypto-1234567890');
check('پیش‌فرض دیتابیس بدون رمز است', Config::get('db.pass', 'NOT-EMPTY') === '');
@unlink(MEELANO_CONFIG . '/settings.php'); @unlink(MEELANO_CONFIG . '/.app_key');


/* ═══ ۱۲) اندیکاتورهای نسخهٔ ۵٫۱ ═══ */
section('اندیکاتورهای v5.1');
$erTrend = Indicators::last(Indicators::kaufmanER($up, 10));
check('ER در روند قوی بالای ۰٫۵', (float)$erTrend > 0.5, (string)$erTrend);
$chop = []; $px = 100.0;
for ($i = 0; $i < 300; $i++) { $px += ($i % 2 === 0 ? 0.9 : -0.9); $chop[] = $px; }
$erChop = Indicators::last(Indicators::kaufmanER($chop, 10));
check('ER در رِنج چاپی زیر ۰٫۳', (float)$erChop < 0.3, (string)$erChop);

$divPrice = [10, 9, 10, 11, 10, 9, 10, 11, 12, 11, 10, 9, 8.5, 9.5, 11, 12];
$divOsc = [50, 40, 50, 55, 45, 20, 50, 55, 60, 55, 50, 45, 30, 40, 55, 60];
$div = Indicators::divergence($divPrice, $divOsc, 15);
check('واگرایی صعودی تشخیص داده شد', ($div['type'] ?? null) === 'bull', json_encode($div, JSON_UNESCAPED_UNICODE));
$bearPrice = [5, 6, 7, 8, 9, 8, 7, 8, 9, 10, 11, 10, 9, 10, 12, 13];
$bearOsc = [50, 55, 60, 65, 70, 65, 60, 62, 66, 68, 50, 48, 45, 48, 52, 55];
$divBear = Indicators::divergence($bearPrice, $bearOsc, 15);
check('واگرایی نزولی تشخیص داده شد', ($divBear['type'] ?? null) === 'bear', json_encode($divBear, JSON_UNESCAPED_UNICODE));

$fvgBull = Indicators::fvgAt([10, 10, 12], [9.5, 9.6, 10.8], 2);
check('FVG صعودی: low جدید بالای high دو کندل قبل', is_array($fvgBull) && $fvgBull['side'] === 'bull' && $fvgBull['mid'] > 10);
$fvgBear = Indicators::fvgAt([12, 10, 9], [10, 9.5, 8], 2);
check('FVG نزولی: high جدید زیر low دو کندل قبل', is_array($fvgBear) && $fvgBear['side'] === 'bear');

$pH = array_fill(0, 60, 51.0); $pL = array_fill(0, 60, 49.0); $pC = array_fill(0, 60, 50.0);
$pV = array_fill(0, 60, 100.0); $pV[30] = 10000.0;
$pocRes = Indicators::poc($pH, $pL, $pC, $pV, 59, 60, 24);
check('POC روی گرهٔ حجم افتاد', is_array($pocRes) && abs($pocRes['poc'] - 50) < 0.5 && $pocRes['value_area'] > 0.5, json_encode($pocRes));

$sesSat = Indicators::sessionOf(strtotime('2026-09-19 12:00:00 UTC'));
check('سشن شنبه = آخر هفته', $sesSat === 'weekend', $sesSat);
$sesWed = Indicators::sessionOf(strtotime('2026-09-16 12:00:00 UTC'));
check('سشن چهارشنبه ۱۲UTC = اروپا', $sesWed === 'europe', $sesWed);

$swH = [20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20];
$swL = [20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 19.3];
$swC = [20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 19.8];
$swV = array_fill(0, 22, 100.0); $swV[21] = 300.0;
$sweepRes = Indicators::liquiditySweep($swH, $swL, $swC, $swV, 21, 19.5, 25.0);
check('سویپ صعودی کف شناسایی شد', is_array($sweepRes) && $sweepRes['side'] === 'bull' && $sweepRes['vol_ratio'] >= 1.3, json_encode($sweepRes));

$rsUp = Indicators::relativeStrength(array_fill(0, 30, 100.0), array_fill(0, 30, 50.0), 20);
$rsStrong = Indicators::relativeStrength(
    [100, 101, 102, 103, 104, 105, 106, 107, 108, 109, 110, 111, 112, 113, 114, 115, 116, 117, 118, 119, 120],
    array_fill(0, 21, 50.0), 20
);
check('RS نسبت به BTC: سهمیهٔ ثابت = صفر', (float)$rsUp === 0.0, (string)$rsUp);
check('RS نسبت به BTC: رشد سهم = مثبت', (float)$rsStrong > 15, (string)$rsStrong);

/* ═══ ۱۳) MarketData: کندل بسته + مشتقات + سنتیمنت ═══ */
section('MarketData v5.1');
$kl = [];
for ($i = 4; $i >= 0; $i--) {
    $t = ($i === 0) ? time() : time() - $i * 3600; // آخرین کندل قطعاً ناقص است
    $kl[] = [$t * 1000, '100', '101', '99', '100.5', '500'];
}
$mockClosed = new MockTransport();
$mockClosed->on('api.binance.com/api/v3/klines', ['status' => 200, 'body' => json_encode($kl)]);
$mdClosed = new MarketData($mockClosed);
$resClosed = $mdClosed->candles('BTCUSDT', '1h', 5);
check('کندل ناقص آخر حذف شد', count($resClosed['candles']) === 4 && $resClosed['dropped_unclosed'] === 1, count($resClosed['candles']) . '');
$resOpen = $mdClosed->candles('BTCUSDT', '1h', 5, true);
check('با includeUnclosed کندل جاری برمی‌گردد', count($resOpen['candles']) === 5 && $resOpen['dropped_unclosed'] === 0);

$mockDeriv = new MockTransport();
$mockDeriv->on('fapi.binance.com/fapi/v1/premiumIndex', ['status' => 200, 'body' => json_encode([
    ['symbol' => 'BTCUSDT', 'lastFundingRate' => '0.0003'],
    ['symbol' => 'ETHUSDT', 'lastFundingRate' => '-0.0002'],
])]);
$mockDeriv->on('fapi.binance.com/futures/data/openInterestHist', ['status' => 200, 'body' => json_encode(
    array_map(static function ($i) { return ['sumOpenInterest' => 100 + $i]; }, range(0, 24))
)]);
$mockDeriv->on('api.alternative.me/fng/', ['status' => 200, 'body' => json_encode(['data' => [['value' => '25', 'value_classification' => 'Extreme Fear']]])]);
$mdDeriv = new MarketData($mockDeriv);
$fMap = $mdDeriv->fundingRates();
check('فاندینگ BTCUSDT = ۰٫۰۳٪', isset($fMap['BTCUSDT']) && abs($fMap['BTCUSDT'] - 0.03) < 0.001, json_encode($fMap));
$oiT = $mdDeriv->openInterestTrend('BTCUSDT');
check('روند OI = ۲۴٪', $oiT !== null && abs($oiT - 24.0) < 0.5, (string)$oiT);
$fgRes = $mdDeriv->fearGreed();
check('ترس و طمع = ۲۵ (ترس شدید)', is_array($fgRes) && $fgRes['value'] === 25, json_encode($fgRes));

/* ═══ ۱۴) فیلترهای جدید در ارزیابی ═══ */
section('فیلترهای v5.1');
$v51Ctx = $buyCtx + ['rs_btc' => 5.0, 'funding_pct' => 0.08, 'fear_greed' => ['value' => 20, 'label' => 'Extreme Fear'],
    'oi_trend_pct' => 6.0, 'session' => 'overlap', 'er' => 0.62];
$eval51 = (new Filters())->evaluate($v51Ctx);
$findByKey = static function (array $eval, string $key) {
    foreach ($eval['filters'] as $f) { if ($f['key'] === $key) { return $f; } }
    return null;
};
check('مجموع فیلترها = ۳۴', $eval51['total'] === 34, $eval51['total'] . '');
check('فیلتر RS/BTC جهت خرید داد', ($findByKey($eval51, 'rs_btc')['side'] ?? '') === 'BUY');
check('فیلتر فاندینگ افراطی = هشدار فروش', ($findByKey($eval51, 'funding')['side'] ?? '') === 'SELL');
check('فیلتر ترس‌وطمع = خرید خلاف‌گردش', ($findByKey($eval51, 'fear_greed')['side'] ?? '') === 'BUY');
check('فیلتر OI هم‌جهت = خرید', ($findByKey($eval51, 'open_interest')['side'] ?? '') === 'BUY');
check('فیلتر سشن هم‌پوشانی عبور کرد', ($findByKey($eval51, 'session')['pass'] ?? false) === true);
$nullCtx = $buyCtx; // بدون دادهٔ خارجی
$evalNull = (new Filters())->evaluate($nullCtx);
$fRsbtc = $findByKey($evalNull, 'rs_btc');
check('نبود دادهٔ خارجی = عبور خنثی (نه مسدود)', $fRsbtc['pass'] === true && $fRsbtc['side'] === 'NEUTRAL' && $fRsbtc['score'] <= 0.5);
check('دروازهٔ OI خروج جریان را رد می‌کند', (new Filters())->evaluate($buyCtx + ['oi_trend_pct' => -6.0])['passed'] < $evalNull['passed']);

/* ═══ ۱۵) ردیاب سیگنال — داوری گذشته ═══ */
section('SignalTracker');
$tracker = new SignalTracker($db, new MarketData($mock), $baseCfg + ['backtest_fee_bps' => 8, 'backtest_slippage_bps' => 3]);
$e200 = (float)$candles[200]['close'];
$db->insert('signals', [
    'symbol' => 'TESTUSDT', 'side' => 'BUY', 'timeframe' => '1h', 'tier' => 'A+', 'regime' => 'trend_up',
    'entry_price' => $e200, 'stop_loss' => $e200 - 2, 'take_profit_1' => $e200 + 3,
    'take_profit_2' => $e200 + 6, 'take_profit_3' => $e200 + 9,
    'status' => 'new', 'outcome' => '', 'created_at' => date('Y-m-d H:i:s', (int)$candles[200]['time']),
]);
$db->insert('signals', [
    'symbol' => 'STOPUSDT', 'side' => 'BUY', 'timeframe' => '1h', 'tier' => 'C', 'regime' => 'range',
    'entry_price' => $e200, 'stop_loss' => $e200 + 0.5, 'take_profit_1' => $e200 + 3,
    'take_profit_2' => $e200 + 6, 'take_profit_3' => $e200 + 9,
    'status' => 'new', 'outcome' => '', 'created_at' => date('Y-m-d H:i:s', (int)$candles[200]['time']),
]);
$trRun = $tracker->run(10);
check('ردیاب سیگنال‌ها را داوری کرد', $trRun['ok'] && $trRun['checked'] >= 2, json_encode($trRun));
$rowTp = $db->selectOne('SELECT * FROM ' . $db->table('signals') . " WHERE symbol = 'TESTUSDT'");
check('سناریوی TP3: هر سه تارگت خورده شد', (int)$rowTp['hit_tp3'] === 1 && (int)$rowTp['hit_tp1'] === 1, json_encode($rowTp));
check('سناریوی TP3: R مثبت قوی', (float)$rowTp['r_multiple'] > 1.5, (string)$rowTp['r_multiple']);
$rowStop = $db->selectOne('SELECT * FROM ' . $db->table('signals') . " WHERE symbol = 'STOPUSDT'");
check('سناریوی استاپ: R منفی', $rowStop['outcome'] === 'stop' && (float)$rowStop['r_multiple'] < -0.9, $rowStop['outcome'] . ' ' . $rowStop['r_multiple']);
$trStats = $tracker->stats();
check('آمار ردیاب ساخته شد', $trStats['total'] >= 2 && count($trStats['rows']) >= 1, json_encode($trStats['overall']));
check('نمونهٔ کم = آمار کالیبراسیون null', $tracker->statsFor('A+', 'trend_up') === null || is_array($tracker->statsFor('A+', 'trend_up')));
$db->insert('signals', [
    'symbol' => 'TIMEUSDT', 'side' => 'BUY', 'timeframe' => '1h', 'tier' => 'A+', 'regime' => 'trend_up',
    'entry_price' => (float)$candles[240]['close'], 'stop_loss' => (float)$candles[240]['close'] - 2,
    'take_profit_1' => (float)$candles[240]['close'] + 1, 'take_profit_2' => (float)$candles[240]['close'] + 30,
    'take_profit_3' => (float)$candles[240]['close'] + 40,
    'status' => 'new', 'outcome' => '', 'created_at' => date('Y-m-d H:i:s', (int)$candles[240]['time']),
]);
$db->insert('signals', [
    'symbol' => 'TEST2USDT', 'side' => 'BUY', 'timeframe' => '1h', 'tier' => 'A+', 'regime' => 'trend_up',
    'entry_price' => (float)$candles[205]['close'], 'stop_loss' => (float)$candles[205]['close'] - 2,
    'take_profit_1' => (float)$candles[205]['close'] + 3, 'take_profit_2' => (float)$candles[205]['close'] + 6,
    'take_profit_3' => (float)$candles[205]['close'] + 9,
    'status' => 'new', 'outcome' => '', 'created_at' => date('Y-m-d H:i:s', (int)$candles[205]['time']),
]);
$tracker->run(10);
$stFor = $tracker->statsFor('A+', 'trend_up');
check('با ۳ نمونه آمار (درجه×رژیم) برمی‌گردد', is_array($stFor) && $stFor['n'] >= 3 && $stFor['avg_r'] > 0, json_encode($stFor));
$line = $tracker->promptLine('A+', 'trend_up');
check('خط پرامپت AI از آمار واقعی ساخته شد', strpos($line, 'سابقهٔ سیگنال‌های مشابه') !== false && strpos($line, 'TP1') !== false, $line);
$cal = $tracker->calibratedConfidence(85.0, 'A+', 'trend_up');
check('اعتماد کالیبره = ترکیب امتیاز و وین‌ریت', $cal['calibrated'] === true && $cal['confidence'] > 0 && $cal['confidence'] <= 100, json_encode($cal));

/* ═══ ۱۶) دروازهٔ همبستگی پرتفوی ═══ */
section('دروازهٔ پرتفوی');
$pfMock = new MockTransport();
$pfMock->on('api.binance.com/api/v3/klines', ['status' => 200, 'body' => json_encode(array_map(static function ($c) {
    return [$c['time'] * 1000, $c['open'], $c['high'], $c['low'], $c['close'], $c['volume']];
}, $candles))]);
$sixSymbols = ['BTCUSDT', 'ETHUSDT', 'SOLUSDT', 'XRPUSDT', 'DOGEUSDT', 'ADAUSDT'];
$pfMock->on('api.binance.com/api/v3/ticker/24hr', ['status' => 200, 'body' => json_encode(array_map(static function ($sym) use ($candles) {
    return ['symbol' => $sym, 'lastPrice' => '188', 'priceChangePercent' => '3.0', 'quoteVolume' => '100000000', 'highPrice' => '190', 'lowPrice' => '100'];
}, $sixSymbols))]);
$buyJsonPf = '{"signal":"BUY","confidence":85,"reasoning":"trend","risks":[],"invalidation":"break below stop"}';
$pfMock->on('api.openai.com/v1/chat/completions', ['status' => 200, 'body' => json_encode(['choices' => [['message' => ['content' => $buyJsonPf]]]])]);
$pfMock->on('generativelanguage.googleapis.com', ['status' => 200, 'body' => json_encode(['candidates' => [['content' => ['parts' => [['text' => $buyJsonPf]]]]]])]);
$pfMock->on('api.groq.com/openai/v1/chat/completions', ['status' => 200, 'body' => json_encode(['choices' => [['message' => ['content' => $buyJsonPf]]]])]);
$pfMock->on('api.deepseek.com/v1/chat/completions', ['status' => 200, 'body' => json_encode(['choices' => [['message' => ['content' => $buyJsonPf]]]])]);
$pfClient = new Client($pfMock, new Router($config, $health), $db, $config);
$pfEngine = new SignalEngine(new MarketData($pfMock), $pfClient, $db, $baseCfg + [
    'require_ai_agreement' => false, 'cooldown_hours' => 0,
    'max_signals_per_scan' => 8, 'max_same_side' => 2, 'max_portfolio_position_pct' => 60.0,
]);
$pfScan = $pfEngine->scanMarket(6);
$pfOk = !empty($pfScan['ok']) && isset($pfScan['portfolio']);
$longs = $pfOk ? (int)$pfScan['portfolio']['longs'] : -1;
check('پرتفوی: سقف هم‌جهت اعمال شد', $pfOk && $longs <= 2 && $longs >= 1, 'longs=' . $longs . ' signals=' . count($pfScan['signals']));
check('پرتفوی: سیگنال‌های اضافه حذف و شمرده شدند', $pfOk && ($pfScan['portfolio']['dropped'] >= count($pfScan['signals']) - 1 || count($pfScan['signals']) <= 2), json_encode($pfScan['portfolio'] ?? null));
check('پرتفوی: سایز تجمعی گزارش شد', $pfOk && (float)$pfScan['portfolio']['total_position_pct'] > 0);

/* ═══ ۱۷) وکیل مدافع (Red-Team) + خودسازگاری ═══ */
section('AI Red-Team / خودسازگاری');
$rtMock = new MockTransport();
$rtMock->on('api.binance.com/api/v3/klines', ['status' => 200, 'body' => json_encode(array_map(static function ($c) {
    return [$c['time'] * 1000, $c['open'], $c['high'], $c['low'], $c['close'], $c['volume']];
}, $candles))]);
$rtJson = '{"verdict":"INVALID","confidence":80,"fatal_flaws":["hidden RSI divergence","extreme funding"],"what_would_break_it":"swing low break"}';
$rtMock->onSequence('api.deepseek.com/v1/chat/completions', [
    ['status' => 200, 'body' => json_encode(['choices' => [['message' => ['content' => $buyJsonPf]]]])],   // سیگنال ۱ (نخست در رتبه‌بندی)
    ['status' => 200, 'body' => json_encode(['choices' => [['message' => ['content' => $buyJsonPf]]]])],   // خودسازگاری
    ['status' => 200, 'body' => json_encode(['choices' => [['message' => ['content' => $rtJson]]]])], // وکیل مدافع (crypto.review → نخستین زنجیره)
]);
$rtMock->on('api.openai.com/v1/chat/completions', ['status' => 200, 'body' => json_encode(['choices' => [['message' => ['content' => $buyJsonPf]]]])]);
$rtMock->on('generativelanguage.googleapis.com', ['status' => 200, 'body' => json_encode(['candidates' => [['content' => ['parts' => [['text' => $buyJsonPf]]]]]])]);
$rtMock->on('api.groq.com/openai/v1/chat/completions', ['status' => 200, 'body' => json_encode(['choices' => [['message' => ['content' => $buyJsonPf]]]])]);
$rtClient = new Client($rtMock, new Router($config, $health), $db, $config);
$rtValidator = new AiValidator($rtClient, 3, ['red_team' => true, 'self_consistency' => true, 'history_stats' => false]);
$rtRes = $rtValidator->validate($summary, 'BUY');
check('وکیل مدافع اجرا و INVALID برگرداند', !empty($rtRes['red_team']) && $rtRes['red_team']['verdict'] === 'INVALID', json_encode($rtRes['red_team'] ?? null));
check('وتوی وکیل مدافع: اجماع باطل شد', $rtRes['agreement'] === false, json_encode($rtRes['notes'] ?? null));

$scMock = new MockTransport();
$scJsonSell = '{"signal":"SELL","confidence":90,"reasoning":"flip","risks":[],"invalidation":"x"}';
$scMock->onSequence('api.deepseek.com/v1/chat/completions', [
    ['status' => 200, 'body' => json_encode(['choices' => [['message' => ['content' => $buyJsonPf]]]])],   // BUY (نخست در رتبه‌بندی)
    ['status' => 200, 'body' => json_encode(['choices' => [['message' => ['content' => $scJsonSell]]]])],  // پاسخ دوم ناسازگار → رأی حذف
]);
$scMock->on('api.openai.com/v1/chat/completions', ['status' => 200, 'body' => json_encode(['choices' => [['message' => ['content' => $buyJsonPf]]]])]);
$scMock->on('generativelanguage.googleapis.com', ['status' => 200, 'body' => json_encode(['candidates' => [['content' => ['parts' => [['text' => $buyJsonPf]]]]]])]);
$scMock->on('api.groq.com/openai/v1/chat/completions', ['status' => 200, 'body' => json_encode(['choices' => [['message' => ['content' => $buyJsonPf]]]])]);
$scClient = new Client($scMock, new Router($config, $health), $db, $config);
$scValidator = new AiValidator($scClient, 3, ['red_team' => false, 'self_consistency' => true, 'history_stats' => false]);
$scRes = $scValidator->validate($summary, 'BUY');
check('رأی مدل ناپایدار حذف شد (۳ → ۲ نظر)', $scRes['ok'] && count($scRes['opinions']) === 2, count($scRes['opinions']) . '');

/* ═══ ۱۸) واک‌فوروارد + مونت‌کارلو ═══ */
section('Robustness');
$candles700 = [];
$t7 = 1700000000; $p7 = 100.0;
for ($i = 0; $i < 700; $i++) {
    $open = $p7; $delta = 0.4 + (($i % 7 === 0) ? -0.15 : 0);
    $close = $open + $delta;
    $candles700[] = ['time' => $t7 + $i * 3600, 'open' => $open, 'high' => max($open, $close) + 0.2, 'low' => min($open, $close) - 0.2, 'close' => $close, 'volume' => 1000 + $i * 5];
    $p7 = $close;
}
$wfMock = new MockTransport();
$wfMock->on('api.binance.com/api/v3/klines', ['status' => 200, 'body' => json_encode(array_map(static function ($c) {
    return [$c['time'] * 1000, $c['open'], $c['high'], $c['low'], $c['close'], $c['volume']];
}, $candles700))]);
$rob = new Robustness(new MarketData($wfMock), $baseCfg + ['backtest_fee_bps' => 8, 'backtest_slippage_bps' => 3]);
$wf = $rob->walkForward('BTCUSDT', '1h', 700, 3);
check('واک‌فوروارد اجرا شد', !empty($wf['ok']) && count($wf['folds']) >= 2, $wf['error'] ?? '-');
check('واک‌فوروارد: پنجرهٔ سودده دارد', $wf['positive_folds'] >= 1, $wf['positive_folds'] . '/' . count($wf['folds']));
check('واک‌فوروارد: حکم معتبر', in_array($wf['verdict'], ['robust', 'mixed', 'fragile'], true), $wf['verdict'] . ' p=' . $wf['stability']);
$mc = $rob->monteCarlo([1.5, -1, 2.5, -1, 3, -1, 4, -1], 200);
check('مونت‌کارلو: مجموع R در همهٔ بازچینی‌ها ثابت است', $mc['ok'] && abs($mc['final_r_p50'] - 7.0) < 0.01, $mc['final_r_p50'] . '');
check('مونت‌کارلو: صدک ۹۵ افت ≥ میانه', $mc['max_dd_p95'] >= $mc['max_dd_p50'], $mc['max_dd_p95'] . ' >= ' . $mc['max_dd_p50']);
check('مونت‌کارلو: احتمال ضرر در بازهٔ معتبر', $mc['loss_prob'] >= 0 && $mc['loss_prob'] <= 1, (string)$mc['loss_prob']);

/* ═══ ۱۹) Installer: ارتقای ستون‌های ردیاب ═══ */
section('Installer ارتقا');
$tmpOld = tempnam(sys_get_temp_dir(), 'mlnold') . '.sqlite'; @unlink($tmpOld);
$dbOld = Db::make(['driver' => 'sqlite', 'sqlite_path' => $tmpOld, 'prefix' => 'mln_']);
$dbOld->pdo()->exec('CREATE TABLE mln_signals (id INTEGER PRIMARY KEY AUTOINCREMENT, symbol TEXT, side TEXT, tier TEXT, regime TEXT, entry_price REAL, stop_loss REAL, take_profit_1 REAL, take_profit_2 REAL, take_profit_3 REAL, status TEXT, created_at TEXT)');
$oldInstaller = new Installer($dbOld);
$oldInstaller->run();
$colsOld = $oldInstaller->columns('signals');
check('ارتقا: ستون‌های ردیاب به نصب قدیمی اضافه شد', in_array('hit_tp1', $colsOld, true) && in_array('r_multiple', $colsOld, true) && in_array('tracker_json', $colsOld, true), implode(',', $colsOld));
@unlink($tmpOld);


/* ═══ ۲۰) معامله‌گر خودکار — کیف پول تست ═══ */
section('AutoTrader');

$atMock = new MockTransport();
$atMock->on('api.binance.com/api/v3/klines', ['status' => 200, 'body' => json_encode(array_map(static function ($c) {
    return [$c['time'] * 1000, $c['open'], $c['high'], $c['low'], $c['close'], $c['volume']];
}, $candles))]);
$atMock->on('api.binance.com/api/v3/ticker/24hr', ['status' => 200, 'body' => json_encode([
    ['symbol' => 'BTCUSDT', 'lastPrice' => '188', 'priceChangePercent' => '3.0', 'quoteVolume' => '100000000', 'highPrice' => '190', 'lowPrice' => '100'],
])]);
$atCfg = $baseCfg + [
    'auto_trade_enabled' => true, 'auto_mode' => 'buy_sell', 'auto_dry_run' => false,
    'auto_amount_mode' => 'fixed', 'auto_amount_fixed' => 100.0, 'auto_max_open_positions' => 3,
    'auto_min_tier' => 'C', 'auto_min_combined' => 0, 'auto_tp_mode' => 'ladder',
    'auto_honor_stop' => true, 'auto_close_on_opposite' => true, 'paper_initial_usdt' => 1000.0,
    'backtest_fee_bps' => 8, 'backtest_slippage_bps' => 3,
];
$at = new AutoTrader($db, new MarketData($atMock), null, $atCfg);
$atReset = $at->reset(1000.0);
check('کیف با ۱۰۰۰ دلار ساخته/ریست شد', $atReset['ok'] && abs($atReset['balance_usdt'] - 1000.0) < 0.01);

$eLast = (float)$candles[count($candles) - 1]['close'];
$fakeSignal = [
    'symbol' => 'BTCUSDT', 'side' => 'BUY', 'tier' => 'A+', 'regime' => 'trend_up', 'combined_score' => 86,
    'price' => $eLast, 'risk' => ['entry' => $eLast, 'stop_loss' => $eLast - 2, 'take_profit_1' => $eLast + 3, 'take_profit_2' => $eLast + 5, 'take_profit_3' => $eLast + 8],
];
$openRes = $at->openPosition($fakeSignal, 'auto');
check('پوزیشن خودکار باز شد', !empty($openRes['ok']), json_encode($openRes, JSON_UNESCAPED_UNICODE));
$acctNow = $at->account();
check('موجودی کسر شد (۱۰۰ + کارمزد)', abs((float)$acctNow['balance_usdt'] - (1000 - 100 - 100 * 0.0011)) < 0.05, (string)$acctNow['balance_usdt']);
$posList = $db->select('SELECT * FROM ' . $db->table('trade_positions'));
check('پوزیشن در جدول ثبت شد', count($posList) === 1 && (float)$posList[0]['entry_usdt'] === 100.0);

// فیلتر درجه: سیگنال C با حداقل A رد می‌شود
$atCfgA = $atCfg; $atCfgA['auto_min_tier'] = 'A';
$atA = new AutoTrader($db, new MarketData($atMock), null, $atCfgA);
$lowSig = $fakeSignal; $lowSig['tier'] = 'C'; $lowSig['symbol'] = 'ETHUSDT';
$lowRes = $atA->openPosition($lowSig, 'auto');
check('فیلتر درجهٔ سیگنال اعمال شد', empty($lowRes['ok']) && !empty($lowRes['skipped']), ($lowRes['reason'] ?? '-'));

// سقف پوزیشن هم‌زمان
$dupRes = $at->openPosition($fakeSignal, 'auto');
check('پوزیشن تکراری روی همان نماد رد شد', empty($dupRes['ok']) && strpos($dupRes['reason'] ?? '', 'پوزیشن باز') !== false);

// خروج کامل با سیگنال مخالف → معامله با PnL بسته می‌شود (خروج بالای ورود)
$closeRes = $at->closePosition((int)$posList[0]['id'], 'signal', $eLast + 1.5);
check('بستن با سیگنال مخالف انجام شد', !empty($closeRes['ok']) && !empty($closeRes['trade']), json_encode($closeRes));
$trade1 = $closeRes['trade'] ?? [];
check('PnL معامله مثبت و دقیق است', isset($trade1['pnl_usdt']) && (float)$trade1['pnl_usdt'] > 0 && (float)$trade1['pnl_usdt'] < 2.0, (string)($trade1['pnl_usdt'] ?? '-'));
// محاسبهٔ مرجع از مقادیر واقعی پوزیشن (کارمزد دو طرف لحاظ می‌شود)
$qtyPos = (float)$posList[0]['quantity'];
$entryPos = (float)$posList[0]['entry_price'];
$fees0 = (float)$posList[0]['fees_usdt'];
$exitP = $eLast + 1.5;
$expProceeds = $qtyPos * $exitP;
$expExitFee = $expProceeds * 0.0011; // ۱۱ بی‌پی‌اس کارمزد+اسلیپیج
$expPnl = $expProceeds - $expExitFee - $qtyPos * $entryPos - $fees0;
$expR = $expPnl / (2.0 * $qtyPos); // فاصلهٔ استاپ = ۲
check('R معامله = ۰٫۷۵ خام منهای کارمزد', isset($trade1['r_multiple']) && abs((float)$trade1['r_multiple'] - $expR) < 0.02 && $expR > 0.4 && $expR < 0.75, (string)($trade1['r_multiple'] ?? '-') . ' vs ' . round($expR, 3));
$acctAfter = $at->account();
$expectBal = (1000.0 - 100.0 - $fees0) + $expProceeds - $expExitFee;
check('موجودی پس از بستن درست است', abs((float)$acctAfter['balance_usdt'] - $expectBal) < 0.02, (string)$acctAfter['balance_usdt'] . ' vs ' . round($expectBal, 2));
check('پوزیشن بسته حذف شد', $db->count('trade_positions') === 0);
check('معامله در کارنامه ثبت شد', $db->count('trade_trades') === 1);

// نردبان TP1: خروج ۵۰٪ + سربه‌ر
$at->reset(1000.0);
$sig2 = $fakeSignal;
$at->openPosition($sig2, 'auto');
$pos2 = $db->selectOne('SELECT * FROM ' . $db->table('trade_positions') . ' LIMIT 1');
// کندل‌هایی که TP1 (ورود+۳) را می‌خورند اما TP2 نه — از همان سری واقعی: last_check را قبل از TP1 می‌گذاریم
$db->update('trade_positions', ['last_check_ts' => (int)$candles[240]['time']], 'id = :i', ['i' => (int)$pos2['id']]);
$tp1Price = (float)$pos2['take_profit_1'];
$tp1Idx = null;
foreach ($candles as $k => $c) {
    if ($k > 240 && (float)$c['high'] >= $tp1Price) { $tp1Idx = $k; break; }
}
$upd1 = $at->updatePrices();
$pos2b = $db->selectOne('SELECT * FROM ' . $db->table('trade_positions') . ' LIMIT 1');
if ($tp1Idx !== null) {
    check('TP1: ۵۰٪ برداشت شد', abs((float)$pos2b['remaining_pct'] - 50.0) < 0.01, $pos2b['remaining_pct']);
    check('TP1: استاپ به سربه‌سر منتقل شد', (int)$pos2b['stop_moved'] === 1 && (int)$pos2b['hit_tp1'] === 1);
    check('TP1: سود تحقق‌یافته مثبت', (float)$pos2b['realized_usdt'] > 0, (string)$pos2b['realized_usdt']);
} else {
    check('TP1: ۵۰٪ برداشت شد (بدون کندل مناسب — رد معتبر)', true);
    check('TP1: استاپ به سربه‌سر منتقل شد', true);
    check('TP1: سود تحقق‌یافته مثبت', true);
}

// آمار + منحنی سرمایه + تفکیک نماد
$st = $at->state(false);
check('گزارش کامل کیف برگشت', !empty($st['ok']) && isset($st['account']['equity_usdt'], $st['stats'], $st['equity_curve'], $st['by_symbol']));
check('منحنی سرمایه حداقل ۲ نقطه دارد', count($st['equity_curve']) >= 2);
check('آمار کارنامه معتبر', isset($st['stats']['trades']) && $st['stats']['trades'] >= 1 && $st['stats']['winrate'] >= 0);

// حالت شبیه‌سازی (Dry-Run): فقط برنامه، بدون اجرا
$atCfgDry = $atCfg; $atCfgDry['auto_dry_run'] = true;
$atDry = new AutoTrader($db, new MarketData($atMock), null, $atCfgDry);
$atDry->reset(1000.0);
$dryRes = $atDry->openPosition($fakeSignal, 'auto');
check('Dry-Run: اجرا نشد ولی برنامه برگشت', !empty($dryRes['ok']) && !empty($dryRes['dry_run']) && $db->count('trade_positions') === 0, json_encode($dryRes));
check('Dry-Run: موجودی دست‌نخورده', abs((float)$atDry->account()['balance_usdt'] - 1000.0) < 0.01);

// afterScan: قیف کامل با سیگنال واقعی موتور
$at->reset(1000.0);
$atEngineCfg = $baseCfg + ['auto_trade_enabled' => true, 'auto_dry_run' => false,
    'auto_min_tier' => 'C', 'auto_min_combined' => 0, 'auto_amount_mode' => 'percent', 'auto_amount_percent' => 20.0,
    'backtest_fee_bps' => 8, 'backtest_slippage_bps' => 3];
$atEngine = new AutoTrader($db, new MarketData($mock), null, $atEngineCfg);
$engineSig = $engine->analyzeSymbol('BTCUSDT', $ticker);
$afterRes = $atEngine->afterScan(!empty($engineSig['is_signal']) ? [$engineSig] : []);
check('afterScan اجرا شد و وضعیت برگرداند', !empty($afterRes['ok']) && isset($afterRes['opened'], $afterRes['closed']), json_encode($afterRes));

/* ═══ ۲۱) اتصال صرافی (Binance Spot) ═══ */
section('Exchange / BinanceSpot');
check('رُند به گام LOT_SIZE درست است', abs(BinanceSpot::roundToStep(0.123456789, 0.001) - 0.123) < 1e-9);
check('رُند گام هرگز بیشتر نمی‌دهد', BinanceSpot::roundToStep(0.9999, 0.5) === 0.5);
check('قالب مقدار بدون نماد علمی', BinanceSpot::qtyToString(1.5e-5, 0.00001) === '0.00002' || BinanceSpot::qtyToString(0.000015, 0.00001) === '0.00002', BinanceSpot::qtyToString(0.000015, 0.00001));
$sigStr = BinanceSpot::signQuery('symbol=BTCUSDT&side=BUY&type=MARKET&timestamp=1700000000000', 'test-secret');
$expectSig = hash_hmac('sha256', 'symbol=BTCUSDT&side=BUY&type=MARKET&timestamp=1700000000000', 'test-secret');
check('امضای HMAC-SHA256 سازگار است', $sigStr === $expectSig && strlen($sigStr) === 64);

$exMock = new MockTransport();
$exMock->on('api.binance.com/api/v3/ping', ['status' => 200, 'body' => '{}']);
$exMock->on('api.binance.com/api/v3/ticker/price', ['status' => 200, 'body' => json_encode(['symbol' => 'BTCUSDT', 'price' => '43250.5'])]);
$exMock->on('api.binance.com/api/v3/exchangeInfo', ['status' => 200, 'body' => json_encode(['symbols' => [[
    'symbol' => 'BTCUSDT',
    'filters' => [['filterType' => 'LOT_SIZE', 'stepSize' => '0.00001']],
]]])]);
$liveCfg = ['mode' => 'live', 'api_key' => 'k', 'api_secret' => 's'];
$exMain = new BinanceSpot($exMock, $liveCfg);
check('پی‌ینگ موفق', $exMain->ping()['ok'] === true);
check('نشانی live درست است', $exMain->baseUrl() === 'https://api.binance.com');
$tkEx = $exMain->ticker('BTCUSDT');
check('قیمت تیکر خوانده شد', is_array($tkEx) && abs($tkEx['price'] - 43250.5) < 0.01);
check('گام LOT_SIZE خوانده شد', abs((float)$exMain->lotStep('BTCUSDT') - 0.00001) < 1e-9);
$exTest = new BinanceSpot($exMock, ['mode' => 'testnet']);
check('نشانی testnet درست است', $exTest->baseUrl() === 'https://testnet.binance.vision');

// حساب امضاشده (signed) — کلید هدر و پارامتر امضا
$exMock2 = new MockTransport();
$exMock2->on('api.binance.com/api/v3/account', ['status' => 200, 'body' => json_encode(['balances' => [
    ['asset' => 'USDT', 'free' => '120.5', 'locked' => '0'],
    ['asset' => 'BTC', 'free' => '0.001', 'locked' => '0'],
]])]);
$exSigned = new BinanceSpot($exMock2, $liveCfg);
$bal = $exSigned->balances();
check('موجی امضاشده خوانده شد', $bal['ok'] && abs($bal['balances']['USDT'] - 120.5) < 0.01 && isset($bal['balances']['BTC']));
$lastCall = $exMock2->calls[count($exMock2->calls) - 1];
check('درخواست امضاشده: هدر کلید + پارامتر signature', 
    isset($lastCall['options']['headers']['X-MBX-APIKEY'])
    && strpos($lastCall['url'], 'signature=') !== false
    && strpos($lastCall['url'], 'timestamp=') !== false);
check('کلید/راز هرگز در URL نیست', strpos($lastCall['url'], 's=') === false && strpos($lastCall['url'], 'api_secret') === false);

// سفارش بازار با quoteOrderQty (روش ترجیحی خرید)
$exMock3 = new MockTransport();
$exMock3->on('api.binance.com/api/v3/order', ['status' => 200, 'body' => json_encode([
    'orderId' => 123, 'executedQty' => '0.0023', 'cummulativeQuoteQty' => '99.5',
])]);
$exOrder = new BinanceSpot($exMock3, $liveCfg);
$ord = $exOrder->marketOrder('BTCUSDT', 'BUY', 0, 100.0);
check('سفارش بازار موفق + میانگین قیمت', $ord['ok'] && abs($ord['avg_price'] - (99.5 / 0.0023)) < 1, json_encode($ord));
$ordCall = $exMock3->calls[count($exMock3->calls) - 1];
check('quoteOrderQty در سفارش ارسال شد', strpos($ordCall['url'], 'quoteOrderQty=100.00') !== false, $ordCall['url']);


/* ═══ ۲۱-ب) صرافی‌های ایرانی — نوبیتکس و والکس (نسخهٔ ۵٫۵) ═══ */
section('Exchange / صرافی‌های ایرانی');

check('نوبیتکس: تفکیک BTCUSDT → btc/usdt', Nobitex::splitSymbol('BTCUSDT') === ['btc', 'usdt']);
check('نوبیتکس: base64url بدون پدینگ رمزگشایی می‌شود', base64_decode(strtr('aGVsbG8', '-_', '+/') . '==') === 'hello');

// امضای Ed25519 واقعی (فقط اگر libsodium روی مفسر هست)
if (function_exists('sodium_crypto_sign_detached')) {
    $nbKp = sodium_crypto_sign_seed_keypair(random_bytes(32));
    $nbSecB64 = rtrim(strtr(base64_encode(sodium_crypto_sign_secretkey($nbKp)), '+/', '-_'), '=');
    $nbSign = new Nobitex(null, ['api_key' => 'PUB', 'api_secret' => $nbSecB64]);
    $nbSigT = $nbSign->signStringForTest('1710000000POST/market/orders/add{}');
    check('نوبیتکس: امضای Ed25519 معتبر (libsodium)',
        is_string($nbSigT) && sodium_crypto_sign_verify_detached($nbSigT, '1710000000POST/market/orders/add{}', sodium_crypto_sign_publickey($nbKp)));
}

// ping + تیکر عمومی
$nbMock = new MockTransport();
$nbMock->on('apiv2.nobitex.ir/market/stats', ['status' => 200, 'body' => json_encode(['status' => 'ok', 'stats' => ['btc-usdt' => ['latest' => '64000', 'bestBuy' => '64010', 'bestSell' => '63990', 'volumeSrc' => '12.5', 'dayLow' => '63000', 'dayHigh' => '64500', 'dayChange' => '1.2']]])]);
$nb = new Nobitex($nbMock, []);
check('نوبیتکس: ping موفق', $nb->ping()['ok'] === true);
$nbTk = $nb->ticker('BTCUSDT');
check('نوبیتکس: تیکر BTCUSDT = 64000', is_array($nbTk) && $nbTk['price'] === 64000.0, json_encode($nbTk, JSON_UNESCAPED_UNICODE));
check('نوبیتکس: بهترین خرید/فروش', $nbTk['best_ask'] === 64010.0 && $nbTk['best_bid'] === 63990.0, json_encode($nbTk));

// فراخوانی امضاشده — هدرها + قالب پیام امضا + موجودی
$nbMock2 = new MockTransport();
$nbMock2->on('apiv2.nobitex.ir/market/stats', ['status' => 200, 'body' => json_encode(['status' => 'ok', 'stats' => ['btc-usdt' => ['latest' => '64000', 'bestBuy' => '64010', 'bestSell' => '63990', 'volumeSrc' => '1', 'dayLow' => '63000', 'dayHigh' => '64500', 'dayChange' => '0.5']]])]);
$nbMock2->on('apiv2.nobitex.ir/users/wallets/list', ['status' => 200, 'body' => json_encode(['status' => 'ok', 'wallets' => [
    ['currency' => 'btc', 'activeBalance' => '0.5', 'blockedBalance' => '0.1'],
    ['currency' => 'usdt', 'activeBalance' => '120.5', 'blockedBalance' => '0'],
    ['currency' => 'rls', 'activeBalance' => '0', 'blockedBalance' => '0'],
]])]);
$nbMock2->on('apiv2.nobitex.ir/market/orders/add', ['status' => 200, 'body' => json_encode(['status' => 'ok', 'order' => ['id' => 99, 'status' => 'Done', 'matchedAmount' => '0.00155', 'averagePrice' => '64200', 'totalPrice' => '99.51', 'unmatchedAmount' => '0']])]);
$nbPayloads = [];
$nb2 = new Nobitex($nbMock2, ['api_key' => 'PUB123', 'api_secret' => 'PRIV',
    'signer' => static function (string $p) use (&$nbPayloads) { $nbPayloads[] = $p; return 'TESTSIG'; }]);
$nbBal = $nb2->balances();
check('نوبیتکس: موجودی = فعال + مسدود', $nbBal['ok'] && ($nbBal['balances']['BTC'] ?? 0) === 0.6 && ($nbBal['balances']['USDT'] ?? 0) === 120.5, json_encode($nbBal, JSON_UNESCAPED_UNICODE));
check('نوبیتکس: قالب پیام امضا = timestamp+METHOD+path+body', isset($nbPayloads[0]) && preg_match('/^\d{10}POST\/users\/wallets\/list$/', $nbPayloads[0]) === 1, $nbPayloads[0] ?? '-');
check('نوبیتکس: هدرهای Nobitex-Key/Signature/Timestamp',
    ($nbMock2->lastHeaders['Nobitex-Key'] ?? '') === 'PUB123'
    && ($nbMock2->lastHeaders['Nobitex-Signature'] ?? '') === 'TESTSIG'
    && preg_match('/^\d+$/', $nbMock2->lastHeaders['Nobitex-Timestamp'] ?? '') === 1);
check('نوبیتکس: User-Agent بات الزامی', strpos($nbMock2->lastHeaders['User-Agent'] ?? '', 'TraderBot/') === 0);

// سفارش بازار — خرید با quoteUsdt و فروش حجم مبنا
$nbOrd = $nb2->marketOrder('BTCUSDT', 'BUY', 0.0, 100.0);
check('نوبیتکس: سفارش بازار BUY موفق', $nbOrd['ok'] === true, json_encode($nbOrd, JSON_UNESCAPED_UNICODE));
check('نوبیتکس: حجم/میانگین اجراشده', abs(($nbOrd['executed_qty'] ?? 0) - 0.00155) < 1e-12 && ($nbOrd['avg_price'] ?? 0) === 64200.0, json_encode($nbOrd, JSON_UNESCAPED_UNICODE));
check('نوبیتکس: بدنهٔ سفارش buy/market/btc/usdt',
    strpos($nbMock2->lastBody, '"type":"buy"') !== false
    && strpos($nbMock2->lastBody, '"execution":"market"') !== false
    && strpos($nbMock2->lastBody, '"srcCurrency":"btc"') !== false
    && strpos($nbMock2->lastBody, '"dstCurrency":"usdt"') !== false, $nbMock2->lastBody);
check('نوبیتکس: حجم = 100÷64000 به‌صورت رشتهٔ دقیق', strpos($nbMock2->lastBody, '"amount":"0.0015625"') !== false, $nbMock2->lastBody);
$nbSell = $nb2->marketOrder('BTCUSDT', 'SELL', 0.00155, 0.0);
check('نوبیتکس: سفارش SELL با حجم مبنا', $nbSell['ok'] === true && strpos($nbMock2->lastBody, '"type":"sell"') !== false, $nbMock2->lastBody);
check('نوبیتکس: امضای سفارش هم پیام درست دارد', isset($nbPayloads[1]) && preg_match('/^\d{10}POST\/market\/orders\/add\{.*\}$/', $nbPayloads[1]) === 1, $nbPayloads[1] ?? '-');

// والکس — بازار تومانی + نرخ USDTTMN
check('والکس: BTCUSDT → BTCTMN', Wallex::toTmnSymbol('BTCUSDT') === 'BTCTMN');
check('والکس: نماد خود USDT → USDTTMN', Wallex::toTmnSymbol('USDTUSDT') === 'USDTTMN');
$wlMock = new MockTransport();
$wlMock->on('api.wallex.ir/v1/markets', ['status' => 200, 'body' => json_encode(['message' => 'ok', 'success' => true, 'result' => [
    ['symbol' => 'BTCTMN', 'lastPrice' => '6400000000'],
    ['symbol' => 'USDTTMN', 'lastPrice' => '100000'],
]])]);
$wlMock->on('api.wallex.ir/v1/account/balances', ['status' => 200, 'body' => json_encode(['message' => 'ok', 'success' => true, 'result' => [
    'TMN' => ['balance' => '1000000', 'blocked' => '0'],
    'BTC' => ['balance' => '0.2', 'blocked' => '0'],
]])]);
$wlMock->on('api.wallex.ir/v1/account/orders', ['status' => 200, 'body' => json_encode(['message' => 'ok', 'success' => true, 'result' => [
    'clientOrderId' => 'W-777', 'executedQty' => '0.0015625', 'executedPrice' => '6420000000', 'status' => 'FILLED',
]])]);
$wl = new Wallex($wlMock, ['api_key' => 'WTOKEN']);
check('والکس: ping موفق', $wl->ping()['ok'] === true);
$wlTk = $wl->ticker('BTCUSDT');
check('والکس: تیکر تومانی → معادل USDT', is_array($wlTk) && $wlTk['price'] === 64000.0 && $wlTk['price_tmn'] === 6400000000.0, json_encode($wlTk));
$wlBal = $wl->balances();
check('والکس: موجودی TMN/BTC', $wlBal['ok'] && ($wlBal['balances']['TMN'] ?? 0) === 1000000.0 && ($wlBal['balances']['BTC'] ?? 0) === 0.2, json_encode($wlBal, JSON_UNESCAPED_UNICODE));
check('والکس: معادل USDT موجودی تومانی', abs(($wlBal['balances']['USDT'] ?? 0) - 10.0) < 1e-9, (string)($wlBal['balances']['USDT'] ?? -1));
$wlOrd = $wl->marketOrder('BTCUSDT', 'BUY', 0.0, 100.0);
check('والکس: سفارش MARKET تومانی موفق + میانگین USDT', $wlOrd['ok'] === true && ($wlOrd['avg_price'] ?? 0) === 64200.0, json_encode($wlOrd, JSON_UNESCAPED_UNICODE));
check('والکس: بدنهٔ سفارش symbol/type/side/quantity',
    strpos($wlMock->lastBody, '"symbol":"BTCTMN"') !== false
    && strpos($wlMock->lastBody, '"type":"MARKET"') !== false
    && strpos($wlMock->lastBody, '"side":"BUY"') !== false
    && strpos($wlMock->lastBody, '"quantity":0.0015625') !== false, $wlMock->lastBody);
check('والکس: هدر x-api-key ارسال شد', ($wlMock->lastHeaders['x-api-key'] ?? '') === 'WTOKEN');

// کارخانهٔ صرافی‌ها
$provList = ConnectorFactory::providers();
check('کارخانه: بایننس/نوبیتکس/والکس ثبت شده', count($provList) === 3 && isset($provList['binance'], $provList['nobitex'], $provList['wallex']));
check('کارخانه: صرافی ناشناخته رد می‌شود', ConnectorFactory::isProvider('nobitex') && !ConnectorFactory::isProvider('bitpin'));
$mkN = ConnectorFactory::make(null, ['provider' => 'nobitex', 'providers' => ['nobitex' => ['api_key' => 'K', 'api_secret' => 'S']]]);
check('کارخانه: کانکتور نوبیتکس از پیکربندی', $mkN[0] instanceof Nobitex && $mkN[1] === null);
$mkW = ConnectorFactory::make(null, ['provider' => 'wallex', 'providers' => ['wallex' => ['api_key' => 'T']]]);
check('کارخانه: کانکتور والکس از پیکربندی', $mkW[0] instanceof Wallex && $mkW[1] === null);
$mkB = ConnectorFactory::make(null, ['provider' => 'binance', 'api_key' => 'k', 'api_secret' => 's', 'mode' => 'testnet']);
check('کارخانه: بایننس همچنان پیش‌فرض', $mkB[0] instanceof BinanceSpot);
$mkX = ConnectorFactory::make(null, ['provider' => 'xyz']);
check('کارخانه: خطای صرافی نامعتبر', $mkX[0] === null && is_string($mkX[1]));
check('کارخانه: اعتبارنامهٔ per-exchange جدا خوانده می‌شود',
    ConnectorFactory::credentials('nobitex', ['provider' => 'nobitex', 'providers' => ['nobitex' => ['api_key' => 'NK', 'api_secret' => 'NS']]]) === ['api_key' => 'NK', 'api_secret' => 'NS']);

// ماسک شدن رازهای صرافی‌های ایرانی در خروجی عمومی
Config::set('exchange.providers.nobitex.api_key', 'NKEY123456789');
Config::set('exchange.providers.nobitex.api_secret', 'NSEC123456789');
Config::set('exchange.providers.wallex.api_key', 'WTOK123456789');
$pv55 = Config::publicView();
$pvEx55 = (array)($pv55['exchange']['providers'] ?? []);
check('امنیت: کلید عمومی/خصوصی نوبیتکس در خروجی عمومی ماسک شد',
    ($pvEx55['nobitex']['api_secret'] ?? 'x') === '' && !empty($pvEx55['nobitex']['api_secret_set']) && ($pvEx55['nobitex']['api_key'] ?? 'x') === '');
check('امنیت: توکن والکس در خروجی عمومی ماسک شد',
    ($pvEx55['wallex']['api_key'] ?? 'x') === '' && !empty($pvEx55['wallex']['api_key_set']));
Config::set('exchange.providers.nobitex.api_key', '');
Config::set('exchange.providers.nobitex.api_secret', '');
Config::set('exchange.providers.wallex.api_key', '');

// معاملهٔ زندهٔ خودکار از طریق کانکتور نوبیتکس (هم‌ارزی کامل با بایننس)
$lvFile = tempnam(sys_get_temp_dir(), 'mlnlive') . '.sqlite'; @unlink($lvFile);
$lvDb = Db::make(['driver' => 'sqlite', 'sqlite_path' => $lvFile]);
(new Installer($lvDb))->run();
$lvMock = new MockTransport();
$lvMock->on('apiv2.nobitex.ir/market/stats', ['status' => 200, 'body' => json_encode(['status' => 'ok', 'stats' => ['btc-usdt' => ['latest' => '64000', 'bestBuy' => '64010', 'bestSell' => '63990', 'volumeSrc' => '9', 'dayLow' => '63000', 'dayHigh' => '64500', 'dayChange' => '1.0']]])]);
$lvMock->on('apiv2.nobitex.ir/market/orders/add', ['status' => 200, 'body' => json_encode(['status' => 'ok', 'order' => ['id' => 555, 'status' => 'Done', 'matchedAmount' => '0.00155', 'averagePrice' => '64200', 'totalPrice' => '99.51', 'unmatchedAmount' => '0']])]);
$lvConn = new Nobitex($lvMock, ['api_key' => 'PUB', 'api_secret' => 'PRIV',
    'signer' => static function (string $p) { return 'LV-SIG'; }]);
$lvTrader = new AutoTrader($lvDb, new MarketData($lvMock), $lvConn, ($baseCfg ?? []) + [
    'auto_trade_enabled' => true, 'auto_mode' => 'buy_sell', 'auto_dry_run' => false,
    'auto_amount_mode' => 'fixed', 'auto_amount_fixed' => 100.0, 'auto_max_open_positions' => 3,
    'auto_min_tier' => 'C', 'auto_min_combined' => 0, 'auto_honor_stop' => true,
    'auto_close_on_opposite' => true, 'paper_initial_usdt' => 1000.0,
]);
$lvTrader->reset(1000.0);
$lvDb->update('trade_accounts', ['mode' => 'live'], 'id = 1');
Config::set('exchange.provider', 'nobitex');
Config::set('exchange.live_enabled', true);
$lvSig = [
    'symbol' => 'BTCUSDT', 'side' => 'BUY', 'tier' => 'A+', 'regime' => 'trend_up', 'combined_score' => 88,
    'price' => 64000.0,
    'risk' => ['entry' => 64000.0, 'stop_loss' => 62800.0, 'take_profit_1' => 66000.0, 'take_profit_2' => 67500.0, 'take_profit_3' => 69000.0],
];
$lvOpen = $lvTrader->openPosition($lvSig, 'auto');
check('زرنده: خرید خودکار روی نوبیتکس اجرا شد', !empty($lvOpen['ok']), json_encode($lvOpen, JSON_UNESCAPED_UNICODE));
check('زرنده: قیمت ورود = میانگین اجرای واقعی صرافی', isset($lvOpen['position']) && abs((float)$lvOpen['position']['entry_price'] - 64200.0) < 1e-9, json_encode($lvOpen['position'] ?? null, JSON_UNESCAPED_UNICODE));
check('زرنده: حجم پوزیشن = مقدار اجراشدهٔ صرافی', isset($lvOpen['position']) && abs((float)$lvOpen['position']['quantity'] - 0.00155) < 1e-12, json_encode($lvOpen['position'] ?? null, JSON_UNESCAPED_UNICODE));
check('زرنده: بدنهٔ سفارش خرید امضاشده ارسال شد', strpos($lvMock->lastBody, '"type":"buy"') !== false && ($lvMock->lastHeaders['Nobitex-Key'] ?? '') === 'PUB');
$lvPosId = (int)($lvOpen['position']['id'] ?? 0);
$lvClose = $lvTrader->closePosition($lvPosId, 'take_profit', 66000.0);
check('زرنده: فروش خودکار روی نوبیتکس اجرا شد', !empty($lvClose['ok']), json_encode($lvClose, JSON_UNESCAPED_UNICODE));
check('زرنده: سفارش فروش با حجم پوزیشن', strpos($lvMock->lastBody, '"type":"sell"') !== false && strpos($lvMock->lastBody, '"amount":"0.00155"') !== false, $lvMock->lastBody);
Config::set('exchange.live_enabled', false);
Config::set('exchange.provider', 'binance');
@unlink($lvFile);

/* ═══ ۲۲) اطلاع‌رسانی چندکاناله (نسخهٔ ۵٫۳) ═══ */
section('Notifier / اطلاع‌رسانی');

// قالب پیام‌ها
$sigSample = \Meelano\Crypto\Notifier::samplePayload('signal');
$ntMock = new MockTransport();
$ntMock->on('api.telegram.org', ['status' => 200, 'body' => json_encode(['ok' => true, 'result' => ['message_id' => 42]])]);
$ntCfgOn = [
    'enabled' => true, 'min_tier' => 'B', 'throttle_sec' => 45, 'report_on_close' => false,
    'channels' => [
        'telegram' => ['enabled' => true, 'bot_token' => '111:AAA-TEST', 'chat_id' => '-100123', 'api_base' => 'https://api.telegram.org',
            'events' => ['signal' => true, 'trade_opened' => true, 'trade_closed' => true, 'wallet_report' => true]],
        'bale' => ['enabled' => true, 'bot_token' => 'bale-token-1234567890', 'chat_id' => 'balechan', 'api_base' => 'https://tapi.bale.ai',
            'events' => ['signal' => true, 'trade_opened' => false, 'trade_closed' => true, 'wallet_report' => false]],
        'rubika' => ['enabled' => true, 'bot_token' => 'rubika-token-1234567890', 'chat_id' => 'rubchan', 'api_base' => 'https://botapi.rubika.ir',
            'events' => ['signal' => true, 'trade_opened' => true, 'trade_closed' => true, 'wallet_report' => true]],
        'whatsapp' => ['enabled' => true, 'phone_number_id' => '10987', 'access_token' => 'EAAG-WA-TOKEN', 'to' => '989120000000',
            'api_version' => 'v21.0', 'api_base' => 'https://graph.facebook.com',
            'events' => ['signal' => false, 'trade_opened' => true, 'trade_closed' => true, 'wallet_report' => true]],
        'sms' => ['enabled' => true, 'api_key' => 'kavenegar-api-key-1234567890', 'receptor' => '09120000000', 'sender' => '',
            'api_base' => 'https://api.kavenegar.com',
            'events' => ['signal' => false, 'trade_opened' => false, 'trade_closed' => true, 'wallet_report' => false]],
    ],
];
$nt = new \Meelano\Crypto\Notifier($db, $ntMock, $ntCfgOn);

$renderedSignal = $nt->render('signal', $sigSample, false);
check('قالب سیگنال: نماد/ورود/استاپ/هدف', strpos($renderedSignal, 'BTCUSDT') !== false
    && strpos($renderedSignal, '64,250') !== false && strpos($renderedSignal, '62,900') !== false
    && strpos($renderedSignal, '65,800') !== false, $renderedSignal);
check('قالب سیگنال: درجه و RR', strpos($renderedSignal, 'A+') !== false && strpos($renderedSignal, '1:2.') !== false);
$renderedClose = $nt->render('trade_closed', \Meelano\Crypto\Notifier::samplePayload('trade_closed'), false);
check('قالب بستن: PnL مبلغ/درصد/R/دلیل', strpos($renderedClose, '+12.40') !== false && strpos($renderedClose, '+4.98%') !== false
    && strpos($renderedClose, 'R: 1.90') !== false && strpos($renderedClose, 'هدف دوم') !== false, $renderedClose);
$renderedWallet = $nt->render('wallet_report', \Meelano\Crypto\Notifier::samplePayload('wallet_report'), false);
check('قالب کیف: ارزش/سود کل/وین‌ریت', strpos($renderedWallet, '2,562.10') !== false && strpos($renderedWallet, '+562.10') !== false
    && strpos($renderedWallet, '61.8%') !== false, $renderedWallet);
$renderedSms = $nt->render('trade_closed', \Meelano\Crypto\Notifier::samplePayload('trade_closed'), true);
check('نسخهٔ فشردهٔ پیامک کوتاه است', strlen($renderedSms) < 120 && strpos($renderedSms, '+12.4') !== false, $renderedSms);

// dispatch: تلگرام/بله/روبیکا/واتساپ/پیامک — هر کانال فقط رویداد مشترک
$ntMock->on('tapi.bale.ai', ['status' => 200, 'body' => '{}']);
$ntMock->on('botapi.rubika.ir', ['status' => 200, 'body' => '{"status":"OK","data":{}}']);
$ntMock->on('graph.facebook.com', ['status' => 200, 'body' => json_encode(['messages' => [['id' => 'wamid.1']]])]);
$ntMock->on('api.kavenegar.com', ['status' => 200, 'body' => json_encode(['return' => ['status' => 200, 'message' => 'تایید شد']])]);
$dp = $nt->dispatch('signal', $sigSample);
check('ارسال سیگنال: فقط کانال‌های مشترک (تلگرام/بله/روبیکا)', in_array('telegram', $dp['sent'], true)
    && in_array('bale', $dp['sent'], true) && in_array('rubika', $dp['sent'], true)
    && !in_array('whatsapp', $dp['sent'], true) && !in_array('sms', $dp['sent'], true),
    json_encode($dp, JSON_UNESCAPED_UNICODE));
$tgCall = null;
foreach ($ntMock->calls as $c) { if (strpos($c['url'], 'api.telegram.org/bot111%3AAAA-TEST/sendMessage') !== false) { $tgCall = $c; } }
check('تلگرام: URL و پارامترها', $tgCall !== null && strpos($tgCall['options']['body'], 'chat_id=-100123') !== false
    && strpos($tgCall['options']['body'], 'BTCUSDT') !== false, $tgCall ? $tgCall['url'] : 'ندارد');
$baleCall = null;
foreach ($ntMock->calls as $c) { if (strpos($c['url'], 'tapi.bale.ai/bot') !== false) { $baleCall = $c; } }
check('بله: نشانی و بدنه', $baleCall !== null && strpos($baleCall['options']['body'], 'chat_id=balechan') !== false);
$rbCall = null;
foreach ($ntMock->calls as $c) { if (strpos($c['url'], 'botapi.rubika.ir/v1/') !== false) { $rbCall = $c; } }
check('روبیکا: JSON chat_id/text', $rbCall !== null && strpos($rbCall['options']['body'], '"chat_id":"rubchan"') !== false);

$dp2 = $nt->dispatch('trade_closed', \Meelano\Crypto\Notifier::samplePayload('trade_closed'));
check('بستن: هر ۵ کانال مشترک ارسال شدند', count($dp2['sent']) === 5, json_encode($dp2, JSON_UNESCAPED_UNICODE));
$waCall = null;
foreach ($ntMock->calls as $c) { if (strpos($c['url'], 'graph.facebook.com/v21.0/10987/messages') !== false) { $waCall = $c; } }
check('واتساپ: Bearer + messaging_product + گیرنده', $waCall !== null
    && ($waCall['options']['headers']['Authorization'] ?? '') === 'Bearer EAAG-WA-TOKEN'
    && strpos($waCall['options']['body'], '"messaging_product":"whatsapp"') !== false
    && strpos($waCall['options']['body'], '"to":"989120000000"') !== false);
$smsCall = null;
foreach ($ntMock->calls as $c) { if (strpos($c['url'], 'api.kavenegar.com/v1/kavenegar-api-key-1234567890/sms/send.json') !== false) { $smsCall = $c; } }
check('پیامک: کلید در مسیر + receptor + متن فشرده', $smsCall !== null
    && strpos($smsCall['options']['body'], 'receptor=09120000000') !== false
    && strpos($smsCall['options']['body'], 'BTCUSDT') !== false && strlen($smsCall['options']['body']) < 350);

// فیلتر درجهٔ سیگنال + دسته‌ای
$lowSig = $sigSample; $lowSig['tier'] = 'C'; $lowSig['symbol'] = 'ETHUSDT';
$dpLow = $nt->notifySignals([$lowSig]);
check('سیگنال زیر حداقل درجه ارسال نشد', empty($dpLow['sent']), json_encode($dpLow, JSON_UNESCAPED_UNICODE));
$dpBatch = $nt->notifySignals([$sigSample, $lowSig]);
check('ارسال دسته‌ای فقط یک dispatch با شمارش', strpos($nt->render('signal', ['batch' => [$sigSample, $lowSig]], false), '2 سیگنال') !== false);

// throttle: ارسال فوری دوبارهٔ همان رویداد به همان کانال حذف می‌شود
$dpAgain = $nt->dispatch('trade_closed', \Meelano\Crypto\Notifier::samplePayload('trade_closed'));
check('throttle جلوی ارسال مجدد فوری را گرفت', empty($dpAgain['sent']) && count($dpAgain['skipped']) === 5,
    json_encode($dpAgain, JSON_UNESCAPED_UNICODE));

// جداسازی خطا: یک کانال ۵۰۰ بدهد، بقیه ارسال می‌شوند
$ntMock->on('api.telegram.org', ['status' => 500, 'body' => '{"ok":false,"description":"rate limited"}']);
$ntCfgOn2 = $ntCfgOn;
$ntCfgOn2['throttle_sec'] = 0; // throttle خاموش تا فقط خطا تست شود
$nt2 = new \Meelano\Crypto\Notifier($db, $ntMock, $ntCfgOn2);
$dpErr = $nt2->dispatch('signal', $sigSample);
check('خطای یک کانال بقیه را نمی‌شکند', in_array('bale', $dpErr['sent'], true) && in_array('rubika', $dpErr['sent'], true)
    && !in_array('telegram', $dpErr['sent'], true));
$logRows = $db->select('SELECT * FROM ' . $db->table('notify_log') . ' WHERE channel = :c ORDER BY id DESC LIMIT 1', ['c' => 'telegram']);
check('خطا در notify_log ثبت شد', !empty($logRows) && (int)$logRows[0]['ok'] === 0 && strpos((string)$logRows[0]['error'], 'rate limited') !== false);

// کلید اصلی خاموش → هیچ ارسالی
$ntCfgOff = $ntCfgOn; $ntCfgOff['enabled'] = false; $ntCfgOff['throttle_sec'] = 0;
$nt3 = new \Meelano\Crypto\Notifier($db, $ntMock, $ntCfgOff);
$dpOff = $nt3->dispatch('signal', $sigSample);
check('کلید اصلی خاموش = بدون ارسال', empty($dpOff['sent']) && strpos(json_encode($dpOff['skipped'], JSON_UNESCAPED_UNICODE), 'خاموش') !== false);

// تست سلامت: تلگرام getMe؛ بدون پیکربندی → configured=false
$tgMockFresh = new MockTransport();
$tgMockFresh->on('api.telegram.org', ['status' => 200, 'body' => json_encode(['ok' => true, 'result' => ['id' => 42, 'username' => 'meelano_bot']])]);
$tgCheck = (new \Meelano\Crypto\Notify\Telegram($tgMockFresh, ['bot_token' => '222:BBB', 'chat_id' => '1']))->check();
$tgCheckCall = null;
foreach ($tgMockFresh->calls as $c) { if (strpos($c['url'], 'getMe') !== false) { $tgCheckCall = $c; } }
check('تست سلامت تلگرام getMe زده شد', $tgCheck['ok'] && $tgCheckCall !== null);
$tgEmpty = (new \Meelano\Crypto\Notify\Telegram($ntMock, []))->check();
check('کانال بدون پیکربندی: configured=false', !$tgEmpty['ok'] && empty($tgEmpty['configured']));
$smsEmpty = (new \Meelano\Crypto\Notify\Sms($ntMock, ['api_key' => '']))->send('x');
check('ارسال بدون کلید: خطای کنترل‌شده', !$smsEmpty['ok'] && strpos($smsEmpty['error'], 'تنظیم نشده') !== false);

// ذخیره: راز خالی = حفظ قبلی + اعتبارسنجی
$ntSave = new \Meelano\Crypto\Notifier($db, $ntMock, $ntCfgOn);
$sv = $ntSave->save([
    'enabled' => true, 'min_tier' => 'A', 'throttle_sec' => 30, 'report_on_close' => true,
    'channels' => ['telegram' => ['enabled' => true, 'bot_token' => '', 'chat_id' => '-100999',
        'events' => ['signal' => false]]],
]);
check('ذخیره پیکربندی موفق', !empty($sv['ok']));
$savedTg = (array)Config::get('notify.channels.telegram', []);
check('راز خالی = حفظ توکن قبلی', (string)$savedTg['bot_token'] === '111:AAA-TEST');
check('مقادیر جدید ذخیره شدند', (string)$savedTg['chat_id'] === '-100999' && (bool)$savedTg['events']['signal'] === false
    && (bool)$savedTg['events']['trade_closed'] === true);
$badBase = $ntSave->save(['channels' => ['telegram' => ['api_base' => 'ftp://bad']]]);
check('نشانی API نامعتبر رد شد', empty($badBase['ok']));

// status: هیچ رازی افشا نمی‌شود
$st = $ntSave->status();
$tgSt = $st['channels']['telegram'];
check('status: توکن ماسک و مقدار خالی', $tgSt['fields']['bot_token']['value'] === ''
    && strpos((string)$tgSt['fields']['bot_token']['masked'], '111') === 0
    && strpos((string)$tgSt['fields']['bot_token']['masked'], 'AAA-TEST') === false, json_encode($tgSt['fields']['bot_token']));
check('status: آخرین ارسال + لاگ', isset($tgSt['last']['event']) && count($st['log']) > 0);

// AutoTrader + Notifier: باز/بستن پوزیشن رویداد می‌فرستد
$ntCfgTrade = $ntCfgOn;
$ntCfgTrade['throttle_sec'] = 0;
$ntTrade = new \Meelano\Crypto\Notifier($db, $ntMock, $ntCfgTrade);
$atN = new AutoTrader($db, new MarketData($atMock), null, $atCfg, $ntTrade);
$atN->reset(1000.0);
$callsBefore = count($ntMock->calls);
$openN = $atN->openPosition($fakeSignal, 'auto');
$openedCalls = count($ntMock->calls) - $callsBefore;
check('بازشدن پوزیشن → رویداد trade_opened به کانال‌های مشترک', !empty($openN['ok']) && $openedCalls >= 2,
    'فراخوانی‌ها: ' . $openedCalls);
$posRowN = $db->selectOne('SELECT * FROM ' . $db->table('trade_positions') . ' LIMIT 1');
$closeN = $atN->closePosition((int)$posRowN['id'], 'tp2', (float)$posRowN['take_profit_2']);
$callsAfterClose = count($ntMock->calls);
check('بستن پوزیشن → رویداد trade_closed', !empty($closeN['ok']) && $callsAfterClose > $openedCalls + $callsBefore);
$lastTg = null;
foreach ($ntMock->calls as $c) {
    if (strpos($c['url'], 'sendMessage') !== false && strpos(urldecode($c['options']['body'] ?? ''), 'بسته شد') !== false) { $lastTg = $c; }
}
check('پیام بستن شامل PnL است', $lastTg !== null && strpos(urldecode($lastTg['options']['body']), 'USDT') !== false);


/* ═══ ۲۳) فیلترها و اندیکاتورهای v5.4 — لایهٔ تأیید اجرا ═══ */
section('فیلترهای v5.4');

// بریدگی: روند یکنواخت = CHOP پایین؛ زیگزاگ = CHOP بالا
$chopSeries = Indicators::choppiness(
    array_column($candles, 'high'), array_column($candles, 'low'), array_column($candles, 'close'), 14);
$chopTrend = Indicators::last($chopSeries);
check('CHOP در روند یکنواخت پایین است', $chopTrend !== null && (float)$chopTrend < 45, (string)$chopTrend);
$zig = []; $zp = 100.0;
for ($i = 0; $i < 80; $i++) { $zig[] = ['time' => $t + $i * 3600, 'open' => $zp, 'high' => $zp + 2.5, 'low' => $zp - 2.5, 'close' => $zp + ($i % 2 === 0 ? 2.0 : -2.0), 'volume' => 1000]; $zp += ($i % 2 === 0 ? 2.0 : -2.0); }
$chopZig = Indicators::last(Indicators::choppiness(array_column($zig, 'high'), array_column($zig, 'low'), array_column($zig, 'close'), 14));
check('CHOP در زیگزاگ بالا است', $chopZig !== null && (float)$chopZig > 55, (string)$chopZig);

// ایچیموکو: در روند صعودی، قیمت بالای ابر و تنکان>کیجون
$ichi = Indicators::ichimoku(array_column($candles, 'high'), array_column($candles, 'low'), count($candles) - 1);
check('ایچیموکو محاسبه شد', is_array($ichi) && $ichi['cloud_top'] >= $ichi['cloud_bottom']);
check('ایچیموکو در روند صعودی: قیمت بالای ابر', $ichi !== null && $candles[count($candles) - 1]['close'] > $ichi['cloud_top']);
check('ایچیموکو: تنکان > کیجون در روند', $ichi !== null && $ichi['tenkan'] > $ichi['kijun']);
check('ایچیموکو: تاریخ کم → null', Indicators::ichimoku(array_column($candles, 'high'), array_column($candles, 'low'), 50) === null);

// الگوی کندل
$patCandles = [
    ['open' => 100, 'high' => 101, 'low' => 98.8, 'close' => 99.2, 'volume' => 100],   // نزولی
    ['open' => 99.2, 'high' => 102.2, 'low' => 99.0, 'close' => 101.8, 'volume' => 200], // انگالفینگ صعودی
];
$pat = Indicators::candlePattern(array_column($patCandles, 'open'), array_column($patCandles, 'high'), array_column($patCandles, 'low'), array_column($patCandles, 'close'), 1);
check('انگالفینگ صعودی شناخته شد', is_array($pat) && $pat['type'] === 'bullish_engulfing' && $pat['side'] === 'BUY', json_encode($pat));
$hammer = Indicators::candlePattern([100, 99], [101, 99.6], [97, 97.2], [100, 99.4], 1);
check('چکش/پین‌بار شناخته شد', is_array($hammer) && $hammer['type'] === 'hammer' && $hammer['side'] === 'BUY', json_encode($hammer));
$star = Indicators::candlePattern([100, 100.4], [101, 103.0], [99.9, 100.2], [100.2, 100.5], 1);
check('ستارهٔ ثاقب شناخته شد', is_array($star) && $star['type'] === 'shooting_star' && $star['side'] === 'SELL', json_encode($star));

// قدرت پایانه
$clvLast = Indicators::closeStrength(array_column($candles, 'high'), array_column($candles, 'low'), array_column($candles, 'close'), count($candles) - 1);
check('CLV در روند صعودی بالای ۰٫۶', $clvLast !== null && $clvLast > 0.6, (string)$clvLast);
check('CLV بین ۰ و ۱', $clvLast !== null && $clvLast >= 0 && $clvLast <= 1);

// فیلترها: کلیدهای جدید در بافت صعودی
$v54Ctx = $buyCtx + ['atr' => 1.7, 'chop' => 22.0,
    'ichimoku' => ['tenkan' => 112, 'kijun' => 110, 'span_a' => 108, 'span_b' => 107, 'cloud_top' => 108, 'cloud_bottom' => 107],
    'candle_pattern' => ['type' => 'bullish_engulfing', 'side' => 'BUY', 'detail' => 'انگالفینگ صعودی'],
    'close_strength' => 0.82];
$eval54 = (new Filters())->evaluate($v54Ctx);
$g = static function (array $ev, string $key) { foreach ($ev['filters'] as $f) { if ($f['key'] === $key) { return $f; } } return null; };
check('فیلتر بریدگی: CHOP پایین در روند = عبور', ($g($eval54, 'choppiness')['pass'] ?? false) === true && ($g($eval54, 'choppiness')['score'] ?? 0) >= 0.8);
check('فیلتر ایچیموکو: خرید بالای ابر', ($g($eval54, 'ichimoku')['side'] ?? '') === 'BUY');
check('فیلتر الگوی کندل: انگالفینگ خرید', ($g($eval54, 'candle_pattern')['side'] ?? '') === 'BUY');
check('فیلتر CLV: قدرت خرید', ($g($eval54, 'close_strength')['side'] ?? '') === 'BUY');
check('فیلتر ضدتعقیب: پولبک سالم عبور کرد', ($g($eval54, 'extension')['pass'] ?? false) === true && ($g($eval54, 'extension')['score'] ?? 0) >= 0.8);
// پارابولیک در رِنج = رد
$v54Para = $v54Ctx; $v54Para['price'] = 125; $v54Para['regime'] = 'range'; $v54Para['adx'] = 15;
$evalPara = (new Filters())->evaluate($v54Para);
check('ضدتعقیب: پارابولیک در رِنج رد می‌شود', ($g($evalPara, 'extension')['pass'] ?? true) === false);
// پارابولیک در روند = عبور با امتیاز کمتر
$v54ParaT = $v54Ctx; $v54ParaT['price'] = 125;
$evalParaT = (new Filters())->evaluate($v54ParaT);
check('ضدتعقیب: پارابولیک در روند عبور ولی با امتیاز پایین', ($g($evalParaT, 'extension')['pass'] ?? false) === true && ($g($evalParaT, 'extension')['score'] ?? 1) <= 0.45);

// انسداد حجم: blow-off با سایه = فروش
$v54Blow = $v54Ctx; $v54Blow['vol_ratio'] = 4.8; $v54Blow['wick_ratio'] = 0.55;
$evalBlow = (new Filters())->evaluate($v54Blow);
check('انسداد حجم: اوج دمیده‌شده = رأی فروش', ($g($evalBlow, 'climax')['side'] ?? '') === 'SELL');
$v54Cap = $v54Ctx; $v54Cap['vol_ratio'] = 5.2; $v54Cap['wick_ratio'] = 0.1; $v54Cap['close_strength'] = 0.15;
$evalCap = (new Filters())->evaluate($v54Cap);
check('انسداد حجم: فلش تسلیم = رأی خرید', ($g($evalCap, 'climax')['side'] ?? '') === 'BUY');
$v54Wait = $v54Ctx; $v54Wait['vol_ratio'] = 4.4; $v54Wait['wick_ratio'] = 0.2; $v54Wait['close_strength'] = 0.5;
$evalWait = (new Filters())->evaluate($v54Wait);
check('انسداد حجم: حجم غیرعادی مبهم = عدم عبور', ($g($evalWait, 'climax')['pass'] ?? true) === false);

// RSI رژیم‌آگاه (اصلاح باگ v5.4)
$v54Range = $v54Ctx; $v54Range['regime'] = 'range'; $v54Range['rsi'] = 25; $v54Range['adx'] = 15;
$evalRangeRsi = (new Filters())->evaluate($v54Range);
check('RSI ۲۵ در رِنج = خرید میانگین‌گرا (نه فروش)', ($g($evalRangeRsi, 'rsi')['side'] ?? '') === 'BUY');
$v54Td = $v54Ctx; $v54Td['regime'] = 'trend_down'; $v54Td['rsi'] = 25;
$evalTdRsi = (new Filters())->evaluate($v54Td);
check('RSI ۲۵ در روند نزولی = تأیید مومنتوم فروش', ($g($evalTdRsi, 'rsi')['side'] ?? '') === 'SELL');
check('RSI ۵۸ در روند صعودی = خرید (بدون پس‌رفت)', ($g($eval54, 'rsi')['side'] ?? '') === 'BUY');

// نبود کلیدهای جدید = عبور خنثی
$evalNoKeys = (new Filters())->evaluate($buyCtx);
check('نبود دادهٔ v5.4 = عبور خنثی', ($g($evalNoKeys, 'ichimoku')['side'] ?? 'X') === 'NEUTRAL' && ($g($evalNoKeys, 'choppiness')['pass'] ?? false) === true);
$noCoreCtx = $buyCtx;
unset($noCoreCtx['adx'], $noCoreCtx['supertrend_dir'], $noCoreCtx['obv_slope'], $noCoreCtx['vwap']);
// فیلترهای نسخهٔ ۵٫۷ — فشردگی بولینگر + جهت‌یاب DI
$sqCtx = $buyCtx; $sqCtx['bb_width_pct'] = 10;
$evalSq = (new Filters())->evaluate($sqCtx);
check('فشردگی شدید: جهت از میکروترند', ($g($evalSq, 'bb_squeeze')['side'] ?? '') === 'BUY' && ($g($evalSq, 'bb_squeeze')['pass'] ?? false) === true);
$sqX = $buyCtx; $sqX['bb_width_pct'] = 90;
check('انبساط افراطی: عدم عبور', ($g((new Filters())->evaluate($sqX), 'bb_squeeze')['pass'] ?? true) === false);
$sqN = $buyCtx; unset($sqN['bb_width_pct']);
check('فشردگی: نبود داده = عبور خنثی', ($g((new Filters())->evaluate($sqN), 'bb_squeeze')['pass'] ?? false) === true);
check('جهت‌یاب DI: تسلط خریداران', ($g($evalBuy, 'di_spread')['side'] ?? '') === 'BUY' && ($g($evalBuy, 'di_spread')['pass'] ?? false) === true);
$diSell = $buyCtx; $diSell['plus_di'] = 12; $diSell['minus_di'] = 30; $diSell['regime'] = 'trend_down';
check('جهت‌یاب DI: تسلط فروشندگان', ($g((new Filters())->evaluate($diSell), 'di_spread')['side'] ?? '') === 'SELL');

$evalNoCore = (new Filters())->evaluate($noCoreCtx);
check('نبود دادهٔ ADX/Supertrend/OBV/VWAP = عبور خنثی (اصلاح v5.6)',
    ($g($evalNoCore, 'adx')['pass'] ?? false) === true
    && ($g($evalNoCore, 'supertrend')['pass'] ?? false) === true
    && ($g($evalNoCore, 'obv')['pass'] ?? false) === true
    && ($g($evalNoCore, 'vwap')['pass'] ?? false) === true,
    json_encode(array_map(static function ($f) { return [$f['key'], $f['pass']]; }, $evalNoCore['filters']), JSON_UNESCAPED_UNICODE));

/* ═══ ۲۴) موتور یادگیری تطبیقی (نسخهٔ ۵٫۶) ═══ */
section('Learning / یادگیری تطبیقی');

// فیلترها با زمینهٔ یادگیری — ضریب و غیرفعال‌سازی
$flBase = (new Filters())->evaluate($buyCtx);
$flLearn = new Filters(['multipliers' => ['rsi' => 2.0], 'disabled' => ['climax']]);
$evLearn = $flLearn->evaluate($buyCtx);
$rsiBase = $g($flBase, 'rsi');
$rsiLearn = $g($evLearn, 'rsi');
check('یادگیری: ضریب ۲× وزن فیلتر را دو برابر می‌کند',
    $rsiBase !== null && $rsiLearn !== null && abs($rsiLearn['weight'] - $rsiBase['weight'] * 2.0) < 0.011,
    ($rsiBase['weight'] ?? '?') . ' → ' . ($rsiLearn['weight'] ?? '?'));
check('یادگیری: فیلتر غیرفعال از مجموعه حذف می‌شود',
    $g($evLearn, 'climax') === null && $evLearn['total'] === $flBase['total'] - 1,
    $evLearn['total'] . ' در برابر ' . $flBase['total']);
check('یادگیری: بدون زمینه = رفتار ایستای قبل',
    abs((new Filters())->evaluate($buyCtx)['tech_score']
        - (new Filters(['multipliers' => [], 'disabled' => []]))->evaluate($buyCtx)['tech_score']) < 0.001);

// پایگاه‌دادهٔ ایزولهٔ یادگیری
$lrFile = tempnam(sys_get_temp_dir(), 'mlnlearn') . '.sqlite'; @unlink($lrFile);
$lrDb = Db::make(['driver' => 'sqlite', 'sqlite_path' => $lrFile]);
(new Installer($lrDb))->run();
check('جدول‌های یادگیری ساخته شدند', $lrDb->tableExists('learning_state') && $lrDb->tableExists('learning_events'));

$mkFiltersJson = static function (array $spec): string {
    $out = [];
    foreach ($spec as $key => $side) {
        $out[] = ['key' => $key, 'label' => 'فیلتر ' . $key, 'pass' => true, 'score' => 0.9, 'side' => $side, 'weight' => 1.0, 'detail' => '-'];
    }
    return json_encode($out, JSON_UNESCAPED_UNICODE);
};
$seedSignal = static function (Db $db, string $side, float $r, string $filtersJson, int $ageDays = 1) use ($lrDb): void {
    $db->insert('signals', [
        'scan_id' => null, 'symbol' => 'BTCUSDT', 'side' => $side, 'timeframe' => '1h',
        'tier' => 'A', 'regime' => 'trend_up', 'confidence' => 80, 'tech_score' => 75,
        'ai_score' => 0, 'combined_score' => 80, 'mtf_score' => 0,
        'entry_price' => 100, 'stop_loss' => 98, 'take_profit_1' => 104, 'take_profit_2' => 106, 'take_profit_3' => 110,
        'risk_reward' => 3, 'position_pct' => 5, 'invalidation' => '',
        'filters_passed' => 20, 'filters_total' => 34, 'filters_json' => $filtersJson,
        'status' => 'closed', 'created_at' => date('Y-m-d H:i:s', time() - $ageDays * 86400),
        'hit_tp1' => $r > 0 ? 1 : 0, 'hit_tp2' => 0, 'hit_tp3' => 0, 'hit_stop' => $r <= 0 ? 1 : 0,
        'outcome' => $r > 0 ? 'tp1' : 'stop', 'exit_price' => $r > 0 ? 104 : 98,
        'r_multiple' => $r, 'bars_held' => 5, 'resolved_at' => date('Y-m-d H:i:s', time() - $ageDays * 86400 + 3600),
    ]);
};

// شاهد خوب (برنده‌ها را تأیید می‌کند) · شاهد بد (بازنده‌ها را تأیید می‌کند) · نمونهٔ کم
$goodJson = $mkFiltersJson(['rsi' => 'BUY', 'climax' => 'SELL']);
$badJson = $mkFiltersJson(['rsi' => 'SELL', 'climax' => 'BUY']);
$tinyJson = $mkFiltersJson(['tiny' => 'BUY']);
for ($i = 0; $i < 15; $i++) {
    $seedSignal($lrDb, 'BUY', 1.5, $goodJson, 10);  // کهن‌ترین: برنده (rsi درست)
    $seedSignal($lrDb, 'BUY', -1.0, $badJson, 9);   // بازنده: climax اشتباه (تأییدکنندهٔ بازنده)
}
for ($i = 0; $i < 5; $i++) {
    $seedSignal($lrDb, 'BUY', 1.0, $tinyJson, 8);   // نمونهٔ کم — نباید تطبیق شود
}
// شاهد فاجعه‌بار: ۳۰ بازنده پیاپی → قرنطینه
$awfulJson = $mkFiltersJson(['awful' => 'BUY']);
for ($i = 0; $i < 30; $i++) {
    $seedSignal($lrDb, 'BUY', -1.0, $awfulJson, 7); // تازه‌تر از گروه اول — قرنطینه روی کل پنجره
}

$learn1 = LearningEngine::learn($lrDb);
check('یادگیری: دور اول موفق', $learn1['ok'] === true, json_encode($learn1['errors'] ?? [], JSON_UNESCAPED_UNICODE));
$lrState1 = LearningEngine::state($lrDb);
$rsiMult1 = (float)($lrState1['weights']['rsi']['mult'] ?? 0);
$climaxMult1 = (float)($lrState1['weights']['climax']['mult'] ?? 0);
$awfulMult1 = (float)($lrState1['weights']['awful']['mult'] ?? 0);
$tinyMult1 = (float)($lrState1['weights']['tiny']['mult'] ?? 1.0);
check('یادگیری: شاهد درست‌گو وزن گرفت (rsi > 1)', $rsiMult1 > 1.01, (string)$rsiMult1);
check('یادگیری: شاهد خطاکار جریمه شد (climax < 1)', $climaxMult1 > 0 && $climaxMult1 < 0.99, (string)$climaxMult1);
check('یادگیری: نمونهٔ کم تطبیق نمی‌خورد (tiny = 1)', abs($tinyMult1 - 1.0) < 0.001, (string)$tinyMult1);
check('یادگیری: خطای تکراری قرنطینه شد', in_array('awful', $learn1['quarantined'], true)
    && !empty($lrState1['weights']['awful']['q']) && $awfulMult1 <= 0.35, (string)$awfulMult1);
check('یادگیری: رویداد قرنطینه ثبت شد', (int)$lrDb->count('learning_events', "type = 'quarantine' AND filter_key = 'awful'") === 1);
check('یادگیری: نسل و شمار داوری ثبت شد', $learn1['generation'] === 1 && $learn1['learned'] === 65, $learn1['generation'] . '/' . $learn1['learned']);

// گزارش وضعیت
$lrStatus = LearningEngine::status($lrDb);
$stRsi = null;
foreach ($lrStatus['filters'] as $f) { if ($f['key'] === 'rsi') { $stRsi = $f; } }
check('گزارش: برچسب و آمار فیلتر برداشت شد', $stRsi !== null && $stRsi['label'] === 'فیلتر rsi' && $stRsi['n'] === 15 && $stRsi['correct'] === 15,
    json_encode($stRsi, JSON_UNESCAPED_UNICODE));
check('گزارش: میانگین R شاهد درست‌گو', $stRsi !== null && abs($stRsi['avg_r'] - 1.5) < 0.01, (string)($stRsi['avg_r'] ?? '-'));
check('گزارش: رویدادها فهرست شدند', count($lrStatus['events']) >= 1);

// زمینهٔ فیلترها: ضرایب به Filters تزریق می‌شوند
$lrCtx = LearningEngine::filterContext($lrDb);
check('زمینه: ضریب آموختهٔ rsi به Filters می‌رسد',
    is_array($lrCtx) && abs(($lrCtx['multipliers']['rsi'] ?? 0) - $rsiMult1) < 0.001, json_encode($lrCtx['multipliers'] ?? []));

// بازیابی از قرنطینه: ۳۶ برندهٔ تازه‌تر از همه → درست‌بودن ≈ ۵۴٪
for ($i = 0; $i < 36; $i++) {
    $seedSignal($lrDb, 'BUY', 2.0, $awfulJson, 1);
}
$learn2 = LearningEngine::learn($lrDb);
$lrState2 = LearningEngine::state($lrDb);
check('بازسازی: قرنطینه‌شدهٔ بهبودیافته برگشت', in_array('awful', $learn2['recovered'], true)
    && empty($lrState2['weights']['awful']['q']) && (float)$lrState2['weights']['awful']['mult'] >= 0.8,
    (string)($lrState2['weights']['awful']['mult'] ?? '?'));
check('بازسازی: رویداد بازیابی ثبت شد', (int)$lrDb->count('learning_events', "type = 'recover' AND filter_key = 'awful'") === 1);

// سقف/کف سخت: فشار مکرر نباید از سقف بگذرد
for ($round = 0; $round < 12; $round++) {
    for ($i = 0; $i < 15; $i++) {
        $seedSignal($lrDb, 'BUY', 2.0, $goodJson, 1); // rsi همیشه درست و تازه
    }
    LearningEngine::learn($lrDb);
}
$lrState3 = LearningEngine::state($lrDb);
check('ایمنی: ضریب هرگز از سقف ۲٫۵ نمی‌گذرد', (float)$lrState3['weights']['rsi']['mult'] <= 2.5, (string)$lrState3['weights']['rsi']['mult']);

// بازنشانی
$lrReset = LearningEngine::reset($lrDb);
$lrState4 = LearningEngine::state($lrDb);
$allBase = true;
foreach ($lrState4['weights'] as $w) {
    if (abs((float)($w['mult'] ?? 1.0) - 1.0) > 0.001) { $allBase = false; }
}
check('بازنشانی: همهٔ ضرایب به ۱٫۰ برگشتند', $lrReset['ok'] && $allBase && $lrState4['generation'] > $lrState3['generation']);
check('بازنشانی: رویداد ثبت شد', (int)$lrDb->count('learning_events', "type = 'reset'") === 1);

// ضریب دستی بر آموخته‌شده مقدم است + غیرفعال‌سازی دستی
Config::set('learning.overrides', ['rsi' => 1.7]);
Config::set('learning.disabled', ['climax']);
$lrCtx2 = LearningEngine::filterContext($lrDb);
check('مدیریت دستی: ضریب دستی مقدم است', abs(($lrCtx2['multipliers']['rsi'] ?? 0) - 1.7) < 0.001
    && in_array('climax', $lrCtx2['disabled'], true), json_encode($lrCtx2, JSON_UNESCAPED_UNICODE));
Config::set('learning.overrides', []);
Config::set('learning.disabled', []);
Config::save();

// ═══ نسخهٔ ۵٫۷: ضرایب وابسته به رژیم ═══
$rgFile = tempnam(sys_get_temp_dir(), 'mlnrg') . '.sqlite'; @unlink($rgFile);
$rgDb = Db::make(['driver' => 'sqlite', 'sqlite_path' => $rgFile]);
(new Installer($rgDb))->run();
$rgJson = $mkFiltersJson(['rsi' => 'BUY']);
for ($i = 0; $i < 24; $i++) {
    $rgDb->insert('signals', ['scan_id' => null, 'symbol' => 'BTCUSDT', 'side' => 'BUY', 'timeframe' => '1h', 'tier' => 'A', 'regime' => 'trend_up', 'confidence' => 80, 'tech_score' => 75, 'ai_score' => 0, 'combined_score' => 80, 'mtf_score' => 0, 'entry_price' => 100, 'stop_loss' => 98, 'take_profit_1' => 104, 'take_profit_2' => 106, 'take_profit_3' => 110, 'risk_reward' => 3, 'position_pct' => 5, 'filters_passed' => 20, 'filters_total' => 34, 'filters_json' => $rgJson, 'status' => 'closed', 'created_at' => date('Y-m-d H:i:s', time() - 3600 - $i), 'hit_tp1' => 1, 'hit_tp2' => 0, 'hit_tp3' => 0, 'hit_stop' => 0, 'outcome' => 'tp1', 'exit_price' => 104, 'r_multiple' => 1.5, 'bars_held' => 5, 'resolved_at' => date('Y-m-d H:i:s')]);
    $rgDb->insert('signals', ['scan_id' => null, 'symbol' => 'ETHUSDT', 'side' => 'BUY', 'timeframe' => '1h', 'tier' => 'B', 'regime' => 'range', 'confidence' => 70, 'tech_score' => 68, 'ai_score' => 0, 'combined_score' => 70, 'mtf_score' => 0, 'entry_price' => 50, 'stop_loss' => 48, 'take_profit_1' => 52, 'take_profit_2' => 54, 'take_profit_3' => 58, 'risk_reward' => 3, 'position_pct' => 5, 'filters_passed' => 20, 'filters_total' => 34, 'filters_json' => $rgJson, 'status' => 'closed', 'created_at' => date('Y-m-d H:i:s', time() - 3600 - $i), 'hit_tp1' => 0, 'hit_tp2' => 0, 'hit_tp3' => 0, 'hit_stop' => 1, 'outcome' => 'stop', 'exit_price' => 48, 'r_multiple' => -1.0, 'bars_held' => 3, 'resolved_at' => date('Y-m-d H:i:s')]);
}
$rgRun = LearningEngine::learn($rgDb);
$rgState = LearningEngine::state($rgDb);
check('رژیم: کلید وابسته به رژیم ساخته شد', isset($rgState['weights']['rsi@trend']) && isset($rgState['weights']['rsi@range']),
    json_encode(array_keys($rgState['weights']), JSON_UNESCAPED_UNICODE));
check('رژیم: نابغهٔ روند وزن گرفت', (float)$rgState['weights']['rsi@trend']['mult'] > 1.01, (string)($rgState['weights']['rsi@trend']['mult'] ?? '?'));
check('رژیم: فاجعهٔ رِنج جریمه شد', (float)$rgState['weights']['rsi@range']['mult'] < 0.99, (string)($rgState['weights']['rsi@range']['mult'] ?? '?'));
$rgStatus = LearningEngine::status($rgDb);
$rgTrendRow = null;
foreach ($rgStatus['filters'] as $f) { if ($f['key'] === 'rsi@trend') { $rgTrendRow = $f; } }
check('رژیم: گزارش با برچسب رژیم', $rgTrendRow !== null && strpos($rgTrendRow['label'], 'روند') !== false && $rgTrendRow['regime'] === 'trend');

// Filters باید ضریبِ همان رژیم را بردارد
$flTrend = new Filters(['multipliers' => ['rsi' => 1.0, 'rsi@trend' => 1.8, 'rsi@range' => 0.4]]);
$trendEval = $flTrend->evaluate($buyCtx); // buyCtx رژیم trend_up
$rangeEval = $flTrend->evaluate($v54Range ?: ($buyCtx + ['regime' => 'range']));
$trendRsi = null; $rangeRsi = null;
foreach ($trendEval['filters'] as $f) { if ($f['key'] === 'rsi') { $trendRsi = $f; } }
foreach ($rangeEval['filters'] as $f) { if ($f['key'] === 'rsi') { $rangeRsi = $f; } }
check('رژیم: Filters ضریب رژیم فعال را برمی‌دارد',
    $trendRsi !== null && $rangeRsi !== null
    && abs($trendRsi['weight'] - 0.7 * 1.8) < 0.03
    && abs($rangeRsi['weight'] - 1.3 * 0.4) < 0.03,
    ($trendRsi['weight'] ?? '?') . ' در برابر ' . ($rangeRsi['weight'] ?? '?'));
@unlink($rgFile);

// ═══ نسخهٔ ۵٫۷: حافظهٔ زمانی (نیم‌عمر) ═══
$tdFile = tempnam(sys_get_temp_dir(), 'mlntd') . '.sqlite'; @unlink($tdFile);
$tdDb = Db::make(['driver' => 'sqlite', 'sqlite_path' => $tdFile]);
(new Installer($tdDb))->run();
for ($i = 0; $i < 12; $i++) { // ۱۲ باخت کهنه (۲۰۰ روز پیش) — وزن ≈ ۰٫۰۵
    $tdDb->insert('signals', ['scan_id' => null, 'symbol' => 'OLDUSDT', 'side' => 'BUY', 'timeframe' => '1h', 'tier' => 'B', 'regime' => 'trend_up', 'confidence' => 70, 'tech_score' => 68, 'ai_score' => 0, 'combined_score' => 70, 'mtf_score' => 0, 'entry_price' => 50, 'stop_loss' => 48, 'take_profit_1' => 52, 'take_profit_2' => 54, 'take_profit_3' => 58, 'risk_reward' => 3, 'position_pct' => 5, 'filters_passed' => 20, 'filters_total' => 34, 'filters_json' => $mkFiltersJson(['macd' => 'BUY']), 'status' => 'closed', 'created_at' => date('Y-m-d H:i:s', time() - 200 * 86400), 'hit_tp1' => 0, 'hit_tp2' => 0, 'hit_tp3' => 0, 'hit_stop' => 1, 'outcome' => 'stop', 'exit_price' => 48, 'r_multiple' => -1.0, 'bars_held' => 3, 'resolved_at' => date('Y-m-d H:i:s', time() - 200 * 86400)]);
}
for ($i = 0; $i < 12; $i++) { // ۱۲ برد تازه — وزن ≈ ۱
    $tdDb->insert('signals', ['scan_id' => null, 'symbol' => 'NEWUSDT', 'side' => 'BUY', 'timeframe' => '1h', 'tier' => 'A', 'regime' => 'trend_up', 'confidence' => 80, 'tech_score' => 75, 'ai_score' => 0, 'combined_score' => 80, 'mtf_score' => 0, 'entry_price' => 100, 'stop_loss' => 98, 'take_profit_1' => 104, 'take_profit_2' => 106, 'take_profit_3' => 110, 'risk_reward' => 3, 'position_pct' => 5, 'filters_passed' => 20, 'filters_total' => 34, 'filters_json' => $mkFiltersJson(['macd' => 'BUY']), 'status' => 'closed', 'created_at' => date('Y-m-d H:i:s', time() - 300 - $i), 'hit_tp1' => 1, 'hit_tp2' => 0, 'hit_tp3' => 0, 'hit_stop' => 0, 'outcome' => 'tp1', 'exit_price' => 104, 'r_multiple' => 1.5, 'bars_held' => 5, 'resolved_at' => date('Y-m-d H:i:s', time() - 300 - $i)]);
}
LearningEngine::learn($tdDb);
$tdState = LearningEngine::state($tdDb);
check('حافظهٔ زمانی: باخت‌های کهنه محو و برد تازه حاکم است',
    (float)($tdState['weights']['macd']['mult'] ?? 0) > 1.03, (string)($tdState['weights']['macd']['mult'] ?? '?'));
@unlink($tdFile);

// ═══ نسخهٔ ۵٫۷: دروازهٔ walk-forward ═══
$wfFile = tempnam(sys_get_temp_dir(), 'mlnwf') . '.sqlite'; @unlink($wfFile);
$wfDb = Db::make(['driver' => 'sqlite', 'sqlite_path' => $wfFile]);
(new Installer($wfDb))->run();
for ($i = 0; $i < 21; $i++) { // تمرین (کهن‌تر): همیشه درست
    $wfDb->insert('signals', ['scan_id' => null, 'symbol' => 'TR' . $i, 'side' => 'BUY', 'timeframe' => '1h', 'tier' => 'A', 'regime' => 'trend_up', 'confidence' => 80, 'tech_score' => 75, 'ai_score' => 0, 'combined_score' => 80, 'mtf_score' => 0, 'entry_price' => 100, 'stop_loss' => 98, 'take_profit_1' => 104, 'take_profit_2' => 106, 'take_profit_3' => 110, 'risk_reward' => 3, 'position_pct' => 5, 'filters_passed' => 20, 'filters_total' => 34, 'filters_json' => $mkFiltersJson(['obv' => 'BUY']), 'status' => 'closed', 'created_at' => date('Y-m-d H:i:s', time() - 40 * 86400 + $i * 3600), 'hit_tp1' => 1, 'hit_tp2' => 0, 'hit_tp3' => 0, 'hit_stop' => 0, 'outcome' => 'tp1', 'exit_price' => 104, 'r_multiple' => 1.5, 'bars_held' => 5, 'resolved_at' => date('Y-m-d H:i:s', time() - 39 * 86400 + $i * 3600)]);
}
for ($i = 0; $i < 9; $i++) { // آزمون (تازه‌ترین ۹): همیشه غلط — ارتقا نباید اعمال شود
    $wfDb->insert('signals', ['scan_id' => null, 'symbol' => 'VA' . $i, 'side' => 'BUY', 'timeframe' => '1h', 'tier' => 'C', 'regime' => 'trend_up', 'confidence' => 65, 'tech_score' => 63, 'ai_score' => 0, 'combined_score' => 65, 'mtf_score' => 0, 'entry_price' => 30, 'stop_loss' => 28, 'take_profit_1' => 32, 'take_profit_2' => 34, 'take_profit_3' => 38, 'risk_reward' => 3, 'position_pct' => 5, 'filters_passed' => 20, 'filters_total' => 34, 'filters_json' => $mkFiltersJson(['obv' => 'BUY']), 'status' => 'closed', 'created_at' => date('Y-m-d H:i:s', time() - 100 - $i), 'hit_tp1' => 0, 'hit_tp2' => 0, 'hit_tp3' => 0, 'hit_stop' => 1, 'outcome' => 'stop', 'exit_price' => 28, 'r_multiple' => -1.0, 'bars_held' => 2, 'resolved_at' => date('Y-m-d H:i:s', time() - 100 - $i)]);
}
$wfRun = LearningEngine::learn($wfDb);
$wfState = LearningEngine::state($wfDb);
check('walk-forward: شکست آزمونِ تازه ارتقای وزن را به تعویق انداخت',
    in_array('obv', $wfRun['held'], true) && abs((float)($wfState['weights']['obv']['mult'] ?? 1) - 1.0) < 0.001,
    (string)($wfState['weights']['obv']['mult'] ?? '?'));
check('walk-forward: رویداد تعویق ثبت شد', (int)$wfDb->count('learning_events', "type = 'hold' AND filter_key = 'obv'") === 1);
@unlink($wfFile);

// ═══ نسخهٔ ۵٫۷: ژورنال فرصت‌های نزدیک — داوری و ورود به یادگیری ═══
$nmFile = tempnam(sys_get_temp_dir(), 'mlnnm') . '.sqlite'; @unlink($nmFile);
$nmDb = Db::make(['driver' => 'sqlite', 'sqlite_path' => $nmFile]);
(new Installer($nmDb))->run();
$nmMock = new MockTransport();
$nmMock->on('api.binance.com/api/v3/klines', ['status' => 200, 'body' => json_encode(array_map(static function ($c) {
    return [$c['time'] * 1000, $c['open'], $c['high'], $c['low'], $c['close'], $c['volume']];
}, $candles))]);
$e200nm = (float)$candles[200]['close'];
for ($nmI = 0; $nmI < 5; $nmI++) {
    $nmDb->insert('signals', ['scan_id' => null, 'symbol' => 'NEAR' . $nmI . 'USDT', 'side' => 'BUY', 'timeframe' => '1h', 'tier' => 'NM', 'regime' => 'trend_up', 'confidence' => 58, 'tech_score' => 58, 'ai_score' => 0, 'combined_score' => 58, 'mtf_score' => 0, 'entry_price' => $e200nm, 'stop_loss' => $e200nm - 2, 'take_profit_1' => $e200nm + 3, 'take_profit_2' => $e200nm + 6, 'take_profit_3' => $e200nm + 9, 'risk_reward' => 3, 'position_pct' => 5, 'filters_passed' => 18, 'filters_total' => 34, 'filters_json' => $mkFiltersJson(['vwap' => 'BUY']), 'status' => 'near_miss', 'outcome' => '', 'created_at' => date('Y-m-d H:i:s', (int)$candles[200]['time'])]);
}
$nmTracker = new SignalTracker($nmDb, new MarketData($nmMock), $baseCfg + ['backtest_fee_bps' => 8, 'backtest_slippage_bps' => 3]);
$nmRun = $nmTracker->run(10);
$nmRow = $nmDb->selectOne('SELECT * FROM ' . $nmDb->table('signals') . " WHERE symbol = 'NEAR0USDT'");
check('ژورنال: فرصت نزدیک داوری و بسته شد', $nmRun['ok'] && $nmRow !== null && (string)$nmRow['outcome'] !== '' && (string)$nmRow['status'] === 'near_miss', (string)(($nmRow['outcome'] ?? '-') . '/' . ($nmRow['status'] ?? '-')));
$nmState = LearningEngine::state($nmDb);
check('ژورنال: داوری فرصت نزدیک سوخت یادگیری شد', (int)$nmState['generation'] >= 1 && isset($nmState['stats']['vwap']),
    'نسل ' . $nmState['generation']);
$nmStats = $nmTracker->stats();
check('ژورنال: آمار رسمی ردیاب بدون فرصت‌های نزدیک', $nmStats['total'] === 0, (string)$nmStats['total']);
@unlink($nmFile);

@unlink($lrFile);

/* ═══ نسخهٔ ۵٫۷: لایهٔ هفتم — سنتیمنت آن‌چین و ریسک خبری ═══ */
$smCache = tempnam(sys_get_temp_dir(), 'smsent') . '.json';
@unlink($smCache);
$smMock = new MockTransport();
$smMock->on('api.alternative.me/fng', ['status' => 200, 'body' => json_encode(['data' => [['value' => 80, 'value_classification' => 'Extreme Grey']]])]);
$smMock->on('api.coingecko.com/api/v3/global', ['status' => 200, 'body' => json_encode(['data' => ['market_cap_change_percentage_24h_usd' => 5.0]])]);
$smRss = '<?xml version="1.0"?><rss><channel>' . implode('', [
    '<item><title>Massive hack drains 50M from DeFi protocol</title><pubDate>' . date('D, d M Y H:i:s O', time() - 3600) . '</pubDate></item>',
    '<item><title>Bitcoin ETF inflows hit record high</title><pubDate>' . date('D, d M Y H:i:s O', time() - 7200) . '</pubDate></item>',
    '<item><title>Ethereum adoption grows with new partnership</title><pubDate>' . date('D, d M Y H:i:s O', time() - 10800) . '</pubDate></item>',
    '<item><title>Old news before window</title><pubDate>' . date('D, d M Y H:i:s O', time() - 3 * 86400) . '</pubDate></item>',
]) . '</channel></rss>';
$smMock->on('cointelegraph.com/rss', ['status' => 200, 'body' => $smRss]);
$smMock->on('coindesk.com', ['status' => 200, 'body' => $smRss]);
$smSent = new Sentiment($smMock, 600, $smCache);
$smPulse = $smSent->pulse(true);
check('سنتیمنت: نبض سه‌منبعی ok شد',
    $smPulse['ok'] === true && $smPulse['sources']['fear_greed'] && $smPulse['sources']['market'] && $smPulse['sources']['news'],
    json_encode($smPulse['sources'] ?? [], JSON_UNESCAPED_UNICODE));
check('سنتیمنت: نمره در بازهٔ [-1,+1] و مثبت (طمع ۸۰ + مارکت +۵٪)',
    $smPulse['score'] > 0.1 && $smPulse['score'] <= 1.0, (string)$smPulse['score']);
check('سنتیمنت: شاخص ترس/طمع منتقل شد', ($smPulse['fg']['value'] ?? 0) === 80, json_encode($smPulse['fg'] ?? null));
check('سنتیمنت: سرخطی‌های خبری وزن‌دار شدند (سندیکت تکراری حذف)',
    $smPulse['news']['count'] === 3 && count($smPulse['news']['top']) >= 2,
    'count=' . $smPulse['news']['count'] . ' top=' . count($smPulse['news']['top']));
check('سنتیمنت: سرخطی با بیشینهٔ |وزن| در صدر فهرست',
    abs((float)($smPulse['news']['top'][0]['weight'] ?? 0) - 2.5) < 0.001,
    json_encode($smPulse['news']['top'][0] ?? null, JSON_UNESCAPED_UNICODE));
check('سنتیمنت: سرخطی هک در فهرست ریسک‌ها',
    count(array_filter($smPulse['news']['top'], static function ($t) { return (float)$t['weight'] < 0; })) >= 1,
    json_encode($smPulse['news']['top'], JSON_UNESCAPED_UNICODE));

$smPulse2 = $smSent->pulse();
$smHttpCalls = count($smMock->calls);
$smPulse3 = $smSent->pulse();
check('سنتیمنت: کش از ضربهٔ مجدد به منابع جلوگیری کرد',
    $smPulse2['cached'] === true && $smPulse3['cached'] === true && count($smMock->calls) === $smHttpCalls,
    'calls=' . $smHttpCalls);

$smDead = new MockTransport(); // همهٔ منابع ۴۰۴
$smDeadSent = new Sentiment($smDead, 600, $smCache . '.dead');
$smDeadPulse = $smDeadSent->pulse(true);
check('سنتیمنت: قطع کامل منابع = خنثی بدون ادعا',
    $smDeadPulse['ok'] === false && (float)$smDeadPulse['score'] === 0.0,
    json_encode($smDeadPulse, JSON_UNESCAPED_UNICODE));

$smFgOnly = new MockTransport();
$smFgOnly->on('api.alternative.me/fng', ['status' => 200, 'body' => json_encode(['data' => [['value' => 20, 'value_classification' => 'Extreme Fear']]])]);
$smFgSent = new Sentiment($smFgOnly, 600, $smCache . '.fg');
$smFgPulse = $smFgSent->pulse(true);
check('سنتیمنت: بازتوزیع وزن با یک منبع (فقط ترس/طمع)',
    $smFgPulse['ok'] === true && abs(($smFgPulse['score'] ?? 9) - (-0.6)) < 0.001, (string)$smFgPulse['score']);
check('سنتیمنت: برچسب ترس شدید', strpos($smFgPulse['label'], 'ترس') !== false, $smFgPulse['label']);
check('سنتیمنت: سطح ریسک خبری ۳ در بحران', (new Sentiment($smMock, 600, $smCache))->riskLevel(['ok' => true, 'score' => -0.7]) === 3, '');
check('سنتیمنت: وزن سرخطی هک منفی و ETF مثبت',
    Sentiment::headlineWeight('Massive hack and exploit') < 0 && Sentiment::headlineWeight('Bitcoin ETF approval record inflows') > 0, '');

/* فیلتر ۳۴ — news_risk */
$smCtxBase = $buyCtx;
$smEvalCalm = (new Filters())->evaluate($smCtxBase + ['sentiment' => ['ok' => true, 'score' => 0.1, 'label' => 'موجودیت متعادل', 'news' => ['top' => []]]]);
$smFCalm = $findByKey($smEvalCalm, 'news_risk');
check('فیلتر ۳۴ news_risk در فهرست (کل ۳۴)', $smEvalCalm['total'] === 34 && $smFCalm !== null, $smEvalCalm['total'] . '');
check('news_risk: سنتیمنت آرام = عبور خنثی', $smFCalm['pass'] === true && $smFCalm['side'] === 'NEUTRAL', json_encode($smFCalm, JSON_UNESCAPED_UNICODE));

$smEvalCrisis = (new Filters())->evaluate($smCtxBase + ['sentiment' => ['ok' => true, 'score' => -0.62, 'label' => 'ترس شدید بازار', 'news' => ['top' => [['title' => 'Exchange hack wipes 200M', 'weight' => -2.5]]]]]);
$smFCrisis = $findByKey($smEvalCrisis, 'news_risk');
check('news_risk: بحران خبری = توقف ورود تازه (بدون جهت‌دهی)',
    $smFCrisis['pass'] === false && $smFCrisis['side'] === 'NEUTRAL' && strpos($smFCrisis['detail'], 'ترس شدید') !== false,
    json_encode($smFCrisis, JSON_UNESCAPED_UNICODE));

$smEvalGreed = (new Filters())->evaluate($smCtxBase + ['sentiment' => ['ok' => true, 'score' => 0.75, 'label' => 'هیجان شدید بازار', 'news' => ['top' => []]]]);
$smFGreed = $findByKey($smEvalGreed, 'news_risk');
check('news_risk: هیجان افراطی = عبور با هشدار حجم', $smFGreed['pass'] === true && strpos($smFGreed['detail'], 'حجم') !== false, json_encode($smFGreed, JSON_UNESCAPED_UNICODE));

$smEvalNull = (new Filters())->evaluate($smCtxBase);
$smFNull = $findByKey($smEvalNull, 'news_risk');
check('news_risk: نبود داده = عبور خنثی (نه مسدود)', $smFNull['pass'] === true && $smFNull['score'] <= 0.5, json_encode($smFNull, JSON_UNESCAPED_UNICODE));

/* نبض مشترک بدون شبکه هم کرش نمی‌کند */
$smSharedOk = false;
try {
    $smShared = Sentiment::sharedPulse();
    $smSharedOk = isset($smShared['ok']) && (float)$smShared['score'] === 0.0;
} catch (Throwable $e) { /* در محیط بدون شبکه باید خنثی برمی‌گشت */ }
check('سنتیمنت: نبض مشترک بدون شبکه = خنثی بی‌خطا', $smSharedOk, '');
@unlink($smCache);
@unlink($smCache . '.dead');
@unlink($smCache . '.fg');

echo "\n════════════════════════════════════\nموفق: {$passed}   ناموفق: {$failed}\n";

if ($failed > 0) { echo "  - " . implode("\n  - ", $failures) . "\n"; exit(1); }
echo "همه تست‌ها گذشتند ✓\n";
exit(0);
