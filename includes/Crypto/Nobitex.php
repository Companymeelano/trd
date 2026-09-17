<?php
namespace Meelano\Crypto;

use Meelano\Ai\CurlTransport;
use Meelano\Ai\Transport;
use Throwable;

/**
 * نوبیتکس — صرافی ایرانی (اسپات).
 * مستندات رسمی: https://apidocs.nobitex.ir
 *
 * احراز هویت (کلید API جدید نوبیتکس):
 *   Nobitex-Key        = کلید عمومی
 *   Nobitex-Signature  = امضای Ed25519 (URL-safe Base64)
 *   Nobitex-Timestamp  = زمان Unix ثانیه‌ای (UTC)
 *   متن امضا: timestamp + METHOD + full_path + raw_body
 *   کلید خصوصی: ۳۲ بایت seed (یا ۶۴ بایت secret) در Base64-url
 *
 * نکات:
 *   - بازارهای usdt نوبیتکس (مثل btc-usdt) برای اجرا استفاده می‌شوند تا
 *     حسابداری پوزیشن‌ها دقیقاً بر مبنای USDT بماند (هم‌ارز موتور تحلیل).
 *   - قیمت بازارهای ریالی در API نوبیتکس «ریال» است؛ ما از بازار usdt
 *     استفاده می‌کنیم تا این تبدیل لازم نباشد.
 *   - User-Agent الگوی TraderBot/... طبق توصیهٔ مستندات ارسال می‌شود.
 *   - امضا با libsodium (افزونهٔ پیش‌فرض PHP 7.2+) انجام می‌شود.
 *
 * @author Milad Yaghoobi — Meelano Studio Design
 */
final class Nobitex implements Connector
{
    private const BASE = 'https://apiv2.nobitex.ir';

    /** @var Transport */
    private $http;
    /** @var string */
    private $apiKey;
    /** @var string */
    private $apiSecret;
    /** @var string */
    private $base;
    /** @var string */
    private $ua;
    /** @var callable|null فقط برای تست (وقتی libsodium در محیط تست نیست) */
    private $signer;

    public function __construct(?Transport $transport = null, array $cfg = [])
    {
        $this->http = $transport ?: new CurlTransport();
        $this->apiKey = trim((string)($cfg['api_key'] ?? ''));
        $this->apiSecret = trim((string)($cfg['api_secret'] ?? ''));
        $this->base = rtrim(trim((string)($cfg['api_base'] ?? self::BASE)), '/');
        $this->ua = trim((string)($cfg['user_agent'] ?? 'TraderBot/Meelano-5.5'));
        if (!empty($cfg['signer']) && is_callable($cfg['signer'])) {
            $this->signer = $cfg['signer'];
        }
    }

    public function name(): string
    {
        return 'nobitex';
    }

    /** نشانی پایهٔ فعلی. */
    public function baseUrl(): string
    {
        return $this->base;
    }

    /** BTCUSDT → ['btc','usdt'] — بازارهای usdt نوبیتکس. */
    public static function splitSymbol(string $symbol): array
    {
        $s = strtoupper(trim($symbol));
        if (strlen($s) > 4 && substr($s, -4) === 'USDT') {
            return [strtolower(substr($s, 0, -4)), 'usdt'];
        }
        return [strtolower($s), 'usdt'];
    }

    public function ping(): array
    {
        $t = m_microtime();
        try {
            $res = $this->call('GET', '/market/stats?srcCurrency=btc&dstCurrency=usdt');
            $ok = $res['status'] === 200 && is_array($res['body_parsed'])
                && ($res['body_parsed']['status'] ?? '') === 'ok';
            return ['ok' => $ok, 'latency_ms' => round((m_microtime() - $t) * 1000, 1),
                'error' => $ok ? null : ($res['error'] ?? 'پاسخ نامعتبر از نوبیتکس')];
        } catch (Throwable $e) {
            return ['ok' => false, 'latency_ms' => round((m_microtime() - $t) * 1000, 1), 'error' => $e->getMessage()];
        }
    }

    public function ticker(string $symbol): ?array
    {
        [$src, $dst] = self::splitSymbol($symbol);
        $res = $this->call('GET', '/market/stats?srcCurrency=' . rawurlencode($src) . '&dstCurrency=' . rawurlencode($dst));
        if ($res['status'] === 200 && is_array($res['body_parsed'])) {
            $stat = (array)($res['body_parsed']['stats'][$src . '-' . $dst] ?? []);
            $price = (float)($stat['latest'] ?? 0);
            if ($price <= 0) {
                $price = (float)($stat['bestBuy'] ?? 0); // جایگزین
            }
            if ($price <= 0) {
                return null;
            }
            return [
                'price' => $price,
                'best_ask' => (float)($stat['bestBuy'] ?? 0) ?: $price,  // بهترین قیمت خرید از صرافی (ask)
                'best_bid' => (float)($stat['bestSell'] ?? 0) ?: $price, // بهترین قیمت فروش به صرافی (bid)
                'change_24h' => (float)($stat['dayChange'] ?? 0),
                'high_24h' => (float)($stat['dayHigh'] ?? 0),
                'low_24h' => (float)($stat['dayLow'] ?? 0),
            ];
        }
        return null;
    }

    public function balances(): array
    {
        if ($this->apiKey === '' || $this->apiSecret === '') {
            return ['ok' => false, 'error' => 'کلید عمومی و خصوصی نوبیتکس تنظیم نشده‌اند.', 'balances' => []];
        }
        $res = $this->call('POST', '/users/wallets/list', null, true);
        if ($res['status'] === 200 && is_array($res['body_parsed'])
            && ($res['body_parsed']['status'] ?? '') === 'ok') {
            $out = [];
            foreach ((array)($res['body_parsed']['wallets'] ?? []) as $w) {
                $free = (float)($w['activeBalance'] ?? 0);
                $locked = (float)($w['blockedBalance'] ?? 0);
                if ($free + $locked > 0) {
                    $out[strtoupper((string)($w['currency'] ?? ''))] = $free + $locked;
                }
            }
            return ['ok' => true, 'error' => null, 'balances' => $out];
        }
        $msg = is_array($res['body_parsed']) ? (string)($res['body_parsed']['message'] ?? '') : '';
        return ['ok' => false, 'error' => $msg !== '' ? $msg : ($res['error'] ?? 'خطای دریافت موجودی نوبیتکس'), 'balances' => []];
    }

    public function lotStep(string $symbol): ?float
    {
        return null; // فرادادهٔ گام در API عمومی نوبیتکس نیست؛ خطای صرافی شفاف برمی‌گردد
    }

    /**
     * سفارش بازار. amount همیشه بر مبنای ارز مبنا (src) است؛ برای خرید
     * با مبلغ، مقدار از قیمت لحظه‌ای محاسبه می‌شود.
     */
    public function marketOrder(string $symbol, string $side, float $quantity, float $quoteUsdt = 0.0): array
    {
        if ($this->apiKey === '' || $this->apiSecret === '') {
            return ['ok' => false, 'error' => 'کلید عمومی و خصوصی نوبیتکس تنظیم نشده‌اند.'];
        }
        [$src, $dst] = self::splitSymbol($symbol);
        $amount = $quantity;
        if ($side === 'BUY' && $amount <= 0 && $quoteUsdt > 0) {
            $tk = $this->ticker($symbol);
            $price = $tk['price'] ?? 0;
            if ($price <= 0) {
                return ['ok' => false, 'error' => 'قیمت لحظه‌ای برای محاسبهٔ حجم سفارش در دسترس نیست.'];
            }
            $amount = $quoteUsdt / $price;
        }
        if ($amount <= 0) {
            return ['ok' => false, 'error' => 'حجم سفارش نامعتبر است.'];
        }
        $amount = round($amount, 8);
        $res = $this->call('POST', '/market/orders/add', [
            'type' => strtolower($side) === 'sell' ? 'sell' : 'buy',
            'execution' => 'market',
            'srcCurrency' => $src,
            'dstCurrency' => $dst,
            'amount' => self::amountString($amount),
        ], true);
        if ($res['status'] === 200 && is_array($res['body_parsed'])
            && ($res['body_parsed']['status'] ?? '') === 'ok') {
            $o = (array)($res['body_parsed']['order'] ?? []);
            $matched = (float)($o['matchedAmount'] ?? 0);
            $avg = (float)($o['averagePrice'] ?? 0);
            if ($avg <= 0 && $matched > 0 && (float)($o['totalPrice'] ?? 0) > 0) {
                $avg = (float)$o['totalPrice'] / $matched;
            }
            if ($avg <= 0) {
                $tk = $this->ticker($symbol);
                $avg = $tk['price'] ?? 0;
            }
            return [
                'ok' => true,
                'order_id' => isset($o['id']) ? (int)$o['id'] : null,
                'executed_qty' => $matched > 0 ? $matched : $amount,
                'avg_price' => $avg,
                'raw_status' => (string)($o['status'] ?? ''),
            ];
        }
        $msg = is_array($res['body_parsed'])
            ? (string)($res['body_parsed']['message'] ?? ($res['body_parsed']['status'] ?? '')) : '';
        return ['ok' => false, 'error' => $msg !== '' && $msg !== 'ok' ? $msg : ($res['error'] ?? 'سفارش نوبیتکس ناموفق بود')];
    }

    /* ═══ امضا و درخواست ═════════════════════════════════════════════════ */

    /** Base64 استاندارد → URL-safe بدون padding. */
    public static function b64urlEncode(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    /** URL-safe Base64 (با/بدون padding) → باینری. */
    public static function b64urlDecode(string $s): ?string
    {
        $s = strtr(trim($s), '-_', '+/');
        $pad = strlen($s) % 4;
        if ($pad === 2 || $pad === 3) {
            $s .= str_repeat('=', 4 - $pad);
        } elseif ($pad === 1) {
            return null;
        }
        $bin = base64_decode($s, true);
        return $bin === false ? null : $bin;
    }

    /** فقط برای تست واحد — امضای یک رشتهٔ مشخص (تولید هرگز صدا زده نمی‌شود). */
    public function signStringForTest(string $payload): ?string
    {
        return $this->edSign($payload);
    }

    private function edSign(string $payload): ?string
    {
        if ($this->signer !== null) {
            return (string)call_user_func($this->signer, $payload); // فقط تزریق تست — هرگز از Config نمی‌آید
        }
        if (!function_exists('sodium_crypto_sign_detached')) {
            return null;
        }
        $seed = self::b64urlDecode($this->apiSecret);
        if ($seed === null) {
            return null;
        }
        if (strlen($seed) === 64) {
            $sk = $seed;
        } elseif (strlen($seed) === 32) {
            $kp = sodium_crypto_sign_seed_keypair($seed);
            $sk = sodium_crypto_sign_secretkey($kp);
        } else {
            return null;
        }
        return self::b64urlEncode(sodium_crypto_sign_detached($payload, $sk));
    }

    /** سه هدر احراز هویت نوبیتکس. @return array|null null = خطای کلید/امضا */
    private function authHeaders(string $method, string $path, string $rawBody): ?array
    {
        $ts = (string)time(); // Unix UTC
        $sig = $this->edSign($ts . strtoupper($method) . $path . $rawBody);
        if ($sig === null) {
            return null;
        }
        return [
            'Nobitex-Key' => $this->apiKey,
            'Nobitex-Signature' => $sig,
            'Nobitex-Timestamp' => $ts,
        ];
    }

    /**
     * درخواست با مسیر کامل (path + query) — دقیقاً همان چیزی که امضا می‌شود.
     * @param array|null $jsonBody null = بدون بدنه (امضای بدنهٔ خالی)
     */
    private function call(string $method, string $path, ?array $jsonBody = null, bool $auth = false): array
    {
        $raw = $jsonBody === null ? '' : (string)json_encode($jsonBody);
        $headers = [
            'Accept' => 'application/json',
            'User-Agent' => $this->ua,
        ];
        if ($jsonBody !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        if ($auth) {
            if ($this->apiKey === '' || $this->apiSecret === '') {
                return ['status' => 0, 'body_parsed' => null, 'error' => 'کلیدهای نوبیتکس تنظیم نشده‌اند.'];
            }
            $h = $this->authHeaders($method, $path, $raw);
            if ($h === null) {
                return ['status' => 0, 'body_parsed' => null,
                    'error' => 'امضای Ed25519 ممکن نشد — کلید خصوصی نامعتبر است یا افزونهٔ libsodium روی سرور فعال نیست (PHP 7.2+ پیش‌فرض دارد).'];
            }
            $headers += $h;
        }
        $options = ['headers' => $headers, 'timeout' => 15];
        if ($jsonBody !== null) {
            $options['body'] = $raw;
        }
        try {
            $res = $this->http->request($method, $this->base . $path, $options);
            return ['status' => $res['status'], 'body_parsed' => json_decode($res['body'], true),
                'error' => $res['error'] !== '' ? $res['error'] : null];
        } catch (Throwable $e) {
            return ['status' => 0, 'body_parsed' => null, 'error' => $e->getMessage()];
        }
    }

    /** مقدار حجم سفارش به رشتهٔ تمیز (بدون نماد علمی/صفر اضافی). */
    private static function amountString(float $v): string
    {
        $s = rtrim(rtrim(number_format($v, 8, '.', ''), '0'), '.');
        return $s === '' ? '0' : $s;
    }
}
