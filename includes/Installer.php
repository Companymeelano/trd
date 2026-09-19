<?php
namespace Meelano;

use Throwable;

/**
 * نصب‌کننده ساختار پایگاه‌داده با گزارش پیشرفت لحظه‌ای.
 *
 * هر مرحله یک رویداد تولید می‌کند تا UI بتواند درصد و نام جدول در حال ساخت
 * را به‌صورت زنده نشان دهد (از طریق SSE در api/db_install.php).
 *
 * @author Milad Yaghoobi — Meelano Studio Design
 */
final class Installer
{
    /** @var Db */
    private $db;

    public function __construct(Db $db)
    {
        $this->db = $db;
    }

    /**
     * اجرای کامل نصب.
     *
     * @param callable|null $onEvent fn(array $event): void
     * @return array{ok:bool,created:array,skipped:array,failed:array,summary:string,duration_ms:float}
     */
    public function run(?callable $onEvent = null): array
    {
        $started = m_microtime();
        $tables = Schema::names();
        $total = count($tables);
        $created = [];
        $skipped = [];
        $failed = [];

        $emit = static function (array $event) use ($onEvent): void {
            if ($onEvent !== null) {
                $onEvent($event);
            }
        };

        $emit([
            'phase' => 'start',
            'step' => 0,
            'total' => $total,
            'percent' => 0,
            'message' => 'آغاز ساخت ساختار پایگاه‌داده…',
            'ok' => true,
        ]);

        // یک عکس فوری از جدول‌های موجود می‌گیریم تا حافظه نهان باعث
        // تشخیص نادرست «جدول تازه ساخته‌شده» نشود.
        $this->db->forgetTablesCache();
        $snapshot = $this->db->tables(true);

        $index = 0;
        foreach ($tables as $table) {
            $index++;
            $def = Schema::tables()[$table];
            $existedBefore = in_array($this->db->table($table), $snapshot, true);
            $percent = (int)floor((($index - 1) / $total) * 100);

            $emit([
                'phase' => 'table:start',
                'step' => $index,
                'total' => $total,
                'percent' => $percent,
                'table' => $table,
                'title' => $def['label'],
                'ok' => true,
                'message' => 'در حال ساخت «' . $def['label'] . '»…',
            ]);

            try {
                $existed = $existedBefore;
                $statements = Schema::statements($table, $this->db->driver(), $this->db->prefix());
                foreach ($statements as $sql) {
                    $this->db->pdo()->exec($sql);
                }

                // راستی‌آزمایی: جدول واقعاً ساخته شد؟
                $columns = $this->columnCount($table);
                if ($columns < 1) {
                    throw new \RuntimeException('جدول ساخته شد اما هیچ ستونی خوانده نشد.');
                }

                $seeded = 0;
                if (!$existed && !empty($def['seed'])) {
                    $seeded = $this->seed($table, $def['seed']);
                }

                if ($existed) {
                    $skipped[] = $table;
                    $detail = 'از قبل وجود داشت — ' . $columns . ' ستون راستی‌آزمایی شد';
                } else {
                    $created[] = $table;
                    $detail = $columns . ' ستون' . ($seeded ? ' + ' . $seeded . ' ردیف اولیه' : '');
                }

                $emit([
                    'phase' => 'table:done',
                    'step' => $index,
                    'total' => $total,
                    'percent' => (int)floor(($index / $total) * 100),
                    'table' => $table,
                    'title' => $def['label'],
                    'existed' => $existed,
                    'columns' => $columns,
                    'seeded' => $seeded,
                    'ok' => true,
                    'message' => '✓ ' . $def['label'] . ' — ' . $detail,
                ]);
            } catch (Throwable $e) {
                $failed[] = ['table' => $table, 'error' => $e->getMessage()];
                $emit([
                    'phase' => 'table:error',
                    'step' => $index,
                    'total' => $total,
                    'percent' => (int)floor(($index / $total) * 100),
                    'table' => $table,
                    'title' => $def['label'],
                    'ok' => false,
                    'message' => '✗ خطا در «' . $def['label'] . '»: ' . $e->getMessage(),
                ]);
            }
        }

        // ارتقای ستون‌های جدید روی نصب‌های موجود (نسخهٔ ۵٫۱ — ردیاب سیگنال)
        try {
            $upgraded = $this->upgradeSignals();
            if ($upgraded > 0) {
                $emit([
                    'phase' => 'upgrade', 'step' => $total, 'total' => $total, 'percent' => 100,
                    'ok' => true,
                    'message' => '✓ ارتقای جدول سیگنال‌ها: ' . $upgraded . ' ستون جدید ردیاب اضافه شد.',
                ]);
            }
        } catch (Throwable $e) {
            $emit([
                'phase' => 'upgrade', 'step' => $total, 'total' => $total, 'percent' => 100,
                'ok' => false,
                'message' => '⚠ ارتقای ستون‌های ردیاب ناموفق: ' . $e->getMessage(),
            ]);
        }

        // ثبت نسخه ساختار
        try {
            $this->recordMigration();
            $emit([
                'phase' => 'migrate',
                'step' => $total,
                'total' => $total,
                'percent' => 97,
                'ok' => true,
                'message' => 'نسخه ساختار ' . Schema::VERSION . ' ثبت شد.',
            ]);
        } catch (Throwable $e) {
            $emit(['phase' => 'migrate', 'step' => $total, 'total' => $total, 'percent' => 97, 'ok' => false, 'message' => $e->getMessage()]);
        }

        // بررسی نهایی یکپارچگی — با تازه‌سازی حافظه نهان جدول‌ها
        $after = $this->db->tables(true);
        $missing = [];
        foreach ($tables as $table) {
            if (!in_array($this->db->table($table), $after, true)) {
                $missing[] = $table;
            }
        }

        $ok = empty($failed) && empty($missing);
        Config::set('app.installed', $ok);
        Config::save();

        $emit([
            'phase' => 'done',
            'step' => $total,
            'total' => $total,
            'percent' => 100,
            'ok' => $ok,
            'created' => count($created),
            'skipped' => count($skipped),
            'failed' => count($failed),
            'message' => $ok
                ? sprintf('نصب کامل شد — %d جدول آماده است.', count($created) + count($skipped))
                : 'نصب با خطا پایان یافت.',
        ]);

        return [
            'ok' => $ok,
            'created' => $created,
            'skipped' => $skipped,
            'failed' => $failed,
            'missing' => $missing,
            'total' => $total,
            'summary' => sprintf('%d ساخته شد، %d از قبل بود، %d ناموفق', count($created), count($skipped), count($failed)),
            'duration_ms' => round((m_microtime() - $started) * 1000, 1),
        ];
    }

    /** وضعیت فعلی نصب — برای چک خودکار هنگام ورود. */
    public function status(): array
    {
        $tables = Schema::names();
        $existing = $this->db->tables();
        $prefixed = $this->db->prefix();
        $present = [];
        $missing = [];
        foreach ($tables as $table) {
            if (in_array($prefixed . $table, $existing, true)) {
                $present[] = $table;
            } else {
                $missing[] = $table;
            }
        }
        return [
            'complete' => empty($missing),
            'present' => $present,
            'missing' => $missing,
            'total' => count($tables),
            'percent' => count($tables) ? (int)round((count($present) / count($tables)) * 100) : 0,
            'schema_version' => Schema::VERSION,
        ];
    }

    /**
     * فهرست نام ستون‌های یک جدول (مستقل از درایور).
     * @return array<int,string>
     */
    public function columns(string $table): array
    {
        $full = $this->db->table($table);
        try {
            if ($this->db->driver() === 'sqlite') {
                $rows = $this->db->pdo()->query("PRAGMA table_info({$full})")->fetchAll();
                return array_map(static function ($r) {
                    return (string)$r['name'];
                }, $rows);
            }
            $rows = $this->db->pdo()->query("SHOW COLUMNS FROM {$full}")->fetchAll();
            return array_map(static function ($r) {
                return (string)($r['Field'] ?? '');
            }, $rows);
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * ارتقای نصب‌های موجود: ستون‌های ردیاب نسخهٔ ۵٫۱ به signals اضافه می‌شوند.
     * CREATE TABLE IF NOT EXISTS ستون جدید به جدول موجود اضافه نمی‌کند؛
     * این متد کمبود را با ALTER پر می‌کند.
     * @return int تعداد ستون‌های اضافه‌شده
     */
    private function upgradeSignals(): int
    {
        if (!$this->db->tableExists('signals')) {
            return 0;
        }
        $wanted = [
            'hit_tp1' => "TINYINT NOT NULL DEFAULT 0",
            'hit_tp2' => "TINYINT NOT NULL DEFAULT 0",
            'hit_tp3' => "TINYINT NOT NULL DEFAULT 0",
            'hit_stop' => "TINYINT NOT NULL DEFAULT 0",
            'outcome' => "VARCHAR(16) NOT NULL DEFAULT ''",
            'exit_price' => "DECIMAL(20,8) NOT NULL DEFAULT 0",
            'r_multiple' => "DECIMAL(8,3) NOT NULL DEFAULT 0",
            'bars_held' => "INT NOT NULL DEFAULT 0",
            'resolved_at' => "DATETIME NULL",
            'tracker_json' => "MEDIUMTEXT NULL",
        ];
        if ($this->db->driver() === 'sqlite') {
            $wanted = [
                'hit_tp1' => "INTEGER NOT NULL DEFAULT 0",
                'hit_tp2' => "INTEGER NOT NULL DEFAULT 0",
                'hit_tp3' => "INTEGER NOT NULL DEFAULT 0",
                'hit_stop' => "INTEGER NOT NULL DEFAULT 0",
                'outcome' => "TEXT NOT NULL DEFAULT ''",
                'exit_price' => "REAL NOT NULL DEFAULT 0",
                'r_multiple' => "REAL NOT NULL DEFAULT 0",
                'bars_held' => "INTEGER NOT NULL DEFAULT 0",
                'resolved_at' => "TEXT NULL",
                'tracker_json' => "TEXT NULL",
            ];
        }
        $existing = $this->columns('signals');
        if (!$existing) {
            return 0;
        }
        $added = 0;
        $full = $this->db->table('signals');
        foreach ($wanted as $col => $def) {
            if (!in_array($col, $existing, true)) {
                $this->db->pdo()->exec("ALTER TABLE {$full} ADD COLUMN {$col} {$def}");
                $added++;
            }
        }
        return $added;
    }

    private function columnCount(string $table): int
    {
        $full = $this->db->table($table);
        try {
            if ($this->db->driver() === 'sqlite') {
                $rows = $this->db->pdo()->query("PRAGMA table_info({$full})")->fetchAll();
                return count($rows ?: []);
            }
            $stmt = $this->db->pdo()->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
            );
            $stmt->execute([$full]);
            return (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }

    private function seed(string $table, array $rows): int
    {
        $count = 0;
        foreach ($rows as $row) {
            $row['created_at'] = date('Y-m-d H:i:s');
            try {
                $this->db->insert($table, $row);
                $count++;
            } catch (Throwable $e) {
                // ردیف تکراری — بی‌خطر
            }
        }
        return $count;
    }

    private function recordMigration(): void
    {
        $table = $this->db->table('migrations');
        if (!in_array($table, $this->db->tables(true), true)) {
            return;
        }
        $exists = $this->db->selectOne("SELECT id FROM {$table} WHERE version = ?", [Schema::VERSION]);
        if (!$exists) {
            $this->db->insert('migrations', [
                'version' => Schema::VERSION,
                'batch' => 1,
                'executed_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }
}
