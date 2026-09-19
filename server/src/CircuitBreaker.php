<?php
declare(strict_types=1);

/**
 * Circuit Breaker نمونه‌محور (instance-based) - برای جلوگیری از
 * درخواست‌های مکرر به سرویس‌های بیرونی (مثل Binance) وقتی که پشت سر هم
 * fail می‌کنند. وضعیت هر "کلید" جدا و در یک فایل JSON نگه داشته می‌شود
 * تا بین ری‌کوئست‌های مختلف PHP-FPM/CGI هم پایدار بماند.
 *
 * حالت‌ها: CLOSED -> OPEN (بعد از N شکست پشت‌سرهم) -> HALF_OPEN (بعد از cooldown)
 */
final class CircuitBreaker
{
    private string $dir;

    public function __construct(
        string $cacheDir,
        private readonly ?Logger $logger = null,
        private readonly int $failureThreshold = 3,
        private readonly int $openTimeout = 60,
        private readonly int $halfOpenTrials = 2,
        private readonly int $cooldown = 2,
        private readonly int $retention = 86400,
    ) {
        $this->dir = rtrim($cacheDir, '/') . '/circuit';
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0775, true);
        }
    }

    public function canProceed(string $key): bool
    {
        $state = $this->readState($key);
        $phase = $this->phaseOf($state);

        if ($phase === 'CLOSED') {
            return true;
        }

        if ($phase === 'OPEN') {
            return false;
        }

        // HALF_OPEN: فقط تعداد محدودی درخواست آزمایشی مجاز است
        if ((int)$state['half_open_trials_used'] < $this->halfOpenTrials) {
            $state['half_open_trials_used'] = (int)$state['half_open_trials_used'] + 1;
            $this->writeState($key, $state);
            return true;
        }

        return false;
    }

    public function recordSuccess(string $key): void
    {
        $this->writeState($key, [
            'failures' => 0,
            'opened_at' => 0,
            'half_open_trials_used' => 0,
            'updated_at' => time(),
        ]);
    }

    public function recordFailure(string $key): void
    {
        $state = $this->readState($key);
        $state['failures'] = (int)$state['failures'] + 1;
        $wasOpenOrHalfOpen = (int)$state['opened_at'] !== 0;

        if ($state['failures'] >= $this->failureThreshold) {
            // یک شکست جدید (چه اولین بار، چه یک probe نیمه‌باز ناموفق) ->
            // پنجره OPEN را از الان دوباره شروع کن تا هیچ‌وقت قفل دائمی رخ ندهد.
            $state['opened_at'] = time();
            $state['half_open_trials_used'] = 0;
            if (!$wasOpenOrHalfOpen) {
                $this->logger?->warn('Circuit breaker OPENED.', ['key' => $key, 'failures' => $state['failures']]);
            }
        }

        $state['updated_at'] = time();
        $this->writeState($key, $state);
    }

    public function getPhase(string $key): string
    {
        return $this->phaseOf($this->readState($key));
    }

    private function phaseOf(array $state): string
    {
        if ((int)$state['failures'] < $this->failureThreshold) {
            return 'CLOSED';
        }

        $openedAt = (int)$state['opened_at'];
        if ($openedAt === 0) {
            return 'CLOSED';
        }

        $elapsed = time() - $openedAt;

        if ($elapsed < $this->openTimeout + $this->cooldown) {
            return 'OPEN';
        }

        // بعد از پایان openTimeout همیشه HALF_OPEN می‌ماند - نه یک پنجره‌ی
        // زمانی محدود - تا وقتی که یک probe صریحاً موفق یا ناموفق ثبت شود.
        // این از قفل‌شدن دائمی بریکر بعد از یک دوره سکوت طولانی جلوگیری می‌کند.
        return 'HALF_OPEN';
    }

    private function readState(string $key): array
    {
        $file = $this->file($key);
        $default = ['failures' => 0, 'opened_at' => 0, 'half_open_trials_used' => 0, 'updated_at' => 0];

        if (!is_file($file)) {
            return $default;
        }

        $raw = @file_get_contents($file);
        if ($raw === false) {
            return $default;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return $default;
        }

        return array_merge($default, $data);
    }

    private function writeState(string $key, array $state): void
    {
        $file = $this->file($key);
        $tmp  = $file . '.tmp.' . bin2hex(random_bytes(4));
        $payload = json_encode($state, JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            return;
        }
        if (@file_put_contents($tmp, $payload, LOCK_EX) !== false) {
            @rename($tmp, $file);
        }
    }

    private function file(string $key): string
    {
        return $this->dir . '/' . hash('sha256', $key) . '.json';
    }
}
