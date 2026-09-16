<?php
namespace Meelano;

use PDO;
use PDOException;
use Throwable;

/**
 * لایه اتصال به پایگاه‌داده.
 *
 * دو درایور پشتیبانی می‌شود:
 *  - mysql  : حالت پروداکشن روی هاست اشتراکی (ainetmee.ir/trader)
 *  - sqlite : حالت توسعه/آفلاین و اجرای تست‌ها بدون سرور MySQL
 *
 * نکته مهندسی: در هاست‌های اشتراکی cPanel پورت 3306 از بیرون بسته است؛
 * بنابراین `host` باید `localhost` باشد و برنامه روی همان هاست اجرا شود.
 *
 * @author Milad Yaghoobi — Meelano Studio Design
 */
final class Db
{
    /** @var PDO|null */
    private $pdo;
    /** @var array */
    private $cfg;
    /** @var string */
    private $prefix;
    /** @var string[]|null */
    private $tablesCache = null;

    public function __construct(array $cfg = [])
    {
        $this->cfg = $cfg ?: (array)Config::get('db', []);
        $this->prefix = (string)($this->cfg['prefix'] ?? 'mln_');
    }

    public static function make(array $override = []): self
    {
        $cfg = array_merge((array)Config::get('db', []), $override);
        return new self($cfg);
    }

    public function driver(): string
    {
        return (string)($this->cfg['driver'] ?? 'mysql');
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    public function table(string $name): string
    {
        return $this->prefix . $name;
    }

    public function dsn(): string
    {
        if ($this->driver() === 'sqlite') {
            $path = (string)($this->cfg['sqlite_path'] ?? '');
            return 'sqlite:' . ($path !== '' ? $path : (MEELANO_STORAGE . '/meelano.sqlite'));
        }
        $host = (string)($this->cfg['host'] ?? 'localhost');
        $socket = (string)($this->cfg['socket'] ?? '');
        $charset = (string)($this->cfg['charset'] ?? 'utf8mb4');
        if ($socket !== '') {
            return sprintf('mysql:unix_socket=%s;dbname=%s;charset=%s', $socket, $this->cfg['name'] ?? '', $charset);
        }
        return sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, (int)($this->cfg['port'] ?? 3306), $this->cfg['name'] ?? '', $charset);
    }

    /**
     * اتصال واقعی و اجرای مجموعه‌ای از بررسی‌های سلامت.
     *
     * @return array{ok:bool,message:string,latency_ms:float,checks:array,server:string,driver:string}
     */
    public function test(bool $deep = true): array
    {
        $started = m_microtime();
        $checks = [];
        $result = [
            'ok' => false,
            'message' => '',
            'latency_ms' => 0.0,
            'checks' => &$checks,
            'server' => '',
            'driver' => $this->driver(),
            'code' => '',
        ];

        $name = (string)($this->cfg['name'] ?? '');
        if ($this->driver() === 'mysql' && $name === '') {
            $checks[] = ['label' => 'نام پایگاه‌داده', 'ok' => false, 'detail' => 'نام دیتابیس وارد نشده است.'];
            $result['message'] = 'نام پایگاه‌داده وارد نشده است.';
            return $result;
        }
        $checks[] = ['label' => 'نام پایگاه‌داده', 'ok' => true, 'detail' => $this->driver() === 'sqlite' ? 'فایل SQLite' : $name];

        try {
            $this->pdo = $this->connect();
        } catch (PDOException $e) {
            $checks[] = ['label' => 'برقراری اتصال', 'ok' => false, 'detail' => $this->explainPdoError($e)];
            $result['message'] = $this->explainPdoError($e);
            $result['code'] = (string)$e->getCode();
            $result['latency_ms'] = round((m_microtime() - $started) * 1000, 1);
            return $result;
        }
        $checks[] = ['label' => 'برقراری اتصال', 'ok' => true, 'detail' => 'دست‌دادن موفق با سرور'];

        try {
            $server = (string)$this->pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
            $result['server'] = $server;
            $checks[] = ['label' => 'نسخه سرور', 'ok' => true, 'detail' => $server];

            $row = $this->pdo->query('SELECT 1 AS ok')->fetch(PDO::FETCH_ASSOC);
            $checks[] = ['label' => 'اجرای کوئری', 'ok' => ($row['ok'] ?? 0) == 1, 'detail' => 'SELECT 1'];

            if ($deep && $this->driver() === 'mysql') {
                $utf = $this->pdo->query("SELECT @@character_set_database AS cs, @@collation_database AS co")->fetch(PDO::FETCH_ASSOC);
                $isUtf8 = isset($utf['cs']) && stripos((string)$utf['cs'], 'utf8') === 0;
                $checks[] = [
                    'label' => 'رمزگذاری utf8mb4',
                    'ok' => $isUtf8,
                    'detail' => ($utf['cs'] ?? '?') . ' / ' . ($utf['co'] ?? '?') . ($isUtf8 ? '' : ' — برای فارسی و ایموجی utf8mb4 لازم است'),
                ];
                $write = $this->probeWritable();
                $checks[] = $write;
            } elseif ($deep) {
                $checks[] = $this->probeWritable();
            }
        } catch (Throwable $e) {
            $checks[] = ['label' => 'بررسی‌های تکمیلی', 'ok' => false, 'detail' => $e->getMessage()];
            $result['message'] = $e->getMessage();
            $result['latency_ms'] = round((m_microtime() - $started) * 1000, 1);
            return $result;
        }

        $result['ok'] = true;
        $result['message'] = 'اتصال سالم است و کوئری‌ها با موفقیت اجرا شدند.';
        $result['latency_ms'] = round((m_microtime() - $started) * 1000, 1);
        return $result;
    }

    /** بررسی امکان ایجاد/حذف جدول موقت — پیش‌نیاز دکمه «ساخت جداول». */
    private function probeWritable(): array
    {
        $probe = $this->table('health_probe');
        try {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$probe} (id INT PRIMARY KEY)");
            $this->pdo->exec("INSERT INTO {$probe} (id) VALUES (1)");
            $this->pdo->exec("DELETE FROM {$probe}");
            $this->pdo->exec("DROP TABLE {$probe}");
            return ['label' => 'سطح دسترسی نوشتن (CREATE/DROP)', 'ok' => true, 'detail' => 'کاربر می‌تواند جدول بسازد'];
        } catch (Throwable $e) {
            return [
                'label' => 'سطح دسترسی نوشتن (CREATE/DROP)',
                'ok' => false,
                'detail' => 'کاربر دیتابیس اجازه ساخت جدول ندارد — از cPanel سطح دسترسی ALL PRIVILEGES بدهید. (' . $e->getMessage() . ')',
            ];
        }
    }

    private function connect(): PDO
    {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_PERSISTENT => (bool)($this->cfg['persist'] ?? false),
        ];
        if ($this->driver() === 'mysql') {
            $options[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, SESSION sql_mode='STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'";
        }
        return new PDO($this->dsn(), (string)($this->cfg['user'] ?? ''), (string)($this->cfg['pass'] ?? ''), $options);
    }

    /** ترجمه خطاهای رایج MySQL به راهنمای عملی فارسی. */
    private function explainPdoError(PDOException $e): string
    {
        $raw = $e->getMessage();
        $map = [
            1045 => 'نام کاربری یا رمز عبور دیتابیس اشتباه است (Access denied).',
            2002 => 'سرور MySQL در دسترس نیست؛ host باید localhost و سرویس MySQL فعال باشد.',
            2003 => 'امکان اتصال به پورت 3306 نیست — در هاست اشتراکی، برنامه باید روی همان سرور اجرا شود.',
            2005 => 'نام میزبان (host) ناشناخته است؛ معمولاً باید localhost باشد.',
            1044 => 'کاربر دیتابیس به این پایگاه دسترسی ندارد.',
            1049 => 'پایگاه‌داده وجود ندارد؛ ابتدا از cPanel دیتابیس را بسازید.',
            2006 => 'ارتباط با سرور قطع شد (Lost connection) — معمولاً فایروال یا max_allowed_packet.',
        ];
        foreach ($map as $code => $text) {
            if (strpos($raw, (string)$code) !== false || strpos($raw, 'SQLSTATE[' . $code) !== false) {
                return $text . ' [' . $code . ']';
            }
        }
        if (stripos($raw, 'Access denied') !== false) {
            return $map[1045];
        }
        if (stripos($raw, 'Connection refused') !== false) {
            return $map[2002];
        }
        return $raw;
    }

    /* ── دسترسی به PDO ───────────────────────────────────────────────── */

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = $this->connect();
        }
        return $this->pdo;
    }

    public function isConnected(): bool
    {
        try {
            $this->pdo()->query('SELECT 1');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** @return string[] نام جدول‌های موجود (با حافظه نهان در طول یک درخواست) */
    public function tables(bool $refresh = false): array
    {
        if ($this->tablesCache !== null && !$refresh) {
            return $this->tablesCache;
        }
        try {
            if ($this->driver() === 'sqlite') {
                $rows = $this->pdo()->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
            } else {
                $rows = $this->pdo()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
            }
            $this->tablesCache = array_map('strval', $rows ?: []);
        } catch (Throwable $e) {
            $this->tablesCache = [];
        }
        return $this->tablesCache;
    }

    public function forgetTablesCache(): void
    {
        $this->tablesCache = null;
    }

    public function tableExists(string $name): bool
    {
        return in_array($this->table($name), $this->tables(), true);
    }

    /** @return int تعداد ردیف‌های یک جدول */
    public function count(string $table, string $where = '', array $params = []): int
    {
        $sql = 'SELECT COUNT(*) FROM ' . $this->table($table) . ($where !== '' ? ' WHERE ' . $where : '');
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    /** @return array<int,array<string,mixed>> */
    public function select(string $sql, array $params = []): array
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function selectOne(string $sql, array $params = []): ?array
    {
        $rows = $this->select($sql, $params);
        return $rows[0] ?? null;
    }

    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /** درج امن و بازگرداندن شناسه. */
    public function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->table($table),
            implode(', ', $columns),
            implode(', ', array_fill(0, count($columns), '?'))
        );
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute(array_values($data));
        return (int)$this->pdo()->lastInsertId();
    }

    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $sets = [];
        foreach (array_keys($data) as $col) {
            $sets[] = $col . ' = ?';
        }
        $sql = sprintf('UPDATE %s SET %s WHERE %s', $this->table($table), implode(', ', $sets), $where);
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute(array_merge(array_values($data), $whereParams));
        return $stmt->rowCount();
    }
}
