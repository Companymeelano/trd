<?php
namespace Meelano\Crypto;

use Throwable;

/**
 * اعتبارسنجی استحکام استراتژی (نسخهٔ ۵٫۱).
 *
 * نتیجهٔ یک بک‌تست تک‌نمونه می‌تواند شانس خوش باشد. دو آزمون استاندارد نهادی:
 *
 *  Walk-Forward: داده به پنجره‌های زمانی متوالی تقسیم می‌شود؛ استراتژی باید در
 *  «اکثریت» پنجره‌ها سودده باشد تا «پایدار» تلقی شود (نه فقط در یک بازهٔ خوش‌شانس).
 *
 *  Monte-Carlo: ترتیب معاملات ۵۰۰ بار تصادفی جابه‌جا می‌شود؛ توزیع واقعی حداکثر
 *  افت و احتمال ضرر گزارش می‌شود — «با ۹۵٪ اطمینان افت شما بین X و Y است».
 *
 * @author Milad Yaghoobi — Meelano Studio Design
 */
final class Robustness
{
    /** @var MarketData */
    private $market;
    /** @var array */
    private $cfg;

    public function __construct(?MarketData $market = null, array $cfg = [])
    {
        $this->market = $market ?: new MarketData(null, $cfg);
        $this->cfg = $cfg;
    }

    /**
     * Walk-Forward: بک‌تست روی پنجره‌های زمانی متوالی.
     *
     * @param int $folds تعداد پنجره‌ها (۳..۸)
     * @return array{ok:bool,symbol:string,timeframe:string,folds:array,positive_folds:int,
     *               stability:float,verdict:string,error:?string}
     */
    public function walkForward(string $symbol, string $interval = '1h', int $bars = 1000, int $folds = 4): array
    {
        $bars = max(600, min(1000, $bars));
        $folds = max(3, min(8, $folds));
        $cres = $this->market->candles($symbol, $interval, $bars);
        if (empty($cres['ok']) || count($cres['candles']) < 400) {
            return ['ok' => false, 'symbol' => $symbol, 'timeframe' => $interval, 'folds' => [],
                'positive_folds' => 0, 'stability' => 0.0, 'verdict' => 'unknown',
                'error' => $cres['error'] ?? 'دادهٔ کافی برای Walk-Forward نیست.'];
        }
        $candles = $cres['candles'];
        $n = count($candles);
        $bt = new Backtest($this->market, $this->cfg);

        // پنجره‌های هم‌پوشان با گام یکسان؛ هر پنجره ≥ ۲۲۰ کندل برای بلوغ اندیکاتورها
        $window = max(220, (int)floor($n / $folds));
        $step = max(40, (int)floor(($n - $window) / max(1, $folds - 1)));

        $out = [];
        $positive = 0;
        for ($f = 0; $f < $folds; $f++) {
            $from = $f * $step;
            $slice = array_slice($candles, $from, $window);
            if (count($slice) < 220) {
                break;
            }
            try {
                $res = $bt->simulate($slice, $symbol, $interval);
                $exp = (float)$res['expectancy_r'];
                if ($exp > 0) { $positive++; }
                $out[] = [
                    'fold' => $f + 1,
                    'from' => $res['from'],
                    'to' => $res['to'],
                    'trades' => (int)$res['trades'],
                    'winrate' => (float)$res['winrate'],
                    'expectancy_r' => $exp,
                    'profit_factor' => (float)$res['profit_factor'],
                    'max_drawdown_r' => (float)$res['max_drawdown_r'],
                ];
            } catch (Throwable $e) {
                $out[] = ['fold' => $f + 1, 'error' => $e->getMessage()];
            }
        }

        $valid = count(array_filter($out, static function ($r) {
            return !isset($r['error']);
        }));
        $stability = $valid > 0 ? round($positive / $valid, 2) : 0.0;
        $verdict = $stability >= 0.75 ? 'robust' : ($stability >= 0.5 ? 'mixed' : 'fragile');

        return [
            'ok' => true,
            'symbol' => $symbol,
            'timeframe' => $interval,
            'bars' => $n,
            'folds' => $out,
            'positive_folds' => $positive,
            'stability' => $stability,
            'verdict' => $verdict, // robust ≥۷۵٪ پنجره‌ها سودده · fragile <۵۰٪
            'error' => null,
        ];
    }

    /**
     * مونت‌کارلو: توزیع ۵۰۰ بازچینیِ تصادفی ترتیب معاملات.
     *
     * @param array $tradesRs آرایهٔ R خالص معاملات (از بک‌تست یا ردیاب)
     * @param int $runs تعداد شبیه‌سازی (۱۰۰..۲۰۰۰)
     * @return array{ok:bool,runs:int,trades:int,final_r_p5:float,final_r_p50:float,final_r_p95:float,
     *               max_dd_p50:float,max_dd_p95:float,loss_prob:float,error:?string}
     */
    public function monteCarlo(array $tradesRs, int $runs = 500): array
    {
        $rs = array_values(array_filter(array_map('floatval', $tradesRs), static function ($r) {
            return is_finite($r);
        }));
        $n = count($rs);
        $runs = max(100, min(2000, $runs));
        if ($n < 5) {
            return ['ok' => false, 'runs' => 0, 'trades' => $n, 'final_r_p5' => 0.0, 'final_r_p50' => 0.0,
                'final_r_p95' => 0.0, 'max_dd_p50' => 0.0, 'max_dd_p95' => 0.0, 'loss_prob' => 1.0,
                'error' => 'حداقل ۵ معامله برای مونت‌کارلو لازم است.'];
        }

        // بذر ثابت = نتیجهٔ قابل بازتولید (حسابرسی)
        try {
            mt_srand(20260916);
        } catch (Throwable $e) {
            // برخی محیط‌ها mt_srand را محدود می‌کنند — ادامه با بذر سیستم
        }

        $finals = [];
        $dds = [];
        for ($run = 0; $run < $runs; $run++) {
            $order = $rs;
            for ($j = $n - 1; $j > 0; $j--) {
                $k = mt_rand(0, $j);
                [$order[$j], $order[$k]] = [$order[$k], $order[$j]];
            }
            $cum = 0.0;
            $peak = 0.0;
            $dd = 0.0;
            foreach ($order as $r) {
                $cum += $r;
                if ($cum > $peak) { $peak = $cum; }
                if ($peak - $cum > $dd) { $dd = $peak - $cum; }
            }
            $finals[] = $cum;
            $dds[] = $dd;
        }
        sort($finals);
        sort($dds);

        $q = static function (array $arr, float $p) {
            $idx = (int)floor($p * (count($arr) - 1));
            return round((float)$arr[$idx], 2);
        };

        return [
            'ok' => true,
            'runs' => $runs,
            'trades' => $n,
            'final_r_p5' => $q($finals, 0.05),   // بدشانس‌ترین ۵٪
            'final_r_p50' => $q($finals, 0.50),  // میانه
            'final_r_p95' => $q($finals, 0.95),  // خوش‌شانس‌ترین ۵٪
            'max_dd_p50' => $q($dds, 0.50),      // میانهٔ حداکثر افت
            'max_dd_p95' => $q($dds, 0.95),      // افت در بدشانس‌ترین ۵٪ (خطر واقعی)
            'loss_prob' => round(count(array_filter($finals, static function ($f) {
                return $f <= 0;
            })) / $runs, 3), // احتمال تمام‌شدن سرِ سود
            'error' => null,
        ];
    }
}
