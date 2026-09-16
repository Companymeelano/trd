<?php
namespace Meelano\Crypto;

use Meelano\Config;
use Meelano\Db;
use Throwable;

/**
 * موتور یادگیری تطبیقی — نسخهٔ ۵٫۶ (ADAPTIVE LEARNING).
 *
 * فلسفه: وزن فیلترها دیگر عددی ایستا نیست؛ هر فیلتر «شاهدی» است که با کارنامهٔ
 * واقعی‌اش سنجیده می‌شود. ردیاب سیگنال (SignalTracker) هر سیگنال را با کندل‌های
 * واقعی بعد از انتشارش داوری می‌کند؛ این موتور همان داوری‌ها را به سراغ رأیِ
 * تک‌تک فیلترها می‌فرستد و می‌پرسد:
 *
 *   «وقتی این فیلتر جهت معامله را تأیید کرد، چه شد؟ سود یا زیان؟»
 *
 * قاعدهٔ یادگیری (پشتیبانی‌شده با رأی جهت‌دار هم‌سو با سیگنال):
 *   - فیلترِ تأییدکنندهٔ سیگنالِ برنده  ← درست (correct)
 *   - فیلترِ تأییدکنندهٔ سیگنالِ بازنده ← اشتباه (wrong)
 *   - رأی مخالف فقط برای گزارش آمار مخالفت‌ها به کار می‌رود (علّیت معکوس
 *     اثبات‌نشده است — صداقت ریاضی بر تخمین بی‌پایه مقدم است).
 *
 * تطبیق وزن (با هموارسازی لاپلاس در برابر نویز نمونهٔ کوچک):
 *   correctness = (correct + k) / (n + 2k)         k = ۳ شبه‌نمونه
 *   edge        = correctness − ۰٫۵
 *   mult_new    = clamp(mult_old × (1 + η × edge × ۲), mult_min, mult_max)
 *
 * قرنطینه و بازسازی (رفع خطاهای تکراری):
 *   - درست‌بودن زیر آستانه با نمونهٔ کافی ← قرنطینه: ضریب تا ۰٫۳۵ کف می‌خورد
 *     و فیلتر عملاً از تصمیم‌گیری کنار می‌رود (رویداد ثبت می‌شود).
 *   - بازیابی: قرنطینه‌ای که درست‌بودنش بالای ۵۲٪ برمی‌گردد، به چرخهٔ فعال
 *     با ضریب ۰٫۸ بازمی‌گردد — «اشتباه کرد، جریمه شد، بهبود یافت، برگشت».
 *
 * ایمنی:
 *   - ضریب همیشه در [mult_min, mult_max] (پیش‌فرض ۰٫۲۵ تا ۲٫۵) قفل است.
 *   - قبل از min_samples نمونه هیچ تغییری نمی‌آید.
 *   - یادگیری خاموش = رفتار دقیقاً برابر نسخهٔ قبل (ضریب ۱٫۰).
 *   - بازنشانی کامل با یک دکمه؛ همهٔ رویدادها در learning_events ثبت می‌شوند.
 *
 * افزودن استراتژی جدید: هر فیلتر تازه‌ای که به Filters::evaluate() اضافه شود
 * به‌طور خودکار در این چرخه ثبت، سنجیده و وزن‌دهی می‌شود — بدون هیچ تغییری
 * در این کلاس (برچسب و رأی از filters_json سیگنال‌ها برداشت می‌شود).
 *
 * @author Milad Yaghoobi — Meelano Studio Design
 */
final class LearningEngine
{
    /* ═══ پیکربندی ═════════════════════════════════════════════════════ */

    public static function defaults(): array
    {
        return [
            'enabled' => true,
            'eta' => 0.12,              // نرخ یادگیری (کوچک = محافظه‌کار)
            'min_samples' => 12,        // حداقل نمونه قبل از هر تطبیق
            'window' => 400,            // پنجرهٔ داوری‌های اخیر
            'mult_min' => 0.25,         // کف ضریب
            'mult_max' => 2.5,          // سقف ضریب
            'quarantine_below' => 0.40, // درست‌بودن زیر این = قرنطینه
            'quarantine_min' => 30,     // حداقل نمونه برای قرنطینه
            'recover_above' => 0.52,    // بازیابی از قرنطینه
            'disabled' => [],           // کلید فیلترهای غیرفعال‌شدهٔ دستی
            'overrides' => [],          // ضریب دستی per-filter (بر تطبیق مقدم است)
        ];
    }

    public static function cfg(): array
    {
        // مقادیر ذخیره‌شده بر پیش‌فرض مقدم‌اند (array_merge: کلید تکراری را سمت راست می‌برد)
        $cfg = array_merge(self::defaults(), (array)Config::get('learning', []));
        foreach (['disabled', 'overrides'] as $listKey) {
            if (!is_array($cfg[$listKey] ?? null)) {
                $cfg[$listKey] = []; // دادهٔ خراب هرگز نباید مسیر را بشکند
            }
        }
        return $cfg;
    }

    /* ═══ وضعیت پایدار ═════════════════════════════════════════════════ */

    /**
     * وضعیت ذخیره‌شدهٔ موتور (نسل، ضرایب، آمار).
     * @return array{generation:int,weights:array,stats:array,total_learned:int,last_run:?string}
     */
    public static function state(Db $db): array
    {
        $fresh = [
            'generation' => 0,
            'weights' => [],   // key => {mult: float, q: 0|1}
            'stats' => [],     // key => {label, n, correct, wrong, correctness, avg_r, ...}
            'total_learned' => 0,
            'last_run' => null,
        ];
        try {
            if (!$db->tableExists('learning_state')) {
                return $fresh;
            }
            $row = $db->selectOne('SELECT * FROM ' . $db->table('learning_state') . ' WHERE id = 1');
            if ($row === null) {
                return $fresh;
            }
            return [
                'generation' => (int)($row['generation'] ?? 0),
                'weights' => (array)json_decode((string)($row['weights_json'] ?? '{}'), true),
                'stats' => (array)json_decode((string)($row['stats_json'] ?? '{}'), true),
                'total_learned' => (int)($row['total_learned'] ?? 0),
                'last_run' => isset($row['last_run']) && $row['last_run'] !== null ? (string)$row['last_run'] : null,
            ];
        } catch (Throwable $e) {
            return $fresh;
        }
    }

    private static function saveState(Db $db, array $state): void
    {
        $row = [
            'generation' => (int)$state['generation'],
            'weights_json' => json_encode($state['weights'], JSON_UNESCAPED_UNICODE),
            'stats_json' => json_encode($state['stats'], JSON_UNESCAPED_UNICODE),
            'total_learned' => (int)$state['total_learned'],
            'last_run' => date('Y-m-d H:i:s'),
        ];
        $exists = $db->selectOne('SELECT id FROM ' . $db->table('learning_state') . ' WHERE id = 1');
        if ($exists !== null) {
            $db->update('learning_state', $row, 'id = 1');
        } else {
            $row['id'] = 1;
            $db->insert('learning_state', $row);
        }
    }

    /**
     * زمینهٔ فیلترها برای Filters: ضرایب اثرگذار + فیلترهای غیرفعال.
     * null = هیچ تغییری (رفتار نسخهٔ قبل). حتی با یادگیریِ خاموش،
     * غیرفعال‌سازی و ضریب دستی اعمال می‌شوند.
     */
    public static function filterContext(?Db $db): ?array
    {
        $cfg = self::cfg();
        $mult = [];
        foreach ((array)($cfg['overrides'] ?? []) as $k => $m) {
            $m = (float)$m;
            if ($m > 0) {
                $mult[(string)$k] = $m; // ضریب دستی مقدم است
            }
        }
        $disabled = array_values(array_unique(array_filter(array_map('strval', (array)($cfg['disabled'] ?? [])))));
        try {
            if ($cfg['enabled'] && $db !== null && $db->isConnected() && $db->tableExists('learning_state')) {
                $st = self::state($db);
                foreach ($st['weights'] as $k => $w) {
                    $m = is_array($w) ? (float)($w['mult'] ?? 1.0) : (float)$w;
                    if ($m > 0 && !isset($mult[(string)$k])) {
                        $mult[(string)$k] = $m; // دستی مقدم؛ تطبیقی فقط جای خالی را پر می‌کند
                    }
                }
            }
        } catch (Throwable $e) { /* بدون DB همان ایستا */ }
        if ($mult === [] && $disabled === []) {
            return null;
        }
        return ['multipliers' => $mult, 'disabled' => $disabled];
    }

    /* ═══ دور یادگیری ══════════════════════════════════════════════════ */

    /**
     * یک دور کامل یادگیری از داوری‌های واقعی سیگنال‌ها.
     *
     * @return array{ok:bool,learned:int,generation:int,changed:array,
     *               quarantined:array,recovered:array,errors:array}
     */
    public static function learn(Db $db, ?array $cfg = null): array
    {
        $out = [
            'ok' => false, 'learned' => 0, 'generation' => 0,
            'changed' => [], 'quarantined' => [], 'recovered' => [], 'errors' => [],
        ];
        try {
            if (!$db->tableExists('signals')) {
                $out['errors'][] = 'جدول سیگنال‌ها موجود نیست.';
                return $out;
            }
            if (!$db->tableExists('learning_state') || !$db->tableExists('learning_events')) {
                $out['errors'][] = 'جدول‌های یادگیری ساخته نشده‌اند — از تنظیمات، نصب/ارتقای جدول‌ها را اجرا کنید.';
                return $out;
            }
            $cfg = $cfg ?? self::cfg();
            $st = self::state($db);
            $weights = $st['weights'];

            $window = max(50, (int)$cfg['window']);
            $rows = $db->select(
                'SELECT side, r_multiple, filters_json FROM ' . $db->table('signals')
                . " WHERE outcome <> '' AND filters_json IS NOT NULL AND filters_json <> ''"
                . ' ORDER BY id DESC LIMIT ' . $window
            );
            $out['learned'] = count($rows);

            /* ── ۱) تجمیع: رأی هر فیلتر در برابر نتیجهٔ واقعی ────────── */
            $agg = [];
            foreach ($rows as $r) {
                $sigSide = strtoupper((string)($r['side'] ?? 'BUY'));
                $rMult = (float)($r['r_multiple'] ?? 0);
                $winner = $rMult > 0.05;
                $fs = json_decode((string)($r['filters_json'] ?? ''), true);
                if (!is_array($fs)) {
                    continue;
                }
                foreach ($fs as $f) {
                    if (!is_array($f)) {
                        continue;
                    }
                    $key = (string)($f['key'] ?? '');
                    if ($key === '') {
                        continue;
                    }
                    $a = $agg[$key] ?? [
                        'label' => '', 'n' => 0, 'correct' => 0, 'wrong' => 0,
                        'sum_r' => 0.0, 'n_sup' => 0, 'opp_right' => 0, 'opp_wrong' => 0,
                    ];
                    if ($a['label'] === '' && !empty($f['label'])) {
                        $a['label'] = (string)$f['label'];
                    }
                    $side = strtoupper((string)($f['side'] ?? 'NEUTRAL'));
                    if ($side === 'BUY' || $side === 'SELL') {
                        if ($side === $sigSide) {
                            // تأییدکنندهٔ جهت معامله — تنها شاهد علّی معتبر
                            $winner ? $a['correct']++ : $a['wrong']++;
                            $a['n']++;
                            $a['sum_r'] += $rMult;
                            $a['n_sup']++;
                        } else {
                            // مخالف جهت — فقط برای گزارش (علّیت معکوس اثبات‌نشده)
                            $winner ? $a['opp_wrong']++ : $a['opp_right']++;
                        }
                    }
                    $agg[$key] = $a;
                }
            }

            /* ── ۲) تطبیق ضرایب با کف/سقف سخت ────────────────────────── */
            $eta = min(0.5, max(0.01, (float)$cfg['eta']));
            $minSamples = max(3, (int)$cfg['min_samples']);
            $multMin = (float)$cfg['mult_min'];
            $multMax = max($multMin + 0.1, (float)$cfg['mult_max']);
            $qBelow = (float)$cfg['quarantine_below'];
            $qMin = (int)$cfg['quarantine_min'];
            $rAbove = (float)$cfg['recover_above'];
            $stats = [];

            foreach ($agg as $key => $a) {
                $n = $a['correct'] + $a['wrong'];
                $prior = 3.0; // هموارسازی لاپلاس
                $correctness = $n > 0 ? ($a['correct'] + $prior) / ($n + 2 * $prior) : 0.5;
                $avgR = $a['n_sup'] > 0 ? $a['sum_r'] / $a['n_sup'] : null;

                $oldW = $weights[$key] ?? ['mult' => 1.0, 'q' => 0];
                $oldMult = is_array($oldW) ? (float)($oldW['mult'] ?? 1.0) : (float)$oldW;
                $wasQ = is_array($oldW) ? (int)($oldW['q'] ?? 0) : 0;

                $newMult = $oldMult;
                $q = $wasQ;
                if ($n >= $minSamples) {
                    $edge = $correctness - 0.5;
                    $newMult = $oldMult * (1.0 + $eta * $edge * 2.0);
                    $newMult = min($multMax, max($multMin, $newMult));
                }

                // قرنطینه: خطای تکراری با نمونهٔ کافی
                if ($n >= $qMin && $correctness < $qBelow) {
                    $newMult = min($newMult, 0.35);
                    if (!$wasQ) {
                        $q = 1;
                        $out['quarantined'][] = $key;
                        self::event($db, $key, 'quarantine', $oldMult, $newMult,
                            'درست‌بودن ' . round($correctness * 100, 1) . '٪ با ' . $n . ' نمونه — فیلتر قرنطینه شد');
                    }
                } elseif ($wasQ && $n >= $minSamples && $correctness >= $rAbove) {
                    // بازسازی: بهبود و بازگشت به چرخهٔ فعال
                    $q = 0;
                    $newMult = max($newMult, 0.8);
                    $out['recovered'][] = $key;
                    self::event($db, $key, 'recover', $oldMult, $newMult,
                        'درست‌بودن به ' . round($correctness * 100, 1) . '٪ بازگشت — فیلتر بازسازی و فعال شد');
                }

                if (abs($newMult - $oldMult) >= 0.01) {
                    $out['changed'][] = $key;
                    if (!in_array($key, $out['quarantined'], true) && !in_array($key, $out['recovered'], true)) {
                        self::event($db, $key, 'adapt', $oldMult, $newMult,
                            'درست‌بودن ' . round($correctness * 100, 1) . '٪ · ' . $n . ' نمونه · میانگین R='
                            . ($avgR !== null ? round($avgR, 2) : '—'));
                    }
                }

                $weights[$key] = ['mult' => round($newMult, 3), 'q' => $q];
                $stats[$key] = [
                    'label' => $a['label'] !== '' ? $a['label'] : $key,
                    'n' => $n,
                    'correct' => $a['correct'],
                    'wrong' => $a['wrong'],
                    'correctness' => round($correctness, 4),
                    'avg_r' => $avgR !== null ? round($avgR, 3) : null,
                    'opp_right' => $a['opp_right'],
                    'opp_wrong' => $a['opp_wrong'],
                    'quarantined' => $q === 1,
                    'mult' => round($newMult, 3),
                ];
            }

            /* ── ۳) ذخیرهٔ نسل تازه ───────────────────────────────────── */
            $st['generation'] = (int)$st['generation'] + 1;
            $st['weights'] = $weights;
            $st['stats'] = $stats;
            $st['total_learned'] = (int)$st['total_learned'] + count($rows);
            self::saveState($db, $st);
            self::pruneEvents($db);

            $out['ok'] = true;
            $out['generation'] = $st['generation'];
        } catch (Throwable $e) {
            $out['errors'][] = $e->getMessage();
        }
        return $out;
    }

    /* ═══ بازنشانی و رویدادها ══════════════════════════════════════════ */

    /** بازنشانی همهٔ ضرایب به ۱٫۰ (پایه) — کارنامه حفظ می‌شود. */
    public static function reset(Db $db): array
    {
        $out = ['ok' => false, 'error' => null];
        try {
            if (!$db->tableExists('learning_state')) {
                $out['error'] = 'جدول یادگیری موجود نیست.';
                return $out;
            }
            $st = self::state($db);
            $st['generation'] = (int)$st['generation'] + 1;
            $st['weights'] = [];
            foreach ((array)$st['stats'] as $k => $s) {
                if (is_array($s)) {
                    $s['quarantined'] = false;
                    $s['mult'] = 1.0;
                    $st['stats'][$k] = $s;
                }
            }
            self::saveState($db, $st);
            self::event($db, '*', 'reset', 0, 1, 'بازنشانی دستی همهٔ ضرایب به پایه (۱٫۰)');
            $out['ok'] = true;
            $out['generation'] = $st['generation'];
        } catch (Throwable $e) {
            $out['error'] = $e->getMessage();
        }
        return $out;
    }

    private static function event(Db $db, string $filter, string $type, float $old, float $new, string $detail): void
    {
        try {
            $db->insert('learning_events', [
                'filter_key' => $filter,
                'type' => $type,
                'old_mult' => round($old, 3),
                'new_mult' => round($new, 3),
                'detail' => $detail,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) { /* رویداد هرگز یادگیری را نمی‌شکند */ }
    }

    /** نگه‌داشتن ۲۰۰ رویداد آخر. */
    private static function pruneEvents(Db $db): void
    {
        try {
            $db->execute(
                'DELETE FROM ' . $db->table('learning_events')
                . ' WHERE id NOT IN (SELECT id FROM ' . $db->table('learning_events')
                . ' ORDER BY id DESC LIMIT 200)'
            );
        } catch (Throwable $e) { /* هرزنامه‌گری رویداد مهم نیست */ }
    }

    /* ═══ گزارش وضعیت ══════════════════════════════════════════════════ */

    /**
     * گزارش کامل برای UI: تنظیمات، نسل، ضرایب مؤثر (با دستی)، آمار فیلترها، رویدادها.
     */
    public static function status(Db $db): array
    {
        $cfg = self::cfg();
        $st = self::state($db);
        $filters = [];
        foreach ($st['stats'] as $key => $s) {
            $s = (array)$s;
            $key = (string)$key;
            $manual = isset($cfg['overrides'][$key]) ? (float)$cfg['overrides'][$key] : null;
            $mult = $manual !== null && $manual > 0 ? $manual : (float)($s['mult'] ?? 1.0);
            $n = (int)($s['n'] ?? 0);
            $filters[] = [
                'key' => $key,
                'label' => (string)($s['label'] ?? $key),
                'n' => $n,
                'correct' => (int)($s['correct'] ?? 0),
                'wrong' => (int)($s['wrong'] ?? 0),
                'correctness' => (float)($s['correctness'] ?? 0.5),
                'avg_r' => isset($s['avg_r']) && $s['avg_r'] !== null ? (float)$s['avg_r'] : null,
                'opp_right' => (int)($s['opp_right'] ?? 0),
                'opp_wrong' => (int)($s['opp_wrong'] ?? 0),
                'mult' => round($mult, 3),
                'drift_pct' => round(($mult - 1.0) * 100, 1),
                'quarantined' => !empty($s['quarantined']),
                'disabled' => in_array($key, (array)$cfg['disabled'], true),
                'manual' => $manual !== null,
                'directional' => $n > 0 || (int)($s['opp_right'] ?? 0) + (int)($s['opp_wrong'] ?? 0) > 0,
            ];
        }
        usort($filters, static function ($a, $b) {
            return [$b['n'], $a['label']] <=> [$a['n'], $b['label']];
        });

        $events = [];
        try {
            if ($db->tableExists('learning_events')) {
                foreach ($db->select('SELECT * FROM ' . $db->table('learning_events') . ' ORDER BY id DESC LIMIT 20') as $e) {
                    $events[] = [
                        'filter' => (string)$e['filter_key'],
                        'type' => (string)$e['type'],
                        'old_mult' => (float)$e['old_mult'],
                        'new_mult' => (float)$e['new_mult'],
                        'detail' => (string)($e['detail'] ?? ''),
                        'at' => (string)($e['created_at'] ?? ''),
                    ];
                }
            }
        } catch (Throwable $e) { /* گزارش خالی */ }

        return [
            'ok' => true,
            'enabled' => (bool)$cfg['enabled'],
            'eta' => (float)$cfg['eta'],
            'min_samples' => (int)$cfg['min_samples'],
            'window' => (int)$cfg['window'],
            'generation' => (int)$st['generation'],
            'total_learned' => (int)$st['total_learned'],
            'last_run' => $st['last_run'],
            'tables_ready' => $db->tableExists('learning_state') && $db->tableExists('learning_events'),
            'filters' => $filters,
            'events' => $events,
        ];
    }
}
