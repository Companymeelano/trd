<?php
namespace Meelano;

/**
 * لاگر سبک با چرخش فایل — بدون وابستگی بیرونی.
 */
final class Logger
{
    private const MAX_BYTES = 2097152; // 2MB

    public static function write(string $channel, string $message, string $level = 'info', array $context = []): void
    {
        $file = MEELANO_LOGS . '/app.log';
        if (@filesize($file) > self::MAX_BYTES) {
            @rename($file, $file . '.' . date('YmdHis'));
        }
        $line = json_encode([
            'ts' => date('Y-m-d H:i:s'),
            'jalali' => m_jalali(),
            'level' => $level,
            'channel' => $channel,
            'msg' => $message,
            'ctx' => $context ?: null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        @file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    public static function info(string $channel, string $message, array $context = []): void
    {
        self::write($channel, $message, 'info', $context);
    }

    public static function error(string $channel, string $message, array $context = []): void
    {
        self::write($channel, $message, 'error', $context);
    }

    /** خواندن n خط آخر برای نمایش در UI. */
    public static function tail(int $lines = 50): array
    {
        $file = MEELANO_LOGS . '/app.log';
        if (!is_file($file)) {
            return [];
        }
        $all = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        return array_map(static function ($l) {
            $d = json_decode((string)$l, true);
            return is_array($d) ? $d : ['msg' => $l];
        }, array_slice($all, -$lines));
    }
}
