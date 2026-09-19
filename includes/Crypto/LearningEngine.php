<?php
namespace Meelano\Crypto;

use Meelano\Config;
use Meelano\Db;
use Throwable;

/**
 * موتور یادگیری تطبیقی — نسخهٔ ۵٫۷ (ADAPTIVE LEARNING II).
 *
 * فلسفه: وزن فیلترها دیگر عددی ایستا نیست؛ هر فیلتر «شاهدی» است که با کارنامهٔ
 * واقعی‌اش سنجیده می‌شود. ردیاب سیگنال (SignalTracker) هر سیگنال را با کندل‌های
 * واقعی بعد از انتشارش داوری می‌کند؛ این موتور همان داوری‌ها را به سراغ رأیِ
 * تک‌تک فیلترها می‌فرستد و می‌پرسد:
 *
 *   «وقتی این فیلتر جهت معامله را تأیید کرد، چه شد؟ سود یا زیان؟»
 *
 * قواعد نسخهٔ ۵٫۷:
 *   ۱) رأی جهت‌دارِ هم‌سو با سیگنال = شاهد علّی؛ رأی مخالف فقط برای گزارش.
 *   ۲) ضرایب وابسته به رژیم: کلید «key@regime» (روند/رِنج/پرنوسان) — فیلتری که
 *      در روند نابغه و در رِنج فاجعه است، دیگر با یک عدد میانگینِ گمراه‌کننده
 *      سنجیده نمی‌شود؛ رژیم فعال هنگام ارزیابی، ضریب همان رژیم را برمی‌دارد.
 *   ۳) حافظهٔ زمانی (نیم‌عمر): داوری‌های قدیمی با وزن ۰٫۵^(سن/نیم‌عمر) محو می‌شوند —
 *      بازار تغییر می‌کند و یادگیری باید تازه بماند.
 *   ۴) اعتبارسنجی walk-forward: پنجره به دو بخش تقسیم می‌شود — ۷۰٪ قدیمی‌تر
 *      «تمرین» (محاسبهٔ ضریب) و ۳۰٪ تازه‌تر «آزمون». اگر شاهد در آزمونِ تازه
 *      شکست بخورد (درستی < آستانه)، تغییر وزن این نسل به تعویق می‌افتد (hold) —
 *      هیچ ضریبی بدون قبولیِ نمونهٔ تازه روی پول واقعی نمی‌رود.
 *      استثنا: قرنطینهٔ ایمنی (خطای تکراری) همیشه اجرا می‌شود — ایمنی مقدم بر بازده.
 *   ۵) قرنطینه و بازسازی: درستی زیر ۴۰٪ با ۳۰+ نمونه → قرنطینه؛ بازگشت به ۵۲٪+ →
 *      بازسازی و فعال‌سازی مجدد.
 *   ۶) ژورنال فرصت‌های نزدیک (near-miss): سیگنال‌هایی که «نزدیک بود» صادر شوند هم
 *      ثبت و داوری می‌شوند تا هزینهٔ واقعی آستانه‌ها اندازه‌گیری و یادگیری شود.
 *
 * ایمنی:
 *   - ضریب همیشه در [mult_min, mult_max] (پیش‌فرض ۰٫۲۵ تا ۲٫۵) قفل است.
 *   - قبل از min_samples نمونه هیچ تطبیقی نمی‌آید.
 *   - یادگیری خاموش = رفتار دقیقاً برابر نسخهٔ قبل (ضریب ۱٫۰).
 *   - بازنشانی کامل با یک دکمه؛ همهٔ رویدادها در learning_events ثبت می‌شوند.
 *   - افزودن فیلتر جدید به کد = خودکار وارد این چرخه (برچسب/رأی از filters_json).
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
            'half_life_days' => 45,     // نیم‌عمر حافظهٔ داوری‌ها (نسخهٔ ۵٫۷)
            'wf_fraction' => 0.30,      // سهم بخش آزمون walk-forward (نسخهٔ ۵٫۷)
            'wf_min_val' => 8,          // حداقل نمونهٔ آزمون برای گرفتن دروازه
            'wf_gate' => 0.45,          // درستی آزمونِ تازه زیر این = تعویق تغییر
            'near_miss' => true,        // ژورنال فرصت‌های نزدیک (نسخهٔ ۵٫۷)
            'regime_weights' => true,   // ضرایب وابسته به رژیم (نسخهٔ ۵٫۷)
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

    /** گروه رژیم برای کلیدهای وابسته به رژیم. */
    public static function regimeGroup(string $regime): string
    {
        if ($regime === Regime::TREND_UP || $regime === Regime::TREND_DOWN) {
            return 'trend';
        }
        if ($regime === Regime::VOLATILE) {
            return 'volatile';
        }
        return 'range';
    }

    /** برچسب فارسی گروه رژیم. */
    public static function regimeLabel(string $group): string
    {
        $map = ['trend' => 'روند', 'range' => 'رِنج', 'volatile' => 'پرنوسان'];
        return $map[$group] ?? $group;
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
            'weights' => [],   // key یا key@regime => {mult: float, q: 0|1}
            'stats' => [],     // key یا key@regime => {label, n, correctness, avg_r, ...}
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
     * @return array{ok:bool,learned:int,generation:int,changed:array,held:array,
     *               quarantined:array,recovered:array,errors:array}
     */
    public static function learn(Db $db, ?array $cfg = null): array
    {
        $out = [
            'ok' => false, 'learned' => 0, 'generation' => 0, 'changed' => [],
            'held' => [], 'quarantined' => [], 'recovered' => [], 'errors' => [],
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
            if (empty($cfg['enabled'])) {
                // موتور در تنظیمات خاموش است — اجرای دستی هم نباید ضریب جابه‌جا کند
                $out['ok'] = true;
                $out['skipped'] = true;
                $out['reason'] = 'موتور یادگیری در تنظیمات خاموش است.';
                return $out;
            }
            $st = self::state($db);
            $weights = $st['weights'];

            /* ── ۰) واکشی پنجرهٔ داوری‌ها (کهن → تازه) ────────────────── */
            $window = max(50, (int)$cfg['window']);
            $rows = $db->select(
                'SELECT side, r_multiple, regime, status, created_at, resolved_at, filters_json'
                . ' FROM ' . $db->table('signals')
                . " WHERE outcome <> '' AND filters_json IS NOT NULL AND filters_json <> ''"
                . ' ORDER BY id DESC LIMIT ' . $window
            );
            $out['learned'] = count($rows);
            $now = time();
            $halfLife = max(1.0, (float)$cfg['half_life_days']) * 86400.0;
            $parsed = [];
            foreach ($rows as $r) {
                $ts = strtotime((string)($r['resolved_at'] ?? '') ?: (string)($r['created_at'] ?? ''));
                if ($ts === false) {
                    $ts = $now;
                }
                $fs = json_decode((string)($r['filters_json'] ?? ''), true);
                $parsed[] = [
                    'side' => strtoupper((string)($r['side'] ?? 'BUY')),
                    'r' => (float)($r['r_multiple'] ?? 0),
                    'regime' => (string)($r['regime'] ?? ''),
                    'filters' => is_array($fs) ? $fs : [],
                    // حافظهٔ زمانی: وزن داوری با نیم‌عمر محو می‌شود
                    'w' => 0.5 ** (max(0, $now - $ts) / $halfLife),
                ];
            }
            usort($parsed, static function ($a, $b) {
                return $a['w'] <=> $b['w']; // کهن (وزن کم) اول — تازه آخر
            });

            /* ── ۱) تفکیک walk-forward: تمرین کهن / آزمون تازه ────────── */
            $wfOn = (float)$cfg['wf_fraction'] > 0.05;
            $valCount = $wfOn ? max(1, (int)floor(count($parsed) * (float)$cfg['wf_fraction'])) : 0;
            $trainRows = $valCount > 0 ? array_slice($parsed, 0, count($parsed) - $valCount) : $parsed;
            $valRows = $valCount > 0 ? array_slice($parsed, count($parsed) - $valCount) : [];
            $aggTrain = self::aggregate($trainRows);
            $aggVal = self::aggregate($valRows);
            $aggAll = self::aggregate($parsed); // برای گزارش کارنامه

            /* ── ۲) تطبیق ضرایب ────────────────────────────────────────── */
            $eta = min(0.5, max(0.01, (float)$cfg['eta']));
            $minSamples = max(3, (int)$cfg['min_samples']);
            $multMin = (float)$cfg['mult_min'];
            $multMax = max($multMin + 0.1, (float)$cfg['mult_max']);
            $qBelow = (float)$cfg['quarantine_below'];
            $qMin = (int)$cfg['quarantine_min'];
            $rAbove = (float)$cfg['recover_above'];
            $wfMin = max(3, (int)$cfg['wf_min_val']);
            $wfGate = (float)$cfg['wf_gate'];
            $regimeOn = (bool)($cfg['regime_weights'] ?? true);
            $prior = 3.0; // هموارسازی لاپلاس
            $stats = [];

            // همهٔ کلیدهای دیده‌شده (جهانی + گروه‌ها)
            $candidates = [];
            foreach ($aggTrain as $key => $a) {
                $candidates[$key] = true;
                if ($regimeOn) {
                    foreach ((array)($a['groups'] ?? []) as $grp => $ga) {
                        $candidates[$key . '@' . $grp] = true;
                    }
                }
            }

            foreach (array_keys($candidates) as $key) {
                $isRegimeKey = strpos($key, '@') !== false;
                [$baseKey, $grp] = $isRegimeKey ? explode('@', $key, 2) : [$key, ''];
                $trainAgg = $aggTrain[$baseKey] ?? null;
                if ($trainAgg === null) {
                    continue;
                }
                $a = $isRegimeKey ? ((array)($trainAgg['groups'] ?? []))[$grp] ?? null : $trainAgg;
                $allA = $aggAll[$baseKey] ?? [];
                $allRow = $isRegimeKey ? ((array)($allA['groups'] ?? []))[$grp] ?? null : $allA;
                if ($a === null || $allRow === null) {
                    continue;
                }

                $n = (int)$a['n'];
                $wCorrect = (float)$a['w_correct'];
                $wWrong = (float)$a['w_wrong'];
                $correctness = ($n > 0) ? ($wCorrect + $prior) / ($wCorrect + $wWrong + 2 * $prior) : 0.5;
                $avgR = $a['sum_wr'] > 0 ? $a['sum_wr'] / $a['sum_w'] : null;

                // کارنامهٔ کل پنجره — مبنای قرنطینه/بازیابی (ایمنی روی همهٔ داده قضاوت می‌کند)
                $an = (int)($allRow['n'] ?? 0);
                $awC = (float)($allRow['w_correct'] ?? 0);
                $awW = (float)($allRow['w_wrong'] ?? 0);
                $allCorrectness = $an > 0 ? ($awC + $prior) / ($awC + $awW + 2 * $prior) : 0.5;

                // درستی بخش آزمون (تازه‌ترین نمونه‌ها) — دروازهٔ walk-forward
                $valCorrectness = null;
                $valN = 0;
                if ($wfOn) {
                    $va = $aggVal[$baseKey] ?? null;
                    if ($va !== null) {
                        $vRow = $isRegimeKey ? ((array)($va['groups'] ?? []))[$grp] ?? null : $va;
                        if ($vRow !== null) {
                            $valN = (int)$vRow['n'];
                            $valCorrectness = $valN > 0
                                ? ((float)$vRow['w_correct'] + $prior)
                                / ((float)$vRow['w_correct'] + (float)$vRow['w_wrong'] + 2 * $prior)
                                : null;
                        }
                    }
                }

                $oldW = $weights[$key] ?? ['mult' => 1.0, 'q' => 0];
                $oldMult = is_array($oldW) ? (float)($oldW['mult'] ?? 1.0) : (float)$oldW;
                $wasQ = is_array($oldW) ? (int)($oldW['q'] ?? 0) : 0;

                $newMult = $oldMult;
                $q = $wasQ;
                $held = false;

                if ($n >= $minSamples) {
                    $proposed = $oldMult * (1.0 + $eta * ($correctness - 0.5) * 2.0);
                    $proposed = min($multMax, max($multMin, $proposed));
                    // دروازهٔ walk-forward: نمونهٔ تازه باید تأیید کند
                    if ($wfOn && $valCorrectness !== null && $valN >= $wfMin && $valCorrectness < $wfGate
                        && abs($proposed - $oldMult) >= 0.01 && $oldMult < $proposed) {
                        // فقط ارتقای وزن تعویق می‌افتد؛ کاهش (محافظه‌کاری) و قرنطینه آزادند
                        $held = true;
                        $out['held'][] = $key;
                        self::event($db, $key, 'hold', $oldMult, $oldMult,
                            'درستی تمرین ' . round($correctness * 100, 1) . '٪ ولی آزمونِ تازه '
                            . round($valCorrectness * 100, 1) . '٪ — ارتقای وزن تا تأیید نمونهٔ تازه به تعویق افتاد');
                    } else {
                        $newMult = $proposed;
                    }
                }

                // قرنطینه: خطای تکراری با نمونهٔ کافی — ایمنی مقدم بر بازده (حتی در hold)
                if ($an >= $qMin && $allCorrectness < $qBelow) {
                    $newMult = min($newMult, 0.35);
                    if (!$wasQ) {
                        $q = 1;
                        $out['quarantined'][] = $key;
                        self::event($db, $key, 'quarantine', $oldMult, $newMult,
                            'درست‌بودن ' . round($allCorrectness * 100, 1) . '٪ با ' . $an . ' نمونه — فیلتر قرنطینه شد');
                    }
                } elseif ($wasQ && $an >= $minSamples && $allCorrectness >= $rAbove
                    && ($valCorrectness === null || $valN < $wfMin || $valCorrectness >= $wfGate)) {
                    // بازسازی: بهبود و بازگشت به چرخهٔ فعال
                    $q = 0;
                    $newMult = max($newMult, 0.8);
                    $out['recovered'][] = $key;
                    self::event($db, $key, 'recover', $oldMult, $newMult,
                        'درست‌بودن به ' . round($allCorrectness * 100, 1) . '٪ بازگشت — فیلتر بازسازی و فعال شد');
                }

                if (!$held && abs($newMult - $oldMult) >= 0.01
                    && !in_array($key, $out['quarantined'], true) && !in_array($key, $out['recovered'], true)) {
                    $out['changed'][] = $key;
                    self::event($db, $key, 'adapt', $oldMult, $newMult,
                        'درستی ' . round($correctness * 100, 1) . '٪ · ' . $n . ' نمونه · میانگین R='
                        . ($avgR !== null ? round($avgR, 2) : '—')
                        . ($valCorrectness !== null ? ' · آزمون ' . round($valCorrectness * 100, 1) . '٪' : ''));
                }

                $weights[$key] = ['mult' => round($newMult, 3), 'q' => $q];

                // کارنامه از کل پنجره (نه فقط تمرین) — همان چیزی که UI نشان می‌دهد
                $label = (string)($a['label'] ?? '');
                if ($label === '' && isset($aggAll[$baseKey]['label'])) {
                    $label = (string)$aggAll[$baseKey]['label'];
                }
                if ($isRegimeKey) {
                    $label = ($label !== '' ? $label : $baseKey) . ' · رژیم ' . self::regimeLabel($grp);
                }
                $stats[$key] = [
                    'label' => $label !== '' ? $label : $baseKey,
                    'regime' => $grp,
                    'n' => $an,
                    'correct' => (int)($allRow['correct'] ?? 0),
                    'wrong' => (int)($allRow['wrong'] ?? 0),
                    'correctness' => round($allCorrectness, 4),
                    'avg_r' => ($allRow['sum_w'] ?? 0) > 0 ? round($allRow['sum_wr'] / $allRow['sum_w'], 3) : null,
                    'opp_right' => (int)($allRow['opp_right'] ?? 0),
                    'opp_wrong' => (int)($allRow['opp_wrong'] ?? 0),
                    'val_correctness' => $valCorrectness !== null ? round($valCorrectness, 4) : null,
                    'val_n' => $valN,
                    'quarantined' => $q === 1,
                    'mult' => round($newMult, 3),
                ];
            }

            /* ── ۳) ذخیرهٔ نسل تازه ───────────────────────────────────── */
            $st['generation'] = (int)$st['generation'] + 1;
            $st['weights'] = $weights;
            $st['stats'] = $stats;
            $st['total_learned'] = (int)$st['total_learned'] + count($parsed);
            self::saveState($db, $st);
            self::pruneEvents($db);

            $out['ok'] = true;
            $out['generation'] = $st['generation'];
        } catch (Throwable $e) {
            $out['errors'][] = $e->getMessage();
        }
        return $out;
    }

    /**
     * تجمیع وزن‌دارِ رأی فیلترها در برابر نتیجهٔ واقعی.
     * خروجی: key => {label,n,correct,wrong,w_correct,w_wrong,sum_wr,sum_w,n_sup,opp_right,opp_wrong,
     *                groups: {group => همین ساختار بدون label/groups}}
     */
    private static function aggregate(array $rows): array
    {
        $agg = [];
        $touch = static function (array &$a): void {
            if (!isset($a['n'])) {
                $a += ['label' => '', 'n' => 0, 'correct' => 0, 'wrong' => 0,
                    'w_correct' => 0.0, 'w_wrong' => 0.0, 'sum_wr' => 0.0, 'sum_w' => 0.0,
                    'n_sup' => 0, 'opp_right' => 0, 'opp_wrong' => 0, 'groups' => []];
            }
        };
        foreach ($rows as $r) {
            $winner = $r['r'] > 0.05;
            $w = (float)$r['w'];
            $group = self::regimeGroup((string)$r['regime']);
            foreach ($r['filters'] as $f) {
                if (!is_array($f)) {
                    continue;
                }
                $key = (string)($f['key'] ?? '');
                if ($key === '') {
                    continue;
                }
                if (!isset($agg[$key])) {
                    $agg[$key] = [];
                }
                $a = &$agg[$key];
                $touch($a);
                if ($a['label'] === '' && !empty($f['label'])) {
                    $a['label'] = (string)$f['label'];
                }
                $side = strtoupper((string)($f['side'] ?? 'NEUTRAL'));
                if ($side !== 'BUY' && $side !== 'SELL') {
                    continue;
                }
                if ($side === $r['side']) {
                    // تأییدکنندهٔ جهت معامله — تنها شاهد علّی معتبر
                    if ($winner) {
                        $a['correct']++;
                        $a['w_correct'] += $w;
                    } else {
                        $a['wrong']++;
                        $a['w_wrong'] += $w;
                    }
                    $a['n']++;
                    $a['sum_wr'] += $w * $r['r'];
                    $a['sum_w'] += $w;
                    $a['n_sup']++;
                    // گروه رژیم همین سیگنال
                    if (!isset($a['groups'][$group]) || !is_array($a['groups'][$group])) {
                        $a['groups'][$group] = [];
                    }
                    $g = &$a['groups'][$group];
                    $touch($g);
                    $g['n']++;
                    if ($winner) {
                        $g['correct']++;
                        $g['w_correct'] += $w;
                    } else {
                        $g['wrong']++;
                        $g['w_wrong'] += $w;
                    }
                    $g['sum_wr'] += $w * $r['r'];
                    $g['sum_w'] += $w;
                    $g['n_sup']++;
                    unset($g);
                } else {
                    // مخالف جهت — فقط برای گزارش (علّیت معکوس اثبات‌نشده)
                    $winner ? $a['opp_wrong']++ : $a['opp_right']++;
                }
            }
            unset($a);
        }
        return $agg;
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
     * گزارش کامل برای UI: تنظیمات، نسل، ضرایب مؤثر (با دستی)، آمار فیلترها، رویدادها، ژورنال.
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
                'regime' => (string)($s['regime'] ?? ''),
                'n' => $n,
                'correct' => (int)($s['correct'] ?? 0),
                'wrong' => (int)($s['wrong'] ?? 0),
                'correctness' => (float)($s['correctness'] ?? 0.5),
                'avg_r' => isset($s['avg_r']) && $s['avg_r'] !== null ? (float)$s['avg_r'] : null,
                'opp_right' => (int)($s['opp_right'] ?? 0),
                'opp_wrong' => (int)($s['opp_wrong'] ?? 0),
                'val_correctness' => isset($s['val_correctness']) && $s['val_correctness'] !== null ? (float)$s['val_correctness'] : null,
                'val_n' => (int)($s['val_n'] ?? 0),
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

        // ژورنال فرصت‌های نزدیک (نسخهٔ ۵٫۷)
        $nearMiss = ['open' => 0, 'judged' => 0];
        try {
            if ($db->tableExists('signals')) {
                $t = $db->table('signals');
                $nearMiss['open'] = (int)$db->count($t, "status = 'near_miss' AND outcome = ''");
                $nearMiss['judged'] = (int)$db->count($t, "status = 'near_miss' AND outcome <> ''");
            }
        } catch (Throwable $e) { /* ژورنال اختیاری */ }

        return [
            'ok' => true,
            'enabled' => (bool)$cfg['enabled'],
            'eta' => (float)$cfg['eta'],
            'min_samples' => (int)$cfg['min_samples'],
            'window' => (int)$cfg['window'],
            'half_life_days' => (float)$cfg['half_life_days'],
            'generation' => (int)$st['generation'],
            'total_learned' => (int)$st['total_learned'],
            'last_run' => $st['last_run'],
            'tables_ready' => $db->tableExists('learning_state') && $db->tableExists('learning_events'),
            'near_miss' => $nearMiss,
            'filters' => $filters,
            'events' => $events,
        ];
    }
}
