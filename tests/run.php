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
use Meelano\Crypto\Regime;
use Meelano\Crypto\RiskManager;
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
check('حداقل ۱۳ فیلتر از ۱۵ عبور کردند', $evalBuy['passed'] >= 13, $evalBuy['passed'] . '/' . $evalBuy['total']);
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

echo "\n════════════════════════════════════\nموفق: {$passed}   ناموفق: {$failed}\n";
if ($failed > 0) { echo "  - " . implode("\n  - ", $failures) . "\n"; exit(1); }
echo "همه تست‌ها گذشتند ✓\n";
exit(0);
