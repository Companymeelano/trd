<?php
namespace Meelano\Crypto;

use Meelano\Ai\Client;

/**
 * اعتبارسنج هوش مصنوعی — کاهش خطا با «اجماع چندمدلی» (نسخهٔ ۵).
 *
 * به‌جای اعتماد به یک مدل، از چند موتور مستقل (برترین‌های مسیریاب) نظر گرفته و
 * با رأی‌گیری وزنی به اجماع می‌رسد. مدل‌ها علاوه بر جهت، «نقطهٔ ابطال» و
 * «ریسک‌های مشخص» هم ارائه می‌دهند تا خروجی صرفاً عدد نباشد.
 *
 * سیگنال نهایی فقط وقتی صادر می‌شود که اجماع AI با سمت تکنیکال هم‌راستا باشد؛
 * این «فیلتر دوم» خطای تک‌مدل را حذف می‌کند.
 *
 * @author Milad Yaghoobi — Meelano Studio Design
 */
final class AiValidator
{
    /** @var Client */
    private $client;
    /** @var int */
    private $panelSize;

    public function __construct(Client $client, int $panelSize = 3)
    {
        $this->client = $client;
        $this->panelSize = max(1, $panelSize);
    }

    /**
     * @param array $summary خلاصهٔ کامل تحلیل (اندیکاتورها + رژیم + MTF)
     * @param string $techSide سمت تکنیکال (BUY/SELL)
     * @return array{ok:bool,side:?string,ai_score:float,agreement:bool,agree_ratio:float,
     *               opinions:array,notes:array,invalidation:?string}
     */
    public function validate(array $summary, string $techSide): array
    {
        $candidates = $this->panel();
        if (!$candidates) {
            return [
                'ok' => false, 'side' => null, 'ai_score' => 0.0, 'agreement' => false, 'agree_ratio' => 0.0,
                'opinions' => [], 'notes' => ['هیچ موتور AI فعالی برای اجماع موجود نیست.'], 'invalidation' => null,
            ];
        }

        $prompt = $this->prompt($summary, $techSide);
        $opinions = [];
        foreach ($candidates as $provider) {
            $res = $this->client->json('crypto.signal', $prompt, [
                'provider' => $provider,
                'temperature' => 0.15,
                'max_tokens' => 400,
            ]);
            if (!empty($res['ok']) && is_array($res['data'])) {
                $signal = strtoupper((string)($res['data']['signal'] ?? ''));
                $conf = min(100.0, max(0.0, (float)($res['data']['confidence'] ?? 0)));
                if (in_array($signal, ['BUY', 'SELL', 'NEUTRAL', 'HOLD'], true)) {
                    $opinions[] = [
                        'provider' => $provider,
                        'provider_label' => $res['provider_label'] ?? $provider,
                        'signal' => $signal === 'HOLD' ? 'NEUTRAL' : $signal,
                        'confidence' => $conf,
                        'reasoning' => mb_substr((string)($res['data']['reasoning'] ?? ''), 0, 400),
                        'risks' => array_slice((array)($res['data']['risks'] ?? []), 0, 4),
                        'invalidation' => mb_substr((string)($res['data']['invalidation'] ?? ''), 0, 240),
                    ];
                }
            }
        }

        if (!$opinions) {
            return [
                'ok' => false, 'side' => null, 'ai_score' => 0.0, 'agreement' => false, 'agree_ratio' => 0.0,
                'opinions' => [], 'notes' => ['هیچ مدلی پاسخ معتبر نداد.'], 'invalidation' => null,
            ];
        }

        // رأی‌گیری وزنی بر پایهٔ اعتماد هر مدل
        $buyW = 0.0; $sellW = 0.0; $totalW = 0.0;
        foreach ($opinions as $o) {
            $w = $o['confidence'];
            $totalW += $w;
            if ($o['signal'] === 'BUY') { $buyW += $w; }
            elseif ($o['signal'] === 'SELL') { $sellW += $w; }
        }
        $aiSide = $buyW >= $sellW ? 'BUY' : 'SELL';
        $dominant = max($buyW, $sellW);
        $aiScore = $totalW > 0 ? ($dominant / $totalW) * 100 : 0;

        // همگرایی مدل‌ها با تکنیکال
        $agreeWithTech = 0;
        foreach ($opinions as $o) {
            if ($o['signal'] === $techSide) { $agreeWithTech++; }
        }
        $agreement = $aiSide === $techSide && ($agreeWithTech / count($opinions)) >= 0.5;

        $notes = [];
        foreach ($opinions as $o) {
            $notes[] = $o['provider'] . ': ' . $o['signal'] . ' (' . round($o['confidence']) . '٪)';
        }

        // نقطهٔ ابطال اجماع: پرتکرارترین نظر مدل‌ها
        $invalidation = null;
        $counts = [];
        foreach ($opinions as $o) {
            if ($o['invalidation'] !== '') {
                $counts[$o['invalidation']] = ($counts[$o['invalidation']] ?? 0) + 1;
            }
        }
        if ($counts) {
            arsort($counts);
            $invalidation = (string)array_key_first($counts);
        }

        return [
            'ok' => true,
            'side' => $aiSide,
            'ai_score' => round($aiScore, 1),
            'agreement' => $agreement,
            'agree_ratio' => round($agreeWithTech / count($opinions), 2),
            'panel' => count($opinions),
            'opinions' => $opinions,
            'notes' => $notes,
            'invalidation' => $invalidation,
        ];
    }

    /** برترین ارائه‌دهندگان واجد شرایط برای وظیفهٔ سیگنال. */
    private function panel(): array
    {
        $ranked = $this->client->router()->rank('crypto.signal');
        $out = [];
        foreach ($ranked as $row) {
            if (!empty($row['eligible']) && $row['score'] > 0) {
                $out[] = $row['provider'];
            }
            if (count($out) >= $this->panelSize) {
                break;
            }
        }
        return $out;
    }

    /** پرامپت نهادی: دادهٔ کامل + الزام خروجی JSON مشخص. */
    private function prompt(array $s, string $techSide): string
    {
        $mtf = '';
        if (!empty($s['mtf']['tf']) && is_array($s['mtf']['tf'])) {
            $parts = [];
            foreach ($s['mtf']['tf'] as $row) {
                if (!empty($row['ok'])) {
                    $parts[] = sprintf(
                        '%s: جهت %s، امتیاز %.0f، رژیم %s، ADX %s',
                        $row['tf'],
                        $row['side'],
                        $row['score'],
                        $row['regime_label'],
                        $row['adx'] !== null ? round((float)$row['adx'], 1) : 'نامشخص'
                    );
                }
            }
            if ($parts) {
                $mtf = "هم‌راستایی چند تایم‌فریم:\n- " . implode("\n- ", $parts) . "\n";
            }
        }

        return "تو یک معامله‌گر نهادی کریپتو با مدیریت ریسک سخت‌گیرانه هستی. "
            . "دادهٔ فنی کاملی از یک ارز در اختیار داری. با سخت‌گیری قضاوت کن؛ "
            . "اگر شواهد کافی نیست حتماً NEUTRAL بده. عدد و قیمت ساختگی تولید نکن.\n\n"
            . "نماد: {$s['symbol']}\n"
            . "قیمت: {$s['price']}\n"
            . "رژیم بازار: " . ($s['regime_label'] ?? 'نامشخص') . " (قدرت: " . ($s['regime_strength'] ?? '?') . ")\n"
            . "اندیکاتورها — RSI(14): {$s['rsi']} · MACD hist: {$s['macd_hist']} · EMA9/21/50/200: {$s['ema9']}/{$s['ema21']}/{$s['ema50']}/{$s['ema200']}\n"
            . "موقعیت بولینگر: " . round(((float)$s['bb_pos']) * 100) . "٪ · استوکستیک K/D: {$s['stoch_k']}/{$s['stoch_d']}\n"
            . "ADX: " . ($s['adx'] ?? 'نامشخص') . " · DI+/DI−: " . ($s['plus_di'] ?? '?') . "/" . ($s['minus_di'] ?? '?') . "\n"
            . "ATR٪: {$s['atr_pct']} · نسبت حجم: {$s['vol_ratio']}× · شیب OBV: " . ($s['obv_slope'] ?? '?') . "\n"
            . "VWAP: " . ($s['vwap'] ?? '?') . " · تغییر ۲۴س٪: {$s['change24']} · ساختار: {$s['structure']}\n"
            . "Supertrend: " . (!empty($s['supertrend_dir']) && (int)$s['supertrend_dir'] === 1 ? 'صعودی' : 'نزولی') . "\n"
            . $mtf
            . "نتیجهٔ موتور تکنیکال: سمت {$techSide} با امتیاز {$s['tech_score']} از ۱۰۰ (عبور از فیلترها: " . ($s['passed'] ?? '?') . "/" . ($s['total'] ?? '?') . ")\n"
            . "پلن ریسک پیشنهادی: ورود {$s['risk_entry']} · استاپ {$s['risk_stop']} · TP2 {$s['risk_tp2']}\n\n"
            . "خروجی را فقط به‌صورت یک آبجکت JSON معتبر و بدون هیچ متن اضافه بده:\n"
            . '{"signal":"BUY|SELL|NEUTRAL","confidence":0-100,"reasoning":"دلیل کوتاه","risks":["..."],"invalidation":"شرطی که تز را باطل می‌کند"}';
    }
}
