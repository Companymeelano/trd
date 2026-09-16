<?php
namespace Meelano\Crypto;

use Meelano\Ai\Client;
use Meelano\Config;
use Meelano\Db;
use Meelano\Logger;
use Throwable;

/**
 * موتور سیگنال کریپتو — نسخهٔ ۵ (ارکستراتور قیف تصمیم نهادی).
 *
 * قیف تصمیم (از ارزان به گران — هر مرحله فقط برگزیدگان مرحلهٔ قبل را می‌بیند):
 *
 *   ۱) پیش‌فیلتر نقدینگی (حجم ۲۴س)
 *   ۲) بافت تایم‌فریم اصلی + رژیم بازار (Regime)
 *   ۳) ۱۵ فیلتر هم‌گرایی وزن‌دار وابسته به رژیم (Filters)
 *   ۴) آستانه‌های سخت: حداقل فیلتر عبوری + حداقل امتیاز تکنیکال
 *   ۵) تأیید چند تایم‌فریمی (MTF) — جهت با تایم‌فریم بالاتر
 *   ۶) اجماع چندمدلی AI (AiValidator) — فیلتر دوم مستقل
 *   ۷) دروازهٔ بیت‌کوین: در رژیم نزولی شدید BTC، لانگ‌های ضعیف مسدود می‌شوند
 *   ۸) پلن ریسک ساختاری (RiskManager) + دروازهٔ حداقل R:R
 *   ۹) درجه‌بندی کیفیت (A+/A/B/C) + خنک‌کردن تکرار (Cooldown)
 *
 * خروجی فقط وقتی صادر می‌شود که همهٔ مراحل هم‌راستا باشند.
 *
 * @author Milad Yaghoobi — Meelano Studio Design
 */
final class SignalEngine
{
    /** @var MarketData */
    private $market;
    /** @var Filters */
    private $filters;
    /** @var RiskManager */
    private $risk;
    /** @var Client|null */
    private $ai;
    /** @var Db|null */
    private $db;
    /** @var array */
    private $cfg;

    public function __construct(?MarketData $market = null, ?Client $ai = null, ?Db $db = null, ?array $cfg = null)
    {
        $this->market = $market ?: new MarketData(null, (array)Config::get('market', []));
        $this->filters = new Filters();
        $this->risk = new RiskManager((array)($cfg ?? (array)Config::get('trading', [])));
        $this->ai = $ai;
        $this->db = $db;
        $this->cfg = $cfg ?: (array)Config::get('trading', []);
    }

    /**
     * رصد کل بازار و تولید سیگنال‌های عبورکرده از قیف کامل.
     *
     * @return array{ok:bool,scanned:int,signals:array,suppressed:int,errors:array,
     *               duration_ms:float,ai_used:bool,breadth:array,btc:?array}
     */
    public function scanMarket(?int $limit = null): array
    {
        $started = m_microtime();
        $limit = $limit ?? (int)($this->cfg['scan_limit'] ?? 40);
        $tickers = $this->market->tickers($limit);

        if (!$tickers) {
            return [
                'ok' => false, 'scanned' => 0, 'signals' => [], 'suppressed' => 0,
                'errors' => ['داده بازار دریافت نشد — اتصال سرور به Binance/CoinGecko را بررسی کنید.'],
                'duration_ms' => 0, 'ai_used' => false, 'breadth' => [], 'btc' => null,
            ];
        }

        // عرض بازار: نسبت صعودی/نزولی — زمینهٔ کلی برای تفسیر سیگنال‌ها
        $breadth = $this->breadth($tickers);

        // رژیم بیت‌کوین — لنگرگاه جهانی بازار کریپتو
        $btc = (bool)($this->cfg['btc_filter'] ?? true) ? $this->btcRegime() : null;

        $signals = [];
        $suppressed = 0;
        $errors = [];
        $aiUsed = false;
        $maxSignals = (int)($this->cfg['max_signals_per_scan'] ?? 8);
        $minVol = (float)($this->cfg['min_quote_volume'] ?? 0);

        foreach ($tickers as $t) {
            if ((float)$t['quote_volume'] < $minVol) {
                continue;
            }
            try {
                $signal = $this->analyzeSymbol($t['symbol'], $t, $btc, $breadth);
            } catch (Throwable $e) {
                $errors[] = $t['symbol'] . ': ' . $e->getMessage();
                Logger::write('crypto', 'خطای تحلیل ' . $t['symbol'] . ': ' . $e->getMessage(), 'warning');
                continue;
            }
            if (!empty($signal['is_signal'])) {
                if (!empty($signal['suppressed'])) {
                    $suppressed++;
                    continue;
                }
                $signals[] = $signal;
                if (!empty($signal['ai']['ok'])) {
                    $aiUsed = true;
                }
                if (count($signals) >= $maxSignals) {
                    break;
                }
            }
        }

        usort($signals, static function ($a, $b) {
            return $b['combined_score'] <=> $a['combined_score'];
        });

        $scanId = $this->recordScan(count($tickers), count($signals), $breadth, $btc);
        foreach ($signals as &$s) {
            $s['scan_id'] = $scanId;
            $this->persistSignal($s);
        }
        unset($s);

        return [
            'ok' => true,
            'scanned' => count($tickers),
            'signals' => $signals,
            'suppressed' => $suppressed,
            'errors' => $errors,
            'duration_ms' => round((m_microtime() - $started) * 1000, 1),
            'ai_used' => $aiUsed,
            'breadth' => $breadth,
            'btc' => $btc,
        ];
    }

    /**
     * تحلیل یک نماد با قیف کامل (با ticker اختیاری برای جلوگیری از فراخوانی اضافه).
     *
     * @param array|null $btc خروجی btcRegime (در صورت null و فعال‌بودن فیلتر، محاسبه می‌شود)
     * @return array
     */
    public function analyzeSymbol(string $symbol, ?array $ticker = null, ?array $btc = null, ?array $breadth = null): array
    {
        $interval = (string)($this->cfg['timeframe'] ?? '1h');
        $cres = $this->market->candles($symbol, $interval, 220);
        if (empty($cres['ok']) || count($cres['candles']) < 60) {
            return ['symbol' => $symbol, 'is_signal' => false, 'error' => $cres['error'] ?? 'کندل کافی نیست'];
        }

        $ctx = Context::build($cres['candles'], (array)$ticker, $this->cfg);

        /* ── رژیم بازار ─────────────────────────────────────────────── */
        $regime = Regime::classify($ctx);
        $ctx['regime'] = $regime['regime'];

        /* ── فیلترهای هم‌گرایی وزن‌دار ───────────────────────────────── */
        $eval = $this->filters->evaluate($ctx);

        $base = [
            'symbol' => $symbol,
            'base' => $ticker['base'] ?? rtrim($symbol, 'USDT'),
            'price' => $ctx['price'],
            'change24' => $ctx['change24'],
            'quote_volume' => $ctx['quote_volume'],
            'timeframe' => $interval,
            'regime' => $regime['regime'],
            'regime_label' => $regime['label'],
            'regime_strength' => $regime['strength'],
            'regime_notes' => $regime['notes'],
            'tech_side' => $eval['side'],
            'tech_score' => $eval['tech_score'],
            'confluence' => $eval['confluence'],
            'confluence_total' => $eval['confluence_total'],
            'filters' => $eval['filters'],
            'passed' => $eval['passed'],
            'total' => $eval['total'],
            'indicators' => [
                'rsi' => $ctx['rsi'],
                'adx' => $ctx['adx'],
                'atr_pct' => $ctx['atr_pct'],
                'vol_ratio' => $ctx['vol_ratio'],
                'bb_pos' => $ctx['bb_pos'],
                'vwap' => $ctx['vwap'],
                'structure' => $ctx['structure'],
                'supertrend_dir' => $ctx['supertrend_dir'] === 1 ? 'up' : ($ctx['supertrend_dir'] === -1 ? 'down' : null),
                'obv_slope' => $ctx['obv_slope'],
            ],
        ];

        /* ── دروازهٔ ۱: آستانه‌های سخت فیلترها ───────────────────────── */
        if ($eval['passed'] < (int)($this->cfg['min_filters_passed'] ?? 11)
            || $eval['tech_score'] < (float)($this->cfg['min_tech_score'] ?? 62)) {
            return $base + ['is_signal' => false, 'reason' => 'عبور نکردن از آستانهٔ فیلترهای سخت‌گیرانه'];
        }

        /* ── دروازهٔ ۲: تأیید چند تایم‌فریمی ─────────────────────────── */
        $mtf = null;
        $requireMtf = (bool)($this->cfg['require_mtf'] ?? true);
        if ($requireMtf) {
            $mtf = (new MultiTimeframe($this->market, $this->cfg))->analyze($symbol, $eval['side'], $interval);
            if ($mtf['ok'] && !$mtf['aligned']) {
                return $base + [
                    'is_signal' => false, 'mtf' => $mtf,
                    'reason' => 'تایم‌فریم‌های بالاتر هم‌راستا نیستند (نسبت هم‌راستایی: ' . round($mtf['alignment_ratio'] * 100) . '٪)',
                ];
            }
        }

        /* ── دروازهٔ ۳: اجماع AI ────────────────────────────────────── */
        $ai = ['ok' => false, 'side' => null, 'ai_score' => 0.0, 'agreement' => false, 'agree_ratio' => 0.0, 'opinions' => [], 'notes' => [], 'invalidation' => null];
        $riskPlan = $this->risk->plan($eval['side'], $ctx['price'], $ctx['atr'], $eval['tech_score'], [
            'swing_low' => $ctx['swing_low'],
            'swing_high' => $ctx['swing_high'],
            'sizing_factor' => $regime['sizing_factor'],
        ]);

        if ($this->ai !== null) {
            $validator = new AiValidator($this->ai, (int)($this->cfg['ai_panel_size'] ?? 3));
            $ai = $validator->validate($base + [
                'risk_entry' => $riskPlan['entry'],
                'risk_stop' => $riskPlan['stop_loss'],
                'risk_tp2' => $riskPlan['take_profit_2'],
                'mtf' => $mtf,
            ], $eval['side']);
        }

        $techW = (float)($this->cfg['tech_weight'] ?? 0.6);
        $aiW = (float)($this->cfg['ai_weight'] ?? 0.4);
        $combined = $ai['ok']
            ? ($eval['tech_score'] * $techW + $ai['ai_score'] * $aiW)
            : $eval['tech_score'];
        $combined = round($combined, 1);

        $requireAgree = (bool)($this->cfg['require_ai_agreement'] ?? true);
        $gateOk = $combined >= (float)($this->cfg['min_combined_score'] ?? 70)
            && (!$requireAgree || !$ai['ok'] || $ai['agreement']);
        if (!$gateOk) {
            return $base + ['is_signal' => false, 'ai' => $ai, 'mtf' => $mtf, 'combined_score' => $combined,
                'reason' => 'امتیاز ترکیبی یا اجماع AI کافی نیست'];
        }

        /* ── دروازهٔ ۴: رژیم بیت‌کوین ───────────────────────────────── */
        if ($btc === null && (bool)($this->cfg['btc_filter'] ?? true)) {
            $btc = $this->btcRegime();
        }
        if ($btc !== null && $btc['strongly'] === 'bearish' && $eval['side'] === 'BUY' && $combined < 82) {
            return $base + ['is_signal' => false, 'ai' => $ai, 'mtf' => $mtf, 'combined_score' => $combined, 'btc' => $btc,
                'reason' => 'رژیم نزولی قوی بیت‌کوین — لانگ فقط با امتیاز A+ مجاز است'];
        }

        /* ── دروازهٔ ۵: حداقل نسبت ریسک به بازده ────────────────────── */
        $minRr = (float)($this->cfg['min_risk_reward'] ?? 2.0);
        if ($riskPlan['risk_reward_2'] < $minRr) {
            return $base + ['is_signal' => false, 'ai' => $ai, 'mtf' => $mtf, 'combined_score' => $combined, 'risk' => $riskPlan,
                'reason' => 'نسبت ریسک/بازده TP2 کمتر از حد مجاز است (' . $riskPlan['risk_reward_2'] . ' < ' . $minRr . ')'];
        }

        /* ── درجه‌بندی کیفیت + خنک‌کردن تکرار ───────────────────────── */
        $tier = $this->tier($combined, $eval, $mtf);
        $cooldown = $this->inCooldown($symbol, $eval['side']);

        $side = $ai['ok'] && $ai['agreement'] ? $ai['side'] : $eval['side'];
        if ($side !== $eval['side'] && !(!$requireAgree && $ai['ok'])) {
            $side = $eval['side']; // بدون الزام اجماع، جهت تکنیکال مرجع است
        }

        return $base + [
            'is_signal' => true,
            'side' => $side,
            'tier' => $tier,
            'ai' => $ai,
            'mtf' => $mtf,
            'combined_score' => $combined,
            'confidence' => $combined,
            'risk' => $riskPlan,
            'suppressed' => $cooldown,
            'suppressed_reason' => $cooldown ? 'سیگنال مشابه همین نماد در ' . (int)($this->cfg['cooldown_hours'] ?? 12) . ' ساعت اخیر صادر شده است.' : null,
            'created_at' => date('Y-m-d H:i:s'),
        ];
    }

    /** عرض بازار از روی تیکرها: نسبت صعودی/نزولی و شدت حرکت. */
    private function breadth(array $tickers): array
    {
        $up = 0;
        $down = 0;
        foreach ($tickers as $t) {
            $ch = (float)$t['change_pct'];
            if ($ch > 0.5) { $up++; }
            elseif ($ch < -0.5) { $down++; }
        }
        $total = max(1, $up + $down);
        return [
            'up' => $up,
            'down' => $down,
            'ratio' => round($up / $total, 2),
            'mood' => $up / $total >= 0.65 ? 'greedy' : ($up / $total <= 0.35 ? 'fearful' : 'neutral'),
        ];
    }

    /** رژیم بیت‌کوین روی 4h — لنگرگاه تصمیم‌های جهانی بازار. */
    public function btcRegime(): ?array
    {
        try {
            $cres = $this->market->candles('BTCUSDT', '4h', 220);
            if (empty($cres['ok']) || count($cres['candles']) < 60) {
                return null;
            }
            $ctx = Context::build($cres['candles'], [], $this->cfg);
            $regime = Regime::classify($ctx);
            $strongly = 'neutral';
            if ($regime['regime'] === Regime::TREND_DOWN && $regime['strength'] >= 55) {
                $strongly = 'bearish';
            } elseif ($regime['regime'] === Regime::TREND_UP && $regime['strength'] >= 55) {
                $strongly = 'bullish';
            }
            return [
                'symbol' => 'BTCUSDT',
                'price' => $ctx['price'],
                'regime' => $regime['regime'],
                'label' => $regime['label'],
                'strength' => $regime['strength'],
                'strongly' => $strongly,
                'change24' => $ctx['change24'],
            ];
        } catch (Throwable $e) {
            Logger::write('crypto', 'تحلیل رژیم BTC ناموفق: ' . $e->getMessage(), 'warning');
            return null;
        }
    }

    /** درجهٔ کیفیت سیگنال: A+ / A / B / C. */
    private function tier(float $combined, array $eval, ?array $mtf): string
    {
        $bonus = 0.0;
        if ($mtf !== null && $mtf['ok']) {
            $bonus += $mtf['alignment_ratio'] >= 0.99 ? 4.0 : 2.0;
        }
        if ($eval['confluence'] >= $eval['confluence_total'] - 1) {
            $bonus += 2.0;
        }
        $score = $combined + $bonus;
        if ($score >= 88) { return 'A+'; }
        if ($score >= 80) { return 'A'; }
        if ($score >= 72) { return 'B'; }
        return 'C';
    }

    /** آیا همین نماد+جهت در بازهٔ خنک‌کردن سیگنال داده است؟ */
    private function inCooldown(string $symbol, string $side): bool
    {
        if ($this->db === null) {
            return false;
        }
        try {
            if (!$this->db->tableExists('signals')) {
                return false;
            }
            $hours = max(0, (int)($this->cfg['cooldown_hours'] ?? 12));
            if ($hours === 0) {
                return false;
            }
            $t = $this->db->table('signals');
            $cutoff = date('Y-m-d H:i:s', time() - $hours * 3600);
            $row = $this->db->selectOne(
                "SELECT id FROM {$t} WHERE symbol = ? AND side = ? AND created_at >= ? LIMIT 1",
                [$symbol, $side, $cutoff]
            );
            return $row !== null;
        } catch (Throwable $e) {
            return false;
        }
    }

    /* ── ذخیره‌سازی ─────────────────────────────────────────────────── */

    private function recordScan(int $scanned, int $found, array $breadth = [], ?array $btc = null): ?int
    {
        if ($this->db === null) {
            return null;
        }
        try {
            if (!$this->db->tableExists('scans')) {
                return null;
            }
            return $this->db->insert('scans', [
                'started_at' => date('Y-m-d H:i:s'),
                'finished_at' => date('Y-m-d H:i:s'),
                'coins_scanned' => $scanned,
                'signals_found' => $found,
                'mode' => 'market',
                'summary_json' => json_encode([
                    'scanned' => $scanned,
                    'found' => $found,
                    'breadth' => $breadth,
                    'btc' => $btc ? [$btc['regime'], $btc['strongly']] : null,
                ], JSON_UNESCAPED_UNICODE),
            ]);
        } catch (Throwable $e) {
            Logger::write('crypto', 'ثبت اسکن ناموفق: ' . $e->getMessage(), 'warning');
            return null;
        }
    }

    private function persistSignal(array $s): void
    {
        if ($this->db === null) {
            return;
        }
        try {
            if (!$this->db->tableExists('signals')) {
                return;
            }
            $this->db->insert('signals', [
                'scan_id' => $s['scan_id'] ?? null,
                'symbol' => $s['symbol'],
                'side' => $s['side'],
                'timeframe' => $s['timeframe'] ?? '',
                'tier' => (string)($s['tier'] ?? 'C'),
                'regime' => (string)($s['regime'] ?? ''),
                'confidence' => $s['combined_score'],
                'tech_score' => $s['tech_score'],
                'ai_score' => (float)($s['ai']['ai_score'] ?? 0),
                'combined_score' => $s['combined_score'],
                'mtf_score' => isset($s['mtf']['mtf_score']) ? (float)$s['mtf']['mtf_score'] : 0,
                'entry_price' => $s['risk']['entry'],
                'stop_loss' => $s['risk']['stop_loss'],
                'take_profit_1' => $s['risk']['take_profit_1'],
                'take_profit_2' => $s['risk']['take_profit_2'],
                'take_profit_3' => $s['risk']['take_profit_3'],
                'risk_reward' => $s['risk']['risk_reward_2'],
                'position_pct' => $s['risk']['position_percent'],
                'invalidation' => $s['risk']['invalidation'] ?? '',
                'filters_passed' => $s['passed'],
                'filters_total' => $s['total'],
                'filters_json' => json_encode($s['filters'], JSON_UNESCAPED_UNICODE),
                'mtf_json' => isset($s['mtf']) ? json_encode($s['mtf'], JSON_UNESCAPED_UNICODE) : null,
                'ai_json' => json_encode($s['ai']['opinions'] ?? [], JSON_UNESCAPED_UNICODE),
                'status' => 'new',
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            Logger::write('crypto', 'ثبت سیگنال ناموفق: ' . $e->getMessage(), 'warning');
        }
    }
}
