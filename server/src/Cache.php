<?php
declare(strict_types=1);

/**
 * کش چندلایه: Redis (در صورت وجود) -> APCu (در صورت وجود) -> فایل.
 * روی اکثر هاست‌های اشتراکی Redis نصب نیست؛ در این حالت سیستم به‌صورت
 * خودکار و بی‌صدا روی APCu یا فایل سوییچ می‌کند - هیچ خطایی رخ نمی‌دهد.
 */
final class Cache
{
    private ?Redis $redis = null;
    private bool $apcuOk;
    private string $fileDir;
    private ?Logger $logger;
    private int $defaultTtl;

    public function __construct(array $cfg, string $fileCacheDir, ?Logger $logger = null)
    {
        $this->logger     = $logger;
        $this->fileDir    = rtrim($fileCacheDir, '/');
        $this->defaultTtl = (int)($cfg['ttl'] ?? 60);
        $this->apcuOk     = function_exists('apcu_enabled') && apcu_enabled();

        if (!is_dir($this->fileDir)) {
            @mkdir($this->fileDir, 0775, true);
        }

        $host = $cfg['host'] ?? null;
        if ($host && class_exists('Redis')) {
            try {
                $redis = new Redis();
                $connected = $redis->connect($host, (int)($cfg['port'] ?? 6379), (float)($cfg['timeout'] ?? 0.5));
                if ($connected) {
                    if (!empty($cfg['password'])) {
                        $redis->auth((string)$cfg['password']);
                    }
                    if (!empty($cfg['database'])) {
                        $redis->select((int)$cfg['database']);
                    }
                    $this->redis = $redis;
                }
            } catch (Throwable $e) {
                $this->redis = null;
                $this->logger?->warn('Redis connection failed, falling back.', ['error' => $e->getMessage()]);
            }
        }
    }

    public function stats(): array
    {
        return [
            'redis' => $this->redis !== null,
            'apcu'  => $this->apcuOk,
            'file'  => true,
        ];
    }

    /**
     * @return array{data: mixed, layer: string, stale: bool}|null
     */
    public function get(string $key, int $staleTtl = 0): ?array
    {
        $envelope = $this->readEnvelope($key);
        if ($envelope === null) {
            return null;
        }

        [$value, $layer, $setAt, $ttl] = $envelope;
        $age = time() - $setAt;

        if ($age <= $ttl) {
            return ['data' => $value, 'layer' => $layer, 'stale' => false];
        }

        if ($staleTtl > 0 && $age <= ($ttl + $staleTtl)) {
            return ['data' => $value, 'layer' => $layer, 'stale' => true];
        }

        return null;
    }

    public function set(string $key, mixed $value, ?int $ttl = null): void
    {
        $ttl = $ttl ?? $this->defaultTtl;
        $payload = json_encode([
            'v'   => $value,
            'ts'  => time(),
            'ttl' => $ttl,
        ], JSON_UNESCAPED_UNICODE);

        if ($payload === false) {
            return;
        }

        if ($this->redis !== null) {
            try {
                $this->redis->setex($this->rkey($key), max($ttl * 3, 60), $payload);
                return;
            } catch (Throwable $e) {
                $this->logger?->warn('Redis write failed, falling back.', ['error' => $e->getMessage()]);
                $this->redis = null;
            }
        }

        if ($this->apcuOk) {
            apcu_store('milano_' . $key, $payload, max($ttl * 3, 60));
            return;
        }

        $this->writeFile($key, $payload);
    }

    /**
     * @return array{0: mixed, 1: string, 2: int, 3: int}|null [value, layer, setAt, ttl]
     */
    private function readEnvelope(string $key): ?array
    {
        if ($this->redis !== null) {
            try {
                $raw = $this->redis->get($this->rkey($key));
                if ($raw !== false && $raw !== null) {
                    $decoded = $this->decode((string)$raw);
                    if ($decoded !== null) {
                        return [$decoded['v'], 'redis', (int)$decoded['ts'], (int)$decoded['ttl']];
                    }
                }
            } catch (Throwable $e) {
                $this->logger?->warn('Redis read failed, falling back.', ['error' => $e->getMessage()]);
                $this->redis = null;
            }
        }

        if ($this->apcuOk) {
            $raw = apcu_fetch('milano_' . $key, $ok);
            if ($ok && $raw !== false) {
                $decoded = $this->decode((string)$raw);
                if ($decoded !== null) {
                    return [$decoded['v'], 'apcu', (int)$decoded['ts'], (int)$decoded['ttl']];
                }
            }
        }

        $file = $this->filePath($key);
        if (is_file($file)) {
            $raw = @file_get_contents($file);
            if ($raw !== false) {
                $decoded = $this->decode($raw);
                if ($decoded !== null) {
                    return [$decoded['v'], 'file', (int)$decoded['ts'], (int)$decoded['ttl']];
                }
            }
        }

        return null;
    }

    private function decode(string $raw): ?array
    {
        $d = json_decode($raw, true);
        if (!is_array($d) || !array_key_exists('v', $d) || !isset($d['ts'], $d['ttl'])) {
            return null;
        }
        return $d;
    }

    private function writeFile(string $key, string $payload): void
    {
        $file = $this->filePath($key);
        $tmp  = $file . '.tmp.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $payload, LOCK_EX) !== false) {
            @rename($tmp, $file);
        }
    }

    private function filePath(string $key): string
    {
        return $this->fileDir . '/' . hash('sha256', $key) . '.cache';
    }

    private function rkey(string $key): string
    {
        return 'milano:' . $key;
    }
}
