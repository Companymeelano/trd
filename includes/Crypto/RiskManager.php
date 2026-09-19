<?php
namespace Meelano\Crypto;

/**
 * مدیریت ریسک و پوزیشن — خروجی عملیاتی هر سیگنال (نسخهٔ ۵).
 *
 * اصول یک میز معاملاتی نهادی:
 *   ۱) استاپ ساختاری: پشت کف/سقف سوئینگ با بافر ATR — نه عدد دلخواه.
 *      اگر سوئینگ دور بود، استاپ ATR جایگزین می‌شود (هرگز عریض‌تر از سقف مجاز).
 *   ۲) سایز پوزیشن از فاصلهٔ استاپ مشتق می‌شود (ریسک ثابت درصدی)، نه سلیقه‌ای.
 *   ۳) نردبان سود: 1.5R / 2.5R / 4R با انتقال استاپ به سربه‌سر بعد از TP1.
 *   ۴) در رژیم پرنوسان و اعتماد پایین، سایز به‌طور خودکار کوچک می‌شود.
 *
 * @author Milad Yaghoobi — Meelano Studio Design
 */
final class RiskManager
{
    /** @var array */
    private $cfg;

    public function __construct(array $cfg = [])
    {
        $this->cfg = $cfg;
    }

    /**
     * @param string $side BUY|SELL
     * @param float  $entry قیمت ورود
     * @param float  $atr مقدار ATR
     * @param float  $confidence اعتماد ترکیبی ۰..۱۰۰
     * @param array  $extra اختیاری: swing_low, swing_high, regime, sizing_factor
     * @return array
     */
    public function plan(string $side, float $entry, float $atr, float $confidence, array $extra = []): array
    {
        $riskPerTrade = (float)($this->cfg['risk_per_trade_percent'] ?? 1.0);
        $atrStopMult = (float)($this->cfg['atr_stop_multiplier'] ?? 2.0);
        $maxStopMult = max($atrStopMult, (float)($this->cfg['max_atr_stop_multiplier'] ?? 3.0));
        $bufferMult = (float)($this->cfg['structure_stop_buffer'] ?? 0.25);
        $maxPosition = (float)($this->cfg['max_position_percent'] ?? 25.0);
        $buy = $side === 'BUY';

        $atr = max($atr, $entry * 0.0015); // حداقل منطقی برای نمادهای کم‌نوسان

        /* ── ۱) انتخاب استاپ: ساختاری در دسترس، وگرنه ATR ───────────── */
        $stopType = 'atr';
        $atrStop = $buy ? $entry - $atr * $atrStopMult : $entry + $atr * $atrStopMult;

        if ($buy && !empty($extra['swing_low'])) {
            $structStop = (float)$extra['swing_low'] - $atr * $bufferMult;
            // استاپ ساختاری باید زیر ورود و نه عریض‌تر از سقف مجاز باشد
            if ($structStop < $entry && $structStop > $entry - $atr * $maxStopMult) {
                if ($structStop > $atrStop) { // فقط وقتی واقعاً تنگ‌تر است (اصلاح v5.4: برچسب صادقانه)
                    $atrStop = $structStop;
                    $stopType = 'structure';
                }
            }
        } elseif (!$buy && !empty($extra['swing_high'])) {
            $structStop = (float)$extra['swing_high'] + $atr * $bufferMult;
            if ($structStop > $entry && $structStop < $entry + $atr * $maxStopMult) {
                if ($structStop < $atrStop) {
                    $atrStop = $structStop;
                    $stopType = 'structure';
                }
            }
        }
        $stop = $atrStop;

        /* ── ۲) نردبان تارگت بر پایهٔ R ─────────────────────────────── */
        $stopDistance = abs($entry - $stop);
        // کف حداقلی (اصلاح v5.4): استاپ تنگ‌تر از ۰٫۹ ATR یا ۰٫۵٪ قیمت = طعمهٔ نویز
        $stopDistance = max($stopDistance, $entry * 0.005, $atr * 0.9);
        $stop = $buy ? $entry - $stopDistance : $entry + $stopDistance;

        $tp1 = $buy ? $entry + $stopDistance * 1.5 : $entry - $stopDistance * 1.5;
        $tp2 = $buy ? $entry + $stopDistance * 2.5 : $entry - $stopDistance * 2.5;
        $tp3 = $buy ? $entry + $stopDistance * 4.0 : $entry - $stopDistance * 4.0;

        $rr1 = $stopDistance > 0 ? abs($tp1 - $entry) / $stopDistance : 0;
        $rr2 = $stopDistance > 0 ? abs($tp2 - $entry) / $stopDistance : 0;
        $rr3 = $stopDistance > 0 ? abs($tp3 - $entry) / $stopDistance : 0;

        /* ── ۳) سایز پوزیشن: ریسک ثابت + تعدیل رژیم و اعتماد ──────── */
        $stopPct = $entry > 0 ? ($stopDistance / $entry) * 100 : 100;
        $position = $stopPct > 0 ? ($riskPerTrade / $stopPct) * 100 : 0;

        $regimeFactor = (float)($extra['sizing_factor'] ?? 1.0);
        $confidenceFactor = 0.55 + ($confidence / 100) * 0.7; // 0.55..1.25
        $position = $position * $regimeFactor * $confidenceFactor;
        $position = max(0.0, min($maxPosition, $position));

        /* ── ۴) فرادادهٔ اجرایی ────────────────────────────────────── */
        $holdBars = max(3, (int)round($stopDistance / max($atr * 0.55, $entry * 0.0004)));
        $leverage = $stopPct > 0 ? (int)max(1, min(3, (int)floor(0.75 / ($stopPct / 100)))) : 1;
        $invalidation = $buy
            ? 'بستن کندل زیر ' . $this->num($stop) . ' یا شکست کف ساختاری، تز را باطل می‌کند.'
            : 'بستن کندل بالای ' . $this->num($stop) . ' یا شکست سقف ساختاری، تز را باطل می‌کند.';

        return [
            'side' => $side,
            'entry' => round($entry, 8),
            'stop_loss' => round($stop, 8),
            'stop_type' => $stopType,
            'stop_distance_pct' => round($stopPct, 2),
            'take_profit_1' => round($tp1, 8),
            'take_profit_2' => round($tp2, 8),
            'take_profit_3' => round($tp3, 8),
            'risk_reward_1' => round($rr1, 2),
            'risk_reward_2' => round($rr2, 2),
            'risk_reward_3' => round($rr3, 2),
            'position_percent' => round($position, 2),
            'risk_per_trade_percent' => $riskPerTrade,
            'sizing_factor' => round($regimeFactor, 2),
            'leverage_suggested' => $leverage,
            'expected_hold_bars' => $holdBars,
            'breakeven_note' => 'پس از رسیدن به TP1، استاپ باقیمانده را به نقطهٔ ورود منتقل کنید (ریسکِ باقیمانده ≈ صفر).',
            'invalidation' => $invalidation,
        ];
    }

    private function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 6, '.', ''), '0'), '.');
    }
}
