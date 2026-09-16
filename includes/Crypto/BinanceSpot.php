<?php
namespace Meelano\Crypto;

use Meelano\Ai\CurlTransport;
use Meelano\Ai\Transport;
use Throwable;

/**
 * Binance Spot — اصلی + Testnet.
 * Testnet: https://testnet.binance.vision (کلید رایگان از testnet.binance.vision)
 */
final class BinanceSpot implements Connector
{
    private const MAIN = 'https://api.binance.com';
    private const TESTNET = 'https://testnet.binance.vision';

    /** @var Transport */
    private $transport;
    /** @var string */
    private $apiKey;
    /** @var string */
    private $apiSecret;
    /** @var bool */
    private $testnet;
    /** @var int */
    private $recvWindow;
    /** @var array<string,float> */
    private $lotCache = [];

    public function __construct(?Transport $transport = null, ?array $cfg = null)
    {
        $cfg = $cfg ?? [];
        $this->transport = $transport ?: new \Meelano\Ai\CurlTransport();
        $this->apiKey = (string)($cfg['api_key'] ?? '');
        $this->apiSecret = (string)($cfg['api_secret'] ?? '');
        $this->testnet = ($cfg['mode'] ?? 'testnet') !== 'live';
        $this->recvWindow = (int)($cfg['receive_window'] ?? 5000);
    }

    public function name(): string
    {
        return 'binance' . ($this->testnet ? ':testnet' : ':live');
    }

    /** نشانی پایهٔ فعلی. */
    public function baseUrl(): string
    {
        return $this->testnet ? self::TESTNET : self::MAIN;
    }

    public function ping(): array
    {
        $t = m_microtime();
        try {
            $res = $this->request('GET', '/api/v3/ping');
            $ok = $res['status'] === 200;
            return ['ok' => $ok, 'latency_ms' => round((m_microtime() - $t) * 1000, 1),
                'error' => $ok ? null : ($res['error'] ?? 'پاسخ نامعتبر')];
        } catch (Throwable $e) {
            return ['ok' => false, 'latency_ms' => round((m_microtime() - $t) * 1000, 1), 'error' => $e->getMessage()];
        }
    }

    public function ticker(string $symbol): ?array
    {
        $res = $this->request('GET', '/api/v3/ticker/price?symbol=' . urlencode($symbol));
        if ($res['status'] === 200 && is_array($res['body_parsed'])) {
            $price = (float)($res['body_parsed']['price'] ?? 0);
            return $price > 0 ? ['price' => $price] : null;
        }
        return null;
    }

    public function balances(): array
    {
        if ($this->apiKey === '' || $this->apiSecret === '') {
            return ['ok' => false, 'error' => 'کلیدهای API تنظیم نشده‌اند.', 'balances' => []];
        }
        $res = $this->signed('GET', '/api/v3/account');
        if ($res['status'] === 200 && is_array($res['body_parsed'])) {
            $out = [];
            foreach ((array)($res['body_parsed']['balances'] ?? []) as $b) {
                $free = (float)($b['free'] ?? 0);
                $locked = (float)($b['locked'] ?? 0);
                if ($free + $locked > 0) {
                    $out[(string)$b['asset']] = $free + $locked;
                }
            }
            return ['ok' => true, 'error' => null, 'balances' => $out];
        }
        $msg = is_array($res['body_parsed']) ? (string)($res['body_parsed']['msg'] ?? '') : '';
        return ['ok' => false, 'error' => $msg !== '' ? $msg : ($res['error'] ?? 'خطای دریافت موجودی'), 'balances' => []];
    }

    public function lotStep(string $symbol): ?float
    {
        if (isset($this->lotCache[$symbol])) {
            return $this->lotCache[$symbol];
        }
        $res = $this->request('GET', '/api/v3/exchangeInfo?symbol=' . urlencode($symbol));
        if ($res['status'] === 200 && is_array($res['body_parsed'])) {
            foreach ((array)($res['body_parsed']['symbols'] ?? []) as $sym) {
                if (($sym['symbol'] ?? '') !== $symbol) { continue; }
                foreach ((array)($sym['filters'] ?? []) as $f) {
                    if (($f['filterType'] ?? '') === 'LOT_SIZE') {
                        $step = (float)($f['stepSize'] ?? 0);
                        $this->lotCache[$symbol] = $step > 0 ? $step : null;
                        return $this->lotCache[$symbol];
                    }
                }
            }
        }
        return null;
    }

    /**
     * سفارش بازار. اگر quoteUsdt > 0 باشد از quoteOrderQty استفاده می‌شود
     * (Binance خودش به‌اندازهٔ گام رُند می‌کند) — روش ترجیحی برای خرید.
     */
    public function marketOrder(string $symbol, string $side, float $quantity, float $quoteUsdt = 0.0): array
    {
        if ($this->apiKey === '' || $this->apiSecret === '') {
            return ['ok' => false, 'error' => 'کلیدهای API تنظیم نشده‌اند.'];
        }
        $params = ['symbol' => $symbol, 'side' => strtoupper($side), 'type' => 'MARKET'];
        if ($quoteUsdt > 0) {
            $params['quoteOrderQty'] = number_format($quoteUsdt, 2, '.', '');
        } else {
            $step = $this->lotStep($symbol);
            $qty = $step !== null ? self::roundToStep($quantity, $step) : $quantity;
            if ($qty <= 0) {
                return ['ok' => false, 'error' => 'مقدار سفارش کمتر از حداقل گام نماد است.'];
            }
            $params['quantity'] = self::qtyToString($qty, $step);
        }
        $res = $this->signed('POST', '/api/v3/order', $params);
        if ($res['status'] === 200 && is_array($res['body_parsed']) && !empty($res['body_parsed']['orderId'])) {
            return [
                'ok' => true,
                'id' => (string)$res['body_parsed']['orderId'],
                'executed_qty' => (float)($res['body_parsed']['executedQty'] ?? 0),
                'cumulative_quote' => (float)($res['body_parsed']['cummulativeQuoteQty'] ?? 0),
                'avg_price' => (float)($res['body_parsed']['cummulativeQuoteQty'] ?? 0) > 0
                    ? ((float)$res['body_parsed']['cummulativeQuoteQty'] / max(1e-12, (float)$res['body_parsed']['executedQty'] ?? 1))
                    : 0.0,
                'error' => null,
            ];
        }
        $msg = is_array($res['body_parsed']) ? (string)($res['body_parsed']['msg'] ?? '') : '';
        return ['ok' => false, 'error' => $msg !== '' ? $msg : ($res['error'] ?? 'سفارش ناموفق بود.')];
    }

    /* ── ابزارها ─────────────────────────────────────────────────────── */

    /** رُند کردن مقدار به مضرب گام (رو به پایین — هرگز بیشتر از موجودی سفارش نده). */
    public static function roundToStep(float $quantity, float $step): float
    {
        if ($step <= 0) { return $quantity; }
        return floor($quantity / $step) * $step;
    }

    /** قالب‌بندی مقدار بدون نماد علمی و صفر اضافی. */
    public static function qtyToString(float $qty, ?float $step): string
    {
        $decimals = 8;
        if ($step !== null && $step > 0) {
            $decimals = max(0, min(8, (int)round(-log10($step))));
        }
        return number_format($qty, $decimals, '.', '');
    }

    /** رشتهٔ امضا (برای تست). */
    public static function signQuery(string $query, string $secret): string
    {
        return hash_hmac('sha256', $query, $secret);
    }

    /* ── درخواست‌ها ──────────────────────────────────────────────────── */

    private function request(string $method, string $path, array $params = []): array
    {
        $url = $this->baseUrl() . $path;
        if ($params) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($params);
        }
        try {
            $res = $this->transport->request($method, $url, [
                'headers' => [
                    'Accept' => 'application/json',
                    'User-Agent' => 'MeelanoTrader/5.2',
                ] + ($this->apiKey !== '' ? ['X-MBX-APIKEY' => $this->apiKey] : []),
                'timeout' => 12,
            ]);
            return ['status' => $res['status'], 'body_parsed' => json_decode($res['body'], true), 'error' => $res['error'] !== '' ? $res['error'] : null];
        } catch (Throwable $e) {
            return ['status' => 0, 'body_parsed' => null, 'error' => $e->getMessage()];
        }
    }

    private function signed(string $method, string $path, array $params = []): array
    {
        $params['timestamp'] = (int)round(m_microtime() * 1000);
        $params['recvWindow'] = $this->recvWindow;
        $query = http_build_query($params);
        $sig = self::signQuery($query, $this->apiSecret);
        return $this->request($method, $path . '?' . $query . '&signature=' . $sig);
    }
}
