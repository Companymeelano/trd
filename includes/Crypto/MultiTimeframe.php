<?php
namespace Meelano\Crypto;

use Throwable;

/**
 * تأیید چند تایم‌فریمی (Multi-Timeframe Confluence).
 *
 * قاعدهٔ طلایی معامله‌گری حرفه‌ای: جهت را تایم‌فریم بالاتر تعیین می‌کند و
 * زمان‌بندی ورود را تایم‌فریم پایین‌تر. این کلاس نماد را روی چند تایم‌فریم
 * (پیش‌فرض 1h/4h/1d) می‌سنجد، امتیاز وزنی هر تایم‌فریم را محاسبه می‌کند و
 * «هم‌راستایی» را به‌صورت نسبت وزن تایم‌فریم‌های موافق با جهت اصلی برمی‌گرداند.
 *
 * سیگنال بدون هم‌راستایی MTF (در صورت فعال‌بودن الزام) هرگز صادر نمی‌شود —
 * این مهم‌ترین فیلتر کاهش خطای ورود در خلاف‌جهت جزر‌وّمد بزرگ‌تر است.
 *
 * @author Milad Yaghoobi — Meelano Studio Design
 */
final class MultiTimeframe
{
    /** @var MarketData */
    private $market;
    /** @var array */
    private $cfg;
    /** @var Filters */
    private $filters;

    /** وزن پیش‌فرض تایم‌فریم‌ها — بالاتر = تعیین‌کننده‌تر برای جهت. */
    private const DEFAULT_TFS = [
        '1h' => 1.0,
        '4h' => 1.6,
        '1d' => 2.0,
    ];

    public function __construct(MarketData $market, array $cfg = [])
    {
        $this->market = $market;
        $this->cfg = $cfg;
        $this->filters = new Filters();
    }

    /**
     * تحلیل هم‌راستایی چند تایم‌فریم یک نماد.
     *
     * @param string $symbol نماد (مثل BTCUSDT)
     * @param string $primarySide جهت تایم‌فریم اصلی (BUY|SELL)
     * @param string $primaryTf تایم‌فریم اصلی تحلیل
     * @return array{ok:bool,aligned:bool,alignment_ratio:float,mtf_score:float,bias:?string,tf:array,error:?string}
     */
    public function analyze(string $symbol, string $primarySide, string $primaryTf = '1h'): array
    {
        $tfs = $this->timeframes($primaryTf);
        $rows = [];
        $agreeWeight = 0.0;
        $totalWeight = 0.0;
        $error = null;

        foreach ($tfs as $tf => $weight) {
            try {
                $cres = $this->market->candles($symbol, $tf, 220);
                if (empty($cres['ok']) || count($cres['candles']) < 60) {
                    $rows[] = ['tf' => $tf, 'ok' => false, 'error' => 'کندل کافی نیست'];
                    continue;
                }
                $ctx = Context::build($cres['candles'], [], $this->cfg);
                $regime = Regime::classify($ctx);
                $ctx['regime'] = $regime['regime'];
                $eval = $this->filters->evaluate($ctx);

                $agree = $eval['side'] === $primarySide;
                if ($agree) {
                    $agreeWeight += $weight * min(1.0, $eval['tech_score'] / 100.0);
                }
                $totalWeight += $weight;

                $rows[] = [
                    'tf' => $tf,
                    'ok' => true,
                    'side' => $eval['side'],
                    'score' => $eval['tech_score'],
                    'regime' => $regime['regime'],
                    'regime_label' => $regime['label'],
                    'adx' => $ctx['adx'],
                    'structure' => $ctx['structure'],
                    'agree' => $agree,
                    'weight' => $weight,
                ];
            } catch (Throwable $e) {
                $rows[] = ['tf' => $tf, 'ok' => false, 'error' => $e->getMessage()];
                $error = $e->getMessage();
            }
        }

        $valid = array_filter($rows, static function ($r) {
            return !empty($r['ok']);
        });
        if (!count($valid)) {
            return [
                'ok' => false,
                'aligned' => false,
                'alignment_ratio' => 0.0,
                'mtf_score' => 0.0,
                'bias' => null,
                'tf' => $rows,
                'error' => $error ?? 'هیچ تایم‌فریمی تحلیل نشد.',
            ];
        }

        $ratio = $totalWeight > 0 ? $agreeWeight / $totalWeight : 0.0;
        $minAlignment = (float)($this->cfg['mtf_min_alignment'] ?? 0.55);
        $aligned = $ratio >= $minAlignment;

        // امتیاز MTF: نسبت وزنی هم‌راستا × میانگین امتیاز تایم‌فریم‌های موافق
        $agreeScores = array_values(array_filter($rows, static function ($r) use ($primarySide) {
            return !empty($r['ok']) && ($r['side'] ?? '') === $primarySide;
        }));
        $avgAgreeScore = count($agreeScores)
            ? array_sum(array_column($agreeScores, 'score')) / count($agreeScores)
            : 0.0;
        $mtfScore = round(min(100.0, $ratio * 70.0 + ($avgAgreeScore / 100.0) * 30.0), 1);

        // جهت غالب بازار از دید تایم‌فریم‌های بالاتر
        $bias = 'NEUTRAL';
        $bigTf = array_filter($rows, static function ($r) {
            return !empty($r['ok']) && in_array($r['tf'], ['4h', '1d'], true);
        });
        $buyW = 0.0;
        $sellW = 0.0;
        foreach ($bigTf as $r) {
            if ($r['side'] === 'BUY') { $buyW += $r['weight']; }
            elseif ($r['side'] === 'SELL') { $sellW += $r['weight']; }
        }
        if ($buyW > 0 && $buyW > $sellW) { $bias = 'BUY'; }
        elseif ($sellW > 0 && $sellW > $buyW) { $bias = 'SELL'; }

        return [
            'ok' => true,
            'aligned' => $aligned,
            'alignment_ratio' => round($ratio, 2),
            'mtf_score' => $mtfScore,
            'bias' => $bias,
            'tf' => $rows,
            'error' => null,
        ];
    }

    /** تایم‌فریم‌های تحلیل با وزنشان (قابل پیکربندی). */
    private function timeframes(string $primaryTf): array
    {
        $custom = (array)($this->cfg['timeframes'] ?? []);
        if ($custom) {
            $out = [];
            foreach ($custom as $tf) {
                $tf = (string)$tf;
                if ($tf !== '') {
                    $out[$tf] = self::DEFAULT_TFS[$tf] ?? 1.0;
                }
            }
            if (!isset($out[$primaryTf])) {
                $out[$primaryTf] = self::DEFAULT_TFS[$primaryTf] ?? 1.0;
            }
            return $out;
        }
        $out = self::DEFAULT_TFS;
        if (!isset($out[$primaryTf])) {
            $out[$primaryTf] = 1.0;
        }
        return $out;
    }
}
