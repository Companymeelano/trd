<?php
namespace Meelano\Crypto;

/**
 * توابع تحلیل تکنیکال — پیاده‌سازی خالص و قطعی (بدون وابستگی).
 *
 * همه توابع روی آرایهٔ عددی کار می‌کنند تا قابل تست و بازتولید باشند.
 * این لایه «ریاضی» است؛ تصمیم‌گیری در Filters و SignalEngine انجام می‌شود.
 *
 * نسخهٔ ۵: ADX/DI (وایلدر)، OBV، VWAP غلتان، Supertrend، عرض باند بولینگر،
 * شیب رگرسیون خطی، سوئینگ‌های ساختاری و رتبهٔ صدکی — مجموعهٔ کامل یک میز معاملاتی نهادی.
 *
 * @author Milad Yaghoobi — Meelano Studio Design
 */
final class Indicators
{
    /* ═══ میانگین‌ها ═════════════════════════════════════════════════════ */

    /** میانگین متحرک ساده. */
    public static function sma(array $values, int $period): array
    {
        $out = [];
        $n = count($values);
        $sum = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $sum += $values[$i];
            if ($i >= $period) {
                $sum -= $values[$i - $period];
            }
            $out[] = $i >= $period - 1 ? $sum / $period : null;
        }
        return $out;
    }

    /** میانگین متحرک نمایی. */
    public static function ema(array $values, int $period): array
    {
        $out = [];
        $n = count($values);
        if ($n === 0) {
            return $out;
        }
        $k = 2.0 / ($period + 1);
        $ema = $values[0];
        for ($i = 0; $i < $n; $i++) {
            $ema = $i === 0 ? $values[0] : ($values[$i] * $k + $ema * (1 - $k));
            $out[] = $i >= $period - 1 ? $ema : null;
        }
        return $out;
    }

    /** میانگین متحرک نمایی روی کل سری از ابتدا (برای هم‌ترازی سری‌های MACD). */
    public static function emaRaw(array $values, int $period): array
    {
        $out = [];
        $n = count($values);
        if ($n === 0) {
            return $out;
        }
        $k = 2.0 / ($period + 1);
        $ema = $values[0];
        for ($i = 0; $i < $n; $i++) {
            $ema = $i === 0 ? $values[0] : ($values[$i] * $k + $ema * (1 - $k));
            $out[] = $ema;
        }
        return $out;
    }

    /* ═══ نوسان‌سنج‌ها ══════════════════════════════════════════════════ */

    /** شاخص قدرت نسبی (Wilder). */
    public static function rsi(array $closes, int $period = 14): array
    {
        $out = [null];
        $n = count($closes);
        $gains = 0.0;
        $losses = 0.0;
        $avgG = null;
        $avgL = null;
        for ($i = 1; $i < $n; $i++) {
            $change = $closes[$i] - $closes[$i - 1];
            $gain = max(0.0, $change);
            $loss = max(0.0, -$change);
            if ($i <= $period) {
                $gains += $gain;
                $losses += $loss;
                $out[] = null;
                if ($i === $period) {
                    $avgG = $gains / $period;
                    $avgL = $losses / $period;
                    $out[$i] = self::rsiFromAvg($avgG, $avgL);
                }
            } else {
                $avgG = (($avgG ?? 0.0) * ($period - 1) + $gain) / $period;
                $avgL = (($avgL ?? 0.0) * ($period - 1) + $loss) / $period;
                $out[] = self::rsiFromAvg($avgG, $avgL);
            }
        }
        return $out;
    }

    private static function rsiFromAvg(float $avgG, float $avgL): float
    {
        if ($avgL == 0.0) {
            return $avgG == 0.0 ? 50.0 : 100.0;
        }
        $rs = $avgG / $avgL;
        return 100.0 - (100.0 / (1.0 + $rs));
    }

    /** MACD → [macd, signal, histogram]. */
    public static function macd(array $closes, int $fast = 12, int $slow = 26, int $signal = 9): array
    {
        $emaFast = self::emaRaw($closes, $fast);
        $emaSlow = self::emaRaw($closes, $slow);
        $macdLine = [];
        $n = count($closes);
        for ($i = 0; $i < $n; $i++) {
            $macdLine[] = $emaFast[$i] - $emaSlow[$i];
        }
        $signalLine = self::emaRaw($macdLine, $signal);
        $hist = [];
        for ($i = 0; $i < $n; $i++) {
            $hist[] = $macdLine[$i] - $signalLine[$i];
        }
        // مقادیر قبل از بلوغِ کند EMA معتبر نیستند
        for ($i = 0; $i < $n; $i++) {
            if ($i < $slow + $signal - 2) {
                $macdLine[$i] = null;
                $signalLine[$i] = null;
                $hist[$i] = null;
            }
        }
        return [$macdLine, $signalLine, $hist];
    }

    /** بولینگر → [middle, upper, lower, width%]. */
    public static function bollinger(array $closes, int $period = 20, float $mult = 2.0): array
    {
        $middle = self::sma($closes, $period);
        $upper = [];
        $lower = [];
        $width = [];
        $n = count($closes);
        for ($i = 0; $i < $n; $i++) {
            if ($middle[$i] === null) {
                $upper[] = null;
                $lower[] = null;
                $width[] = null;
                continue;
            }
            $slice = array_slice($closes, $i - $period + 1, $period);
            $variance = 0.0;
            foreach ($slice as $v) {
                $variance += ($v - $middle[$i]) ** 2;
            }
            $sd = sqrt($variance / $period);
            $upper[] = $middle[$i] + $mult * $sd;
            $lower[] = $middle[$i] - $mult * $sd;
            $width[] = $middle[$i] > 0 ? ((($middle[$i] + $mult * $sd) - ($middle[$i] - $mult * $sd)) / $middle[$i]) * 100 : null;
        }
        return [$middle, $upper, $lower, $width];
    }

    /** استوکستیک کند → [K, D]. */
    public static function stochastic(array $highs, array $lows, array $closes, int $period = 14, int $smooth = 3): array
    {
        $k = [];
        $n = count($closes);
        for ($i = 0; $i < $n; $i++) {
            if ($i < $period - 1) {
                $k[] = null;
                continue;
            }
            $hh = max(array_slice($highs, $i - $period + 1, $period));
            $ll = min(array_slice($lows, $i - $period + 1, $period));
            $k[] = ($hh - $ll) > 0 ? (($closes[$i] - $ll) / ($hh - $ll)) * 100 : 50.0;
        }
        $d = self::alignCompact($k, static function (array $compact) use ($smooth) {
            return self::sma($compact, $smooth);
        });
        return [$k, $d];
    }

    /** میانگین دامنهٔ واقعی (ATR — Wilder). */
    public static function atr(array $highs, array $lows, array $closes, int $period = 14): array
    {
        $n = count($closes);
        $tr = [];
        for ($i = 0; $i < $n; $i++) {
            if ($i === 0) {
                $tr[] = $highs[$i] - $lows[$i];
            } else {
                $tr[] = max(
                    $highs[$i] - $lows[$i],
                    abs($highs[$i] - $closes[$i - 1]),
                    abs($lows[$i] - $closes[$i - 1])
                );
            }
        }
        $atr = [null];
        $sum = 0.0;
        $prev = null;
        for ($i = 1; $i < $n; $i++) {
            if ($i <= $period) {
                $sum += $tr[$i];
                if ($i === $period) {
                    $prev = $sum / $period;
                    $atr[] = $prev;
                } else {
                    $atr[] = null;
                }
            } else {
                $prev = (($prev ?? 0.0) * ($period - 1) + $tr[$i]) / $period;
                $atr[] = $prev;
            }
        }
        return $atr;
    }

    /* ═══ قدرت روند و جریان سرمایه ══════════════════════════════════════ */

    /**
     * ADX/DI (وایلدر) → [adx[], +di[], -di[]].
     * ADX > ۲۵ = روند قوی؛ بین ۲۰-۲۵ = روند نوپا؛ < ۲۰ = بازار رِنج.
     */
    public static function adx(array $highs, array $lows, array $closes, int $period = 14): array
    {
        $n = count($closes);
        $adx = array_fill(0, $n, null);
        $plusDi = array_fill(0, $n, null);
        $minusDi = array_fill(0, $n, null);
        if ($n < $period * 2 + 2) {
            return [$adx, $plusDi, $minusDi];
        }

        $tr = 0.0; $pdm = 0.0; $mdm = 0.0;
        $trS = null; $pdmS = null; $mdmS = null;
        $dxs = [];
        $adxVal = null;
        for ($i = 1; $i < $n; $i++) {
            $upMove = $highs[$i] - $highs[$i - 1];
            $downMove = $lows[$i - 1] - $lows[$i];
            $pdm_t = ($upMove > $downMove && $upMove > 0) ? $upMove : 0.0;
            $mdm_t = ($downMove > $upMove && $downMove > 0) ? $downMove : 0.0;
            $tr_t = max(
                $highs[$i] - $lows[$i],
                abs($highs[$i] - $closes[$i - 1]),
                abs($lows[$i] - $closes[$i - 1])
            );

            if ($i <= $period) {
                $tr += $tr_t; $pdm += $pdm_t; $mdm += $mdm_t;
                if ($i < $period) { continue; }
                $trS = $tr; $pdmS = $pdm; $mdmS = $mdm;
            } else {
                $trS = $trS - ($trS / $period) + $tr_t;
                $pdmS = $pdmS - ($pdmS / $period) + $pdm_t;
                $mdmS = $mdmS - ($mdmS / $period) + $mdm_t;
            }

            $pd = ($trS > 0) ? 100.0 * $pdmS / $trS : 0.0;
            $md = ($trS > 0) ? 100.0 * $mdmS / $trS : 0.0;
            $plusDi[$i] = $pd;
            $minusDi[$i] = $md;
            $sum = $pd + $md;
            $dxs[] = $sum > 0 ? 100.0 * abs($pd - $md) / $sum : 0.0;

            $cnt = count($dxs);
            if ($cnt === $period) {
                $adxVal = array_sum($dxs) / $period;
            } elseif ($cnt > $period) {
                $adxVal = (($adxVal * ($period - 1)) + $dxs[$cnt - 1]) / $period;
            }
            if ($adxVal !== null) {
                $adx[$i] = $adxVal;
            }
        }
        return [$adx, $plusDi, $minusDi];
    }

    /** حجم تعادلی (OBV) — انباشت/توزیع هوشمندانه. */
    public static function obv(array $closes, array $volumes): array
    {
        $out = [0.0];
        $n = count($closes);
        for ($i = 1; $i < $n; $i++) {
            if ($closes[$i] > $closes[$i - 1]) {
                $out[] = $out[$i - 1] + (float)$volumes[$i];
            } elseif ($closes[$i] < $closes[$i - 1]) {
                $out[] = $out[$i - 1] - (float)$volumes[$i];
            } else {
                $out[] = $out[$i - 1];
            }
        }
        return $out;
    }

    /** VWAP غلتان روی N کندل — مرجع نهادی برای میانگین هزینهٔ近期. */
    public static function vwap(array $highs, array $lows, array $closes, array $volumes, int $period = 20): array
    {
        $n = count($closes);
        $out = array_fill(0, $n, null);
        $pvSum = 0.0;
        $vSum = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $tp = ($highs[$i] + $lows[$i] + $closes[$i]) / 3.0;
            $pv = $tp * (float)$volumes[$i];
            $pvSum += $pv;
            $vSum += (float)$volumes[$i];
            if ($i >= $period) {
                $tpOld = ($highs[$i - $period] + $lows[$i - $period] + $closes[$i - $period]) / 3.0;
                $pvSum -= $tpOld * (float)$volumes[$i - $period];
                $vSum -= (float)$volumes[$i - $period];
            }
            if ($i >= $period - 1 && $vSum > 0) {
                $out[$i] = $pvSum / $vSum;
            }
        }
        return $out;
    }

    /**
     * Supertrend → [line[], dir[]] — dir=+1 صعودی، dir=-1 نزولی.
     * تریلینگ‌استاپِ ساختاری که در روند قوی سیگنال نگه می‌دارد و در شکست خارج می‌کند.
     */
    public static function supertrend(array $highs, array $lows, array $closes, int $period = 10, float $mult = 3.0): array
    {
        $n = count($closes);
        $line = array_fill(0, $n, null);
        $dir = array_fill(0, $n, null);
        if ($n === 0) {
            return [$line, $dir];
        }
        $atr = self::atr($highs, $lows, $closes, $period);
        $finalUpper = null;
        $finalLower = null;
        $curDir = 1;
        for ($i = 0; $i < $n; $i++) {
            if ($atr[$i] === null) {
                continue;
            }
            $mid = ($highs[$i] + $lows[$i]) / 2.0;
            $bu = $mid + $mult * $atr[$i];
            $bl = $mid - $mult * $atr[$i];
            $finalUpper = ($finalUpper === null || $bu < $finalUpper || $closes[$i - 1] > $finalUpper) ? $bu : $finalUpper;
            $finalLower = ($finalLower === null || $bl > $finalLower || $closes[$i - 1] < $finalLower) ? $bl : $finalLower;
            if ($closes[$i] > $finalUpper) {
                $curDir = 1;
            } elseif ($closes[$i] < $finalLower) {
                $curDir = -1;
            }
            $dir[$i] = $curDir;
            $line[$i] = $curDir === 1 ? $finalLower : $finalUpper;
        }
        return [$line, $dir];
    }

    /* ═══ ساختار و آمار ═════════════════════════════════════════════════ */

    /** شیب رگرسیون خطی نرمال‌شده (٪ در هر کندل نسبت به میانگین). مثبت = روند صعودی. */
    public static function slope(array $values, int $period): ?float
    {
        $n = count($values);
        if ($n < $period || $period < 2) {
            return null;
        }
        $slice = array_slice($values, $n - $period);
        $cnt = count($slice);
        $sumX = $sumY = $sumXY = $sumX2 = 0.0;
        $meanY = array_sum($slice) / $cnt;
        for ($i = 0; $i < $cnt; $i++) {
            $x = $i;
            $y = $slice[$i];
            $sumX += $x;
            $sumY += $y;
            $sumXY += $x * $y;
            $sumX2 += $x * $x;
        }
        $denom = ($cnt * $sumX2) - ($sumX * $sumX);
        if ($denom == 0.0 || $meanY == 0.0) {
            return null;
        }
        $slope = (($cnt * $sumXY) - ($sumX * $sumY)) / $denom;
        return ($slope / $meanY) * 100.0;
    }

    /** نرخ تغییر درصدی نسبت به n کندل قبل. */
    public static function roc(array $closes, int $period = 1): array
    {
        $out = [];
        $n = count($closes);
        for ($i = 0; $i < $n; $i++) {
            $prev = $i - $period >= 0 ? $closes[$i - $period] : null;
            $out[] = ($prev !== null && $prev != 0) ? (($closes[$i] - $prev) / $prev) * 100 : null;
        }
        return $out;
    }

    /** نسبت حجم فعلی به میانگین حجم. */
    public static function volumeRatio(array $volumes, int $period = 20): ?float
    {
        $n = count($volumes);
        if ($n < $period + 1) {
            return null;
        }
        $avg = array_sum(array_slice($volumes, $n - $period - 1, $period)) / $period;
        return $avg > 0 ? $volumes[$n - 1] / $avg : null;
    }

    /** تشخیص ساختار سقف/کف بالاتر. */
    public static function structure(array $closes, int $lookback = 10): string
    {
        $n = count($closes);
        if ($n < $lookback * 2) {
            return 'range';
        }
        $firstHigh = max(array_slice($closes, $n - $lookback * 2, $lookback));
        $secondHigh = max(array_slice($closes, $n - $lookback, $lookback));
        $firstLow = min(array_slice($closes, $n - $lookback * 2, $lookback));
        $secondLow = min(array_slice($closes, $n - $lookback, $lookback));
        if ($secondHigh > $firstHigh && $secondLow > $firstLow) {
            return 'uptrend';
        }
        if ($secondHigh < $firstHigh && $secondLow < $firstLow) {
            return 'downtrend';
        }
        return 'range';
    }

    /** کف سوئینگ اخیر (برای استاپ ساختاری در خرید). */
    public static function swingLow(array $lows, int $lookback = 20): ?float
    {
        $n = count($lows);
        if ($n === 0) {
            return null;
        }
        return min(array_slice($lows, max(0, $n - $lookback)));
    }

    /** سقف سوئینگ اخیر (برای استاپ ساختاری در فروش). */
    public static function swingHigh(array $highs, int $lookback = 20): ?float
    {
        $n = count($highs);
        if ($n === 0) {
            return null;
        }
        return max(array_slice($highs, max(0, $n - $lookback)));
    }

    /** نسبت سایهٔ بالایی به کل دامنه (تشخیص فشار فروش/منیپولیشن). */
    public static function upperWickRatio(array $highs, array $lows, array $opens, array $closes, ?int $at = null): ?float
    {
        $n = count($closes);
        if ($n === 0) {
            return null;
        }
        $i = $at ?? ($n - 1);
        if ($i < 0 || $i >= $n) {
            return null;
        }
        $range = $highs[$i] - $lows[$i];
        if ($range <= 0) {
            return null;
        }
        $upperWick = $highs[$i] - max($closes[$i], $opens[$i]);
        return $upperWick / $range;
    }

    /** رتبهٔ صدکی مقدار نسبت به آخرین n عضو سری (۰..۱۰۰). برای فشردگی باند/نوسان. */
    public static function percentileRank(array $series, ?float $value, int $window = 100): ?float
    {
        if ($value === null) {
            return null;
        }
        $clean = array_values(array_filter(array_slice($series, -$window), static function ($v) {
            return $v !== null;
        }));
        $cnt = count($clean);
        if ($cnt < 10) {
            return null;
        }
        $below = 0;
        foreach ($clean as $v) {
            if ($v <= $value) {
                $below++;
            }
        }
        return ($below / $cnt) * 100.0;
    }

    /** آخرین مقدار غیرتهی یک آرایه. */
    public static function last(array $values)
    {
        for ($i = count($values) - 1; $i >= 0; $i--) {
            if ($values[$i] !== null) {
                return $values[$i];
            }
        }
        return null;
    }

    /** مقدار غیرتهی در ایندکس i (یا نزدیک‌ترین قبلی — برای بک‌تست بدون نشت داده). */
    public static function at(array $values, int $i)
    {
        $n = count($values);
        for ($j = min($i, $n - 1); $j >= 0; $j--) {
            if ($values[$j] !== null) {
                return $values[$j];
            }
        }
        return null;
    }

    /** هم‌تراز خروجی یک تابع روی بخش فشردهٔ غیرتهی. */
    private static function alignCompact(array $series, callable $fn): array
    {
        $n = count($series);
        $compactIdx = [];
        $compact = [];
        for ($i = 0; $i < $n; $i++) {
            if ($series[$i] !== null) {
                $compactIdx[] = $i;
                $compact[] = $series[$i];
            }
        }
        $computed = $fn($compact);
        $out = array_fill(0, $n, null);
        foreach ($compactIdx as $k => $i) {
            $out[$i] = $computed[$k] ?? null;
        }
        return $out;
    }

    /* ═══════════════ اندیکاتورهای نسخهٔ ۵٫۱ (دقت پیشرفته) ═══════════════ */

    /**
     * نسبت کارایی کافمن (ER) — تفکیک دقیق‌تر روند از رِنج نسبت به ADX.
     * ER = |close(i) − close(i−n)| ÷ Σ|close(j) − close(j−1)|
     * نزدیک ۱ = حرکت کارآمد (روند قوی) · نزدیک ۰ = رِنج/نویز.
     */
    public static function kaufmanER(array $closes, int $period = 10): array
    {
        $n = count($closes);
        $out = array_fill(0, $n, null);
        if ($n < $period + 1) {
            return $out;
        }
        $sum = 0.0;
        for ($i = 1; $i <= $period; $i++) {
            $sum += abs($closes[$i] - $closes[$i - 1]);
        }
        for ($i = $period; $i < $n; $i++) {
            if ($i > $period) {
                $sum += abs($closes[$i] - $closes[$i - 1]);
                $sum -= abs($closes[$i - $period] - $closes[$i - $period - 1]);
            }
            $change = abs($closes[$i] - $closes[$i - $period]);
            $out[$i] = $sum > 0 ? $change / $sum : 0.0;
        }
        return $out;
    }

    /**
     * قله‌های محلی (پیوت) در پنجرهٔ منتهی به i — برای واگرایی.
     * هر پیوت باید w کندل از هر طرف بزرگ‌تر (یا کوچک‌تر) از همسایگان باشد.
     * @return array<int,array{idx:int,val:float}> جدیدترین در انتها
     */
    public static function pivots(array $values, int $i, bool $high, int $w = 3, int $count = 2, int $lookback = 60): array
    {
        $from = max($w, $i - $lookback);
        $out = [];
        for ($j = $from; $j <= $i - $w; $j++) {
            $v = $values[$j];
            if ($v === null) { continue; }
            $isPivot = true;
            for ($k = $j - $w; $k <= $j + $w; $k++) {
                if ($k === $j || $k < 0 || !isset($values[$k]) || $values[$k] === null) { continue; }
                if ($high ? ($values[$k] > $v) : ($values[$k] < $v)) { $isPivot = false; break; }
            }
            if ($isPivot) {
                $out[] = ['idx' => $j, 'val' => (float)$v];
                if (count($out) >= $count) { break; }
            }
        }
        return $out;
    }

    /**
     * واگرایی کلاسیک بین قیمت و اسیلاتور (RSI/OBV) در ایندکس i.
     * bull = کف پایین‌تر قیمت + کف بالاتر اسیلاتور
     * bear = سقف بالاتر قیمت + سقف پایین‌تر اسیلاتور
     * @return array{type:?string,strength:float,detail:string}
     */
    public static function divergence(array $price, array $osc, int $i, int $lookback = 70): array
    {
        $none = ['type' => null, 'strength' => 0.0, 'detail' => ''];
        if ($i < 10 || $i >= count($price)) {
            return $none;
        }
        // واگرایی صعودی روی دو کف اخیر
        $lows = self::pivots($price, $i, false, 3, 2, $lookback);
        if (count($lows) === 2) {
            [$a, $b] = $lows;
            $oa = $osc[$a['idx']] ?? null;
            $ob = $osc[$b['idx']] ?? null;
            if ($oa !== null && $ob !== null
                && $b['val'] < $a['val'] * 0.999 && $ob > $oa * 1.001) {
                $gap = abs($a['val'] - $b['val']) / max(1e-9, $a['val']);
                return [
                    'type' => 'bull',
                    'strength' => round(min(1.0, 0.5 + $gap * 8), 2),
                    'detail' => 'کف پایین‌تر قیمت با کف بالاتر اسیلاتور',
                ];
            }
        }
        // واگرایی نزولی روی دو سقف اخیر
        $highs = self::pivots($price, $i, true, 3, 2, $lookback);
        if (count($highs) === 2) {
            [$a, $b] = $highs;
            $oa = $osc[$a['idx']] ?? null;
            $ob = $osc[$b['idx']] ?? null;
            if ($oa !== null && $ob !== null
                && $b['val'] > $a['val'] * 1.001 && $ob < $oa * 0.999) {
                $gap = abs($b['val'] - $a['val']) / max(1e-9, $a['val']);
                return [
                    'type' => 'bear',
                    'strength' => round(min(1.0, 0.5 + $gap * 8), 2),
                    'detail' => 'سقف بالاتر قیمت با سقف پایین‌تر اسیلاتور',
                ];
            }
        }
        return $none;
    }

    /**
     * گپ ارزش منصفانه (FVG) در کندل‌های i−2..i.
     * صعودی: low(i) > high(i−2) · نزولی: high(i) < low(i−2)
     * @return array{side:string,low:float,high:float,mid:float,size_pct:float}|null
     */
    public static function fvgAt(array $highs, array $lows, int $i): ?array
    {
        if ($i < 2) { return null; }
        $h0 = $highs[$i - 2] ?? null;
        $l2 = $lows[$i] ?? null;
        $l0 = $lows[$i - 2] ?? null;
        $h2 = $highs[$i] ?? null;
        if ($h0 === null || $l2 === null || $l0 === null || $h2 === null) { return null; }
        if ($l2 > $h0) { // FVG صعودی
            $gap = $l2 - $h0;
            return [
                'side' => 'bull', 'low' => (float)$h0, 'high' => (float)$l2,
                'mid' => round(($h0 + $l2) / 2, 8),
                'size_pct' => round($gap / max(1e-9, $h0) * 100, 3),
            ];
        }
        if ($h2 < $l0) { // FVG نزولی
            $gap = $l0 - $h2;
            return [
                'side' => 'bear', 'low' => (float)$h2, 'high' => (float)$l0,
                'mid' => round(($h2 + $l0) / 2, 8),
                'size_pct' => round($gap / max(1e-9, $l0) * 100, 3),
            ];
        }
        return null;
    }

    /**
     * پروفایل حجم ساده — نقطهٔ کنترل (POC) پنجرهٔ منتهی به i.
     * قیمت معیار به bucketها تقسیم و حجم هر سطل جمع می‌شود؛ POC = مرکز پرحجم‌ترین سطل.
     * @return array{poc:float,value_area:float}|null
     */
    public static function poc(array $highs, array $lows, array $closes, array $volumes, int $i, int $window = 100, int $buckets = 24): ?array
    {
        $from = max(0, $i - $window + 1);
        if ($i - $from + 1 < 10) { return null; }
        $lo = INF; $hi = -INF;
        for ($j = $from; $j <= $i; $j++) {
            if ($lows[$j] !== null) { $lo = min($lo, $lows[$j]); }
            if ($highs[$j] !== null) { $hi = max($hi, $highs[$j]); }
        }
        if (!is_finite($lo) || !is_finite($hi) || $hi <= $lo) { return null; }
        $step = ($hi - $lo) / $buckets;
        $vol = array_fill(0, $buckets, 0.0);
        for ($j = $from; $j <= $i; $j++) {
            $tp = ($highs[$j] + $lows[$j] + $closes[$j]) / 3;
            $b = (int)floor(($tp - $lo) / $step);
            if ($b >= 0 && $b < $buckets) {
                $vol[$b] += (float)$volumes[$j];
            }
        }
        $best = 0; $bestVol = -1.0;
        $total = 0.0;
        foreach ($vol as $b => $v) {
            $total += $v;
            if ($v > $bestVol) { $bestVol = $v; $best = $b; }
        }
        if ($total <= 0) { return null; }
        return [
            'poc' => round($lo + ($best + 0.5) * $step, 8),
            'value_area' => round($bestVol / $total, 3), // سهم حجمی سطل POC
        ];
    }

    /** برچسب سشن معاملاتی از مهر زمانی UTC (نقدینگی متفاوت سشن‌ها). */
    public static function sessionOf(int $ts): string
    {
        $dow = (int)gmdate('w', $ts);
        $hour = (int)gmdate('G', $ts);
        if ($dow === 6 || ($dow === 0 && $hour < 22) || ($dow === 5 && $hour >= 22)) {
            return 'weekend';
        }
        $asia = ($hour >= 0 && $hour < 8);
        $europe = ($hour >= 7 && $hour < 16);
        $us = ($hour >= 13 && $hour < 21);
        if ($europe && $us) { return 'overlap'; }
        if ($asia && $europe) { return 'asia_europe'; }
        if ($asia) { return 'asia'; }
        if ($europe) { return 'europe'; }
        if ($us) { return 'america'; }
        return 'off';
    }

    /**
     * شکار نقدینگی (Liquidity Sweep) در ایندکس i:
     * ردِ کف سوئینگ و بسته‌شدن بالای آن با حجم بالا = سویپ صعودی (ورود نهادی).
     * @return array{side:string,level:float,vol_ratio:float}|null
     */
    public static function liquiditySweep(array $highs, array $lows, array $closes, array $volumes, int $i, ?float $swingLow, ?float $swingHigh): ?array
    {
        if ($i < 2 || $swingLow === null || $swingHigh === null) { return null; }
        // میانگین حجم ۲۰ کندلی قبل از i
        $from = max(0, $i - 21);
        $sum = 0.0; $cnt = 0;
        for ($j = $from; $j < $i; $j++) { $sum += (float)$volumes[$j]; $cnt++; }
        $avgVol = $cnt > 0 ? $sum / $cnt : 0.0;
        if ($avgVol <= 0) { return null; }
        $volRatio = (float)$volumes[$i] / $avgVol;

        // سویپ صعودی: سایهٔ پایین کف را شکست، کلوز بالای کف ماند، حجم تأییدی
        if ((float)$lows[$i] < $swingLow && (float)$closes[$i] > $swingLow && $volRatio >= 1.3) {
            return ['side' => 'bull', 'level' => $swingLow, 'vol_ratio' => round($volRatio, 2)];
        }
        // سویپ نزولی: سایهٔ بالا سقف را شکست، کلوز زیر سقف ماند
        if ((float)$highs[$i] > $swingHigh && (float)$closes[$i] < $swingHigh && $volRatio >= 1.3) {
            return ['side' => 'bear', 'level' => $swingHigh, 'vol_ratio' => round($volRatio, 2)];
        }
        return null;
    }

    /**
     * قدرت نسبی نسبت به بیت‌کوین (RS%) — بهترین فیلتر آلت‌کوین.
     شیب ۲۰ کندلی خط نسبت (close/btc) به‌صورت درصد.
     @return float|null مثبت = قوی‌تر از BTC · منفی = ضعیف‌تر
     */
    public static function relativeStrength(array $closes, array $btcCloses, int $period = 20): ?float
    {
        $n = count($closes);
        $m = count($btcCloses);
        if ($n < $period + 1 || $m < $n) { return null; }
        // هم‌تراز انتهایی: کندل آخر نماد با کندل آخر بیت‌کوین
        $off = $m - $n;
        $last = $closes[$n - 1] / max(1e-9, $btcCloses[$m - 1]);
        $prevIdx = $n - 1 - $period;
        if ($prevIdx < 0 || !isset($btcCloses[$off + $prevIdx])) { return null; }
        $prev = $closes[$prevIdx] / max(1e-9, $btcCloses[$off + $prevIdx]);
        if ($prev <= 0) { return null; }
        return round(($last / $prev - 1) * 100, 2);
    }

    /* ═══ اندیکاتورهای نسخهٔ ۵٫۴ (دقت پیشرفته) ═════════════════════════ */

    /**
     * شاخص بریدگی (Choppiness Index, 0..100) — کم = جهت‌دار، زیاد = پرنویز/رِنج.
     * CHOP = 100 × log10(ΣTR / (maxHigh − minLow)) / log10(period)
     */
    public static function choppiness(array $highs, array $lows, array $closes, int $period = 14): array
    {
        $n = count($closes);
        $out = array_fill(0, $n, null);
        for ($i = $period; $i < $n; $i++) {
            $sumTr = 0.0;
            $hi = -INF;
            $lo = INF;
            for ($j = $i - $period + 1; $j <= $i; $j++) {
                $prevClose = $j > 0 ? (float)$closes[$j - 1] : (float)$closes[$j];
                $tr = max(
                    (float)$highs[$j] - (float)$lows[$j],
                    abs((float)$highs[$j] - $prevClose),
                    abs((float)$lows[$j] - $prevClose)
                );
                $sumTr += $tr;
                $hi = max($hi, (float)$highs[$j]);
                $lo = min($lo, (float)$lows[$j]);
            }
            $range = $hi - $lo;
            if ($sumTr > 0 && $range > 0) {
                $out[$i] = round(100.0 * log10($sumTr / $range) / log10($period), 2);
            }
        }
        return $out;
    }

    /** میانگین دونچین (سقف/کف دوره) — پایهٔ تنکان/کیجون/اسپن. */
    private static function donchianMid(array $highs, array $lows, int $end, int $period): ?float
    {
        if ($end < $period - 1) {
            return null;
        }
        $hi = -INF;
        $lo = INF;
        for ($j = $end - $period + 1; $j <= $end; $j++) {
            $hi = max($hi, (float)$highs[$j]);
            $lo = min($lo, (float)$lows[$j]);
        }
        return ($hi + $lo) / 2.0;
    }

    /**
     * ایچیموکو در ایندکس i — بدون نشت آینده: ابرِ رسم‌شده روی i از
     * داده‌های ≤ i−26 ساخته می‌شود (همان شیفت استاندارد ۲۶ کندله).
     * @return array{tenkan:float,kijun:float,span_a:float,span_b:float,cloud_top:float,cloud_bottom:float}|null
     */
    public static function ichimoku(array $highs, array $lows, int $i): ?array
    {
        if ($i < 78) { // ۲۶ شیفت + ۵۲ عرض اسپن B
            return null;
        }
        $tenkan = self::donchianMid($highs, $lows, $i, 9);
        $kijun = self::donchianMid($highs, $lows, $i, 26);
        $at = $i - 26; // نقطهٔ ساخت ابری که روی i رسم می‌شود
        $tA = self::donchianMid($highs, $lows, $at, 9);
        $kA = self::donchianMid($highs, $lows, $at, 26);
        $spanB = self::donchianMid($highs, $lows, $at, 52);
        if ($tenkan === null || $kijun === null || $tA === null || $kA === null || $spanB === null) {
            return null;
        }
        $spanA = ($tA + $kA) / 2.0;
        return [
            'tenkan' => round($tenkan, 8),
            'kijun' => round($kijun, 8),
            'span_a' => round($spanA, 8),
            'span_b' => round($spanB, 8),
            'cloud_top' => round(max($spanA, $spanB), 8),
            'cloud_bottom' => round(min($spanA, $spanB), 8),
        ];
    }

    /**
     * الگوی کندل تأیید در کندلِ بستهٔ i (انگالفینگ/چکش/ستارهٔ ثاقب).
     * @return array{type:string,side:string,detail:string}|null
     */
    public static function candlePattern(array $opens, array $highs, array $lows, array $closes, int $i): ?array
    {
        if ($i < 1) {
            return null;
        }
        $o = (float)$opens[$i];
        $h = (float)$highs[$i];
        $l = (float)$lows[$i];
        $c = (float)$closes[$i];
        $po = (float)$opens[$i - 1];
        $pc = (float)$closes[$i - 1];
        $range = $h - $l;
        if ($range <= 0) {
            return null;
        }
        $body = abs($c - $o);
        $pBody = abs($pc - $po);
        $upper = $h - max($o, $c);
        $lower = min($o, $c) - $l;

        // چکش/پین‌بار صعودی: سایهٔ پایین بلند + بدنهٔ کوچک بالا
        if ($body > 0 && $lower >= $body * 2.0 && $lower >= $range * 0.55 && $upper <= $range * 0.2) {
            return ['type' => 'hammer', 'side' => 'BUY', 'detail' => 'چکش/پین‌بار صعودی — ردِ عرضه در کف'];
        }
        // ستارهٔ ثاقب/پین‌بار نزولی
        if ($body > 0 && $upper >= $body * 2.0 && $upper >= $range * 0.55 && $lower <= $range * 0.2) {
            return ['type' => 'shooting_star', 'side' => 'SELL', 'detail' => 'ستارهٔ ثاقب — ردِ تقاضا در سقف'];
        }
        // انگالفینگ: بدنهٔ کندل، بدنهٔ مخالف قبل را کاملاً می‌بلعد
        if ($c > $o && $pc < $po && $c >= $po && $o <= $pc && $body > $pBody) {
            return ['type' => 'bullish_engulfing', 'side' => 'BUY', 'detail' => 'انگالفینگ صعودی'];
        }
        if ($c < $o && $pc > $po && $c <= $po && $o >= $pc && $body > $pBody) {
            return ['type' => 'bearish_engulfing', 'side' => 'SELL', 'detail' => 'انگالفینگ نزولی'];
        }
        return null;
    }

    /**
     * قدرت پایانهٔ کندل (Close Location Value) — کجا در دامنهٔ کندل بسته شد؟
     * میانگین پنجرهٔ ۳ کندله: ~۱ = هیئت‌رسمی خرید، ~۰ = فروش.
     */
    public static function closeStrength(array $highs, array $lows, array $closes, int $i, int $window = 3): ?float
    {
        $vals = [];
        for ($j = max(0, $i - $window + 1); $j <= $i; $j++) {
            $h = (float)$highs[$j];
            $l = (float)$lows[$j];
            if ($h - $l <= 0) {
                continue;
            }
            $vals[] = ((float)$closes[$j] - $l) / ($h - $l);
        }
        if (!$vals) {
            return null;
        }
        return round(array_sum($vals) / count($vals), 3);
    }
}
