<?php
namespace Meelano\Crypto;

use Meelano\Config;
use Meelano\Db;
use Throwable;

/**
 * معامله‌گر خودکار — کیف پول تست و اجرای زنده (نسخهٔ ۵٫۲).
 *
 * دو حالت:
 *   paper — کیف مجازی USDT؛ معاملات کاملاً شبیه‌سازی می‌شوند (کارمزد+اسلیپیج لحاظ می‌شود)
 *   live  — اجرای واقعی روی صرافی از طریق Connector (پیش‌فرض قفل؛ نیازمند کلید + تأیید صریح)
 *
 * مدل خروج (لایه‌نردبانی، آینهٔ SignalTracker):
 *   TP1 → خروج ۵۰٪ + انتقال استاپ به سربه‌سر · TP2 → ۲۵٪ · TP3 → باقی‌مانده
 *   (یا خروج کامل در TP2/TP3 طبق تنظیم؛ یا سیگنال مخالف / بستن دستی)
 *
 * همهٔ رویدادها تفکیک‌شده ثبت می‌شوند: هر پوزیشن یک رکورد، هر بستن یک
 * معاملهٔ کامل با PnL (USDT و ٪ و R)، و منحنی سرمایه از تاریخچه ساخته می‌شود.
 *
 * @author Milad Yaghoobi — Meelano Studio Design
 */
final class AutoTrader
{
    /** @var Db */
    private $db;
    /** @var MarketData */
    private $market;
    /** @var array */
    private $cfg;
    /** @var Connector|null فقط برای حالت live */
    private $live;
    /** @var Notifier|null سامانهٔ اطلاع‌رسانی (اختیاری — خطای آن هرگز معامله را نمی‌شکند) */
    private $notifier;

    private const TIERS = ['C' => 1, 'B' => 2, 'A' => 3, 'A+' => 4];

    public function __construct(Db $db, ?MarketData $market = null, ?Connector $live = null, ?array $cfg = null, ?Notifier $notifier = null)
    {
        $this->db = $db;
        $this->market = $market ?: new MarketData(null, (array)Config::get('market', []));
        $this->live = $live;
        $this->cfg = $cfg ?? array_merge((array)Config::get('trading', []), (array)Config::get('market', []));
        $this->notifier = $notifier;
    }

    /* ═══ حساب ═══════════════════════════════════════════════════════════ */

    /** حساب پیش‌فرض را می‌سازد (در صورت نبود) و برمی‌گرداند. */
    public function ensureAccount(): array
    {
        $row = $this->db->selectOne('SELECT * FROM ' . $this->db->table('trade_accounts') . ' WHERE id = 1');
        if ($row) {
            return $row;
        }
        $initial = max(100.0, (float)($this->cfg['paper_initial_usdt'] ?? 10000.0));
        $now = date('Y-m-d H:i:s');
        $this->db->insert('trade_accounts', [
            'label' => 'حساب کاغذی اصلی', 'mode' => 'paper', 'exchange' => 'internal',
            'initial_usdt' => $initial, 'balance_usdt' => $initial,
            'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ]);
        return $this->db->selectOne('SELECT * FROM ' . $this->db->table('trade_accounts') . ' WHERE id = 1') ?? [];
    }

    /** بازنشانی کیف تست (پوزیشن‌ها و معاملات قبلی پاک می‌شوند). */
    public function reset(float $initialUsdt): array
    {
        $initial = max(100.0, min(10000000.0, $initialUsdt));
        $now = date('Y-m-d H:i:s');
        $row = $this->db->selectOne('SELECT * FROM ' . $this->db->table('trade_accounts') . ' WHERE id = 1');
        if ($row) {
            $this->db->update('trade_accounts', [
                'initial_usdt' => $initial, 'balance_usdt' => $initial, 'updated_at' => $now,
            ], 'id = 1');
        } else {
            $this->ensureAccount();
            $this->db->update('trade_accounts', [
                'initial_usdt' => $initial, 'balance_usdt' => $initial, 'updated_at' => $now,
            ], 'id = 1');
        }
        $this->db->execute('DELETE FROM ' . $this->db->table('trade_positions'));
        $this->db->execute('DELETE FROM ' . $this->db->table('trade_trades'));
        return ['ok' => true, 'initial_usdt' => $initial, 'balance_usdt' => $initial];
    }

    public function account(): array
    {
        return $this->ensureAccount();
    }

    /* ═══ پیکربندی ═══════════════════════════════════════════════════════ */

    /** پیکربندی فعلی معاملهٔ خودکار (برای UI). */
    public function config(): array
    {
        return [
            'auto_trade_enabled' => (bool)($this->cfg['auto_trade_enabled'] ?? false),
            'auto_mode' => (string)($this->cfg['auto_mode'] ?? 'buy_sell'),
            'auto_amount_mode' => (string)($this->cfg['auto_amount_mode'] ?? 'percent'),
            'auto_amount_percent' => (float)($this->cfg['auto_amount_percent'] ?? 10.0),
            'auto_amount_fixed' => (float)($this->cfg['auto_amount_fixed'] ?? 100.0),
            'auto_max_open_positions' => (int)($this->cfg['auto_max_open_positions'] ?? 5),
            'auto_min_tier' => (string)($this->cfg['auto_min_tier'] ?? 'A'),
            'auto_min_combined' => (float)($this->cfg['auto_min_combined'] ?? 75.0),
            'auto_tp_mode' => (string)($this->cfg['auto_tp_mode'] ?? 'ladder'),
            'auto_honor_stop' => (bool)($this->cfg['auto_honor_stop'] ?? true),
            'auto_close_on_opposite' => (bool)($this->cfg['auto_close_on_opposite'] ?? true),
            'auto_dry_run' => (bool)($this->cfg['auto_dry_run'] ?? true),
            'paper_initial_usdt' => (float)($this->cfg['paper_initial_usdt'] ?? 10000.0),
        ];
    }

    /** ذخیرهٔ پیکربندی (اعتبارسنجی کامل). */
    public function saveConfig(array $in): array
    {
        $modes = ['buy_sell', 'buy_only', 'sell_only'];
        if (isset($in['auto_mode']) && in_array($in['auto_mode'], $modes, true)) {
            Config::set('trading.auto_mode', $in['auto_mode']);
        }
        if (isset($in['auto_amount_mode']) && in_array($in['auto_amount_mode'], ['percent', 'fixed'], true)) {
            Config::set('trading.auto_amount_mode', $in['auto_amount_mode']);
        }
        $nums = [
            'auto_amount_percent' => [1.0, 100.0],
            'auto_amount_fixed' => [10.0, 1000000.0],
            'auto_min_combined' => [0.0, 100.0],
        ];
        foreach ($nums as $key => [$lo, $hi]) {
            if (isset($in[$key])) {
                Config::set('trading.' . $key, max($lo, min($hi, (float)$in[$key])));
            }
        }
        if (isset($in['auto_max_open_positions'])) {
            Config::set('trading.auto_max_open_positions', max(1, min(20, (int)$in['auto_max_open_positions'])));
        }
        if (isset($in['auto_min_tier']) && isset(self::TIERS[$in['auto_min_tier']])) {
            Config::set('trading.auto_min_tier', (string)$in['auto_min_tier']);
        }
        if (isset($in['auto_tp_mode']) && in_array($in['auto_tp_mode'], ['ladder', 'tp2', 'tp3'], true)) {
            Config::set('trading.auto_tp_mode', (string)$in['auto_tp_mode']);
        }
        foreach (['auto_honor_stop', 'auto_close_on_opposite', 'auto_dry_run'] as $flag) {
            if (isset($in[$flag])) {
                Config::set('trading.' . $flag, (bool)$in[$flag]);
            }
        }
        // کلید اصلی — جداگانه تا ناخواسته فعال نشود
        if (array_key_exists('auto_trade_enabled', $in)) {
            Config::set('trading.auto_trade_enabled', (bool)$in['auto_trade_enabled']);
        }
        Config::save();
        $this->cfg = array_merge($this->cfg, array_intersect_key($in, $this->config()));
        return ['ok' => true, 'config' => $this->config()];
    }

    /* ═══ اجرای سیگنال (قلاب پس از اسکن) ═════════════════════════════════ */

    /**
     * پس از هر اسکن: ابتدا پایش خروج پوزیشن‌های باز، سپس ورود‌های جدید.
     * @return array{ok:bool,opened:array,closed:array,skipped:array,errors:array,dry_run:bool}
     */
    public function afterScan(array $signals): array
    {
        $out = ['ok' => true, 'opened' => [], 'closed' => [], 'skipped' => [], 'errors' => [], 'dry_run' => false];
        if (!$this->db->tableExists('trade_accounts')) {
            return $out;
        }
        $this->ensureAccount();
        $mode = (string)($this->cfg['auto_mode'] ?? 'buy_sell');

        // ۱) پایش خروج (همیشه — حتی sell_only یا buy_only؛ استاپ/TP باید اجرا شود)
        $upd = $this->updatePrices();
        foreach ($upd['closed'] as $c) {
            $out['closed'][] = $c;
        }

        // ۲) سیگنال‌های SELL → بستن پوزیشن همان نماد
        if (($this->cfg['auto_close_on_opposite'] ?? true) && $mode !== 'buy_only') {
            foreach ($signals as $sig) {
                if (($sig['side'] ?? '') !== 'SELL') {
                    continue;
                }
                $sym = (string)$sig['symbol'];
                foreach ($this->openPositions($sym) as $pos) {
                    if ($pos['side'] === 'BUY') {
                        $r = $this->closePosition((int)$pos['id'], 'signal', (float)($sig['price'] ?? 0));
                        if (!empty($r['ok'])) {
                            $out['closed'][] = $r['trade'];
                        }
                    }
                }
            }
        }

        // ۳) سیگنال‌های BUY → ورود جدید
        if ($mode !== 'sell_only') {
            foreach ($signals as $sig) {
                if (($sig['side'] ?? '') !== 'BUY') {
                    continue;
                }
                $r = $this->openPosition($sig, 'auto');
                if (!empty($r['ok'])) {
                    $out['opened'][] = $r['position'];
                    if (!empty($r['dry_run'])) {
                        $out['dry_run'] = true; // حداقل یک ورود فقط شبیه‌سازی شد
                    }
                } elseif (!empty($r['skipped'])) {
                    $out['skipped'][] = $r['reason'];
                } elseif (!empty($r['error'])) {
                    $out['errors'][] = $r['error'];
                }
            }
        }
        return $out;
    }

    /* ═══ ورود پوزیشن ═════════════════════════════════════════════════════ */

    /**
     * بازکردن پوزیشن بر اساس سیگنال (یا دستی).
     * @return array{ok:bool,position?:array,skipped?:bool,reason?:string,dry_run?:bool,error?:string}
     */
    public function openPosition(array $signal, string $source = 'auto'): array
    {
        $acct = $this->ensureAccount();
        $symbol = strtoupper((string)($signal['symbol'] ?? ''));
        $price = (float)($signal['risk']['entry'] ?? $signal['price'] ?? 0);
        if ($symbol === '' || $price <= 0) {
            return ['ok' => false, 'error' => 'نماد یا قیمت نامعتبر است.'];
        }

        if ($source === 'auto') {
            // فیلترهای کیفیت سیگنال
            $tier = (string)($signal['tier'] ?? 'C');
            $minTier = (string)($this->cfg['auto_min_tier'] ?? 'A');
            if ((self::TIERS[$tier] ?? 0) < (self::TIERS[$minTier] ?? 3)) {
                return ['ok' => false, 'skipped' => true, 'reason' => $symbol . ': درجهٔ ' . $tier . ' زیر حداقل (' . $minTier . ')'];
            }
            $combined = (float)($signal['combined_score'] ?? 0);
            if ($combined < (float)($this->cfg['auto_min_combined'] ?? 75)) {
                return ['ok' => false, 'skipped' => true, 'reason' => $symbol . ': امتیاز ترکیبی ' . $combined . ' زیر حداقل'];
            }
        }

        // بدون پوزیشن تکراری روی همان نماد
        if ($this->openPositions($symbol)) {
            return ['ok' => false, 'skipped' => true, 'reason' => $symbol . ': پوزیشن باز دارد'];
        }

        // سقف پوزیشن هم‌زمان
        $maxOpen = (int)($this->cfg['auto_max_open_positions'] ?? 5);
        $openAll = $this->openPositions();
        if (count($openAll) >= $maxOpen) {
            return ['ok' => false, 'skipped' => true, 'reason' => 'سقف ' . $maxOpen . ' پوزیشن هم‌زمان پر است'];
        }

        // سایز پوزیشن
        $balance = (float)$acct['balance_usdt'];
        $amountMode = (string)($this->cfg['auto_amount_mode'] ?? 'percent');
        $amount = $amountMode === 'fixed'
            ? (float)($this->cfg['auto_amount_fixed'] ?? 100.0)
            : $balance * max(0.5, min(100.0, (float)($this->cfg['auto_amount_percent'] ?? 10.0))) / 100.0;
        if (isset($signal['usdt']) && $source === 'manual') {
            $amount = max(10.0, (float)$signal['usdt']); // ورود دستی: مبلغ صریح
        }
        $amount = min($amount, $balance);
        if ($amount < 10.0) {
            return ['ok' => false, 'skipped' => true, 'reason' => 'موجودی کافی نیست (حداقل ۱۰ USDT)'];
        }

        // حالت آزمایشی: فقط برنامهٔ اجرا برگردد
        $dryRun = $source === 'auto' && (bool)($this->cfg['auto_dry_run'] ?? true);
        $risk = (array)($signal['risk'] ?? []);
        $plan = [
            'symbol' => $symbol,
            'side' => 'BUY',
            'entry' => round($price, 8),
            'quantity' => round($amount / $price, 8),
            'entry_usdt' => round($amount, 2),
            'stop_loss' => (float)($risk['stop_loss'] ?? 0),
            'take_profit_1' => (float)($risk['take_profit_1'] ?? 0),
            'take_profit_2' => (float)($risk['take_profit_2'] ?? 0),
            'take_profit_3' => (float)($risk['take_profit_3'] ?? 0),
            'tier' => (string)($signal['tier'] ?? 'C'),
            'regime' => (string)($signal['regime'] ?? ''),
            'combined_score' => (float)($signal['combined_score'] ?? 0),
        ];
        if ($dryRun) {
            return ['ok' => true, 'dry_run' => true, 'position' => $plan,
                'skipped' => false, 'reason' => $symbol . ' در حالت شبیه‌سازی فقط ثبت شد (Dry-Run فعال است)'];
        }

        // اجرای واقعی (live) — فقط با کلید و تأیید صریح
        if (($acct['mode'] ?? 'paper') === 'live') {
            $liveRes = $this->executeLive($symbol, 'BUY', $plan['quantity'], $amount);
            if (!empty($liveRes['ok'])) {
                $plan['entry'] = $liveRes['avg_price'] > 0 ? $liveRes['avg_price'] : $plan['entry'];
                $plan['quantity'] = $liveRes['executed_qty'] > 0 ? $liveRes['executed_qty'] : $plan['quantity'];
                $plan['entry_usdt'] = round($plan['quantity'] * $plan['entry'], 2);
                $amount = $plan['entry_usdt'];
            } else {
                return ['ok' => false, 'error' => $symbol . ': سفارش زنده ناموفق — ' . ($liveRes['error'] ?? '?')];
            }
        }

        $feeBps = $this->feeBps();
        $entryFee = $amount * $feeBps / 10000.0;
        $now = date('Y-m-d H:i:s');
        $posId = $this->db->insert('trade_positions', [
            'account_id' => 1,
            'symbol' => $symbol,
            'side' => 'BUY',
            'entry_price' => $plan['entry'],
            'quantity' => $plan['quantity'],
            'entry_usdt' => $amount,
            'stop_loss' => $plan['stop_loss'],
            'take_profit_1' => $plan['take_profit_1'],
            'take_profit_2' => $plan['take_profit_2'],
            'take_profit_3' => $plan['take_profit_3'],
            'remaining_pct' => 100.0,
            'realized_usdt' => 0.0,
            'fees_usdt' => $entryFee,
            'signal_id' => isset($signal['id']) ? (int)$signal['id'] : null,
            'tier' => $plan['tier'],
            'regime' => $plan['regime'],
            'combined_score' => $plan['combined_score'],
            'mark_price' => $plan['entry'],
            'hit_tp1' => 0,
            'stop_moved' => 0,
            'last_check_ts' => time(),
            'source' => $source,
            'opened_at' => $now,
        ]);
        $this->db->update('trade_accounts', [
            'balance_usdt' => round($balance - $amount - $entryFee, 2),
            'updated_at' => $now,
        ], 'id = 1');

        $posRow = $this->positionRow($posId);
        $this->notifyOpened($posRow ?? []);

        return ['ok' => true, 'position' => $posRow];
    }

    /* ═══ پایش خروج (استاپ/TP لایه‌نردبانی روی کندل بسته) ═════════════════ */

    /**
     * برای هر پوزیشن باز: کندل‌های بستهٔ بعد از آخرین بررسی را می‌خواند و
     * استاپ/TP ها را با اولویت محافظه‌کارانهٔ استاپ اجرا می‌کند.
     * @return array{ok:bool,checked:int,closed:array,events:array,errors:array}
     */
    public function updatePrices(): array
    {
        $out = ['ok' => true, 'checked' => 0, 'closed' => [], 'events' => [], 'errors' => []];
        if (!$this->db->tableExists('trade_positions')) {
            return $out;
        }
        $tf = (string)($this->cfg['timeframe'] ?? '1h');
        $tpMode = (string)($this->cfg['auto_tp_mode'] ?? 'ladder');
        $honorStop = (bool)($this->cfg['auto_honor_stop'] ?? true);

        foreach ($this->openPositions() as $pos) {
            $out['checked']++;
            $sym = (string)$pos['symbol'];
            try {
                $cres = $this->market->candles($sym, $tf, 60);
                if (empty($cres['ok'])) {
                    continue;
                }
                $lastMark = (float)$pos['mark_price'];
                $lastCheck = (int)$pos['last_check_ts'];
                $stop = (float)$pos['stop_loss'];
                $tp1 = (float)$pos['take_profit_1'];
                $tp2 = (float)$pos['take_profit_2'];
                $tp3 = (float)$pos['take_profit_3'];
                $stopMoved = (int)$pos['stop_moved'] === 1;
                $hitTp1 = (int)$pos['hit_tp1'] === 1;
                $remaining = (float)$pos['remaining_pct'];
                $changed = false;

                foreach ($cres['candles'] as $c) {
                    if ((int)$c['time'] <= $lastCheck) {
                        continue; // قبلاً بررسی شده
                    }
                    $lastCheck = (int)$c['time'];
                    $lastMark = (float)$c['close'];
                    $lo = (float)$c['low'];
                    $hi = (float)$c['high'];
                    $effStop = $stopMoved ? (float)$pos['entry_price'] : $stop; // سربه‌سر بعد از TP1

                    // استاپ — اولویت محافظه‌کارانه
                    if ($honorStop && $stop > 0 && $lo <= $effStop) {
                        $reason = $stopMoved ? 'be_stop' : 'stop';
                        $r = $this->exitSegment((int)$pos['id'], $effStop, $remaining, $reason);
                        if (!empty($r['closed_trade'])) {
                            $out['closed'][] = $r['closed_trade'];
                        }
                        $remaining = 0.0;
                        $changed = true;
                        break;
                    }
                    // TP1 → ۵۰٪ + سربه‌سر (در حالت لایه‌نردبانی)
                    if (!$hitTp1 && $tp1 > 0 && $hi >= $tp1) {
                        $pct = $tpMode === 'ladder' ? 50.0 : 0.0;
                        if ($pct > 0) {
                            $r = $this->exitSegment((int)$pos['id'], $tp1, $pct, 'tp1');
                            if (!empty($r['closed_trade'])) {
                                $out['closed'][] = $r['closed_trade'];
                                $remaining = 0.0;
                                break;
                            }
                            $remaining -= $pct;
                            $out['events'][] = $sym . ': TP1 برداشت شد (۵۰٪) — استاپ به سربه‌سر منتقل شد';
                        }
                        $hitTp1 = true;
                        $stopMoved = true;
                        $changed = true;
                        if ($tpMode !== 'ladder') {
                            continue; // حالت tp2/tp3: TP1 نادیده گرفته می‌شود
                        }
                    }
                    // TP2 → ۲۵٪ (لایه) یا ۱۰۰٪ (حالت tp2)
                    if ($tp2 > 0 && $hi >= $tp2) {
                        $pct = $tpMode === 'ladder' ? 25.0 : 100.0;
                        $r = $this->exitSegment((int)$pos['id'], $tp2, min($pct, $remaining), 'tp2');
                        if (!empty($r['closed_trade'])) {
                            $out['closed'][] = $r['closed_trade'];
                            $remaining = 0.0;
                            $changed = true;
                            break;
                        }
                        $remaining -= $pct;
                        $changed = true;
                        if ($tpMode !== 'ladder') {
                            break;
                        }
                    }
                    // TP3 → باقی‌مانده
                    if ($tp3 > 0 && $remaining > 0 && $hi >= $tp3) {
                        $r = $this->exitSegment((int)$pos['id'], $tp3, $remaining, 'tp3');
                        if (!empty($r['closed_trade'])) {
                            $out['closed'][] = $r['closed_trade'];
                        }
                        $remaining = 0.0;
                        $changed = true;
                        break;
                    }
                }

                if ($remaining > 0) {
                    $this->db->update('trade_positions', [
                        'mark_price' => $lastMark,
                        'hit_tp1' => $hitTp1 ? 1 : 0,
                        'stop_moved' => $stopMoved ? 1 : 0,
                        'remaining_pct' => round($remaining, 2),
                        'last_check_ts' => $lastCheck,
                    ], 'id = :id', ['id' => (int)$pos['id']]);
                }
            } catch (Throwable $e) {
                $out['errors'][] = $sym . ': ' . $e->getMessage();
            }
        }
        return $out;
    }

    /** بستن کامل پوزیشن (دستی/سیگنال مخالف) در قیمت مشخص یا قیمت جاری. */
    public function closePosition(int $positionId, string $reason = 'manual', float $price = 0.0): array
    {
        $pos = $this->positionRow($positionId);
        if (!$pos) {
            return ['ok' => false, 'error' => 'پوزیشن پیدا نشد.'];
        }
        if ($price <= 0) {
            $t = $this->market->tickers(300);
            foreach ($t as $row) {
                if ($row['symbol'] === $pos['symbol']) {
                    $price = (float)$row['last'];
                    break;
                }
            }
        }
        if ($price <= 0 && $this->live !== null) {
            $tk = $this->live->ticker((string)$pos['symbol']);
            $price = $tk['price'] ?? 0;
        }
        if ($price <= 0) {
            return ['ok' => false, 'error' => 'قیمت جاری در دسترس نیست.'];
        }
        $r = $this->exitSegment($positionId, $price, (float)$pos['remaining_pct'], $reason);
        return ['ok' => !empty($r['ok']), 'trade' => $r['closed_trade'] ?? null];
    }

    /* ═══ گزارش ══════════════════════════════════════════════════════════ */

    /**
     * وضعیت کامل پنل: حساب + پوزیشن‌های زنده + آمار + منحنی سرمایه.
     * @param bool $refresh قیمت‌ها به‌روز شوند (خروج‌ها هم بررسی می‌شوند)
     */
    public function state(bool $refresh = true): array
    {
        $acct = $this->ensureAccount();
        if ($refresh) {
            try {
                $this->updatePrices();
            } catch (Throwable $e) { /* پایش در خطا رد می‌شود */ }
            $acct = $this->ensureAccount();
        }
        $positions = [];
        $unrealized = 0.0;
        foreach ($this->openPositions() as $pos) {
            $p = $this->decoratePosition($pos);
            $unrealized += $p['unrealized_usdt'];
            $positions[] = $p;
        }
        $equity = (float)$acct['balance_usdt'] + $unrealized;
        return [
            'ok' => true,
            'account' => [
                'label' => (string)$acct['label'],
                'mode' => (string)$acct['mode'],
                'initial_usdt' => (float)$acct['initial_usdt'],
                'balance_usdt' => round((float)$acct['balance_usdt'], 2),
                'equity_usdt' => round($equity, 2),
                'unrealized_usdt' => round($unrealized, 2),
                'total_pnl_usdt' => round($equity - (float)$acct['initial_usdt'], 2),
                'total_pnl_pct' => (float)$acct['initial_usdt'] > 0
                    ? round(($equity / (float)$acct['initial_usdt'] - 1) * 100, 2) : 0.0,
                'open_count' => count($positions),
            ],
            'auto' => $this->config(),
            'positions' => $positions,
            'trades' => $this->recentTrades(50),
            'stats' => $this->stats(),
            'equity_curve' => $this->equityCurve($equity),
            'by_symbol' => $this->bySymbol(),
        ];
    }

    /** آمار کارنامه. */
    public function stats(): array
    {
        $t = $this->db->table('trade_trades');
        $row = $this->db->selectOne(
            "SELECT COUNT(*) AS n,
                SUM(CASE WHEN pnl_usdt > 0 THEN 1 ELSE 0 END) AS wins,
                SUM(pnl_usdt) AS pnl,
                SUM(CASE WHEN pnl_usdt > 0 THEN pnl_usdt ELSE 0 END) AS gross_win,
                SUM(CASE WHEN pnl_usdt < 0 THEN -pnl_usdt ELSE 0 END) AS gross_loss,
                AVG(pnl_pct) AS avg_pct, AVG(r_multiple) AS avg_r, AVG(duration_min) AS avg_dur,
                MAX(pnl_usdt) AS best, MIN(pnl_usdt) AS worst
             FROM {$t}"
        ) ?? [];
        $n = (int)($row['n'] ?? 0);
        return [
            'trades' => $n,
            'winrate' => $n > 0 ? round((int)($row['wins'] ?? 0) / $n * 100, 1) : 0.0,
            'pnl_usdt' => round((float)($row['pnl'] ?? 0), 2),
            'profit_factor' => (float)($row['gross_loss'] ?? 0) > 0
                ? round((float)($row['gross_win'] ?? 0) / (float)$row['gross_loss'], 2)
                : ((float)($row['gross_win'] ?? 0) > 0 ? 99.9 : 0.0),
            'avg_pnl_pct' => $n > 0 ? round((float)($row['avg_pct'] ?? 0), 2) : 0.0,
            'avg_r' => $n > 0 ? round((float)($row['avg_r'] ?? 0), 2) : 0.0,
            'avg_duration_min' => $n > 0 ? round((float)($row['avg_dur'] ?? 0), 0) : 0,
            'best_usdt' => round((float)($row['best'] ?? 0), 2),
            'worst_usdt' => round((float)($row['worst'] ?? 0), 2),
        ];
    }

    /** منحنی سرمایه: نقطهٔ آغاز + هر بستن معامله + اکنون. */
    public function equityCurve(float $currentEquity = 0.0): array
    {
        $acct = $this->ensureAccount();
        $t = $this->db->table('trade_trades');
        $rows = $this->db->select("SELECT closed_at, pnl_usdt FROM {$t} ORDER BY closed_at ASC, id ASC LIMIT 500");
        $points = [['t' => (string)$acct['created_at'], 'equity' => (float)$acct['initial_usdt']]];
        $cum = (float)$acct['initial_usdt'];
        foreach ($rows as $r) {
            $cum += (float)$r['pnl_usdt'];
            $points[] = ['t' => (string)$r['closed_at'], 'equity' => round($cum, 2)];
        }
        if ($currentEquity > 0) {
            $points[] = ['t' => date('Y-m-d H:i:s'), 'equity' => round($currentEquity, 2)];
        }
        return $points;
    }

    /** سود/ضرر به تفکیک نماد (برای نمودار میله‌ای). */
    public function bySymbol(): array
    {
        $t = $this->db->table('trade_trades');
        $rows = $this->db->select(
            "SELECT symbol, COUNT(*) AS n, SUM(pnl_usdt) AS pnl FROM {$t} GROUP BY symbol ORDER BY pnl DESC LIMIT 12"
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['symbol' => (string)$r['symbol'], 'n' => (int)$r['n'], 'pnl_usdt' => round((float)$r['pnl'], 2)];
        }
        return $out;
    }

    public function recentTrades(int $limit = 50): array
    {
        $t = $this->db->table('trade_trades');
        $rows = $this->db->select("SELECT * FROM {$t} ORDER BY closed_at DESC, id DESC LIMIT " . max(1, min(200, $limit)));
        foreach ($rows as &$r) {
            $r['pnl_usdt'] = round((float)$r['pnl_usdt'], 2);
            $r['pnl_pct'] = round((float)$r['pnl_pct'], 2);
            $r['r_multiple'] = round((float)$r['r_multiple'], 2);
            $r['fees_usdt'] = round((float)$r['fees_usdt'], 4);
            $r['entry_price'] = (float)$r['entry_price'];
            $r['exit_price'] = (float)$r['exit_price'];
            $r['quantity'] = (float)$r['quantity'];
            $r['entry_usdt'] = round((float)$r['entry_usdt'], 2);
            $r['exit_usdt'] = round((float)$r['exit_usdt'], 2);
        }
        unset($r);
        return $rows;
    }

    /* ═══ داخلی ══════════════════════════════════════════════════════════ */

    private function openPositions(?string $symbol = null): array
    {
        $sql = 'SELECT * FROM ' . $this->db->table('trade_positions') . ' WHERE account_id = 1';
        $params = [];
        if ($symbol !== null) {
            $sql .= ' AND symbol = :s';
            $params['s'] = $symbol;
        }
        $sql .= ' ORDER BY id ASC';
        return $this->db->select($sql, $params);
    }

    private function positionRow(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM ' . $this->db->table('trade_positions') . ' WHERE id = :i', ['i' => $id]);
    }

    /** پوزیشن + شاخص‌های زنده (PnL باز، فاصله تا استاپ/TP). */
    private function decoratePosition(array $pos): array
    {
        $entry = (float)$pos['entry_price'];
        $mark = (float)$pos['mark_price'] ?: $entry;
        $qty = (float)$pos['quantity'];
        $remaining = (float)$pos['remaining_pct'] / 100.0;
        $unreal = ($mark - $entry) * $qty * $remaining; // فقط BUY (اسپات)
        $stop = (float)$pos['stop_loss'];
        return [
            'id' => (int)$pos['id'],
            'symbol' => (string)$pos['symbol'],
            'side' => (string)$pos['side'],
            'entry_price' => $entry,
            'mark_price' => $mark,
            'quantity' => $qty,
            'entry_usdt' => round((float)$pos['entry_usdt'], 2),
            'remaining_pct' => (float)$pos['remaining_pct'],
            'realized_usdt' => round((float)$pos['realized_usdt'], 2),
            'unrealized_usdt' => round($unreal, 2),
            'total_pnl_usdt' => round((float)$pos['realized_usdt'] + $unreal, 2),
            'pnl_pct' => (float)$pos['entry_usdt'] > 0
                ? round(((float)$pos['realized_usdt'] + $unreal) / (float)$pos['entry_usdt'] * 100, 2) : 0.0,
            'stop_loss' => $stop,
            'stop_type' => (int)$pos['stop_moved'] === 1 ? 'breakeven' : ($stop > 0 ? 'signal' : 'none'),
            'take_profit_1' => (float)$pos['take_profit_1'],
            'take_profit_2' => (float)$pos['take_profit_2'],
            'take_profit_3' => (float)$pos['take_profit_3'],
            'hit_tp1' => (int)$pos['hit_tp1'] === 1,
            'tier' => (string)$pos['tier'],
            'regime' => (string)$pos['regime'],
            'combined_score' => (float)$pos['combined_score'],
            'source' => (string)$pos['source'],
            'opened_at' => (string)$pos['opened_at'],
            'progress_pct' => $stop > 0 && $mark > 0
                ? round(max(0.0, min(100.0, ($mark - $stop) / max(1e-9, ((float)$pos['take_profit_2'] ?: $mark * 1.05) - $stop)) * 100), 1)
                : 50.0,
        ];
    }

    /**
     * خروج یک بخش از پوزیشن در قیمت مشخص؛ با صفرشدن remaining،
     * پوزیشن بسته و رکورد کامل معامله نوشته می‌شود.
     */
    private function exitSegment(int $posId, float $price, float $pct, string $reason): array
    {
        $pos = $this->positionRow($posId);
        if (!$pos || (float)$pos['remaining_pct'] <= 0) {
            return ['ok' => false];
        }
        $pct = max(0.0, min((float)$pos['remaining_pct'], $pct));
        if ($pct <= 0) {
            return ['ok' => false];
        }
        $acct = $this->ensureAccount();
        $qtySeg = (float)$pos['quantity'] * $pct / 100.0;
        $proceeds = $qtySeg * $price;
        $exitFee = $proceeds * $this->feeBps() / 10000.0;
        $entryFeeSeg = (float)$pos['fees_usdt'] * $pct / 100.0;
        $costSeg = $qtySeg * (float)$pos['entry_price'];
        $pnlSeg = $proceeds - $exitFee - $costSeg - $entryFeeSeg;

        $remaining = round((float)$pos['remaining_pct'] - $pct, 2);
        $realized = (float)$pos['realized_usdt'] + $pnlSeg;
        $fees = (float)$pos['fees_usdt'] + $exitFee;
        $now = date('Y-m-d H:i:s');

        if ($remaining > 0.01) {
            $this->db->update('trade_positions', [
                'remaining_pct' => $remaining,
                'realized_usdt' => round($realized, 2),
                'fees_usdt' => round($fees, 4),
                'mark_price' => $price,
                'last_check_ts' => time(),
            ], 'id = :i', ['i' => $posId]);
            return ['ok' => true, 'closed_trade' => null, 'pnl_seg' => round($pnlSeg, 2)];
        }

        // بستن کامل → رکورد معامله + حذف پوزیشن
        $qtyTotal = (float)$pos['quantity'];
        $entryUsdt = (float)$pos['entry_usdt'];
        $exitUsdtTotal = $entryUsdt + $realized; // ارزش خروجی خالص
        $stopDist = (float)$pos['stop_loss'] > 0 ? abs((float)$pos['entry_price'] - (float)$pos['stop_loss']) : 0.0;
        $rMultiple = $stopDist > 0 ? $realized / ($stopDist * $qtyTotal) : 0.0;
        $duration = max(0, (strtotime($now) - strtotime((string)$pos['opened_at'])) / 60);

        // حالت live: سفارش فروش واقعی
        if (($acct['mode'] ?? 'paper') === 'live' && $this->live !== null) {
            $liveRes = $this->executeLive((string)$pos['symbol'], 'SELL', $qtySeg, 0.0);
            if (empty($liveRes['ok'])) {
                return ['ok' => false, 'error' => $liveRes['error'] ?? 'سفارش زنده ناموفق'];
            }
        }

        $tradeId = $this->db->insert('trade_trades', [
            'account_id' => 1,
            'position_id' => $posId,
            'symbol' => (string)$pos['symbol'],
            'side' => (string)$pos['side'],
            'entry_price' => (float)$pos['entry_price'],
            'exit_price' => round($price, 8),
            'quantity' => $qtyTotal,
            'entry_usdt' => round($entryUsdt, 2),
            'exit_usdt' => round($exitUsdtTotal, 2),
            'pnl_usdt' => round($realized, 2),
            'pnl_pct' => $entryUsdt > 0 ? round($realized / $entryUsdt * 100, 3) : 0.0,
            'r_multiple' => round($rMultiple, 3),
            'fees_usdt' => round($fees, 4),
            'exit_reason' => $reason,
            'tier' => (string)$pos['tier'],
            'regime' => (string)$pos['regime'],
            'source' => (string)$pos['source'],
            'opened_at' => (string)$pos['opened_at'],
            'closed_at' => $now,
            'duration_min' => (int)$duration,
        ]);
        $this->db->execute('DELETE FROM ' . $this->db->table('trade_positions') . ' WHERE id = :i', ['i' => $posId]);
        $this->db->update('trade_accounts', [
            'balance_usdt' => round((float)$acct['balance_usdt'] + $proceeds - $exitFee, 2),
            'updated_at' => $now,
        ], 'id = 1');

        $tradeRow = $this->db->selectOne(
            'SELECT * FROM ' . $this->db->table('trade_trades') . ' WHERE id = :i', ['i' => $tradeId]
        );
        $this->notifyClosed($tradeRow ?? []);

        return ['ok' => true, 'closed_trade' => $tradeRow, 'pnl_seg' => round($pnlSeg, 2)];
    }

    /* ═══ اطلاع‌رسانی رویدادها (نسخهٔ ۵٫۳) ══════════════════════════════ */

    /** رویداد «پوزیشن باز شد» — شکست اطلاع‌رسانی هرگز معامله را نمی‌شکند. */
    private function notifyOpened(array $position): void
    {
        if ($this->notifier === null) {
            return;
        }
        try {
            $this->notifier->notifyOpen($position);
        } catch (Throwable $e) { /* بی‌اثر روی معامله */ }
    }

    /** رویداد «پوزیشن بسته شد» + گزارش کیف اختیاری پس از بستن. */
    private function notifyClosed(array $trade): void
    {
        if ($this->notifier === null) {
            return;
        }
        try {
            $this->notifier->notifyClose($trade);
            if ($this->notifier->reportOnClose()) {
                $this->notifier->notifyWallet($this->state(false));
            }
        } catch (Throwable $e) { /* بی‌اثر روی معامله */ }
    }

    /** اجرای سفارش زنده (فقط با کلید و مجوز). */
    private function executeLive(string $symbol, string $side, float $qty, float $quoteUsdt): array
    {
        if ($this->live === null || !(bool)Config::get('exchange.live_enabled', false)) {
            return ['ok' => false, 'error' => 'معاملهٔ زنده فعال نیست (کلیدها/مجوز را در پنل صرافی بررسی کنید).'];
        }
        try {
            return $this->live->marketOrder($symbol, $side, $qty, $quoteUsdt);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function feeBps(): float
    {
        return max(0.0, (float)($this->cfg['backtest_fee_bps'] ?? 8.0))
            + max(0.0, (float)($this->cfg['backtest_slippage_bps'] ?? 3.0));
    }
}
