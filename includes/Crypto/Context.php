<?php
namespace Meelano\Crypto;

/**
 * سازندهٔ «بافت تحلیل» — تمام اندیکاتورهای یک سری کندل، یک‌بار محاسبه می‌شوند.
 *
 * دو خروجی دارد:
 *   series()   → آرایهٔ کامل اندیکاتورها (برای بک‌تست؛ مقدار هر ایندکس فقط از
 *                داده‌های ≤ همان ایندکس ساخته شده = بدون نشت آینده)
 *   snapshot() → مقادیر لحظه‌ای/آخرین برای موتور سیگنال زنده
 *
 * این جداسازی، اسکن زنده و بک‌تست را از یک ریاضی واحد تغذیه می‌کند؛
 * یعنی نتایج بک‌تست با منطق زنده سازگار است (قابلیت حسابرسی).
 *
 * @author Milad Yaghoobi — Meelano Studio Design
 */
final class Context
{
    /**
     * محاسبهٔ سری کامل اندیکاتورها روی یک آرایهٔ کندل استاندارد.
     *
     * @param array $candles هر عضو: ['time','open','high','low','close','volume']
     * @return array سری‌های اندیکاتور
     */
    public static function series(array $candles): array
    {
        $opens = array_column($candles, 'open');
        $highs = array_column($candles, 'high');
        $lows = array_column($candles, 'low');
        $closes = array_column($candles, 'close');
        $volumes = array_column($candles, 'volume');

        [$macdLine, $signalLine, $hist] = Indicators::macd($closes);
        [$bbMid, $bbUp, $bbLo, $bbWidth] = Indicators::bollinger($closes);
        [$stochK, $stochD] = Indicators::stochastic($highs, $lows, $closes);
        [$adx, $plusDi, $minusDi] = Indicators::adx($highs, $lows, $closes);
        [$stLine, $stDir] = Indicators::supertrend($highs, $lows, $closes);

        return [
            'n' => count($candles),
            'time' => array_column($candles, 'time'),
            'open' => $opens,
            'high' => $highs,
            'low' => $lows,
            'close' => $closes,
            'volume' => $volumes,
            'rsi' => Indicators::rsi($closes, 14),
            'ema9' => Indicators::ema($closes, 9),
            'ema21' => Indicators::ema($closes, 21),
            'ema50' => Indicators::ema($closes, 50),
            'ema200' => Indicators::ema($closes, 200),
            'macd' => $macdLine,
            'macd_signal' => $signalLine,
            'macd_hist' => $hist,
            'bb_mid' => $bbMid,
            'bb_upper' => $bbUp,
            'bb_lower' => $bbLo,
            'bb_width' => $bbWidth,
            'stoch_k' => $stochK,
            'stoch_d' => $stochD,
            'atr' => Indicators::atr($highs, $lows, $closes, 14),
            'adx' => $adx,
            'plus_di' => $plusDi,
            'minus_di' => $minusDi,
            'supertrend' => $stLine,
            'supertrend_dir' => $stDir,
            'obv' => Indicators::obv($closes, $volumes),
            'vwap' => Indicators::vwap($highs, $lows, $closes, $volumes, 20),
            'roc24' => Indicators::roc($closes, 24),
        ];
    }

    /**
     * عکس‌العمل لحظه‌ای از سری در ایندکس i (بدون دیدن آینده).
     * برای i منهایی یا خیلی کوچک، مقادیر ممکن است null باشند.
     *
     * @return array بافت آماده برای Regime/Filters/RiskManager
     */
    public static function snapshot(array $s, int $i, array $ticker = [], array $cfg = []): array
    {
        $n = (int)$s['n'];
        $i = max(0, min($i, $n - 1));
        $price = (float)$s['close'][$i];

        $atr = (float)(Indicators::at($s['atr'], $i) ?? 0.0);
        $atrPct = $price > 0 ? ($atr / $price) * 100 : 0.0;
        $bbUp = (float)(Indicators::at($s['bb_upper'], $i) ?? $price);
        $bbLo = (float)(Indicators::at($s['bb_lower'], $i) ?? $price);
        $bbRange = $bbUp - $bbLo;
        $bbPos = $bbRange > 0 ? max(0.0, min(1.0, ($price - $bbLo) / $bbRange)) : 0.5;
        $bbWidth = Indicators::at($s['bb_width'], $i);

        $macdHist = Indicators::at($s['macd_hist'], $i);
        $macdHistPrev = null;
        for ($j = $i - 1; $j >= 0; $j--) {
            if ($s['macd_hist'][$j] !== null) { $macdHistPrev = $s['macd_hist'][$j]; break; }
        }

        $vwap = Indicators::at($s['vwap'], $i);
        $obv = $s['obv'];
        $obvSlope = Indicators::slope(array_slice($obv, 0, $i + 1), 14);
        $priceSlope = Indicators::slope(array_slice($s['close'], 0, $i + 1), 14);

        // حجم نسبی تا ایندکس i
        $volWindow = array_slice($s['volume'], 0, $i + 1);
        $volRatio = Indicators::volumeRatio($volWindow) ?? 1.0;

        $adx = Indicators::at($s['adx'], $i);

        $stochK = Indicators::at($s['stoch_k'], $i);
        $stochD = Indicators::at($s['stoch_d'], $i);

        return [
            'price' => $price,
            'rsi' => round((float)(Indicators::at($s['rsi'], $i) ?? 50.0), 2),
            'ema9' => (float)(Indicators::at($s['ema9'], $i) ?? $price),
            'ema21' => (float)(Indicators::at($s['ema21'], $i) ?? $price),
            'ema50' => (float)(Indicators::at($s['ema50'], $i) ?? $price),
            'ema200' => (float)(Indicators::at($s['ema200'], $i) ?? $price),
            'macd_hist' => $macdHist !== null ? (float)$macdHist : 0.0,
            'macd_hist_prev' => $macdHistPrev !== null ? (float)$macdHistPrev : 0.0,
            'bb_upper' => $bbUp,
            'bb_lower' => $bbLo,
            'bb_pos' => round($bbPos, 3),
            'bb_width' => $bbWidth !== null ? round((float)$bbWidth, 3) : null,
            'bb_width_pct' => Indicators::percentileRank(array_slice($s['bb_width'], 0, $i + 1), $bbWidth, 100),
            'stoch_k' => $stochK !== null ? round((float)$stochK, 2) : 50.0,
            'stoch_d' => $stochD !== null ? round((float)$stochD, 2) : 50.0,
            'atr' => $atr,
            'atr_pct' => round($atrPct, 3),
            'adx' => $adx !== null ? round((float)$adx, 2) : null,
            'plus_di' => Indicators::at($s['plus_di'], $i),
            'minus_di' => Indicators::at($s['minus_di'], $i),
            'supertrend_dir' => Indicators::at($s['supertrend_dir'], $i),
            'supertrend_line' => Indicators::at($s['supertrend'], $i),
            'obv_slope' => $obvSlope !== null ? round((float)$obvSlope, 4) : null,
            'price_slope' => $priceSlope !== null ? round((float)$priceSlope, 4) : null,
            'vwap' => $vwap !== null ? round((float)$vwap, 8) : null,
            'vol_ratio' => round((float)$volRatio, 2),
            'change24' => (float)($ticker['change_pct'] ?? (Indicators::at($s['roc24'], $i) ?? 0)),
            'quote_volume' => (float)($ticker['quote_volume'] ?? 0),
            'structure' => Indicators::structure(array_slice($s['close'], 0, $i + 1), 12),
            'wick_ratio' => round((float)(Indicators::upperWickRatio($s['high'], $s['low'], $s['open'], $s['close'], $i) ?? 0), 3),
            'swing_low' => Indicators::swingLow(array_slice($s['low'], 0, $i + 1), 20),
            'swing_high' => Indicators::swingHigh(array_slice($s['high'], 0, $i + 1), 20),
            'min_quote_volume' => (float)($cfg['min_quote_volume'] ?? 5000000),
        ];
    }

    /** میان‌بر: بافت لحظه‌ای آخرین کندل (تحلیل زنده). */
    public static function build(array $candles, array $ticker = [], array $cfg = []): array
    {
        $s = self::series($candles);
        $ctx = self::snapshot($s, count($candles) - 1, $ticker, $cfg);
        $ctx['series'] = $s;
        return $ctx;
    }
}
