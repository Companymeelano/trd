<?php
namespace Meelano\Ai;

use Meelano\Config;

/**
 * مسیریاب هوشمند وظایف → ارائه‌دهنده.
 *
 * امتیاز هر ارائه‌دهنده برای هر وظیفه از جمع این عوامل ساخته می‌شود:
 *   ۱) پشتیبانی از قابلیت مورد نیاز (پیش‌شرط قطعی)
 *   ۲) امتیاز تخصصی (affinity) از ماتریس رجیستری
 *   ۳) نتیجه آخرین تست سلامت (موفقیت + تأخیر اندازه‌گیری‌شده)
 *   ۴) وزن کیفیت/سرعت مخصوص همان وظیفه
 *   ۵) جریمه کلاس هزینه
 *   ۶) اولویت دستی کاربر (اگر حالت manual باشد)
 *
 * خروجی: بهترین ارائه‌دهنده + زنجیره پشتیبان + دلیل خوانا برای نمایش در UI.
 *
 * @author Milad Yaghoobi — Meelano Studio Design
 */
final class Router
{
    /** @var array */
    private $config;
    /** @var array<string,array> نتیجه تست سلامت provider => row */
    private $health;

    public function __construct(?array $config = null, array $health = [])
    {
        $this->config = $config ?: Config::all();
        $this->health = $health;
    }

    /** آیا ارائه‌دهنده کلید فعال دارد؟ */
    private function isConfigured(string $id): bool
    {
        $provider = $this->config['ai']['providers'][$id] ?? [];
        if (empty($provider['enabled'])) {
            return false;
        }
        $meta = Registry::provider($id);
        $keyField = $meta['key_field'] ?? 'api_key';
        $key = trim((string)($provider[$keyField] ?? ''));
        if ($key === '') {
            return false;
        }
        foreach ($meta['requires_extra'] ?? [] as $extra) {
            if (trim((string)($provider[$extra] ?? '')) === '') {
                return false;
            }
        }
        return true;
    }

    /**
     * رتبه‌بندی همه ارائه‌دهندگان برای یک وظیفه.
     *
     * @return array<int,array{provider:string,score:float,reasons:array,eligible:bool}>
     */
    public function rank(string $task): array
    {
        $taskDef = Registry::task($task);
        if ($taskDef === null) {
            return [];
        }
        $qualityWeight = (float)($taskDef['quality_weight'] ?? 0.5);
        $speedWeight = (float)($taskDef['speed_weight'] ?? 0.5);
        $requires = $taskDef['requires'] ?? [Registry::CAP_CHAT];

        $rows = [];
        foreach (Registry::providers() as $id => $meta) {
            $missingCaps = array_values(array_diff($requires, $meta['capabilities']));
            $configured = $this->isConfigured($id);
            $reasons = [];
            $score = 0.0;

            if (!$configured) {
                $reasons[] = 'کلید API تنظیم یا فعال نیست';
                $rows[] = ['provider' => $id, 'score' => 0.0, 'reasons' => $reasons, 'eligible' => false];
                continue;
            }
            if ($missingCaps) {
                $reasons[] = 'قابلیت لازم را ندارد: ' . implode('، ', $missingCaps);
                $rows[] = ['provider' => $id, 'score' => 0.0, 'reasons' => $reasons, 'eligible' => false];
                continue;
            }

            // ۱) پایه توانمندی
            $score += 50.0;
            $reasons[] = 'پشتیبانی کامل از قابلیت‌های لازم';

            // ۲) تخصص — وزن اصلی تصمیم‌گیری
            $affinity = (float)($meta['affinity'][$task] ?? 8);
            $score += $affinity;
            $reasons[] = 'امتیاز تخصصی ' . $affinity . '/۳۰ برای این وظیفه';
            if ($affinity >= 25) {
                // موتور تخصصی این حوزه — پاداش ویژه
                $score += 10.0;
                $reasons[] = 'موتور تخصصی این حوزه (+۱۰)';
            }

            // ۳) سلامت اندازه‌گیری‌شده
            $h = $this->health[$id] ?? null;
            if ($h !== null) {
                $ok = (int)($h['ok'] ?? 0) === 1;
                $latency = (int)($h['latency_ms'] ?? 0);
                if ($ok) {
                    $score += 10.0;
                    $speedScore = max(0, 30 - ($latency / 60.0));
                    $weighted = ($speedScore * $speedWeight) + (10.0 * $qualityWeight);
                    $score += $weighted;
                    $reasons[] = sprintf('تست سلامت موفق با تأخیر %dms (+%.1f)', $latency, 10.0 + $weighted);
                } else {
                    $score -= 40.0;
                    $reasons[] = 'آخرین تست سلامت ناموفق بود (−۴۰)';
                }
            } else {
                // تست‌نشده: نه پاداش نه جریمه — با تخصص رقابت می‌کند
                $score += 10.0;
                $reasons[] = 'هنوز تست نشده — امتیاز خنثی';
            }

            // ۴) کلاس سرعت
            $speedBonus = (6 - (int)$meta['speed_class']) * 2.5 * $speedWeight;
            $score += $speedBonus;

            // ۵) جریمه هزینه
            $score -= (int)$meta['cost_class'] * 1.5;
            $reasons[] = sprintf('کلاس سرعت %d و هزینه %d', $meta['speed_class'], $meta['cost_class']);

            $rows[] = ['provider' => $id, 'score' => round($score, 2), 'reasons' => $reasons, 'eligible' => true];
        }

        usort($rows, static function ($a, $b) {
            if ($a['eligible'] !== $b['eligible']) {
                return $a['eligible'] ? -1 : 1;
            }
            return $b['score'] <=> $a['score'];
        });

        return $rows;
    }

    /** بهترین ارائه‌دهنده برای یک وظیفه (با احترام به حالت دستی). */
    public function pick(string $task): ?string
    {
        $mode = (string)($this->config['routing']['mode'] ?? 'auto');
        $manual = (string)($this->config['routing']['map'][$task] ?? '');

        if ($manual !== '' && $this->isConfigured($manual)) {
            return $manual;
        }
        if ($mode === 'manual' && $manual === '') {
            return null; // کاربر خواسته فقط دستی باشد
        }
        foreach ($this->rank($task) as $row) {
            if ($row['eligible'] && $row['score'] > 0) {
                return $row['provider'];
            }
        }
        return null;
    }

    /** زنجیره پشتیبان: بهترین‌ها به ترتیب. */
    public function fallbackChain(string $task, int $limit = 3): array
    {
        $chain = [];
        foreach ($this->rank($task) as $row) {
            if ($row['eligible'] && $row['score'] > 0) {
                $chain[] = $row['provider'];
            }
            if (count($chain) >= $limit) {
                break;
            }
        }
        return $chain;
    }

    /**
     * مسیریابی خودکار کامل برای همه وظایف — همان دکمه «انتخاب هوشمند».
     *
     * @return array{map:array,fallback:array,report:array,changed:int}
     */
    public function autoRoute(): array
    {
        $oldMap = (array)($this->config['routing']['map'] ?? []);
        $map = [];
        $fallback = [];
        $report = [];
        $changed = 0;

        foreach (array_keys(Registry::tasks()) as $task) {
            $ranked = $this->rank($task);
            $eligible = array_values(array_filter($ranked, static function ($r) {
                return $r['eligible'] && $r['score'] > 0;
            }));

            if (!$eligible) {
                $report[] = [
                    'task' => $task,
                    'label' => Registry::task($task)['label'],
                    'provider' => null,
                    'score' => 0,
                    'note' => 'هیچ ارائه‌دهنده واجد شرایطی با کلید فعال وجود ندارد.',
                ];
                continue;
            }

            $best = $eligible[0];
            $map[$task] = $best['provider'];
            $fallback[$task] = array_slice(array_column($eligible, 'provider'), 1, 2);

            if (($oldMap[$task] ?? null) !== $best['provider']) {
                $changed++;
            }

            $report[] = [
                'task' => $task,
                'label' => Registry::task($task)['label'],
                'section' => Registry::task($task)['section'],
                'provider' => $best['provider'],
                'provider_label' => Registry::provider($best['provider'])['label'],
                'score' => $best['score'],
                'runner_up' => isset($eligible[1]) ? $eligible[1]['provider'] : null,
                'reasons' => $best['reasons'],
                'note' => Registry::provider($best['provider'])['notes'],
            ];
        }

        return ['map' => $map, 'fallback' => $fallback, 'report' => $report, 'changed' => $changed];
    }
}
