<?php
namespace Meelano\Crypto;

/**
 * زنجیرهٔ فیلترهای سخت‌گیرانهٔ کریپتو — نسخهٔ ۵٫۴ (هم‌گرایی وزن‌دار، ۳۱ شاهد).
 *
 * فلسفه: به‌جای یک مدل تنها، ۳۱ شاهد مستقل در شش لایهٔ اطلاعاتی رأی وزنی می‌دهند
 * و وزن هر شاهد به «رژیم بازار» وابسته است:
 *   ۱) تکنیکال کلاسیک (روند/مومنتوم/حجم/نوسان)
 *   ۲) ساختار بازار (Supertrend، ساختار، سویپ نقدینگی، FVG، POC)
 *   ۳) جریان سرمایه (OBV، واگرایی، حجم نسبی)
 *   ۴) مشتقات (فاندینگ، اوپن اینترست)
 *   ۵) کلان (قدرت نسبی به BTC، ترس و طمع، سشن)
 * فیلترهای دادهٔ خارجی در نبود داده «خنثیِ عبوری» می‌مانند (هرگز مسدود نمی‌کنند):
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
        $atrAbs = max(1e-9, (float)($ctx['atr'] ?? 0));
        $chop = $ctx['chop'] !== null ? (float)$ctx['chop'] : null;
        $ichi = is_array($ctx['ichimoku'] ?? null) ? $ctx['ichimoku'] : null;
        $pattern = is_array($ctx['candle_pattern'] ?? null) ? $ctx['candle_pattern'] : null;
        $clv = $ctx['close_strength'] !== null ? (float)$ctx['close_strength'] : null;

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

        /* ── ۳) مومنتوم RSI: منطقهٔ بهینه (رژیم‌آگاه — اصلاح v5.4) ── */
        if ($isTrend) {
            // روند: ادامه‌دار — اشباع فروش در روند نزولی = تأیید مومنتوم فروش
            $rsiBuy = $rsi >= 45 && $rsi <= 68;
            $rsiSell = $rsi >= 70 || $rsi <= 30;
            $rsiScore = $rsiBuy ? 0.9 : ($rsi >= 75 ? 0.8 : ($rsiSell ? 0.55 : 0.2));
            $rsiNote = 'روند: مومنتوم ادامه‌دار';
        } elseif ($isRange) {
            // رِنج: بازگشت به میانگین — اشباع فروش = فرصت خرید (نه فروش!)
            $rsiBuy = $rsi <= 35 || ($rsi >= 45 && $rsi <= 62);
            $rsiSell = $rsi >= 68;
            $rsiScore = $rsi <= 35 ? 0.85 : ($rsi >= 68 ? 0.8 : (($rsi >= 45 && $rsi <= 62) ? 0.7 : 0.35));
            $rsiNote = 'رِنج: بازگشت به میانگین';
        } else {
            // پرنوسان: محافظه‌کار
            $rsiBuy = $rsi >= 45 && $rsi <= 65;
            $rsiSell = $rsi >= 72 || $rsi <= 28;
            $rsiScore = $rsiBuy ? 0.75 : ($rsiSell ? 0.6 : 0.3);
            $rsiNote = 'پرنوسان: محافظه‌کار';
        }
        $f = $this->f('rsi', 'مومنتوم RSI در منطقهٔ بهینه', $rsiBuy || $rsiSell, $rsiScore,
            $rsiBuy ? 'BUY' : ($rsiSell ? 'SELL' : 'NEUTRAL'), $wRev,
            'RSI(14): ' . round($rsi, 1) . ' (' . $rsiNote . ')');
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

        /* ═══ فیلترهای نسخهٔ ۵٫۱ — لایه‌های اطلاعاتی جدید ═════════════ */

        /* ── ۱۶) قدرت نسبی به بیت‌کوین: سوخت واقعی آلت‌کوین ─────────── */
        $rsBtc = $ctx['rs_btc'] ?? null;
        if ($rsBtc !== null && (float)$rsBtc !== 0.0) {
            $rsB = (float)$rsBtc;
            $rsBuy = $rsB >= 1.0;
            $rsSell = $rsB <= -1.0;
            $f = $this->f('rs_btc', 'قدرت نسبی به بیت‌کوین', true,
                $rsBuy ? 0.9 : ($rsSell ? 0.85 : 0.45), $rsBuy ? 'BUY' : ($rsSell ? 'SELL' : 'NEUTRAL'), 1.0,
                'RS٪ ۲۰ کندل: ' . $rsB . '٪ (' . ($rsB > 0 ? 'قوی‌تر از BTC' : 'ضعیف‌تر از BTC') . ')');
            $vote($f, 1.0);
        } else {
            $f = $this->f('rs_btc', 'قدرت نسبی به بیت‌کوین', true, 0.5, 'NEUTRAL', 1.0, 'دادهٔ هم‌تراز BTC موجود نیست.');
        }
        $filters[] = $f;

        /* ── ۱۷) واگرایی قیمت/اسیلاتور — قدیمی‌ترین هشدار چرخش ─────── */
        $divR = $ctx['div_rsi'] ?? ['type' => null];
        $divO = $ctx['div_obv'] ?? ['type' => null];
        $divType = null;
        $divStrength = 0.0;
        if (($divR['type'] ?? null) !== null) {
            $divType = $divR['type'];
            $divStrength = (float)($divR['strength'] ?? 0.5);
        }
        if (($divO['type'] ?? null) !== null && ($divO['type'] ?? '') === $divType) {
            $divStrength = min(1.0, $divStrength + 0.2); // تأیید دوگانه RSI+OBV
        } elseif (($divO['type'] ?? null) !== null && $divType === null) {
            $divType = $divO['type'];
            $divStrength = (float)($divO['strength'] ?? 0.4);
        }
        if ($divType !== null) {
            $hasRsi = ($divR['type'] ?? null) !== null;
            $hasObv = ($divO['type'] ?? null) !== null;
            $divDetail = 'واگرایی ' . ($divType === 'bull' ? 'صعودی' : 'نزولی')
                . ($hasRsi ? ' + RSI' : '') . ($hasObv ? ' + OBV' : '')
                . ' — قدرت: ' . round($divStrength * 100) . '٪';
            $f = $this->f('divergence', 'واگرایی قیمت/اسیلاتور', true,
                0.55 + $divStrength * 0.4, $divType === 'bull' ? 'BUY' : 'SELL', 1.2, $divDetail);
            $vote($f, 1.2);
        } else {
            $f = $this->f('divergence', 'واگرایی قیمت/اسیلاتور', true, 0.5, 'NEUTRAL', 1.2, 'واگرایی فعالی دیده نشد.');
        }
        $filters[] = $f;

        /* ── ۱۸) شکار نقدینگی (Liquidity Sweep) — مُهر ورود نهادی ──── */
        $sweep = $ctx['sweep'] ?? null;
        if (is_array($sweep)) {
            $swBuy = $sweep['side'] === 'bull';
            $f = $this->f('sweep', 'شکار نقدینگی (سویپ استاپ)', true,
                0.95, $swBuy ? 'BUY' : 'SELL', 1.3,
                'سویپ ' . ($swBuy ? 'کف' : 'سقف') . ' ' . $this->sci((float)$sweep['level'])
                . ' با حجم ' . $sweep['vol_ratio'] . '× — بازگشت به داخل محدوده');
            $vote($f, 1.3);
        } else {
            $f = $this->f('sweep', 'شکار نقدینگی (سویپ استاپ)', true, 0.5, 'NEUTRAL', 1.3, 'الگوی سویپ در کندل جاری نیست.');
        }
        $filters[] = $f;

        /* ── ۱۹) نسبت کارایی کافمان — تأیید رژیم ────────────────────── */
        $er = $ctx['er'] ?? null;
        if ($er !== null) {
            if ($isTrend) {
                $erOk = (float)$er >= 0.30;
                $erDetail = 'ER=' . round((float)$er, 2) . ' — ' . ($erOk ? 'حرکت کارآمد برای روند' : 'حرکت پرنویز برای معاملهٔ روندی');
            } else {
                $erOk = (float)$er <= 0.45;
                $erDetail = 'ER=' . round((float)$er, 2) . ' — ' . ($erOk ? 'نویز مناسب بازگشت به میانگین' : 'حرکت جهت‌دار بیش از حد رِنج');
            }
            $f = $this->f('efficiency', 'نسبت کارایی کافمان (ER)', $erOk, $erOk ? 0.8 : 0.25, 'NEUTRAL', 0.8, $erDetail);
        } else {
            $f = $this->f('efficiency', 'نسبت کارایی کافمان (ER)', true, 0.5, 'NEUTRAL', 0.8, 'دادهٔ کافی نیست.');
        }
        $filters[] = $f;

        /* ── ۲۰) گپ ارزش منصفانه (FVG) — لنگرگاه ورود حدی ──────────── */
        $fvg = $ctx['fvg'] ?? null;
        $atrVal = max(1e-9, $atrPct / 100 * $price);
        if (is_array($fvg) && $fvg['size_pct'] >= 0.15) {
            $near = $fvg['side'] === 'bull'
                ? ($price - $fvg['high']) <= $atrVal * 1.5
                : ($fvg['low'] - $price) <= $atrVal * 1.5;
            $f = $this->f('fvg', 'گپ ارزش منصفانه (FVG)', true,
                $near ? 0.8 : 0.55, $fvg['side'] === 'bull' ? 'BUY' : 'SELL', 0.9,
                'FVG ' . ($fvg['side'] === 'bull' ? 'صعودی' : 'نزولی') . ' — میانه: ' . $this->sci($fvg['mid']) . ' (' . $fvg['size_pct'] . '٪)');
            $vote($f, 0.9);
        } else {
            $f = $this->f('fvg', 'گپ ارزش منصفانه (FVG)', true, 0.5, 'NEUTRAL', 0.9, 'گپ فعال نزدیک قیمت نیست.');
        }
        $filters[] = $f;

        /* ── ۲۱) سشن معاملاتی — نقدینگی سشن‌ها متفاوت است ──────────── */
        $session = (string)($ctx['session'] ?? '');
        $isWeekend = $session === 'weekend';
        $sessionLabels = [
            'asia' => 'آسیا', 'europe' => 'اروپا', 'america' => 'آمریکا',
            'overlap' => 'هم‌پوشانی اروپا/آمریکا', 'asia_europe' => 'هم‌پوشانی آسیا/اروپا',
            'weekend' => 'آخر هفته', 'off' => 'خارج از سشن‌های اصلی',
        ];
        $sessionOk = !$isWeekend || $volRatio >= 1.5; // آخر هفته فقط با حجم واقعی
        $f = $this->f('session', 'سشن معاملاتی', $sessionOk,
            $isWeekend ? 0.35 : ($session === 'overlap' ? 0.8 : 0.65), 'NEUTRAL', 0.6,
            'سشن: ' . ($sessionLabels[$session] ?? $session) . ($isWeekend ? ' — نقدینگی کم؛ نیازمند تأیید حجم' : ''));
        $filters[] = $f;

        /* ── ۲۲) فاندینگ — شلوغی پوزیشن‌های اهرمی ────────────────────── */
        $funding = $ctx['funding_pct'] ?? null;
        if ($funding !== null) {
            $fr = (float)$funding;
            $crowdLongs = $fr >= 0.05;   // فاندینگ افراطی مثبت = اسکوییز نزولی محتمل
            $crowdShorts = $fr <= -0.05; // فاندینگ افراطی منفی = اسکوییز صعودی محتمل
            $f = $this->f('funding', 'فاندینگ (شلوغی اهرمی)', true,
                $crowdLongs ? 0.75 : ($crowdShorts ? 0.75 : 0.6),
                $crowdLongs ? 'SELL' : ($crowdShorts ? 'BUY' : 'NEUTRAL'), 0.8,
                'فاندینگ ۸ساعته: ' . $fr . '٪' . ($crowdLongs ? ' — لانگ‌ها شلوغ (خطر اسکوییز)' : ($crowdShorts ? ' — شورت‌ها شلوغ (خطر اسکوییز)' : ' — نرمال')));
            $vote($f, 0.8);
        } else {
            $f = $this->f('funding', 'فاندینگ (شلوغی اهرمی)', true, 0.5, 'NEUTRAL', 0.8, 'دادهٔ فاندینگ در دسترس نیست.');
        }
        $filters[] = $f;

        /* ── ۲۳) اوپن اینترست — سوخت واقعی حرکت ──────────────────────── */
        $oi = $ctx['oi_trend_pct'] ?? null;
        if ($oi !== null) {
            $oiT = (float)$oi;
            $oiExit = $oiT <= -4.0; // خروج جریان از بازار
            if ($oiT >= 2.0) {
                $oiSide = $change24 > 0 ? 'BUY' : 'SELL'; // ورود پول + جهت قیمت = روند سالم
                $oiDetail = 'OI +' . $oiT . '٪ هم‌جهت با قیمت — روند پشتیبان';
                $oiScore = 0.8;
            } elseif ($oiExit) {
                $oiSide = 'NEUTRAL';
                $oiDetail = 'OI ' . $oiT . '٪ — خروج جریان؛ حرکت‌ها بی‌سوخت‌اند';
                $oiScore = 0.3;
            } else {
                $oiSide = 'NEUTRAL';
                $oiDetail = 'OI ' . $oiT . '٪ — خنثی';
                $oiScore = 0.55;
            }
            $f = $this->f('open_interest', 'اوپن اینترست (جریان پوزیشن‌ها)', !$oiExit, $oiScore, $oiSide, 0.8, $oiDetail);
            if ($oiSide !== 'NEUTRAL') { $vote($f, 0.8); }
        } else {
            $f = $this->f('open_interest', 'اوپن اینترست (جریان پوزیشن‌ها)', true, 0.5, 'NEUTRAL', 0.8, 'دادهٔ OI در دسترس نیست.');
        }
        $filters[] = $f;

        /* ── ۲۴) ترس و طمع — خلاف‌رفتن با هیجان جمع ──────────────────── */
        $fg = $ctx['fear_greed'] ?? null;
        if (is_array($fg) && isset($fg['value'])) {
            $fgv = (int)$fg['value'];
            $fgBuy = $fgv <= 25;   // ترس شدید = فرصت خلاف‌گردش
            $fgSell = $fgv >= 75;  // طمع شدید = احتیاط
            $f = $this->f('fear_greed', 'ترس و طمع (خلاف‌گردش)', true,
                ($fgBuy || $fgSell) ? 0.7 : 0.6, $fgBuy ? 'BUY' : ($fgSell ? 'SELL' : 'NEUTRAL'), 0.6,
                'شاخص: ' . $fgv . '/۱۰۰ (' . ($fg['label'] ?? '') . ')' . ($fgBuy ? ' — ترس شدید' : ($fgSell ? ' — طمع شدید' : '')));
            if ($fgBuy || $fgSell) { $vote($f, 0.6); }
        } else {
            $f = $this->f('fear_greed', 'ترس و طمع (خلاف‌گردش)', true, 0.5, 'NEUTRAL', 0.6, 'شاخص در دسترس نیست.');
        }
        $filters[] = $f;

        /* ── ۲۵) پروفایل حجم (POC) — آهن‌ربای قیمت ────────────────────── */
        $poc = $ctx['poc'] ?? null;
        if (is_array($poc) && (float)$poc['poc'] > 0) {
            $pocDist = (($price - (float)$poc['poc']) / (float)$poc['poc']) * 100;
            $pocBuy = $pocDist <= -1.5;  // قیمت زیر گره حجم = جذب به سمت بالا
            $pocSell = $pocDist >= 1.5;  // قیمت بالای گره حجم = جذب به سمت پایین
            $nearPoc = abs($pocDist) < 1.2;
            $f = $this->f('poc', 'گره حجم (POC)', true,
                $nearPoc ? 0.85 : ($pocBuy || $pocSell ? 0.65 : 0.5),
                $pocBuy ? 'BUY' : ($pocSell ? 'SELL' : 'NEUTRAL'), 0.7,
                'POC: ' . $this->sci((float)$poc['poc']) . ' — قیمت ' . ($pocDist >= 0 ? '+' : '') . round($pocDist, 2) . '٪ ' . ($nearPoc ? '(منطقهٔ تعادل)' : 'فاصله دارد'));
            if ($pocBuy || $pocSell) { $vote($f, 0.7); }
        } else {
            $f = $this->f('poc', 'گره حجم (POC)', true, 0.5, 'NEUTRAL', 0.7, 'پروفایل حجم کافی نیست.');
        }
        $filters[] = $f;


        /* ═══ فیلترهای نسخهٔ ۵٫۴ — لایهٔ ششم: تأیید اجرا و ضدتعقیب ════ */

        /* ── ۲۶) بریدگی بازار (Choppiness) — انرژی جهت‌دار واقعی ──── */
        if ($chop !== null) {
            if ($isTrend) {
                $chopOk = $chop <= 55.0;
                $chopScore = $chop <= 40.0 ? 0.85 : 0.6;
                $chopDetail = 'CHOP=' . $chop . ' — ' . ($chopOk ? 'انرژی جهت‌دار کافی برای روند' : 'نویز بالا در دل روند — احتیاط');
            } else {
                $chopOk = $chop >= 35.0;
                $chopScore = $chop >= 55.0 ? 0.8 : 0.6;
                $chopDetail = 'CHOP=' . $chop . ' — ' . ($chopOk ? 'بازار بریده؛ منطق رِنج/میانگین معتبر' : 'فشردگی جهت‌دار — شکست در راه است');
            }
            $f = $this->f('choppiness', 'شاخص بریدگی بازار (CHOP)', $chopOk, $chopScore, 'NEUTRAL', 0.9, $chopDetail);
        } else {
            $f = $this->f('choppiness', 'شاخص بریدگی بازار (CHOP)', true, 0.5, 'NEUTRAL', 0.9, 'دادهٔ کافی نیست.');
        }
        $filters[] = $f;

        /* ── ۲۷) ابر ایچیموکو — جهت‌نمای ساختاری نهادی ──────────────── */
        if ($ichi !== null) {
            $pAbove = $price > (float)$ichi['cloud_top'];
            $pBelow = $price < (float)$ichi['cloud_bottom'];
            $tAboveK = (float)$ichi['tenkan'] > (float)$ichi['kijun'];
            $tBelowK = (float)$ichi['tenkan'] < (float)$ichi['kijun'];
            $ichiBuy = $pAbove && $tAboveK;
            $ichiSell = $pBelow && $tBelowK;
            $f = $this->f('ichimoku', 'موقعیت ابر ایچیموکو', true,
                $ichiBuy || $ichiSell ? 0.85 : 0.45,
                $ichiBuy ? 'BUY' : ($ichiSell ? 'SELL' : 'NEUTRAL'), $wTrend,
                'قیمت ' . ($pAbove ? 'بالای ابر' : ($pBelow ? 'زیر ابر' : 'داخل ابر'))
                . ' · تنکان ' . ($tAboveK ? '>' : '<') . ' کیجون');
            $vote($f, $wTrend);
        } else {
            $f = $this->f('ichimoku', 'موقعیت ابر ایچیموکو', true, 0.5, 'NEUTRAL', $wTrend, 'تاریخچهٔ کافی برای ابر نیست.');
        }
        $filters[] = $f;

        /* ── ۲۸) ضدتعقیب: فاصلهٔ قیمت از EMA21 بر حسب ATR ───────────── */
        $ext = ($price - $ema21) / $atrAbs;
        if ((float)($ctx['atr'] ?? 0) > 0) {
            $healthy = $ext >= -2.5 && $ext <= 3.0;
            $stretched = ($ext > 3.0 && $ext <= 5.0) || ($ext < -2.5 && $ext >= -5.0);
            $parabolic = $ext > 5.0 || $ext < -5.0;
            if ($isTrend && $parabolic) {
                // روندِ کشیده می‌تواند ادامه یابد — ولی کیفیت ورود پایین است
                $extPass = true;
                $extScore = 0.4;
                $extDetail = 'کشیدگی ' . round($ext, 1) . ' ATR از EMA21 — روند معتبر ولی ورود پرریسک (پولبک بهتر است)';
            } else {
                $extPass = !$parabolic;
                $extScore = $healthy ? 0.85 : ($stretched ? 0.55 : 0.25);
                $extDetail = 'فاصله از EMA21: ' . round($ext, 1) . '× ATR — ' . ($healthy ? 'منطقهٔ سالم (پولبک/شروع موج)' : ($stretched ? 'کشیده — صبر برای پولبک منطقی‌تر است' : 'پارابولیک — تعقیب ممنوع'));
            }
            $f = $this->f('extension', 'ضدتعقیب (فاصله از EMA21)', $extPass, $extScore, 'NEUTRAL', 1.0, $extDetail);
        } else {
            $f = $this->f('extension', 'ضدتعقیب (فاصله از EMA21)', true, 0.5, 'NEUTRAL', 1.0, 'ATR در دسترس نیست.');
        }
        $filters[] = $f;

        /* ── ۲۹) الگوی کندل تأیید (انگالفینگ/چکش/ستاره) ─────────────── */
        if ($pattern !== null) {
            $patSide = (string)$pattern['side'];
            $f = $this->f('candle_pattern', 'الگوی کندل تأیید', true, 0.8, $patSide, 0.8,
                (string)($pattern['detail'] ?? 'الگوی تأییدی'));
            $vote($f, 0.8);
        } else {
            $f = $this->f('candle_pattern', 'الگوی کندل تأیید', true, 0.5, 'NEUTRAL', 0.8, 'الگوی تأییدی روی کندل بسته نیست.');
        }
        $filters[] = $f;

        /* ── ۳۰) قدرت پایانهٔ کندل (CLV) — هیئت‌رسمی خرید/فروش ──────── */
        if ($clv !== null) {
            $clvBuy = $clv >= 0.65;
            $clvSell = $clv <= 0.35;
            $f = $this->f('close_strength', 'قدرت پایانهٔ کندل (CLV)', true,
                $clvBuy ? 0.75 : ($clvSell ? 0.75 : 0.5),
                $clvBuy ? 'BUY' : ($clvSell ? 'SELL' : 'NEUTRAL'), 0.7,
                'میانگین محل بسته‌شدن: ' . round($clv * 100) . '٪ دامنهٔ کندل');
            $vote($f, 0.7);
        } else {
            $f = $this->f('close_strength', 'قدرت پایانهٔ کندل (CLV)', true, 0.5, 'NEUTRAL', 0.7, 'دادهٔ کافی نیست.');
        }
        $filters[] = $f;

        /* ── ۳۱) انسداد حجم (Volume Climax) — دام تعقیب اوج ──────────── */
        if ($volRatio >= 4.0) {
            if ($wickRatio >= 0.45) {
                // انفجار حجم + سایهٔ بالا = اوج دمیده‌شده (blow-off)
                $f = $this->f('climax', 'انسداد حجم (Climax)', true, 0.6, 'SELL', 0.7,
                    'حجم ' . round($volRatio, 1) . '× با سایهٔ ' . round($wickRatio * 100) . '٪ — اوج دمیده‌شده، خطر واژگونی');
                $vote($f, 0.7);
            } elseif ($clv !== null && $clv <= 0.25) {
                // انفجار حجم + بسته‌شدن در کف = تسلیم (capitulation)
                $f = $this->f('climax', 'انسداد حجم (Climax)', true, 0.6, 'BUY', 0.7,
                    'حجم ' . round($volRatio, 1) . '× با بسته‌شدن ' . round($clv * 100) . '٪ — فلش تسلیم؛ بازگشت محتمل');
                $vote($f, 0.7);
            } else {
                $f = $this->f('climax', 'انسداد حجم (Climax)', false, 0.35, 'NEUTRAL', 0.7,
                    'حجم ' . round($volRatio, 1) . '× میانگین — حجم غیرعادی؛ صبر برای تثبیت');
            }
        } else {
            $f = $this->f('climax', 'انسداد حجم (Climax)', true, 0.85, 'NEUTRAL', 0.7,
                'حجم ' . round($volRatio, 2) . '× — طبیعی');
        }
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
