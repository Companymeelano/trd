<?php
declare(strict_types=1);

/**
 * لاگر ساده JSON-Lines - یک خط JSON به ازای هر رویداد.
 */
final class Logger
{
    private string $dir;
    private string $minLevel;

    private const LEVELS = ['INFO' => 0, 'WARN' => 1, 'ERROR' => 2, 'CRITICAL' => 3];

    public function __construct(string $logDir, string $minLevel = 'INFO')
    {
        $this->dir = rtrim($logDir, '/');
        $this->minLevel = strtoupper($minLevel);

        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0775, true);
        }
    }

    public function info(string $msg, array $ctx = []): void
    {
        $this->write('INFO', $msg, $ctx);
    }

    public function warn(string $msg, array $ctx = []): void
    {
        $this->write('WARN', $msg, $ctx);
    }

    public function error(string $msg, array $ctx = []): void
    {
        $this->write('ERROR', $msg, $ctx);
    }

    public function critical(string $msg, array $ctx = []): void
    {
        $this->write('CRITICAL', $msg, $ctx);
    }

    private function write(string $level, string $msg, array $ctx): void
    {
        $levelRank = self::LEVELS[$level] ?? 0;
        $minRank   = self::LEVELS[$this->minLevel] ?? 0;
        if ($levelRank < $minRank) {
            return;
        }

        $line = json_encode([
            'ts'    => date('c'),
            'epoch' => microtime(true),
            'level' => $level,
            'msg'   => $msg,
            'ctx'   => $ctx,
        ], JSON_UNESCAPED_UNICODE);

        if ($line === false) {
            return;
        }

        $file = $this->dir . '/' . date('Y-m-d') . '.log';
        @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
    }
}
