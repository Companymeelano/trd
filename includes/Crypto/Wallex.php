<?php
namespace Meelano\Crypto;

use Meelano\Ai\CurlTransport;
use Meelano\Ai\Transport;
use Throwable;

/**
 * والکس — صرافی ایرانی (اسپات).
 * مستندات رسمی: https://developers.wallex.ir
 *
 * احراز هویت: هدر سادهٔ `x-api-key: <توکن>` (ساخت از پنل والکس ← مدیریت API).
 *
 * نکتهٔ معماری: بازارهای والکس تومانی (TMN) هستند؛ برای هم‌ارزی کامل با
 * موتور تحلیل (قیمت‌های USDT)، این کانکتور قیمت/مبلغ را با نرخ لحظه‌ای
 * USDTTMN به معادل USDT تبدیل می‌کند:
 *   ticker(BTCUSDT)  → قیمت BTCTMN ÷ قیمت USDTTMN  (USDT)
 *   marketOrder(خرید با X USDT) → X × نرخ → سفارش MKT در BTCTMN
 * بنابراین حسابداری AutoTrader در همهٔ صرافی‌ها بر مبنای USDT می‌ماند.
 *
 * @author Milad Yaghoobi — Meelano Studio Design
 */
final class Wallex implements Connector
{
    private const BASE = 'https://api.wallex.ir';

    /** @var Transport */
    private $http;
    /** @var string */
    private $apiKey;
    /** @var string */
    private $base;
    /** @var array|null کش بازارها */
    private $marketsCache;

    public function __construct(?Transport $transport = null, array $cfg = [])
    {
        $this->http = $transport ?: new CurlTransport();
        $this->apiKey = trim((string)($cfg['api_key'] ?? ''));
        $this->base = rtrim(trim((string)($cfg['api_base'] ?? self::BASE)), '/');
    }

    public function name(): string
    {
        return 'wallex';
    }

    public function baseUrl(): string
    {
        return $this->base;
    }

    /** BTCUSDT → BTCTMN. */
    public static function toTmnSymbol(string $symbol): string
    {
        $s = strtoupper(trim($symbol));
        if (strlen($s) > 4 && substr($s, -4) === 'USDT') {
            return substr($s, 0, -4) . 'TMN';
        }
        return $s . 'TMN';
    }

    public function ping(): array
    {
        $t = m_microtime();
        try {
            $res = $this->call('GET', '/v1/markets');
            $ok = $res['status'] === 200 && is_array($res['body_parsed'])
                && !empty($res['body_parsed']['result']);
            return ['ok' => $ok, 'latency_ms' => round((m_microtime() - $t) * 1000, 1),
                'error' => $ok ? null : ($res['error'] ?? 'پاسخ نامعتبر از والکس')];
        } catch (Throwable $e) {
            return ['ok' => false, 'latency_ms' => round((m_microtime() - $t) * 1000, 1), 'error' => $e->getMessage()];
        }
    }

    /** فهرست بازارها (کش درون‌شیء) — نگاشت symbol → دادهٔ بازار. */
    private function markets(): ?array
    {
        if ($this->marketsCache !== null) {
            return $this->marketsCache;
        }
        $res = $this->call('GET', '/v1/markets');
        if ($res['status'] === 200 && is_array($res['body_parsed']) && isset($res['body_parsed']['result'])) {
            $map = [];
            foreach ((array)$res['body_parsed']['result'] as $m) {
                if (is_array($m) && !empty($m['symbol'])) {
                    $map[strtoupper((string)$m['symbol'])] = $m;
                }
            }
            $this->marketsCache = $map;
            return $map;
        }
        return null;
    }

    /** نرخ لحظه‌ای USDT/TMN از خود والکس. */
    private function usdtTmnRate(): ?float
    {
        $mk = $this->markets();
        if ($mk === null) {
            return null;
        }
        $usdt = (array)($mk['USDTTMN'] ?? []);
        $r = (float)($usdt['lastPrice'] ?? 0);
        return $r > 0 ? $r : null;
    }

    public function ticker(string $symbol): ?array
    {
        $tmnSym = self::toTmnSymbol($symbol);
        $rate = $this->usdtTmnRate();
        if ($rate === null) {
            return null;
        }
        if ($tmnSym === 'USDTTMN') {
            return ['price' => 1.0, 'price_tmn' => $rate, 'usdt_tmn' => $rate];
        }
        $mk = $this->markets();
        $m = (array)($mk[$tmnSym] ?? []);
        $last = (float)($m['lastPrice'] ?? 0);
        if ($last <= 0) {
            return null;
        }
        return [
            'price' => round($last / $rate, 8),
            'price_tmn' => $last,
            'usdt_tmn' => $rate,
        ];
    }

    public function balances(): array
    {
        if ($this->apiKey === '') {
            return ['ok' => false, 'error' => 'توکن API والکس تنظیم نشده است.', 'balances' => []];
        }
        $res = $this->call('GET', '/v1/account/balances', true);
        if ($res['status'] === 200 && is_array($res['body_parsed'])
            && !empty($res['body_parsed']['result']) && is_array($res['body_parsed']['result'])) {
            $out = [];
            foreach ($res['body_parsed']['result'] as $asset => $b) {
                if (!is_array($b)) {
                    continue;
                }
                $v = (float)($b['balance'] ?? 0) + (float)($b['blocked'] ?? 0);
                if ($v > 0) {
                    $out[strtoupper((string)$asset)] = $v;
                }
            }
            // معادل USDT موجودی تومانی — برای نمایش هم‌ارز با سایر صرافی‌ها
            if (!isset($out['USDT']) && isset($out['TMN'])) {
                $rate = $this->usdtTmnRate();
                if ($rate !== null) {
                    $out['USDT'] = round($out['TMN'] / $rate, 2);
                }
            }
            return ['ok' => true, 'error' => null, 'balances' => $out];
        }
        $msg = is_array($res['body_parsed']) ? (string)($res['body_parsed']['message'] ?? '') : '';
        return ['ok' => false, 'error' => $msg !== '' ? $msg : ($res['error'] ?? 'خطای دریافت موجودی والکس'), 'balances' => []];
    }

    public function lotStep(string $symbol): ?float
    {
        return null;
    }

    /**
     * سفارش بازار در بازار TMN والکس.
     * BUY با quoteUsdt: تبدیل به تومان → محاسبهٔ مقدار از قیمت لحظه‌ای.
     * SELL با quantity: فروش حجم ارز مبنا.
     */
    public function marketOrder(string $symbol, string $side, float $quantity, float $quoteUsdt = 0.0): array
    {
        if ($this->apiKey === '') {
            return ['ok' => false, 'error' => 'توکن API والکس تنظیم نشده است.'];
        }
        $tmnSym = self::toTmnSymbol($symbol);
        $rate = $this->usdtTmnRate();
        if ($rate === null) {
            return ['ok' => false, 'error' => 'نرخ USDT/TMN والکس در دسترس نیست.'];
        }
        $mk = $this->markets();
        $m = (array)($mk[$tmnSym] ?? []);
        $last = (float)($m['lastPrice'] ?? 0);
        if ($last <= 0) {
            return ['ok' => false, 'error' => 'قیمت بازار ' . $tmnSym . ' در دسترس نیست.'];
        }

        $qty = $quantity;
        if ($side === 'BUY' && $qty <= 0 && $quoteUsdt > 0) {
            $qty = ($quoteUsdt * $rate) / $last;
        }
        if ($qty <= 0) {
            return ['ok' => false, 'error' => 'حجم سفارش نامعتبر است.'];
        }
        $qty = round($qty, 8);

        $res = $this->call('POST', '/v1/account/orders', true, [
            'symbol' => $tmnSym,
            'type' => 'MARKET',
            'side' => strtoupper($side) === 'SELL' ? 'SELL' : 'BUY',
            'quantity' => $qty,
        ]);

        if ($res['status'] === 200 && is_array($res['body_parsed'])
            && (bool)($res['body_parsed']['success'] ?? false)) {
            $o = (array)($res['body_parsed']['result'] ?? []);
            $executed = (float)($o['executedQty'] ?? 0);
            if ($executed <= 0) {
                $executed = (float)($o['origQty'] ?? 0);
            }
            $avgTmn = (float)($o['executedPrice'] ?? 0);
            if ($avgTmn <= 0 && $executed > 0 && (float)($o['executedSum'] ?? 0) > 0) {
                $avgTmn = (float)$o['executedSum'] / $executed;
            }
            if ($avgTmn <= 0) {
                $avgTmn = $last;
            }
            return [
                'ok' => true,
                'order_id' => isset($o['clientOrderId']) ? (string)$o['clientOrderId'] : null,
                'executed_qty' => $executed > 0 ? $executed : $qty,
                'avg_price' => round($avgTmn / $rate, 8), // معادل USDT برای حسابداری
                'avg_price_tmn' => $avgTmn,
                'usdt_tmn' => $rate,
                'raw_status' => (string)($o['status'] ?? ''),
            ];
        }
        $msg = is_array($res['body_parsed']) ? (string)($res['body_parsed']['message'] ?? '') : '';
        return ['ok' => false, 'error' => $msg !== '' ? $msg : ($res['error'] ?? 'سفارش والکس ناموفق بود')];
    }

    /* ═══ درخواست ════════════════════════════════════════════════════════ */

    private function call(string $method, string $path, bool $auth = false, ?array $jsonBody = null): array
    {
        $headers = ['Accept' => 'application/json'];
        if ($jsonBody !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        if ($auth) {
            $headers['x-api-key'] = $this->apiKey;
        }
        $options = ['headers' => $headers, 'timeout' => 15];
        if ($jsonBody !== null) {
            $options['body'] = (string)json_encode($jsonBody);
        }
        try {
            $res = $this->http->request($method, $this->base . $path, $options);
            return ['status' => $res['status'], 'body_parsed' => json_decode($res['body'], true),
                'error' => $res['error'] !== '' ? $res['error'] : null];
        } catch (Throwable $e) {
            return ['status' => 0, 'body_parsed' => null, 'error' => $e->getMessage()];
        }
    }
}
