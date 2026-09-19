<?php
namespace Meelano\Crypto;

/**
 * طبقه‌بند رژیم بازار — «اول بازار را بشناس، بعد معامله کن».
 *
 * یک معامله‌گر با ۴۰ سال سابقه قبل از هر چیز می‌پرسد: بازار الان در چه
 * حالتی است؟ استراتژی روندی در بازار رِنج له می‌شود و استراتژی بازگشت به
 * میانگین در روند قوی wiped out می‌شود. این کلاس رژیم را با شواهد کمی تشخیص
 * می‌دهد و وزن فیلترها و سایز پوزیشن را به آن وابسته می‌کند:
 *
 *   trend_up   → روند صعودی قوی (ADX بالا + DI+ غالب) → وزن فیلترهای روندی ↑
 *   trend_down → روند نزولی قوی → فقط پروژهٔ شورت/خروج جدی
 *   range      → بازار فشرده/رِنج (عرض باند در صدک پایین) → وزن بازگشت به میانگین ↑
 *   volatile   → نوسان وحشی (ATR٪ یا عرض باند در صدک بسیار بالا) → احتیاط، سایز ↓
 *
 * @author Milad Yaghoobi — Meelano Studio Design
 */
final class Regime
{
    public const TREND_UP = 'trend_up';
    public const TREND_DOWN = 'trend_down';
    public const RANGE = 'range';
    public const VOLATILE = 'volatile';

    private const LABELS = [
        self::TREND_UP => 'روند صعودی',
        self::TREND_DOWN => 'روند نزولی',
        self::RANGE => 'بازار رِنج/فشرده',
        self::VOLATILE => 'نوسان بالا',
    ];

    /**
     * @param array $ctx بافت اندیکاتورها (شامل adx, plus_di, minus_di, bb_width,
     *                  bb_width_pct, atr_pct, ema50, ema200, price, structure)
     * @return array{regime:string,label:string,strength:float,sizing_factor:float,notes:array}
     */
    public static function classify(array $ctx): array
    {
        $adx = (float)($ctx['adx'] ?? 0);
        $plusDi = (float)($ctx['plus_di'] ?? 0);
        $minusDi = (float)($ctx['minus_di'] ?? 0);
        $di = $plusDi - $minusDi;
        $bbWidthPct = $ctx['bb_width_pct'] !== null ? (float)$ctx['bb_width_pct'] : 50.0;
        $atrPct = (float)($ctx['atr_pct'] ?? 0);
        $price = (float)($ctx['price'] ?? 0);
        $ema50 = (float)($ctx['ema50'] ?? $price);
        $ema200 = (float)($ctx['ema200'] ?? $price);
        $structure = (string)($ctx['structure'] ?? 'range');

        $notes = [];

        // ۱) نوسان وحشی؟ — مهم‌ترین ریسکِ سایز پوزیشن
        $isVolatile = ($atrPct >= 4.0 && $bbWidthPct >= 85) || $atrPct >= 6.0;
        if ($isVolatile) {
            $notes[] = sprintf('نوسان غیرعادی: ATR٪=%.2f، صدک عرض باند=%.0f', $atrPct, $bbWidthPct);
        }

        // ۲) روند؟ — ADX + DI + ساختار
        $trendOk = $adx >= 20.0 && abs($di) >= 6.0;
        $emaAlignUp = $ema50 > $ema200 && $price > $ema200;
        $emaAlignDown = $ema50 < $ema200 && $price < $ema200;
        $trendUp = ($trendOk && $di > 0) || ($adx >= 25.0 && $di > 0 && ($emaAlignUp || $structure === 'uptrend'));
        $trendDown = ($trendOk && $di < 0) || ($adx >= 25.0 && $di < 0 && ($emaAlignDown || $structure === 'downtrend'));

        // ۳) فشردگی؟ — عرض باند در صدک پایین = بازار در انتظار شکست
        $isSqueeze = $bbWidthPct <= 30.0;

        if ($isVolatile) {
            $regime = self::VOLATILE;
            $sizing = 0.55;
            $notes[] = 'حالت دفاعی: سایز پوزیشن کاهش یافت و آستانهٔ سیگنال سخت‌گیرانه‌تر شد.';
        } elseif ($trendUp xor $trendDown) {
            $regime = $trendUp ? self::TREND_UP : self::TREND_DOWN;
            $sizing = $adx >= 28.0 ? 1.0 : 0.85;
            $notes[] = sprintf('ADX=%.1f با DI%s غالب؛ پیروی از روند مجاز.', $adx, $di > 0 ? '+' : '−');
            if ($regime === self::TREND_UP && $isSqueeze) {
                $notes[] = 'فشردگی باند در دل روند — شکست صعودی محتمل ولی استاپ تنگ‌تر لازم است.';
            }
        } elseif ($isSqueeze || $adx < 18.0) {
            $regime = self::RANGE;
            $sizing = 0.7;
            $notes[] = sprintf('ADX=%.1f و فشردگی=%.0f — بازار رِنج؛ استراتژی بازگشت به میانگین.', $adx, $bbWidthPct);
        } else {
            // روند ضعیف/مبهم
            $regime = self::RANGE;
            $sizing = 0.7;
            $notes[] = sprintf('شواهد روند کافی نیست (ADX=%.1f، DI=%.1f) — رفتار رِنج.', $adx, $di);
        }

        $strength = max(0.0, min(100.0, $adx * 2.0 + abs($di) * 1.2 + ($isSqueeze ? 5.0 : 0.0)));

        return [
            'regime' => $regime,
            'label' => self::LABELS[$regime],
            'strength' => round($strength, 1),
            'sizing_factor' => $sizing,
            'adx' => round($adx, 1),
            'di' => round($di, 1),
            'bb_width_pct' => round($bbWidthPct, 1),
            'notes' => $notes,
        ];
    }

    /** برچسب فارسی رژیم. */
    public static function label(string $regime): string
    {
        return self::LABELS[$regime] ?? 'نامشخص';
    }
}
