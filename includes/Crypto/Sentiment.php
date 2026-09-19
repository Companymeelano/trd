<?php
namespace Meelano\Crypto;

use Meelano\Ai\CurlTransport;
use Meelano\Ai\Transport;
use Throwable;

/**
 * لایهٔ هفتم — سنجش سنتیمنت آن‌چین بازار کریپتو (نسخهٔ ۵٫۷).
 *
 * سه منبع مستقل با «وزن‌دهی نرم» ترکیب می‌شوند و نتیجه یک نمرهٔ واحد در
 * بازهٔ ‎[−1, +1]‎ است:
 *   ۱) شاخص ترس و طمع (alternative.me)     — خلاف‌گردش کلاسیک
 *   ۲) مومنتوم کلان بازار (CoinGecko global) — تغییر ارزش کل بازار ۲۴ ساعته
 *   ۳) اخبار زندهٔ آن‌چین (RSS عمومی)        — وزن‌دهی کلیدواژه‌ای عنوان‌ها
 *
 * اصل مهندسی: هر منبع مستقل از دیگری خطا می‌خورد؛ اگر منبعی در دسترس نباشد
 * وزن او بین بقیه بازتوزیع می‌شود و اگر هیچ منبعی پاسخ ندهد، نمرهٔ «خنثی»
 * با پرچم ok=false برمی‌گردد تا هرگز جهت سیگنال را وارونه نکند.
 *
 * @author Milad Yaghoobi — Meelano Studio Design
 */
final class Sentiment
{
    /** @var Transport */
    private $http;

    /** عمر کش ثانیه */
    private $ttl;

    /** مسیر کش */
    private $cachePath;

    /** وزن منابع (جمع = ۱) */
    private const W_FG = 0.35;
    private const W_MARKET = 0.30;
    private const W_NEWS = 0.35;

    /** کلیدواژه‌های خبری وزن‌دار — مثبت = خوش‌بینی، منفی = ریسک */
    private const KEYWORDS = [
        // ریسک / منفی
        'hack' => -2.0, 'hacked' => -2.0, 'exploit' => -2.0, 'exploited' => -2.0,
        'breach' => -1.5, 'stolen' => -1.5, 'rug' => -1.5, 'liquidation' => -1.0,
        'liquidations' => -1.0, 'crash' => -1.5, 'plunge' => -1.0, 'dump' => -1.0,
        'ban' => -1.5, 'bans' => -1.5, 'lawsuit' => -1.0, 'sec sues' => -1.5,
        'fraud' => -1.5, 'insolvency' => -2.0, 'bankrupt' => -2.0, 'outflow' => -0.5,
        'selloff' => -1.0, 'fear' => -0.5, 'warns' => -0.5, 'warning' => -0.5,
        // خوش‌بینی / مثبت
        'etf' => 1.0, 'etfs' => 1.0, 'approval' => 1.5, 'approved' => 1.5,
        'adoption' => 1.0, 'inflow' => 1.0, 'inflows' => 1.0, 'all-time high' => 1.0,
        'record' => 0.75, 'surge' => 0.75, 'rally' => 0.75, 'bullish' => 1.0,
        'upgrade' => 0.5, 'partnership' => 0.75, 'institutional' => 0.75,
        'accumulation' => 0.75, 'halving' => 0.5, 'whale buying' => 1.0,
    ];

    /** فیدهای RSS عمومی (بدون کلید) */
    private const FEEDS = [
        'https://cointelegraph.com/rss',
        'https://www.coindesk.com/arc/outboundfeeds/rss/',
    ];

    /** @var array|null کش درون‌درخواست — یک fetch برای کل اسکن */
    private static $memoPulse = null;

    public function __construct(?Transport $http = null, int $ttl = 600, ?string $cachePath = null)
    {
        $this->http = $http ?: new CurlTransport();
        $this->ttl = max(60, $ttl);
        $this->cachePath = $cachePath ?? (MEELANO_CACHE . '/sentiment.json');
    }

    /**
     * نبض سنتیمنت بازار — با کش.
     *
     * @return array{ok:bool,score:float,label:string,color:string,fg:?array,
     *               market_24h:?float,news:array,sources:array,at:string,cached:bool}
     */
    public function pulse(bool $force = false): array
    {
        if (!$force) {
            $cached = $this->readCache();
            if ($cached !== null) {
                $cached['cached'] = true;
                return $cached;
            }
        }

        $fg = $this->fetchFearGreed();
        $market = $this->fetchMarketMomentum();
        $news = $this->fetchNews();

        // بازتوزیع وزن بین منابع در دسترس
        $parts = [];
        if ($fg !== null) {
            $parts[] = ['w' => self::W_FG, 'v' => (float)$fg['score']];
        }
        if ($market !== null) {
            $parts[] = ['w' => self::W_MARKET, 'v' => $market];
        }
        if ($news['score'] !== null) {
            $parts[] = ['w' => self::W_NEWS, 'v' => $news['score']];
        }

        $out = [
            'ok' => count($parts) > 0,
            'score' => 0.0,
            'label' => 'خنثی — داده کافی نیست',
            'color' => 'var(--gold-1)',
            'fg' => $fg,
            'market_24h' => $market,
            'news' => [
                'count' => $news['count'],
                'weighted' => $news['score'],
                'top' => $news['top'],
                'feeds_ok' => $news['feeds_ok'],
            ],
            'sources' => [
                'fear_greed' => $fg !== null,
                'market' => $market !== null,
                'news' => $news['score'] !== null,
            ],
            'at' => date('Y-m-d H:i:s'),
            'cached' => false,
        ];

        if ($out['ok']) {
            $sumW = 0.0;
            $acc = 0.0;
            foreach ($parts as $p) {
                $acc += $p['w'] * $p['v'];
                $sumW += $p['w'];
            }
            $score = $sumW > 0 ? $acc / $sumW : 0.0;
            $out['score'] = round(max(-1.0, min(1.0, $score)), 3);
            [$out['label'], $out['color']] = self::interpret($out['score']);
        }

        $this->writeCache($out);
        return $out;
    }

    /** نبض مشترک — در یک اسکن فقط یک‌بار به منابع ضربه می‌زند. */
    public static function sharedPulse(bool $force = false): array
    {
        if (self::$memoPulse === null || $force) {
            self::$memoPulse = (new self())->pulse($force);
        }
        return self::$memoPulse;
    }

    /**
     * سطح ریسک خبری ۰..۳ — برای فیلتر news_risk.
     * ۰ = داده نیست (خنثی) · ۱ = ملایم · ۲ = بالا · ۳ = بحران
     */
    public function riskLevel(array $pulse = null): int
    {
        $p = $pulse ?? $this->pulse();
        if (empty($p['ok'])) {
            return 0;
        }
        $s = (float)$p['score'];
        if ($s <= -0.6) {
            return 3;
        }
        if ($s <= -0.35) {
            return 2;
        }
        if ($s <= -0.15) {
            return 1;
        }
        return 0;
    }

    /* ── تفسیر نمره ──────────────────────────────────────────── */

    /** @return array{0:string,1:string} [برچسب، رنگ] */
    public static function interpret(float $score): array
    {
        if ($score <= -0.6) {
            return ['ترس شدید بازار', 'var(--rose)'];
        }
        if ($score <= -0.2) {
            return ['احتیاط — فشار فروشنده', 'var(--gold-1)'];
        }
        if ($score < 0.2) {
            return ['موجودیت متعادل', 'var(--gold-1)'];
        }
        if ($score < 0.6) {
            return ['خوش‌بینی اندک', 'var(--emerald)'];
        }
        return ['هیجان شدید بازار', 'var(--emerald)'];
    }

    /* ── منبع ۱: ترس و طمع ───────────────────────────────────── */

    /** @return array{value:int,label:string,score:float}|null */
    private function fetchFearGreed(): ?array
    {
        try {
            $res = $this->http->request('GET', 'https://api.alternative.me/fng/?limit=1', ['timeout' => 8]);
            if ((int)$res['status'] !== 200) {
                return null;
            }
            $data = json_decode((string)$res['body'], true);
            $row = is_array($data) ? ($data['data'][0] ?? null) : null;
            if (!is_array($row) || !isset($row['value'])) {
                return null;
            }
            $v = max(0, min(100, (int)$row['value']));
            return [
                'value' => $v,
                'label' => (string)($row['value_classification'] ?? ''),
                'score' => ($v - 50) / 50.0, // 0→−1 ، 50→0 ، 100→+1
            ];
        } catch (Throwable $e) {
            return null;
        }
    }

    /* ── منبع ۲: مومنتوم کلان ────────────────────────────────── */

    /** @return float|null نمره در ‎[−1, +1]‎ */
    private function fetchMarketMomentum(): ?float
    {
        try {
            $res = $this->http->request('GET', 'https://api.coingecko.com/api/v3/global', ['timeout' => 8]);
            if ((int)$res['status'] !== 200) {
                return null;
            }
            $data = json_decode((string)$res['body'], true);
            $chg = is_array($data)
                ? ($data['data']['market_cap_change_percentage_24h_usd'] ?? null)
                : null;
            if ($chg === null || !is_numeric($chg)) {
                return null;
            }
            // ±۱۰٪ حرکت روزانهٔ کل بازار = اشباع نمره
            return max(-1.0, min(1.0, ((float)$chg) / 10.0));
        } catch (Throwable $e) {
            return null;
        }
    }

    /* ── منبع ۳: اخبار آن‌چین ────────────────────────────────── */

    /**
     * وزن‌دهی کلیدواژه‌ای عنوان‌های ۲۴ ساعت گذشته.
     *
     * @return array{score:?float,count:int,top:array,feeds_ok:int}
     */
    private function fetchNews(): array
    {
        $titles = [];
        $seen = [];
        $feedsOk = 0;
        $cutoff = time() - 86400;

        foreach (self::FEEDS as $feed) {
            try {
                $res = $this->http->request('GET', $feed, ['timeout' => 8]);
                if ((int)$res['status'] !== 200) {
                    continue;
                }
                $items = self::parseRssItems((string)$res['body']);
                if (!$items) {
                    continue;
                }
                $feedsOk++;
                foreach ($items as $it) {
                    $ts = $it['ts'] ?? 0;
                    if ($ts > 0 && $ts < $cutoff) {
                        continue; // فقط پنجرهٔ ۲۴ ساعته
                    }
                    $key = mb_strtolower(trim((string)$it['title']));
                    if ($key === '' || isset($seen[$key])) {
                        continue; // سندیکت مشترک بین فیدها — یک‌بار بشمار
                    }
                    $seen[$key] = true;
                    $titles[] = $it;
                    if (count($titles) >= 60) {
                        break 2; // سقف نمونه برای کارایی
                    }
                }
            } catch (Throwable $e) {
                continue;
            }
        }

        if (!$titles) {
            return ['score' => null, 'count' => 0, 'top' => [], 'feeds_ok' => $feedsOk];
        }

        $acc = 0.0;
        $top = [];
        foreach ($titles as $t) {
            $w = self::headlineWeight((string)$t['title']);
            $acc += $w;
            if ($w !== 0.0) {
                $top[] = [
                    'title' => mb_substr((string)$t['title'], 0, 140),
                    'weight' => round($w, 2),
                    'at' => (string)($t['at'] ?? ''),
                ];
            }
        }
        usort($top, static function ($a, $b) {
            return abs($b['weight']) <=> abs($a['weight']);
        });

        // میانگین وزن سرخطی‌ها با اشباع نرم در ±۱
        $avg = $titles ? $acc / count($titles) : 0.0;
        return [
            'score' => round(max(-1.0, min(1.0, $avg)), 3),
            'count' => count($titles),
            'top' => array_slice($top, 0, 5),
            'feeds_ok' => $feedsOk,
        ];
    }

    /** وزن یک سرخطی — جمع وزن کلیدواژه‌های یافت‌شده، با سقف نرم. */
    public static function headlineWeight(string $title): float
    {
        $t = ' ' . mb_strtolower($title) . ' ';
        $w = 0.0;
        foreach (self::KEYWORDS as $kw => $kwW) {
            if (strpos($t, $kw) !== false) {
                $w += $kwW;
            }
        }
        // سقف نرم: هر سرخطی حداکثر ±۲.۵
        return max(-2.5, min(2.5, $w));
    }

    /** پارس سبک RSS بدون افزونهٔ XML — فقط title و pubDate. */
    public static function parseRssItems(string $xml): array
    {
        $out = [];
        if (!preg_match_all('#<item[^>]*>(.*?)</item>#is', $xml, $mItems)) {
            return $out;
        }
        foreach (array_slice($mItems[1], 0, 40) as $chunk) {
            $title = '';
            if (preg_match('#<title>(?:<!\[CDATA\[)?(.*?)(?:\]\]>)?</title>#is', $chunk, $m)) {
                $title = html_entity_decode(trim(strip_tags($m[1])), ENT_QUOTES | ENT_HTML5);
            }
            if ($title === '') {
                continue;
            }
            $ts = 0;
            $at = '';
            if (preg_match('#<pubDate>(.*?)</pubDate>#is', $chunk, $m)) {
                $at = trim($m[1]);
                $ts = strtotime($at) ?: 0;
            }
            $out[] = ['title' => $title, 'ts' => $ts, 'at' => $at];
        }
        return $out;
    }

    /* ── کش فایلی ────────────────────────────────────────────── */

    private function readCache(): ?array
    {
        try {
            if (!is_file($this->cachePath)) {
                return null;
            }
            $raw = @file_get_contents($this->cachePath);
            $data = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($data) || empty($data['at'])) {
                return null;
            }
            if ((time() - strtotime((string)$data['at'])) > $this->ttl) {
                return null;
            }
            return $data;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function writeCache(array $data): void
    {
        try {
            $dir = dirname($this->cachePath);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            @file_put_contents($this->cachePath, json_encode($data, JSON_UNESCAPED_UNICODE));
        } catch (Throwable $e) { /* کش اختیاری است */ }
    }
}
