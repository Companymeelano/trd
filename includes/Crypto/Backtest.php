<?php
namespace Meelano\Crypto;

use Throwable;

/**
 * بک‌تست شفاف و بدون نشت داده (نسخهٔ ۵٫۱).
 *
 * روش: همهٔ اندیکاتورها یک‌بار روی کل سری محاسبه می‌شوند؛ چون هر مقدار در
 * ایندکس i فقط از داده‌های ≤ i ساخته می‌شود، می‌توان با اطمینان در هر کندل
 * «فیلترهای همان لحظه» را اجرا کرد. ورود در OPEN کندل بعدی (نه کندل سیگنال)،
 * خروج با استاپ/TP2، و کارمزد + اسلیپیج (بی‌پی‌اس) در هر معامله لحاظ می‌شود.
 *
 * نسخهٔ ۵٫۱:
 *   - اسلیپیج قابل‌تنظیم (پر کردن واقعی بدتر از قیمت صفحه است)
 *   - تفکیک عملکرد به تفکیک رژیم (استراتژی در کدام رژیم سود می‌دهد؟)
 *   - جداسازی simulate() برای بازاستفاده در Walk-Forward و مونت‌کارلو
 *
 * صداقت کامل: این بک‌تست فقط لایهٔ تکنیکال را می‌سنجد (بدون اجماع AI که
 * در زمان‌های گذشته قابل بازتولید نیست). نتیجهٔ آن «تاب» است نه «تضمین».
 *
 * @author Milad Yaghoobi — Meelano Studio Design
 */
final class Backtest
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
     * اجرای بک‌تست روی یک نماد.
     *
     * @return array{ok:bool,symbol:string,timeframe:string,bars:int,trades:int,winrate:float,
     *               avg_r:float,expectancy_r:float,profit_factor:float,max_drawdown_r:float,
     *               equity:array,trades_list:array,by_regime:array,model:string,error:?string}
     */
    public function run(string $symbol, string $interval = '1h', int $bars = 500): array
    {
        $started = m_microtime();
        $bars = max(220, min(1000, $bars));
        $cres = $this->market->candles($symbol, $interval, $bars);
        if (empty($cres['ok']) || count($cres['candles']) < 220) {
            return $this->fail($symbol, $interval, $cres['error'] ?? 'دادهٔ کافی برای بک‌تست نیست (حداقل ۲۲۰ کندل).');
        }
        $out = $this->simulate($cres['candles'], $symbol, $interval);
        $out['duration_ms'] = round((m_microtime() - $started) * 1000, 1);
        return $out;
    }

    /**
     * شبیه‌سازی روی کندل‌های داده‌شده (بدون شبکه) — برای Walk-Forward.
     * @param array $candles کندل‌های بسته‌شده به ترتیب زمانی
     */
    public function simulate(array $candles, string $symbol = 'TEST', string $interval = '1h'): array
    {
        $n = count($candles);
        $series = Context::series($candles);
        $filters = new Filters();
        $risk = new RiskManager($this->cfg);

        $minFilters = (int)($this->cfg['min_filters_passed'] ?? 17);
        $minTech = (float)($this->cfg['min_tech_score'] ?? 62.0);
        $minRr = (float)($this->cfg['min_risk_reward'] ?? 2.0);
        $feeBps = max(0.0, (float)($this->cfg['backtest_fee_bps'] ?? 8.0));   // کارمزد رفت‌وبرگشت
        $slipBps = max(0.0, (float)($this->cfg['backtest_slippage_bps'] ?? 3.0)); // اسلیپیج هر سمت
        $costBps = $feeBps + $slipBps;
        $horizon = (int)($this->cfg['backtest_horizon_bars'] ?? 72);
        $start = 210; // بلوغ EMA200 + بافر

        $trades = [];
        $equity = [0.0];
        $cumR = 0.0;
        $peak = 0.0;
        $maxDd = 0.0;
        $i = $start;
        while ($i < $n - 2) {
            try {
                $ctx = Context::snapshot($series, $i, [], $this->cfg);
                if ($ctx['adx'] === null || $ctx['ema200'] === null) {
                    $i++;
                    continue;
                }
                $regime = Regime::classify($ctx);
                $ctx['regime'] = $regime['regime'];
                $eval = $filters->evaluate($ctx);

                $passed = $eval['passed'] >= $minFilters && $eval['tech_score'] >= $minTech;
                if (!$passed) {
                    $i++;
                    continue;
                }

                // ورود در OPEN کندل بعدی — بدون نشت داده (+ اسلیپیج بدتر)
                $entryIdx = $i + 1;
                $buy = $eval['side'] === 'BUY';
                $entry = (float)$candles[$entryIdx]['open'];
                $entry = $buy ? $entry * (1 + $slipBps / 10000) : $entry * (1 - $slipBps / 10000);
                $atr = (float)$ctx['atr'];
                if ($entry <= 0 || $atr <= 0) {
                    $i++;
                    continue;
                }
                $plan = $risk->plan($eval['side'], $entry, $atr, $eval['tech_score'], [
                    'swing_low' => $ctx['swing_low'],
                    'swing_high' => $ctx['swing_high'],
                    'sizing_factor' => $regime['sizing_factor'],
                ]);
                if ($plan['risk_reward_2'] < $minRr) {
                    $i++;
                    continue;
                }

                // شبیه‌سازی تا افق مشخص (خروج هم با اسلیپیج بدتر)
                $exitIdx = null;
                $exitPrice = null;
                $outcome = 'timeout';
                $stop = (float)$plan['stop_loss'];
                $tp2 = (float)$plan['take_profit_2'];
                for ($j = $entryIdx + 1; $j < min($n, $entryIdx + $horizon); $j++) {
                    $lo = (float)$candles[$j]['low'];
                    $hi = (float)$candles[$j]['high'];
                    if ($buy ? ($lo <= $stop) : ($hi >= $stop)) {
                        // محافظه‌کارانه: استاپ اول بررسی می‌شود
                        $exitIdx = $j;
                        $exitPrice = $buy ? $stop * (1 - $slipBps / 10000) : $stop * (1 + $slipBps / 10000);
                        $outcome = 'stop';
                        break;
                    }
                    if ($buy ? ($hi >= $tp2) : ($lo <= $tp2)) {
                        $exitIdx = $j;
                        $exitPrice = $buy ? $tp2 * (1 - $slipBps / 10000) : $tp2 * (1 + $slipBps / 10000);
                        $outcome = 'tp2';
                        break;
                    }
                }
                if ($exitIdx === null) {
                    $exitIdx = min($n - 1, $entryIdx + $horizon - 1);
                    $exitPrice = (float)$candles[$exitIdx]['close'];
                    $outcome = 'timeout';
                }

                $stopDist = abs($entry - $stop);
                $rMultiple = $stopDist > 0 ? (($buy ? ($exitPrice - $entry) : ($entry - $exitPrice)) / $stopDist) : 0.0;
                $feeR = $stopDist > 0 ? ((($costBps / 10000) * 2 * $entry) / $stopDist) : 0.0; // کارمزد+اسلیپیج در واحد R
                $netR = round($rMultiple - $feeR, 3);

                $cumR += $netR;
                $equity[] = round($cumR, 3);
                $peak = max($peak, $cumR);
                $maxDd = max($maxDd, $peak - $cumR);

                $trades[] = [
                    'entry_time' => isset($candles[$entryIdx]['time']) ? date('Y-m-d H:i', (int)$candles[$entryIdx]['time']) : '',
                    'side' => $eval['side'],
                    'regime' => $regime['regime'],
                    'entry' => round($entry, 8),
                    'stop' => round($stop, 8),
                    'exit' => round($exitPrice, 8),
                    'outcome' => $outcome,
                    'bars' => $exitIdx - $entryIdx,
                    'r' => $netR,
                ];
                $i = $exitIdx + 1; // تا خروج، معاملهٔ جدید باز نمی‌شود
            } catch (Throwable $e) {
                $i++;
            }
        }

        $wins = count(array_filter($trades, static function ($t) {
            return $t['r'] > 0;
        }));
        $grossWin = array_sum(array_map(static function ($t) {
            return $t['r'] > 0 ? $t['r'] : 0.0;
        }, $trades));
        $grossLoss = abs(array_sum(array_map(static function ($t) {
            return $t['r'] < 0 ? $t['r'] : 0.0;
        }, $trades)));
        $count = count($trades);

        return [
            'ok' => true,
            'symbol' => $symbol,
            'timeframe' => $interval,
            'bars' => $n,
            'from' => isset($candles[$start]['time']) ? date('Y-m-d', (int)$candles[$start]['time']) : '',
            'to' => isset($candles[$n - 1]['time']) ? date('Y-m-d', (int)$candles[$n - 1]['time']) : '',
            'trades' => $count,
            'winrate' => $count > 0 ? round($wins / $count * 100, 1) : 0.0,
            'avg_r' => $count > 0 ? round(array_sum(array_column($trades, 'r')) / $count, 3) : 0.0,
            'expectancy_r' => $count > 0 ? round(array_sum(array_column($trades, 'r')) / $count, 3) : 0.0,
            'profit_factor' => $grossLoss > 0 ? round($grossWin / $grossLoss, 2) : ($grossWin > 0 ? 99.9 : 0.0),
            'max_drawdown_r' => round($maxDd, 2),
            'equity' => $equity,
            'trades_list' => array_slice($trades, -40), // ۴۰ معاملهٔ آخر
            'by_regime' => $this->byRegime($trades),
            'model' => 'tech-only',
            'fee_bps' => $feeBps,
            'slippage_bps' => $slipBps,
            'duration_ms' => 0.0,
            'error' => null,
        ];
    }

    /** عملکرد به تفکیک رژیم — استراتژی در کدام رژیم سود می‌دهد؟ */
    private function byRegime(array $trades): array
    {
        $out = [];
        foreach ($trades as $t) {
            $r = (string)$t['regime'];
            if (!isset($out[$r])) {
                $out[$r] = ['trades' => 0, 'wins' => 0, 'sum_r' => 0.0];
            }
            $out[$r]['trades']++;
            if ($t['r'] > 0) { $out[$r]['wins']++; }
            $out[$r]['sum_r'] += (float)$t['r'];
        }
        foreach ($out as $r => &$row) {
            $row['winrate'] = $row['trades'] > 0 ? round($row['wins'] / $row['trades'] * 100, 1) : 0.0;
            $row['expectancy_r'] = $row['trades'] > 0 ? round($row['sum_r'] / $row['trades'], 3) : 0.0;
            unset($row['wins'], $row['sum_r']);
        }
        unset($row);
        ksort($out);
        return $out;
    }

    private function fail(string $symbol, string $interval, string $error): array
    {
        return [
            'ok' => false, 'symbol' => $symbol, 'timeframe' => $interval, 'bars' => 0, 'trades' => 0,
            'winrate' => 0.0, 'avg_r' => 0.0, 'expectancy_r' => 0.0, 'profit_factor' => 0.0,
            'max_drawdown_r' => 0.0, 'equity' => [], 'trades_list' => [], 'by_regime' => [],
            'model' => 'tech-only', 'fee_bps' => 0.0, 'slippage_bps' => 0.0, 'duration_ms' => 0.0, 'error' => $error,
        ];
    }
}
