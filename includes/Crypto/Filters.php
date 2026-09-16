<?php
namespace Meelano\Crypto;

/**
 * زنجیرهٔ فیلترهای سخت‌گیرانهٔ کریپتو — نسخهٔ ۵ (هم‌گرایی وزن‌دار).
 *
 * فلسفه: به‌جای یک مدل تنها، پانزده شاهد مستقل (روند، مومنتوم، حجم،
 * نوسان، ساختار، جریان سرمایه، آنتی‌منیپولیشن و قدرت روند) رأی وزنی می‌دهند
 * و وزن هر شاهد به «رژیم بازار» وابسته است:
 *   - در روند قوی، فیلترهای روندی (EMA، Supertrend، میکروترند) وزن بیشتری دارند.
 *   - در بازار رِنج، فیلترهای بازگشت به میانگین (بولینگر، استوکستیک، RSI) سنگین‌ترند.
 *   - در نوسان وحشی، بار آستانه‌ها بالاتر می‌رود.
 *
 * هیچ سیگنالی بدون عبور از حد نصاب فیلترها و امتیاز تکنیکال صادر نمی‌شود.
 *
 * @author Milad Yaghoobi — Meelano Studio Design
 */
final class Filters
{
    /**
     * ارزیابی همهٔ فیلترها روی یک بافت (context) از اندیکاتورها.
     *
     * @param array $ctx بافت از Context::snapshot (+ regime از Regime::classify)
     * @return array{side:string,filters:array,tech_score:float,passed:int,total:int,
     *               buy_votes:float,sell_votes:float,confluence:int,regime:string}
     */
    public function evaluate(array $ctx): array
    {
        $regime = (string)($ctx['regime'] ?? Regime::RANGE);
        $isTrend = in_array($regime, [Regime::TREND_UP, Regime::TREND_DOWN], true);
        $isRange = $regime === Regime::RANGE;

        // ضرایب وزن بر اساس رژیم
        $wTrend = $isTrend ? 1.3 : ($isRange ? 0.7 : 1.0);
        $wRev = $isRange ? 1.3 : ($isTrend ? 0.7 : 1.0);

        $filters = [];
        $buyVotes = 0.0;
        $sellVotes = 0.0;
        $agreeBuy = 0;
        $agreeSell = 0;
        $dirFilters = 0;

        $vote = static function (array &$f, float $weight) use (&$buyVotes, &$sellVotes, &$agreeBuy, &$agreeSell, &$dirFilters): void {
            if ($f['side'] === 'BUY' || $f['side'] === 'SELL') {
                $dirFilters++;
                $w = $weight * $f['score'];
                if ($f['side'] === 'BUY') {
                    $buyVotes += $w;
                    if ($f['score'] >= 0.5) { $agreeBuy++; }
                } else {
                    $sellVotes += $w;
                    if ($f['score'] >= 0.5) { $agreeSell++; }
                }
            }
        };

        $rsi = (float)($ctx['rsi'] ?? 50);
        $macdHist = (float)($ctx['macd_hist'] ?? 0);
        $macdHistPrev = (float)($ctx['macd_hist_prev'] ?? 0);
        $ema9 = (float)($ctx['ema9'] ?? 0);
        $ema21 = (float)($ctx['ema21'] ?? 0);
        $ema50 = (float)($ctx['ema50'] ?? 0);
        $ema200 = (float)($ctx['ema200'] ?? 0);
        $price = (float)($ctx['price'] ?? 0);
        $bbPos = (float)($ctx['bb_pos'] ?? 0.5);
        $stochK = (float)($ctx['stoch_k'] ?? 50);
        $stochD = (float)($ctx['stoch_d'] ?? 50);
        $atrPct = (float)($ctx['atr_pct'] ?? 0);
        $volRatio = (float)($ctx['vol_ratio'] ?? 1);
        $change24 = (float)($ctx['change24'] ?? 0);
        $quoteVolume = (float)($ctx['quote_volume'] ?? 0);
        $structure = (string)($ctx['structure'] ?? 'range');
        $wickRatio = (float)($ctx['wick_ratio'] ?? 0);
        $adx = $ctx['adx'] !== null ? (float)$ctx['adx'] : null;
        $stDir = $ctx['supertrend_dir'] !== null ? (int)$ctx['supertrend_dir'] : null;
        $obvSlope = $ctx['obv_slope'] !== null ? (float)$ctx['obv_slope'] : null;
        $vwap = $ctx['vwap'] !== null ? (float)$ctx['vwap'] : null;

        /* ── ۱) نقدینگی: حجم ۲۴س (سخت، بدون جهت) ──────────────────── */
        $minVol = (float)($ctx['min_quote_volume'] ?? 5000000);
        $liquidity = $quoteVolume >= $minVol;
        $f = $this->f('liquidity', 'نقدینگی کافی (حجم ۲۴س)', $liquidity, $liquidity ? 1.0 : 0.0, 'NEUTRAL', 1.0,
            'حجم ۲۴س: ' . $this->compact($quoteVolume) . ' (حداقل: ' . $this->compact($minVol) . ')');
        $filters[] = $f;

        /* ── ۲) روند ساختاری: EMA50/200 + سقف/کف بالاتر ───────────── */
        $trendBuy = $structure === 'uptrend' || ($ema50 > $ema200 && $price > $ema50);
        $trendSell = $structure === 'downtrend' || ($ema50 < $ema200 && $price < $ema50);
        $f = $this->f('trend', 'هم‌راستایی با روند', $trendBuy || $trendSell,
            ($trendBuy || $trendSell) ? 0.9 : 0.3, $trendBuy ? 'BUY' : ($trendSell ? 'SELL' : 'NEUTRAL'), $wTrend,
            'ساختار: ' . $structure . ' · EMA50 ' . ($ema50 >= $ema200 ? 'بالای' : 'زیر') . ' EMA200');
        $vote($f, $wTrend);
        $filters[] = $f;

        /* ── ۳) مومنتوم RSI: منطقهٔ بهینه ─────────────────────────── */
        $rsiBuy = $rsi >= 45 && $rsi <= 68;
        $rsiSell = $rsi >= 70 || $rsi <= 30;
        $f = $this->f('rsi', 'مومنتوم RSI در منطقهٔ بهینه', $rsiBuy || $rsiSell,
            $rsiBuy ? 0.9 : ($rsi >= 75 ? 0.8 : ($rsiSell ? 0.55 : 0.2)),
            $rsiBuy ? 'BUY' : ($rsiSell ? 'SELL' : 'NEUTRAL'), $wRev,
            'RSI(14): ' . round($rsi, 1) . ($isRange ? ' (منطقهٔ رِنج: بازگشت به میانگین)' : ''));
        $vote($f, $wRev);
        $filters[] = $f;

        /* ── ۴) MACD: چرخش هیستوگرام ──────────────────────────────── */
        $macdBuy = $macdHist > 0 && $macdHist >= $macdHistPrev;
        $macdSell = $macdHist < 0 && $macdHist <= $macdHistPrev;
        $f = $this->f('macd', 'چرخش هیستوگرام MACD', $macdBuy || $macdSell,
            ($macdBuy || $macdSell) ? 0.85 : 0.3, $macdBuy ? 'BUY' : ($macdSell ? 'SELL' : 'NEUTRAL'), 1.0,
            'هیستوگرام: ' . $this->sci($macdHist) . ' (قبلی: ' . $this->sci($macdHistPrev) . ')');
        $vote($f, 1.0);
        $filters[] = $f;

        /* ── ۵) حجم: تأیید حرکت ───────────────────────────────────── */
        $volConfirm = $volRatio >= 1.25;
        $f = $this->f('volume', 'تأیید حرکت با حجم', $volConfirm,
            $volConfirm ? min(1.0, $volRatio / 2.0) : 0.2, 'NEUTRAL', 1.0,
            'نسبت حجم: ' . round($volRatio, 2) . '× میانگین');
        $filters[] = $f;

        /* ── ۶) نوسان: ATR قابل‌مدیریت ────────────────────────────── */
        $atrOk = $atrPct > 0.12 && $atrPct < 6.0;
        $f = $this->f('volatility', 'نوسان در دامنهٔ قابل‌مدیریت', $atrOk,
            $atrOk ? 0.8 : 0.2, 'NEUTRAL', 1.0, 'ATR٪: ' . round($atrPct, 2));
        $filters[] = $f;

        /* ── ۷) بولینگر: موقعیت قیمت در باند ──────────────────────── */
        $bbBuy = $bbPos <= 0.38;
        $bbSell = $bbPos >= 0.9;
        $bbMid = $bbPos > 0.4 && $bbPos < 0.8;
        $f = $this->f('bollinger', 'موقعیت بولینگر', $bbBuy || $bbSell || $bbMid,
            $bbBuy ? 0.85 : ($bbSell ? 0.6 : ($bbMid ? 0.55 : 0.35)),
            $bbBuy ? 'BUY' : ($bbSell ? 'SELL' : 'NEUTRAL'), $wRev,
            'موقعیت در باند: ' . round($bbPos * 100) . '٪');
        $vote($f, $wRev);
        $filters[] = $f;

        /* ── ۸) استوکستیک: کراس در منطقهٔ اشباع ───────────────────── */
        $stochBuy = $stochK < 32 && $stochK > $stochD;
        $stochSell = $stochK > 68 && $stochK < $stochD;
        $f = $this->f('stochastic', 'کراس استوکستیک', $stochBuy || $stochSell,
            ($stochBuy || $stochSell) ? 0.8 : 0.3, $stochBuy ? 'BUY' : ($stochSell ? 'SELL' : 'NEUTRAL'), $wRev,
            'K: ' . round($stochK, 1) . ' · D: ' . round($stochD, 1));
        $vote($f, $wRev);
        $filters[] = $f;

        /* ── ۹) سقوط آزاد: نداشتن چاقوی در حال سقوط ───────────────── */
        $noFreefall = $change24 > -12.0;
        $f = $this->f('freefall', 'عدم ورود به سقوط آزاد', $noFreefall,
            $noFreefall ? 0.9 : 0.0, $noFreefall ? 'NEUTRAL' : 'SELL', 1.0,
            'تغییر ۲۴س: ' . round($change24, 1) . '٪');
        $vote($f, 1.0);
        $filters[] = $f;

        /* ── ۱۰) آنتی‌منیپولیشن: سایهٔ بالایی غیرعادی ─────────────── */
        $antiManip = $wickRatio < 0.45;
        $f = $this->f('manipulation', 'آنتی‌منیپولیشن (سایهٔ فروش)', $antiManip,
            $antiManip ? 0.8 : 0.1, $antiManip ? 'NEUTRAL' : 'SELL', 1.0,
            'نسبت سایهٔ بالا: ' . round($wickRatio * 100) . '٪');
        $vote($f, 1.0);
        $filters[] = $f;

        /* ── ۱۱) قدرت روند ADX: سازگار با رژیم ────────────────────── */
        if ($adx !== null) {
            if ($isTrend) {
                $adxOk = $adx >= 20.0;
                $detail = 'ADX=' . round($adx, 1) . ' — برای معاملهٔ روندی کافی است.';
            } else {
                $adxOk = $adx < 28.0;
                $detail = 'ADX=' . round($adx, 1) . ' — برای معاملهٔ رِنج/میانگین مناسب است.';
            }
            $f = $this->f('adx', 'قدرت روند ADX سازگار با رژیم', $adxOk,
                $adxOk ? 0.8 : 0.25, 'NEUTRAL', 1.0, $detail);
        } else {
            $f = $this->f('adx', 'قدرت روند ADX سازگار با رژیم', false, 0.25, 'NEUTRAL', 1.0, 'دادهٔ ADX کافی نیست.');
        }
        $filters[] = $f;

        /* ── ۱۲) Supertrend: جهت تریلینگ‌استاپ ساختاری ────────────── */
        if ($stDir !== null) {
            $stBuy = $stDir === 1;
            $f = $this->f('supertrend', 'جهت Supertrend', true,
                0.85, $stBuy ? 'BUY' : 'SELL', $wTrend,
                'Supertrend ' . ($stBuy ? 'صعودی' : 'نزولی') . ' — خط: ' . $this->sci((float)($ctx['supertrend_line'] ?? 0)));
            $vote($f, $wTrend);
        } else {
            $f = $this->f('supertrend', 'جهت Supertrend', false, 0.25, 'NEUTRAL', $wTrend, 'دادهٔ کافی نیست.');
        }
        $filters[] = $f;

        /* ── ۱۳) جریان سرمایه OBV: انباشت یا توزیع ────────────────── */
        if ($obvSlope !== null) {
            $obvBuy = $obvSlope > 0.05;
            $obvSell = $obvSlope < -0.05;
            $f = $this->f('obv', 'جریان سرمایه (OBV)', $obvBuy || $obvSell,
                ($obvBuy || $obvSell) ? 0.8 : 0.4, $obvBuy ? 'BUY' : ($obvSell ? 'SELL' : 'NEUTRAL'), 1.0,
                'شیب OBV: ' . $this->sci($obvSlope) . ($obvBuy ? ' (انباشت)' : ($obvSell ? ' (توزیع)' : '')));
            $vote($f, 1.0);
        } else {
            $f = $this->f('obv', 'جریان سرمایه (OBV)', false, 0.4, 'NEUTRAL', 1.0, 'دادهٔ OBV کافی نیست.');
        }
        $filters[] = $f;

        /* ── ۱۴) VWAP: قیمت نسبت به میانگین هزینهٔ فعال ──────────── */
        if ($vwap !== null && $vwap > 0) {
            $vwapBuy = $price > $vwap;
            $dist = (($price - $vwap) / $vwap) * 100;
            $f = $this->f('vwap', 'موقعیت نسبت به VWAP', abs($dist) < 8.0,
                0.75, $vwapBuy ? 'BUY' : 'SELL', 1.0,
                'قیمت ' . ($vwapBuy ? 'بالای' : 'زیر') . ' VWAP (' . round($dist, 2) . '٪)');
            $vote($f, 1.0);
        } else {
            $f = $this->f('vwap', 'موقعیت نسبت به VWAP', false, 0.4, 'NEUTRAL', 1.0, 'دادهٔ VWAP کافی نیست.');
        }
        $filters[] = $f;

        /* ── ۱۵) میکروترند EMA9/21: ورود هم‌زمان با جریان کوتاه‌مدت ─ */
        $microBuy = $ema9 > $ema21 && $price > $ema9;
        $microSell = $ema9 < $ema21 && $price < $ema9;
        $f = $this->f('microtrend', 'میکروترند EMA9/21', $microBuy || $microSell,
            ($microBuy || $microSell) ? 0.8 : 0.3, $microBuy ? 'BUY' : ($microSell ? 'SELL' : 'NEUTRAL'), $wTrend,
            'EMA9 ' . ($ema9 >= $ema21 ? '>' : '<') . ' EMA21 · قیمت ' . ($price >= $ema9 ? 'بالای' : 'زیر') . ' EMA9');
        $vote($f, $wTrend);
        $filters[] = $f;

        /* ── جمع‌بندی ───────────────────────────────────────────────── */
        $passed = count(array_filter($filters, static function ($f) {
            return $f['pass'];
        }));
        $side = $buyVotes >= $sellVotes ? 'BUY' : 'SELL';
        $dominant = max($buyVotes, $sellVotes);

        // سقف آرای جهت‌دار: جمع وزن فیلترهای جهت‌دار با امتیاز کامل
        $maxVotes = 0.0;
        foreach ($filters as $fl) {
            if ($fl['side'] === 'BUY' || $fl['side'] === 'SELL') {
                $maxVotes += $fl['weight'];
            }
        }
        $voteShare = $maxVotes > 0 ? $dominant / $maxVotes : 0.0;
        $passRatio = count($filters) > 0 ? $passed / count($filters) : 0.0;
        $agreeCount = $side === 'BUY' ? $agreeBuy : $agreeSell;
        $confluenceRatio = $dirFilters > 0 ? $agreeCount / $dirFilters : 0.0;

        $techScore = max(0.0, min(100.0,
            $voteShare * 55.0 + $passRatio * 30.0 + $confluenceRatio * 15.0
        ));

        return [
            'side' => $side,
            'buy_votes' => round($buyVotes, 2),
            'sell_votes' => round($sellVotes, 2),
            'confluence' => $agreeCount,
            'confluence_total' => $dirFilters,
            'filters' => $filters,
            'passed' => $passed,
            'total' => count($filters),
            'tech_score' => round($techScore, 1),
            'regime' => $regime,
        ];
    }

    private function f(string $key, string $label, bool $pass, float $score, string $side, float $weight, string $detail): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'pass' => $pass,
            'score' => round($score, 2),
            'side' => $side,
            'weight' => round($weight, 2),
            'detail' => $detail,
        ];
    }

    private function compact(float $v): string
    {
        if ($v >= 1e9) { return round($v / 1e9, 2) . 'B'; }
        if ($v >= 1e6) { return round($v / 1e6, 2) . 'M'; }
        if ($v >= 1e3) { return round($v / 1e3, 1) . 'K'; }
        return (string)round($v, 1);
    }

    private function sci(float $v): string
    {
        if ($v == 0.0) { return '0'; }
        if (abs($v) >= 1000 || abs($v) < 0.01) { return (string)round($v, 2); }
        return number_format($v, 4);
    }
}
