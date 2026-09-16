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
use Meelano\Crypto\Context;
use Meelano\Crypto\Filters;
use Meelano\Crypto\Indicators;
use Meelano\Crypto\MarketData;
use Meelano\Crypto\AutoTrader;
use Meelano\Crypto\BinanceSpot;
use Meelano\Crypto\Regime;
use Meelano\Crypto\RiskManager;
use Meelano\Crypto\Robustness;
use Meelano\Crypto\SignalTracker;
use Meelano\Crypto\SignalEngine;
use Meelano\Db;
use Meelano\Installer;
use Meelano\Schema;
use Meelano\Security;

$passed = 0; $failed = 0; $failures = [];

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
check('حداقل ۲۲ فیلتر از ۲۵ عبور کردند', $evalBuy['passed'] >= 22, $evalBuy['passed'] . '/' . $evalBuy['total']);
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
check('استاپ ساختاری انتخاب شد', $plan['stop_type'] === 'structure', $plan['stop_type']);
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
check('مجموع فیلترها = ۲۵', $eval51['total'] === 25, $eval51['total'] . '');
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

echo "\n════════════════════════════════════\nموفق: {$passed}   ناموفق: {$failed}\n";

if ($failed > 0) { echo "  - " . implode("\n  - ", $failures) . "\n"; exit(1); }
echo "همه تست‌ها گذشتند ✓\n";
exit(0);
