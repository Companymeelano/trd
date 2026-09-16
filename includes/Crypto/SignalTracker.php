<?php
namespace Meelano\Crypto;

use Meelano\Db;
use Throwable;

/**
 * ردیاب سیگنال — چشم سامانه به گذشته (نسخهٔ ۵٫۱).
 *
 * وظیفه: هر سیگنال ذخیره‌شده را با کندل‌های واقعیِ بعد از انتشارش داوری کند
 * و پاسخ را به دیتابیس برگرداند. بدون این حلقهٔ بازخورد، «درجهٔ A+» فقط یک
 * ادعاست؛ با آن، به آمار قابل‌اندازه‌گیری تبدیل می‌شود:
 *   - نرخ رسیدن به TP1/TP2/TP3 و استاپ به تفکیک (درجه × رژیم)
 *   - امید ریاضی واقعی (R) هر ترکیب
 *   - سوختِ پرامپت AI و «اعتماد کالیبره» داشبورد
 *
 * مدل خروج (سند صداقت):
 *   ۵۰٪ پوزیشن در TP1 (استاپ به سربه‌سر منتقل می‌شود — مطابق پلن ریسک)
 *   ۲۵٪ در TP2 (باقیمانده سربه‌سر)
 *   ۲۵٪ باقی‌مانده تا TP3 یا خروج افق در کلوز
 * بنابراین بدترین حالتِ بعد از TP1 سود تضمینی +۰٫۷۵R است.
 *
 * @author Milad Yaghoobi — Meelano Studio Design
 */
final class SignalTracker
{
    /** @var Db */
    private $db;
    /** @var MarketData */
    private $market;
    /** @var array */
    private $cfg;

    public function __construct(Db $db, ?MarketData $market = null, array $cfg = [])
    {
        $this->db = $db;
        $this->market = $market ?: new MarketData(null, $cfg);
        $this->cfg = $cfg;
    }

    /**
     * یک دور داوری سیگنال‌های باز با کندل‌های واقعی.
     *
     * @return array{ok:bool,checked:int,updated:int,closed:int,errors:array}
     */
    public function run(int $limit = 60): array
    {
        if (!$this->db->tableExists('signals')) {
            return ['ok' => false, 'checked' => 0, 'updated' => 0, 'closed' => 0, 'errors' => ['جدول signals موجود نیست.']];
        }
        $limit = max(1, min(200, $limit));
        $rows = $this->db->select(
            'SELECT * FROM ' . $this->db->table('signals')
            . " WHERE status IN ('new', 'suppressed', 'near_miss') AND outcome = '' ORDER BY created_at ASC LIMIT " . (int)$limit
        );

        $checked = 0;
        $updated = 0;
        $closed = 0;
        $errors = [];

        foreach ($rows as $sig) {
            $checked++;
            try {
                $res = $this->judge($sig);
                if ($res === null) {
                    continue; // هنوز دادهٔ کافی نیست
                }
                $this->db->update('signals', [
                    'hit_tp1' => (int)$res['hit_tp1'],
                    'hit_tp2' => (int)$res['hit_tp2'],
                    'hit_tp3' => (int)$res['hit_tp3'],
                    'hit_stop' => (int)$res['hit_stop'],
                    'outcome' => (string)$res['outcome'],
                    'exit_price' => (float)$res['exit_price'],
                    'r_multiple' => (float)$res['r_multiple'],
                    'bars_held' => (int)$res['bars_held'],
                    'resolved_at' => $res['resolved_at'],
                    'tracker_json' => json_encode($res['detail'], JSON_UNESCAPED_UNICODE),
                    // هویت ردیف حفظ می‌شود: سرکوب‌شده/فرصت نزدیک پس از داوری هم همان می‌مانند
                    'status' => in_array((string)$sig['status'], ['suppressed', 'near_miss'], true)
                        ? (string)$sig['status'] : 'closed',
                ], 'id = :id', ['id' => (int)$sig['id']]);
                $updated++;
                if ($res['outcome'] !== 'open') {
                    $closed++;
                }
            } catch (Throwable $e) {
                $errors[] = ($sig['symbol'] ?? '?') . ': ' . $e->getMessage();
            }
        }

        // نسخهٔ ۵٫۶: هر داوری تازه، سوخت موتور یادگیری تطبیقی است
        if ($closed > 0) {
            try {
                LearningEngine::learn($this->db);
            } catch (Throwable $e) { /* یادگیری هرگز داوری را نمی‌شکند */ }
        }

        return ['ok' => true, 'checked' => $checked, 'updated' => $updated, 'closed' => $closed, 'errors' => $errors];
    }

    /**
     * داوری یک سیگنال با کندل‌های واقعی بعد از انتشار.
     *
     * @return array|null null = هنوز قابل داوری نیست (کندل کافی/در دسترس نیست)
     */
    private function judge(array $sig): ?array
    {
        $tf = (string)($sig['timeframe'] ?? '1h');
        $entry = (float)$sig['entry_price'];
        $stop = (float)$sig['stop_loss'];
        $tp1 = (float)$sig['take_profit_1'];
        $tp2 = (float)$sig['take_profit_2'];
        $tp3 = (float)$sig['take_profit_3'];
        $buy = strtoupper((string)($sig['side'] ?? 'BUY')) === 'BUY';
        $createdAt = strtotime((string)($sig['created_at'] ?? ''));
        if ($entry <= 0 || $stop <= 0 || $createdAt === false) {
            return null;
        }
        $stopDist = abs($entry - $stop);
        if ($stopDist <= 0) {
            return null;
        }

        $horizon = (int)($this->cfg['backtest_horizon_bars'] ?? 72);
        $maxAge = (int)($this->cfg['tracker_max_age_hours'] ?? 96) * 3600;
        $sec = MarketData::intervalSeconds($tf);
        $need = (int)ceil(min($horizon + 8, ($maxAge / $sec) + 8));
        $cres = $this->market->candles((string)$sig['symbol'], $tf, max(60, min(300, $need)));
        if (empty($cres['ok'])) {
            return null;
        }
        $candles = $cres['candles'];

        // ایندکس اولین کندلِ «بعد از» انتشار سیگنال
        $start = null;
        foreach ($candles as $k => $c) {
            if ((int)$c['time'] >= $createdAt + $sec) {
                $start = (int)$k;
                break;
            }
        }
        if ($start === null) {
            return null; // کندلِ بعد از انتشار هنوز موجود نیست
        }

        $hit = ['tp1' => null, 'tp2' => null, 'tp3' => null, 'stop' => null];
        $hitAt = static function (array $c) use ($buy, $stop, $tp1, $tp2, $tp3, &$hit): ?string {
            $lo = (float)$c['low'];
            $hi = (float)$c['high'];
            // محافظه‌کارانه: استاپ اولویت دارد
            if ($buy ? ($lo <= $stop) : ($hi >= $stop)) { return 'stop'; }
            if ($hit['tp1'] === null && ($buy ? ($hi >= $tp1) : ($lo <= $tp1))) { return 'tp1'; }
            if ($hit['tp2'] === null && ($buy ? ($hi >= $tp2) : ($lo <= $tp2))) { return 'tp2'; }
            if ($hit['tp3'] === null && ($buy ? ($hi >= $tp3) : ($lo <= $tp3))) { return 'tp3'; }
            return null;
        };

        $exitIdx = null;
        $exitPrice = null;
        $outcome = null;
        $end = min(count($candles), $start + $horizon);
        for ($j = $start; $j < $end; $j++) {
            $ev = $hitAt($candles[$j]);
            if ($ev === 'stop') {
                $exitIdx = $j;
                // بعد از TP1 استاپ = سربه‌سر (استاپ منتقل شده)
                $exitPrice = $hit['tp1'] !== null ? $entry : $stop;
                $outcome = $hit['tp1'] !== null ? 'be_stop' : 'stop';
                $hit['stop'] = $j;
                break;
            }
            if ($ev !== null) {
                $hit[$ev] = $j;
                if ($ev === 'tp3') {
                    $exitIdx = $j;
                    $exitPrice = $tp3;
                    $outcome = 'tp3';
                    break;
                }
            }
        }

        // افق تمام شد یا هنوز در جریان است
        if ($outcome === null) {
            $lastIdx = min(count($candles) - 1, $start + $horizon - 1);
            $cutoff = $createdAt + $maxAge;
            if ((int)$candles[$lastIdx]['time'] >= $cutoff || $lastIdx >= count($candles) - 1 && (int)$candles[count($candles) - 1]['time'] < time() - 6 * $sec) {
                // خیلی قدیمی — با آخرین قیمت موجود ببند
                $exitIdx = $lastIdx;
                $exitPrice = (float)$candles[$lastIdx]['close'];
                $outcome = 'timeout';
            } else {
                return null; // هنوز در جریان — دفعهٔ بعد داوری می‌شود
            }
        }

        // محاسبهٔ R با مدل خروج پلکانی: 50% TP1 · 25% TP2 · 25% TP3/بازار
        $r = 0.0;
        $parts = 0.0;
        if ($hit['tp1'] !== null) { $r += 0.50 * (abs($tp1 - $entry) / $stopDist); $parts += 0.50; }
        if ($hit['tp2'] !== null) { $r += 0.25 * (abs($tp2 - $entry) / $stopDist); $parts += 0.25; }
        $remain = 1.0 - $parts;
        if ($remain > 0) {
            $lastR = $buy ? ($exitPrice - $entry) : ($entry - $exitPrice);
            $r += $remain * ($lastR / $stopDist);
        }
        if ($outcome === 'stop') { $r = -1.0; } // خروج کامل قبل از هر TP

        // کارمزد + اسلیپیج در واحد R
        $feeBps = max(0.0, (float)($this->cfg['backtest_fee_bps'] ?? 8.0));
        $slipBps = max(0.0, (float)($this->cfg['backtest_slippage_bps'] ?? 3.0));
        $feeR = ((($feeBps + $slipBps) / 10000) * 2 * $entry) / $stopDist;
        $r = round($r - $feeR, 3);

        $outcomeLabel = $outcome === 'timeout' && $hit['tp1'] !== null ? 'tp1_timeout' : $outcome;

        return [
            'hit_tp1' => $hit['tp1'] !== null ? 1 : 0,
            'hit_tp2' => $hit['tp2'] !== null ? 1 : 0,
            'hit_tp3' => $hit['tp3'] !== null ? 1 : 0,
            'hit_stop' => $hit['stop'] !== null ? 1 : 0,
            'outcome' => $outcomeLabel,
            'exit_price' => round((float)$exitPrice, 8),
            'r_multiple' => $r,
            'bars_held' => $exitIdx !== null ? (int)($exitIdx - $start) : 0,
            'resolved_at' => date('Y-m-d H:i:s'),
            'detail' => [
                'tp1_at' => $hit['tp1'] !== null ? date('Y-m-d H:i', (int)$candles[$hit['tp1']]['time']) : null,
                'tp2_at' => $hit['tp2'] !== null ? date('Y-m-d H:i', (int)$candles[$hit['tp2']]['time']) : null,
                'tp3_at' => $hit['tp3'] !== null ? date('Y-m-d H:i', (int)$candles[$hit['tp3']]['time']) : null,
                'stop_at' => $hit['stop'] !== null ? date('Y-m-d H:i', (int)$candles[$hit['stop']]['time']) : null,
                'start_at' => date('Y-m-d H:i', (int)$candles[$start]['time']),
                'model' => '50/25/25 + BE-stop',
            ],
        ];
    }

    /**
     * آمار عملکرد واقعی سیگنال‌ها به تفکیک (درجه × رژیم).
     *
     * @return array{rows:array,overall:array,total:int}
     */
    public function stats(): array
    {
        if (!$this->db->tableExists('signals')) {
            return ['rows' => [], 'overall' => $this->emptyStat(), 'total' => 0];
        }
        $t = $this->db->table('signals');
        $rows = $this->db->select(
            "SELECT tier, regime, COUNT(*) AS n,
                SUM(CASE WHEN hit_tp1 = 1 THEN 1 ELSE 0 END) AS tp1_hits,
                SUM(CASE WHEN hit_tp2 = 1 THEN 1 ELSE 0 END) AS tp2_hits,
                SUM(CASE WHEN hit_stop = 1 AND hit_tp1 = 0 THEN 1 ELSE 0 END) AS raw_stops,
                SUM(CASE WHEN r_multiple > 0 THEN 1 ELSE 0 END) AS wins,
                AVG(r_multiple) AS avg_r
             FROM {$t} WHERE outcome <> '' AND r_multiple IS NOT NULL AND status <> 'near_miss'
             GROUP BY tier, regime HAVING COUNT(*) >= 1
             ORDER BY tier ASC, n DESC"
        );
        $out = [];
        foreach ($rows as $r) {
            $n = max(1, (int)$r['n']);
            $out[] = [
                'tier' => (string)$r['tier'],
                'regime' => (string)$r['regime'],
                'n' => (int)$r['n'],
                'tp1_rate' => round((int)$r['tp1_hits'] / $n * 100, 1),
                'tp2_rate' => round((int)$r['tp2_hits'] / $n * 100, 1),
                'raw_stop_rate' => round((int)$r['raw_stops'] / $n * 100, 1),
                'win_rate' => round((int)$r['wins'] / $n * 100, 1),
                'avg_r' => round((float)$r['avg_r'], 3),
            ];
        }

        $o = $this->db->selectOne(
            "SELECT COUNT(*) AS n,
                SUM(CASE WHEN hit_tp1 = 1 THEN 1 ELSE 0 END) AS tp1_hits,
                SUM(CASE WHEN r_multiple > 0 THEN 1 ELSE 0 END) AS wins,
                AVG(r_multiple) AS avg_r
             FROM {$t} WHERE outcome <> '' AND r_multiple IS NOT NULL AND status <> 'near_miss'"
        );
        $overall = $this->emptyStat();
        $total = 0;
        if ($o && (int)$o['n'] > 0) {
            $total = (int)$o['n'];
            $overall = [
                'n' => $total,
                'tp1_rate' => round((int)$o['tp1_hits'] / $total * 100, 1),
                'win_rate' => round((int)$o['wins'] / $total * 100, 1),
                'avg_r' => round((float)$o['avg_r'], 3),
            ];
        }
        return ['rows' => $out, 'overall' => $overall, 'total' => $total];
    }

    /**
     * آمار یک ترکیب (درجه × رژیم) — سوخت کالیبراسیون و پرامپت AI.
     * @return array{n:int,tp1_rate:float,win_rate:float,avg_r:float}|null
     */
    public function statsFor(string $tier, string $regime): ?array
    {
        if (!$this->db->tableExists('signals')) {
            return null;
        }
        $row = $this->db->selectOne(
            'SELECT COUNT(*) AS n,
                SUM(CASE WHEN hit_tp1 = 1 THEN 1 ELSE 0 END) AS tp1_hits,
                SUM(CASE WHEN r_multiple > 0 THEN 1 ELSE 0 END) AS wins,
                AVG(r_multiple) AS avg_r
             FROM ' . $this->db->table('signals') . '
             WHERE outcome <> \'\' AND r_multiple IS NOT NULL AND status <> \'near_miss\' AND tier = :t AND regime = :r',
            ['t' => $tier, 'r' => $regime]
        );
        if (!$row || (int)$row['n'] < 3) {
            return null; // نمونهٔ آماری کافی نیست
        }
        $n = (int)$row['n'];
        return [
            'n' => $n,
            'tp1_rate' => round((int)$row['tp1_hits'] / $n * 100, 1),
            'win_rate' => round((int)$row['wins'] / $n * 100, 1),
            'avg_r' => round((float)$row['avg_r'], 3),
        ];
    }

    /** خط خلاصه برای تزریق به پرامپت AI («سابقهٔ سیگنال‌های مشابه»). */
    public function promptLine(string $tier, string $regime): string
    {
        $st = $this->statsFor($tier, $regime);
        if ($st === null) {
            return '';
        }
        return sprintf(
            'سابقهٔ سیگنال‌های مشابه (درجه %s در رژیم %s): از %d سیگنال، %s%% به TP1 رسیدند، وین‌ریت %s%%، میانگین %sR.',
            $tier,
            $regime,
            $st['n'],
            rtrim(rtrim(number_format($st['tp1_rate'], 1), '0'), '.'),
            rtrim(rtrim(number_format($st['win_rate'], 1), '0'), '.'),
            rtrim(rtrim(number_format($st['avg_r'], 2), '0'), '.')
        );
    }

    /** اعتماد کالیبره: ترکیب امتیاز موتور با وین‌ریت تاریخیِ همان ترکیب. */
    public function calibratedConfidence(float $combined, string $tier, string $regime): array
    {
        $st = $this->statsFor($tier, $regime);
        if ($st === null) {
            return ['confidence' => round($combined, 1), 'calibrated' => false, 'stats' => null];
        }
        $w = min(0.35, $st['n'] / 100); // وزن آمار با نمونه رشد می‌کند (سقف ۳۵٪)
        $cal = round($combined * (1 - $w) + $st['win_rate'] * $w, 1);
        return ['confidence' => $cal, 'calibrated' => true, 'stats' => $st];
    }

    private function emptyStat(): array
    {
        return ['n' => 0, 'tp1_rate' => 0.0, 'win_rate' => 0.0, 'avg_r' => 0.0];
    }
}
