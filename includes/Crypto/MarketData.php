<?php
namespace Meelano\Crypto;

use Meelano\Ai\CurlTransport;
use Meelano\Ai\Transport;
use Throwable;

/**
 * لایهٔ دادهٔ بازار کریپتو — منابع عمومی (Binance + CoinGecko).
 *
 * اصل مهندسی: هر عدد با «منبع» برچسب می‌خورد و در خطا به منبع جایگزین یا
 * دادهٔ کش سقوط کنترل‌شده می‌کند؛ هرگز دادهٔ جعلی به‌عنوان واقعی جا زده نمی‌شود.
 *
 * نسخهٔ ۵: کش دو لایه — حافظهٔ درون‌درخواست (برای MTF که همان کندل را
 * چند بار می‌خواهد) + کش فایلی با TTL (برای احترام به rate-limit صرافی
 * روی هاست اشتراکی). کش فایلی با تنظیم `file_cache` فعال می‌شود.
 *
 * @author Milad Yaghoobi — Meelano Studio Design
 */
final class MarketData
{
    /** @var Transport */
    private $transport;
    /** @var array */
    private $cfg;
    /** @var array<string,array{ts:int,res:array}> کش درون‌درخواست */
    private $memo = [];

    public function __construct(?Transport $transport = null, array $cfg = [])
    {
        $this->transport = $transport ?: new CurlTransport();
        $this->cfg = $cfg;
    }

    /** فهرست ارزهای فعال با متریک‌های ۲۴ ساعته (برای رصد کل بازار). */
    public function tickers(int $limit = 100): array
    {
        $ttl = (int)($this->cfg['ticker_cache_ttl'] ?? 45);
        $res = $this->cached('https://api.binance.com/api/v3/ticker/24hr', $ttl);
        if ($res['status'] === 200 && is_array($res['body_parsed'])) {
            $out = [];
            foreach ($res['body_parsed'] as $row) {
                $symbol = (string)($row['symbol'] ?? '');
                if (substr($symbol, -4) !== 'USDT') {
                    continue;
                }
                $out[] = [
                    'symbol' => $symbol,
                    'base' => substr($symbol, 0, -4),
                    'last' => (float)($row['lastPrice'] ?? 0),
                    'change_pct' => (float)($row['priceChangePercent'] ?? 0),
                    'quote_volume' => (float)($row['quoteVolume'] ?? 0),
                    'high' => (float)($row['highPrice'] ?? 0),
                    'low' => (float)($row['lowPrice'] ?? 0),
                    'source' => 'binance',
                ];
                if (count($out) >= $limit * 3) {
                    break;
                }
            }
            usort($out, static function ($a, $b) {
                return $b['quote_volume'] <=> $a['quote_volume'];
            });
            return array_slice($out, 0, $limit);
        }

        // سقوط کنترل‌شده به CoinGecko
        $cgUrl = 'https://api.coingecko.com/api/v3/coins/markets?vs_currency=usd&order=volume_desc&per_page=' . $limit . '&page=1&price_change_percentage=24h';
        $cg = $this->cached($cgUrl, $ttl);
        if ($cg['status'] === 200 && is_array($cg['body_parsed'])) {
            $out = [];
            foreach ($cg['body_parsed'] as $row) {
                $out[] = [
                    'symbol' => strtoupper((string)($row['symbol'] ?? '')) . 'USDT',
                    'base' => strtoupper((string)($row['symbol'] ?? '')),
                    'last' => (float)($row['current_price'] ?? 0),
                    'change_pct' => (float)($row['price_change_percentage_24h'] ?? 0),
                    'quote_volume' => (float)($row['total_volume'] ?? 0),
                    'high' => (float)($row['high_24h'] ?? 0),
                    'low' => (float)($row['low_24h'] ?? 0),
                    'source' => 'coingecko',
                ];
            }
            return $out;
        }
        return [];
    }

    /**
     * کندل‌های یک جفت‌ارز — فقط کندل‌های «بسته‌شده».
     *
     * نسخهٔ ۵٫۱: کندل آخرِ در حال شکل‌گیری حذف می‌شود مگر صراحتاً خواسته شده
     * باشد؛ محاسبهٔ اندیکاتور روی کندلِ باز، منبع اصلی سیگنال کاذب است.
     *
     * @param bool $includeUnclosed اگر true باشد کندل جاریِ ناقص هم برمی‌گردد
     * @return array{ok:bool,candles:array<array{time:int,open:float,high:float,low:float,close:float,volume:float}>,source:string,error:?string,closed_only:bool,dropped_unclosed:int}
     */
    public function candles(string $symbol, string $interval = '1h', int $limit = 200, bool $includeUnclosed = false): array
    {
        $allowed = ['5m', '15m', '30m', '1h', '2h', '4h', '6h', '12h', '1d', '1w'];
        if (!in_array($interval, $allowed, true)) {
            return ['ok' => false, 'candles' => [], 'source' => 'none', 'error' => 'تایم‌فریم نامعتبر: ' . $interval];
        }
        $url = 'https://api.binance.com/api/v3/klines?symbol=' . urlencode($symbol)
            . '&interval=' . urlencode($interval) . '&limit=' . $limit;
        $ttl = (int)($this->cfg['candle_cache_ttl'] ?? 45);
        $res = $this->cached($url, $ttl);
        if ($res['status'] === 200 && is_array($res['body_parsed'])) {
            $candles = [];
            foreach ($res['body_parsed'] as $k) {
                if (!is_array($k) || count($k) < 6) {
                    continue;
                }
                $candles[] = [
                    'time' => (int)($k[0] / 1000),
                    'open' => (float)$k[1],
                    'high' => (float)$k[2],
                    'low' => (float)$k[3],
                    'close' => (float)$k[4],
                    'volume' => (float)$k[5],
                ];
            }
            if ($candles) {
                // حذف کندلِ در حال شکل‌گیری — تحلیل فقط روی دادهٔ قطعی
                $dropped = 0;
                if (!$includeUnclosed) {
                    $sec = self::intervalSeconds($interval);
                    $last = &$candles[count($candles) - 1];
                    if ((int)$last['time'] + $sec > time()) {
                        array_pop($candles);
                        $dropped = 1;
                        unset($last);
                    }
                }
                if ($candles) {
                    return [
                        'ok' => true, 'candles' => $candles, 'source' => 'binance',
                        'error' => null, 'closed_only' => !$includeUnclosed, 'dropped_unclosed' => $dropped,
                    ];
                }
            }
        }
        return ['ok' => false, 'candles' => [], 'source' => 'none',
            'error' => $res['error'] ?? 'داده کندل دریافت نشد', 'closed_only' => !$includeUnclosed, 'dropped_unclosed' => 0];
    }

    /** طول هر تایم‌فریم بر حسب ثانیه. */
    public static function intervalSeconds(string $interval): int
    {
        $map = [
            '5m' => 300, '15m' => 900, '30m' => 1800, '1h' => 3600, '2h' => 7200,
            '4h' => 14400, '6h' => 21600, '12h' => 43200, '1d' => 86400, '1w' => 604800,
        ];
        return $map[$interval] ?? 3600;
    }

    /* ── داده‌های مشتقات و سنتیمنت (نسخهٔ ۵٫۱) ─────────────────────────── */

    /**
     * نرخ فاندینگ همهٔ نمادها (Binance Futures) — نقشهٔ symbol => فاندینگ ۸ساعته (٪).
     * فاندینگِ افراطی = شلوغی یک‌طرفه = خطر اسکوییز.
     * @return array<string,float>
     */
    public function fundingRates(): array
    {
        $out = [];
        $res = $this->cached('https://fapi.binance.com/fapi/v1/premiumIndex', max(60, (int)($this->cfg['ticker_cache_ttl'] ?? 45)));
        if ($res['status'] === 200 && is_array($res['body_parsed'])) {
            foreach ($res['body_parsed'] as $row) {
                $symbol = (string)($row['symbol'] ?? '');
                $rate = (float)($row['lastFundingRate'] ?? 0);
                if ($symbol !== '' && $rate !== 0.0) {
                    $out[$symbol] = round($rate * 100, 5); // کسر → درصد
                }
            }
        }
        return $out;
    }

    /**
     * روند اوپن اینترست ۲۴ ساعتهٔ یک نماد (٪).
     * OI صعودی + قیمت صعودی = روند سالم؛ OI صعودی + قیمت نزولی = فشار فروش واقعی.
     */
    public function openInterestTrend(string $symbol): ?float
    {
        $url = 'https://fapi.binance.com/futures/data/openInterestHist?symbol=' . urlencode($symbol)
            . '&period=1h&limit=25';
        $res = $this->cached($url, max(120, (int)($this->cfg['ticker_cache_ttl'] ?? 45) * 2));
        if ($res['status'] === 200 && is_array($res['body_parsed']) && count($res['body_parsed']) >= 12) {
            $first = (float)($res['body_parsed'][0]['sumOpenInterest'] ?? 0);
            $last = (float)($res['body_parsed'][count($res['body_parsed']) - 1]['sumOpenInterest'] ?? 0);
            if ($first > 0) {
                return round(($last / $first - 1) * 100, 2);
            }
        }
        return null;
    }

    /**
     * شاخص ترس و طمع (alternative.me) — ۰ (ترس شدید) تا ۱۰۰ (طمع شدید).
     * @return array{value:int,label:string}|null
     */
    public function fearGreed(): ?array
    {
        $res = $this->cached('https://api.alternative.me/fng/?limit=1', 600);
        if ($res['status'] === 200 && is_array($res['body_parsed'])) {
            $row = $res['body_parsed']['data'][0] ?? null;
            if (is_array($row) && isset($row['value'])) {
                return [
                    'value' => (int)$row['value'],
                    'label' => (string)($row['value_classification'] ?? ''),
                ];
            }
        }
        return null;
    }

    /**
     * GET با کش دو لایه.
     * ابتدا حافظهٔ درون‌درخواست، سپس کش فایلی (در صورت فعال‌بودن)، سپس شبکه.
     */
    private function cached(string $url, int $ttl): array
    {
        $now = time();
        if (isset($this->memo[$url]) && ($now - $this->memo[$url]['ts']) < max(5, $ttl)) {
            return $this->memo[$url]['res'];
        }

        $res = null;
        if (!empty($this->cfg['file_cache'])) {
            $res = $this->fileCacheGet($url, $ttl);
        }
        if ($res === null) {
            $res = $this->get($url);
            $this->memo[$url] = ['ts' => $now, 'res' => $res];
            if (!empty($this->cfg['file_cache']) && $res['status'] === 200) {
                $this->fileCacheSet($url, $res, $ttl);
            }
        } else {
            $this->memo[$url] = ['ts' => $now, 'res' => $res];
        }
        return $res;
    }

    /** خواندن از کش فایلی. */
    private function fileCacheGet(string $url, int $ttl): ?array
    {
        $path = $this->cachePath($url);
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['ts'], $data['res'])) {
            return null;
        }
        if ((time() - (int)$data['ts']) >= max(5, $ttl)) {
            return null; // منقضی — دادهٔ کهنه هرگز به‌عنوان تازه جا زده نمی‌شود
        }
        return $data['res'];
    }

    /** نوشتن در کش فایلی. */
    private function fileCacheSet(string $url, array $res, int $ttl): void
    {
        $path = $this->cachePath($url);
        @file_put_contents($path, json_encode([
            'ts' => time(),
            'ttl' => $ttl,
            'res' => $res,
        ], JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    private function cachePath(string $url): string
    {
        $dir = MEELANO_CACHE;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir . '/mkt_' . sha1($url) . '.json';
    }

    private function get(string $url): array
    {
        try {
            $res = $this->transport->request('GET', $url, [
                'headers' => ['Accept' => 'application/json', 'User-Agent' => 'MeelanoCrypto/5.0'],
                'timeout' => (int)($this->cfg['timeout'] ?? 15),
            ]);
            return [
                'status' => $res['status'],
                'body_parsed' => json_decode($res['body'], true),
                'error' => $res['error'] !== '' ? $res['error'] : null,
            ];
        } catch (Throwable $e) {
            return ['status' => 0, 'body_parsed' => null, 'error' => $e->getMessage()];
        }
    }
}
