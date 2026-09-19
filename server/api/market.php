<?php
declare(strict_types=1);

if (!defined('TRD_APP')) {
    header('HTTP/1.1 403 Forbidden');
    exit('Forbidden');
}

/**
 * دریافت داده‌های کندل از بایننس با تنظیمات پایدارتر برای هاست‌های ایران
 */
function get_klines_from_binance(string $symbol, string $interval, int $limit): array
{
    $allowed = [
        '15m' => '15m',
        '1h'  => '1h',
        '4h'  => '4h',
        '1d'  => '1d',
    ];

    if (!isset($allowed[$interval])) {
        throw new InvalidArgumentException('Invalid timeframe.');
    }

    $query = http_build_query([
        'symbol'   => $symbol,
        'interval' => $allowed[$interval],
        'limit'    => max(10, min(1000, $limit)),
    ]);

    $url = 'https://api.binance.com/api/v3/klines?' . $query;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20, // افزایش به ۲۰ ثانیه برای عبور از تاخیر شبکه
        CURLOPT_CONNECTTIMEOUT => 10, // افزایش به ۱۰ ثانیه برای اتصال اولیه
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36', // استفاده از User-Agent برای عبور از فیلترینگ‌های لایه ۷
    ]);
    
    $response = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($errno !== 0 || $response === false) {
        // لاگ کردن خطای شبکه برای دیباگ دقیق‌تر
        error_log("Binance API error (cURL $errno): $error");
        throw new RuntimeException('Unable to fetch market data: Connection failed. Check server outgoing firewall or Binance status.');
    }

    $data = json_decode((string)$response, true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid market data format received from Binance.');
    }

    return array_map(static function (array $k): array {
        return [
            'open_time' => (int)($k[0] / 1000),
            'open'      => (float)$k[1],
            'high'      => (float)$k[2],
            'low'       => (float)$k[3],
            'close'     => (float)$k[4],
            'volume'    => (float)$k[5],
        ];
    }, $data);
}

/**
 * دریافت داده با مکانیسم کش (Cache) برای جلوگیری از درخواست‌های مکرر
 */
function get_klines_cached(string $symbol, string $timeframe, int $limit = 300): array
{
    $key = md5("klines:{$symbol}:{$timeframe}:{$limit}");
    $file = CACHE_DIR . '/' . $key . '.json';

    // بررسی اعتبار کش
    if (is_file($file)) {
        $mtime = filemtime($file);
        if ($mtime !== false && (time() - $mtime) < CACHE_TTL) {
            $cached = json_decode((string)@file_get_contents($file), true);
            if (is_array($cached) && isset($cached['data'], $cached['source'])) {
                return $cached;
            }
        }
    }

    try {
        $klines = get_klines_from_binance($symbol, $timeframe, $limit);
    } catch (Throwable $e) {
        // در صورت خطا، سعی کن از آخرین کش موجود استفاده کنی
        if (is_file($file)) {
            $cached = json_decode((string)@file_get_contents($file), true);
            if (is_array($cached) && isset($cached['data'])) {
                return $cached;
            }
        }
        throw $e;
    }

    $cacheData = [
        'data'       => $klines,
        'source'     => 'binance',
        'fetched_at' => date('c'),
    ];

    @file_put_contents($file, json_encode($cacheData, JSON_UNESCAPED_SLASHES), LOCK_EX);

    return $cacheData;
}
