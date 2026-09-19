<?php
namespace Meelano\Crypto;

use Meelano\Ai\CurlTransport;
use Meelano\Ai\Transport;
use Meelano\Config;
use Meelano\Crypto\Notify\Bale;
use Meelano\Crypto\Notify\Channel;
use Meelano\Crypto\Notify\Rubika;
use Meelano\Crypto\Notify\Sms;
use Meelano\Crypto\Notify\Telegram;
use Meelano\Crypto\Notify\WhatsApp;
use Meelano\Db;
use Meelano\Security;
use Throwable;

/**
 * سامانهٔ اطلاع‌رسانی چندکاناله (نسخهٔ ۵٫۳).
 *
 * رویدادها:
 *   signal       سیگنال جدید موتور (فیلتر درجه + ارسال دسته‌ای)
 *   trade_opened پوزیشن باز شد (خودکار/دستی)
 *   trade_closed پوزیشن بسته شد (استاپ/TP1-3/سیگنال مخالف/دستی) با PnL دقیق
 *   wallet_report گزارش کیف: موجودی، سود/زیان کل و باز، وین‌ریت
 *
 * کانال‌ها: تلگرام · بله · روبیکا · واتساپ (Cloud API) · پیامک (کاوه‌نگار)
 *
 * اصول: هر کانال در try/catch جدا (خطای یک کانال بقیه را نمی‌شکند)؛
 * هر ارسال در notify_log ثبت می‌شود؛ throttle ضداسپم به‌ازای (کانال × رویداد)؛
 * توکن‌ها فقط در Config رمزنگاری‌شده — در status همیشه ماسک.
 *
 * @author Milad Yaghoobi — Meelano Studio Design
 */
final class Notifier
{
    public const EVENTS = ['signal', 'trade_opened', 'trade_closed', 'wallet_report'];

    private const TIERS = ['C' => 1, 'B' => 2, 'A' => 3, 'A+' => 4];
    private const SECRET_FIELDS = ['bot_token', 'access_token', 'api_key'];

    /** @var Db */
    private $db;
    /** @var Transport */
    private $http;
    /** @var array */
    private $cfg;
    /** @var bool|null */
    private $logReady = null;

    public function __construct(Db $db, ?Transport $transport = null, ?array $cfg = null)
    {
        $this->db = $db;
        $this->http = $transport ?: new CurlTransport();
        $this->cfg = $cfg ?? (array)Config::get('notify', []);
    }

    /* ═══ رجیستری کانال‌ها ═══════════════════════════════════════════════ */

    /** فرادادهٔ کانال‌ها برای UI (فیلدها، راهنما، رویدادهای پیش‌فرض). */
    public static function channelMeta(): array
    {
        return [
            'telegram' => [
                'label' => 'تلگرام', 'icon' => 'fa-solid fa-paper-plane', 'color' => '#229ED9',
                'help' => 'از @BotFather ربات بسازید، توکن را اینجا بگذارید و ربات را با حق «ارسال پیام» عضو کانال/گروه کنید. مقصد می‌تواند -100… (کانال)، شناسهٔ گروه یا chat_id عددی باشد.',
                'fields' => [
                    ['id' => 'bot_token', 'label' => 'توکن ربات (BotFather)', 'secret' => true, 'ph' => '123456789:AAF…'],
                    ['id' => 'chat_id', 'label' => 'مقصد (کانال/گروه/چت)', 'secret' => false, 'ph' => '-1001234567890 یا @mychannel'],
                    ['id' => 'api_base', 'label' => 'نشانی API (اختیاری)', 'secret' => false, 'ph' => 'https://api.telegram.org'],
                ],
                'default_events' => ['signal' => true, 'trade_opened' => true, 'trade_closed' => true, 'wallet_report' => true],
            ],
            'bale' => [
                'label' => 'بله', 'icon' => 'fa-solid fa-comment-dots', 'color' => '#41B35D',
                'help' => 'پیام‌رسان بله از Bot API سازگار با تلگرام استفاده می‌کند. از ربات BotFather بله توکن بگیرید و ربات را به کانال اضافه کنید.',
                'fields' => [
                    ['id' => 'bot_token', 'label' => 'توکن ربات بله', 'secret' => true, 'ph' => 'توکن از BotFather بله'],
                    ['id' => 'chat_id', 'label' => 'مقصد (کانال/گروه)', 'secret' => false, 'ph' => 'شناسهٔ کانال یا گروه'],
                    ['id' => 'api_base', 'label' => 'نشانی API (اختیاری)', 'secret' => false, 'ph' => 'https://tapi.bale.ai'],
                ],
                'default_events' => ['signal' => true, 'trade_opened' => true, 'trade_closed' => true, 'wallet_report' => true],
            ],
            'rubika' => [
                'label' => 'روبیکا', 'icon' => 'fa-solid fa-comments', 'color' => '#E0407A',
                'help' => 'ربات روبیکا بسازید، توکن و شناسهٔ مقصد (channel_id/chat_id) را وارد کنید. اگر نشانی پیش‌فرض با نسخهٔ فعلی Bot API روبیکا تفاوت داشت، همان را در فیلد نشانی API اصلاح کنید.',
                'fields' => [
                    ['id' => 'bot_token', 'label' => 'توکن ربات روبیکا', 'secret' => true, 'ph' => 'توکن ربات'],
                    ['id' => 'chat_id', 'label' => 'مقصد (شناسهٔ کانال/چت)', 'secret' => false, 'ph' => 'شناسهٔ مقصد'],
                    ['id' => 'api_base', 'label' => 'نشانی API (اختیاری)', 'secret' => false, 'ph' => 'https://botapi.rubika.ir'],
                ],
                'default_events' => ['signal' => true, 'trade_opened' => true, 'trade_closed' => true, 'wallet_report' => true],
            ],
            'whatsapp' => [
                'label' => 'واتساپ', 'icon' => 'fa-solid fa-phone', 'color' => '#25D366',
                'help' => 'WhatsApp Business Cloud API رسمی متا: در developers.facebook.com یک اپ بسازید، شمارهٔ کسب‌وکار را ثبت کنید و phone_number_id + توکن دسترسی دائمی بسازید. گیرنده باید داخل پنجرهٔ ۲۴ ساعته باشد.',
                'fields' => [
                    ['id' => 'phone_number_id', 'label' => 'شناسهٔ شمارهٔ کسب‌وکار', 'secret' => false, 'ph' => 'مثال: 109876543210987'],
                    ['id' => 'access_token', 'label' => 'توکن دسترسی دائمی', 'secret' => true, 'ph' => 'EAAG…'],
                    ['id' => 'to', 'label' => 'شمارهٔ گیرنده (با کد کشور)', 'secret' => false, 'ph' => '989123456789'],
                    ['id' => 'api_version', 'label' => 'نسخهٔ API', 'secret' => false, 'ph' => 'v21.0'],
                    ['id' => 'api_base', 'label' => 'نشانی API (اختیاری)', 'secret' => false, 'ph' => 'https://graph.facebook.com'],
                ],
                'default_events' => ['signal' => true, 'trade_opened' => true, 'trade_closed' => true, 'wallet_report' => true],
            ],
            'sms' => [
                'label' => 'پیامک (کاوه‌نگار)', 'icon' => 'fa-solid fa-mobile-screen', 'color' => '#F59E0B',
                'help' => 'از پنل kavenegar.com کلید API بگیرید. برای پیامک نسخهٔ فشردهٔ پیام ارسال می‌شود (طول/هزینهٔ کمتر). پیش‌فرض فقط «بسته‌شدن پوزیشن» فعال است.',
                'fields' => [
                    ['id' => 'api_key', 'label' => 'کلید API کاوه‌نگار', 'secret' => true, 'ph' => 'کلید ۳۲ تا ۴۵ کاراکتری'],
                    ['id' => 'receptor', 'label' => 'شمارهٔ گیرنده', 'secret' => false, 'ph' => '09123456789'],
                    ['id' => 'sender', 'label' => 'خط ارسال (اختیاری)', 'secret' => false, 'ph' => 'شمارهٔ خط'],
                    ['id' => 'api_base', 'label' => 'نشانی API (اختیاری)', 'secret' => false, 'ph' => 'https://api.kavenegar.com'],
                ],
                'default_events' => ['signal' => false, 'trade_opened' => false, 'trade_closed' => true, 'wallet_report' => false],
            ],
        ];
    }

    /** @return array<string,string> شناسهٔ کانال => کلاس درایور */
    private static function channelClasses(): array
    {
        return [
            'telegram' => Telegram::class,
            'bale' => Bale::class,
            'rubika' => Rubika::class,
            'whatsapp' => WhatsApp::class,
            'sms' => Sms::class,
        ];
    }

    /* ═══ پیکربندی ═══════════════════════════════════════════════════════ */

    /** پیکربندی نرمال‌شده (پیش‌فرض‌ها همیشه حاضرند). */
    public function config(): array
    {
        $out = [
            'enabled' => (bool)($this->cfg['enabled'] ?? false),
            'min_tier' => (string)($this->cfg['min_tier'] ?? 'B'),
            'throttle_sec' => max(0, (int)($this->cfg['throttle_sec'] ?? 45)),
            'report_on_close' => (bool)($this->cfg['report_on_close'] ?? false),
            'channels' => [],
        ];
        foreach (self::channelMeta() as $id => $meta) {
            $ch = (array)($this->cfg['channels'][$id] ?? []);
            $events = [];
            foreach (self::EVENTS as $e) {
                $events[$e] = (bool)($ch['events'][$e] ?? $meta['default_events'][$e]);
            }
            $clean = ['enabled' => (bool)($ch['enabled'] ?? false), 'events' => $events];
            foreach ($meta['fields'] as $f) {
                $clean[$f['id']] = trim((string)($ch[$f['id']] ?? ''));
            }
            $out['channels'][$id] = $clean;
        }
        return $out;
    }

    /** ذخیرهٔ پیکربندی — راز خالی = حفظ راز قبلی. */
    public function save(array $in): array
    {
        $cur = $this->config();
        $next = $cur;
        if (array_key_exists('enabled', $in)) {
            $next['enabled'] = (bool)$in['enabled'];
        }
        if (isset($in['min_tier']) && isset(self::TIERS[(string)$in['min_tier']])) {
            $next['min_tier'] = (string)$in['min_tier'];
        }
        if (isset($in['throttle_sec'])) {
            $next['throttle_sec'] = max(0, min(3600, (int)$in['throttle_sec']));
        }
        if (array_key_exists('report_on_close', $in)) {
            $next['report_on_close'] = (bool)$in['report_on_close'];
        }
        $channelsIn = (array)($in['channels'] ?? []);
        foreach (self::channelMeta() as $id => $meta) {
            if (!isset($channelsIn[$id]) || !is_array($channelsIn[$id])) {
                continue;
            }
            $cin = $channelsIn[$id];
            $ch = $cur['channels'][$id];
            if (array_key_exists('enabled', $cin)) {
                $ch['enabled'] = (bool)$cin['enabled'];
            }
            foreach ($meta['fields'] as $f) {
                $fid = $f['id'];
                if (!array_key_exists($fid, $cin)) {
                    continue;
                }
                $v = trim((string)$cin[$fid]);
                if ($v === '' && in_array($fid, self::SECRET_FIELDS, true) && $ch[$fid] !== '') {
                    continue; // راز جدید خالی → مقدار قبلی حفظ شود
                }
                if ($fid === 'api_base' && $v !== '' && !preg_match('#^https?://#i', $v)) {
                    return ['ok' => false, 'error' => $meta['label'] . ': نشانی API باید با http:// یا https:// شروع شود.'];
                }
                $ch[$fid] = $v;
            }
            if (isset($cin['events']) && is_array($cin['events'])) {
                foreach (self::EVENTS as $e) {
                    if (array_key_exists($e, $cin['events'])) {
                        $ch['events'][$e] = (bool)$cin['events'][$e];
                    }
                }
            }
            $next['channels'][$id] = $ch;
        }
        Config::set('notify', $next);
        Config::save();
        try {
            Security::audit('notify', 'save', 'پیکربندی اطلاع‌رسانی ذخیره شد');
        } catch (Throwable $e) { /* ممیزی اختیاری است */ }
        $this->cfg = $next;
        return ['ok' => true];
    }

    /** وضعیت کامل برای UI — بدون هیچ راز آشکار. */
    public function status(): array
    {
        $cfg = $this->config();
        $lastBy = $this->lastByChannel();
        $channels = [];
        foreach (self::channelMeta() as $id => $meta) {
            $ch = $cfg['channels'][$id];
            $fields = [];
            foreach ($meta['fields'] as $f) {
                $val = (string)($ch[$f['id']] ?? '');
                if (in_array($f['id'], self::SECRET_FIELDS, true) && $val !== '') {
                    $fields[$f['id']] = ['value' => '', 'set' => true, 'masked' => m_mask_secret($val)];
                } else {
                    $fields[$f['id']] = ['value' => $val, 'set' => $val !== ''];
                }
            }
            $driver = $this->driver($id, $ch);
            $channels[$id] = [
                'label' => $meta['label'],
                'icon' => $meta['icon'],
                'color' => $meta['color'],
                'enabled' => (bool)$ch['enabled'],
                'configured' => $driver !== null && $driver->configured(),
                'events' => $ch['events'],
                'fields' => $fields,
                'last' => isset($lastBy[$id]) ? $lastBy[$id] : null,
            ];
        }
        return [
            'ok' => true,
            'enabled' => $cfg['enabled'],
            'min_tier' => $cfg['min_tier'],
            'throttle_sec' => $cfg['throttle_sec'],
            'report_on_close' => $cfg['report_on_close'],
            'channels' => $channels,
            'log' => $this->recentLog(20),
        ];
    }

    /* ═══ ارسال ══════════════════════════════════════════════════════════ */

    /**
     * رویداد را به همهٔ کانال‌های فعالِ مشترک می‌فرستد.
     * @param bool $force نادیده‌گرفتن کلید اصلی فعال‌سازی (ارسال دستی گزارش)
     */
    public function dispatch(string $event, array $payload, bool $force = false): array
    {
        $out = ['event' => $event, 'sent' => [], 'failed' => [], 'skipped' => []];
        if (!in_array($event, self::EVENTS, true)) {
            $out['failed'][] = 'رویداد ناشناخته: ' . $event;
            return $out;
        }
        $cfg = $this->config();
        if (!$cfg['enabled'] && !$force) {
            $out['skipped'][] = 'اطلاع‌رسانی خاموش است';
            return $out;
        }
        foreach (array_keys(self::channelClasses()) as $id) {
            $ch = $cfg['channels'][$id];
            if (!$ch['enabled']) {
                $out['skipped'][] = $id . ': غیرفعال';
                continue;
            }
            if (empty($ch['events'][$event])) {
                $out['skipped'][] = $id . ': مشترک این رویداد نیست';
                continue;
            }
            $driver = $this->driver($id, $ch);
            if ($driver === null || !$driver->configured()) {
                $out['skipped'][] = $id . ': پیکربندی ناقص';
                continue;
            }
            if ($this->throttled($id, $event)) {
                $out['skipped'][] = $id . ': فاصلهٔ زمانی (throttle)';
                continue;
            }
            $text = $this->render($event, $payload, $id === 'sms');
            try {
                $r = $driver->send($text);
            } catch (Throwable $e) {
                $r = ['ok' => false, 'error' => $e->getMessage()];
            }
            $ok = !empty($r['ok']);
            $this->logSend($id, $event, $ok, (string)($r['error'] ?? ''), function_exists('mb_substr') ? mb_substr($text, 0, 160, 'UTF-8') : substr($text, 0, 160));
            if ($ok) {
                $out['sent'][] = $id;
            } else {
                $out['failed'][] = $id . ': ' . (string)($r['error'] ?? 'خطای نامشخص');
            }
        }
        return $out;
    }

    /** سیگنال‌های جدید (فیلتر درجه + دسته‌ای برای چند سیگنال). */
    public function notifySignals(array $signals): array
    {
        $minTier = self::TIERS[(string)($this->cfg['min_tier'] ?? 'B')] ?? 2;
        $keep = [];
        foreach ($signals as $sig) {
            $tier = (string)($sig['tier'] ?? 'C');
            if ((self::TIERS[$tier] ?? 0) >= $minTier) {
                $keep[] = $sig;
            }
        }
        if (!$keep) {
            return ['event' => 'signal', 'sent' => [], 'failed' => [], 'skipped' => ['همهٔ سیگنال‌ها زیر حداقل درجهٔ اطلاع‌رسانی هستند']];
        }
        if (count($keep) === 1) {
            return $this->dispatch('signal', $keep[0]);
        }
        return $this->dispatch('signal', ['batch' => $keep]);
    }

    /** رویداد بازشدن پوزیشن. */
    public function notifyOpen(array $position): array
    {
        return $this->dispatch('trade_opened', $position);
    }

    /** رویداد بسته‌شدن پوزیشن (با PnL دقیق). */
    public function notifyClose(array $trade): array
    {
        return $this->dispatch('trade_closed', $trade);
    }

    /** گزارش کیف/کارنامه (state از AutoTrader::state). */
    public function notifyWallet(array $state, bool $force = false): array
    {
        return $this->dispatch('wallet_report', $state, $force);
    }

    /** آیا پس از هر بستن، گزارش کیف هم ارسال شود؟ */
    public function reportOnClose(): bool
    {
        return (bool)($this->cfg['report_on_close'] ?? false);
    }

    /** ارسال پیام تست واقعی به یک کانال (بدون throttle). */
    public function sendTest(string $channelId): array
    {
        $meta = self::channelMeta()[$channelId] ?? null;
        if ($meta === null) {
            return ['ok' => false, 'error' => 'کانال ناشناخته است.'];
        }
        $ch = $this->config()['channels'][$channelId];
        $driver = $this->driver($channelId, $ch);
        if ($driver === null || !$driver->configured()) {
            return ['ok' => false, 'error' => 'ابتدا فیلدهای ضروری کانال را ذخیره کنید.'];
        }
        $r = $driver->send($this->render('test', [], $channelId === 'sms'));
        $this->logSend($channelId, 'test', !empty($r['ok']), (string)($r['error'] ?? ''), 'پیام تست اتصال');
        return $r;
    }

    /** بررسی سلامت اتصال یک کانال (بدون ارسال پیام). */
    public function checkChannel(string $channelId): array
    {
        $meta = self::channelMeta()[$channelId] ?? null;
        if ($meta === null) {
            return ['ok' => false, 'error' => 'کانال ناشناخته است.'];
        }
        $ch = $this->config()['channels'][$channelId];
        $driver = $this->driver($channelId, $ch);
        if ($driver === null) {
            return ['ok' => false, 'error' => 'درایور کانال در دسترس نیست.'];
        }
        return $driver->check();
    }

    /** پیش‌نمایش متن پیام هر رویداد (با دادهٔ نمونه). */
    public function preview(string $event): array
    {
        if (!in_array($event, self::EVENTS, true)) {
            return ['ok' => false, 'error' => 'رویداد ناشناخته است.'];
        }
        return ['ok' => true, 'event' => $event, 'text' => $this->render($event, self::samplePayload($event), false)];
    }

    /* ═══ قالب‌های پیام ══════════════════════════════════════════════════ */

    /** ساخت پیام هر رویداد — کامل یا فشرده (پیامک). */
    public function render(string $event, array $p, bool $compact = false): string
    {
        switch ($event) {
            case 'signal':
                return isset($p['batch']) ? $this->tplSignalBatch((array)$p['batch'], $compact) : $this->tplSignal($p, $compact);
            case 'trade_opened':
                return $this->tplOpen($p, $compact);
            case 'trade_closed':
                return $this->tplClose($p, $compact);
            case 'wallet_report':
                return $this->tplWallet($p, $compact);
            case 'test':
                return $compact
                    ? 'میلانو | تست اتصال پیامک موفق ✅'
                    : "🔔 تست اتصال میلانو تریدینگ اینتلیجنس\n━━━━━━━━━━━━━━━━━━\n✅ اتصال این کانال با موفقیت برقرار است.\n🕐 زمان: " . date('Y-m-d H:i');
        }
        return '';
    }

    private function tplSignal(array $s, bool $c): string
    {
        $sym = (string)($s['symbol'] ?? '?');
        $side = ($s['side'] ?? 'BUY') === 'SELL' ? 'فروش/خروج' : 'خرید';
        $risk = (array)($s['risk'] ?? []);
        $entry = (float)($risk['entry'] ?? $s['price'] ?? 0);
        $stop = (float)($risk['stop_loss'] ?? 0);
        $tp = [(float)($risk['take_profit_1'] ?? 0), (float)($risk['take_profit_2'] ?? 0), (float)($risk['take_profit_3'] ?? 0)];
        if ($c) {
            $tpStr = implode('/', array_map(static function ($v) { return $v > 0 ? (string)round($v) : '-'; }, $tp));
            return '🎯 میلانو | ' . $side . ' ' . $sym . ' (' . ($s['tier'] ?? 'C') . ') | ورود ' . $this->pr($entry)
                . ' | استاپ ' . $this->pr($stop) . ' | اهداف ' . $tpStr;
        }
        $rr = $this->rr($entry, $stop, $tp[1] > 0 ? $tp[1] : $tp[0]);
        return "🎯 سیگنال {$side} | {$sym}\n━━━━━━━━━━━━━━━━━━\n"
            . '🎖 درجه: ' . ($s['tier'] ?? 'C') . ' | اعتماد: ' . $this->n((float)($s['combined_score'] ?? 0), 0) . '%'
            . "\n🧭 رژیم بازار: " . $this->regimeLabel((string)($s['regime'] ?? ''))
            . "\n💵 ورود: " . $this->pr($entry)
            . "\n🛑 استاپ: " . $this->pr($stop)
            . "\n🎯 اهداف: " . $this->pr($tp[0]) . ' ← ' . $this->pr($tp[1]) . ' ← ' . $this->pr($tp[2])
            . ($rr !== '' ? "\n⚖️ ریسک به ریوارد: 1:" . $rr : '')
            . "\n\n📡 میلانو تریدینگ اینتلیجنس";
    }

    private function tplSignalBatch(array $sigs, bool $c): string
    {
        $n = count($sigs);
        if ($c) {
            $parts = [];
            foreach ($sigs as $s) {
                $risk = (array)($s['risk'] ?? []);
                $parts[] = (($s['side'] ?? 'BUY') === 'SELL' ? 'فروش ' : 'خرید ') . ($s['symbol'] ?? '?') . '@' . $this->pr((float)($risk['entry'] ?? $s['price'] ?? 0));
            }
            return '🎯 میلانو | ' . $n . ' سیگنال: ' . implode(' | ', $parts);
        }
        $lines = "🎯 {$n} سیگنال جدید موتور میلانو\n━━━━━━━━━━━━━━━━━━\n";
        $i = 0;
        foreach ($sigs as $s) {
            $i++;
            $risk = (array)($s['risk'] ?? []);
            $entry = (float)($risk['entry'] ?? $s['price'] ?? 0);
            $stop = (float)($risk['stop_loss'] ?? 0);
            $lines .= $i . ') ' . (($s['side'] ?? 'BUY') === 'SELL' ? '🔻 فروش' : '🔺 خرید') . ' ' . ($s['symbol'] ?? '?')
                . ' | ' . ($s['tier'] ?? 'C') . ' | ورود ' . $this->pr($entry) . ' | استاپ ' . $this->pr($stop) . "\n";
        }
        return $lines . "\n📡 میلانو تریدینگ اینتلیجنس";
    }

    private function tplOpen(array $p, bool $c): string
    {
        $sym = (string)($p['symbol'] ?? '?');
        $entry = (float)($p['entry_price'] ?? 0);
        $usdt = (float)($p['entry_usdt'] ?? 0);
        $stop = (float)($p['stop_loss'] ?? 0);
        $src = ($p['source'] ?? 'auto') === 'manual' ? 'دستی' : 'خودکار';
        if ($c) {
            return '📥 میلانو | ' . $sym . ' باز شد (' . $src . ') | ' . $this->n($usdt, 1) . ' USDT @ ' . $this->pr($entry) . ' | استاپ ' . $this->pr($stop);
        }
        return "📥 پوزیشن باز شد | {$sym}\n━━━━━━━━━━━━━━━━━━\n"
            . '⚙️ اجرا: ' . $src . ' | درجه: ' . ($p['tier'] ?? '-')
            . "\n💵 مبلغ: " . $this->n($usdt) . ' USDT @ ' . $this->pr($entry)
            . "\n🛑 استاپ: " . $this->pr($stop)
            . "\n🎯 اهداف: " . $this->pr((float)($p['take_profit_1'] ?? 0)) . ' ← ' . $this->pr((float)($p['take_profit_2'] ?? 0)) . ' ← ' . $this->pr((float)($p['take_profit_3'] ?? 0))
            . "\n\n📡 میلانو تریدینگ اینتلیجنس";
    }

    private function tplClose(array $t, bool $c): string
    {
        $sym = (string)($t['symbol'] ?? '?');
        $pnl = (float)($t['pnl_usdt'] ?? 0);
        $pct = (float)($t['pnl_pct'] ?? 0);
        if ($c) {
            return '📤 میلانو | ' . $sym . ' بسته شد (' . $this->reasonLabel((string)($t['exit_reason'] ?? '')) . ') | '
                . $this->signed($pnl, 1) . ' USDT (' . $this->signed($pct, 2) . '%)';
        }
        $dur = $this->durationLabel((int)($t['duration_min'] ?? 0));
        return "📤 پوزیشن بسته شد | {$sym}\n━━━━━━━━━━━━━━━━━━\n"
            . '🏁 دلیل: ' . $this->reasonLabel((string)($t['exit_reason'] ?? ''))
            . ($dur !== '' ? "\n⏱ مدت: {$dur}" : '')
            . "\n💰 نتیجه: " . $this->signed($pnl) . ' USDT (' . $this->signed($pct, 2) . '%)'
            . ' | R: ' . $this->n((float)($t['r_multiple'] ?? 0), 2)
            . "\n💵 ورود " . $this->pr((float)($t['entry_price'] ?? 0)) . ' → خروج ' . $this->pr((float)($t['exit_price'] ?? 0))
            . "\n\n📡 میلانو تریدینگ اینتلیجنس";
    }

    private function tplWallet(array $s, bool $c): string
    {
        $a = (array)($s['account'] ?? []);
        $st = (array)($s['stats'] ?? []);
        $equity = (float)($a['equity_usdt'] ?? 0);
        $balance = (float)($a['balance_usdt'] ?? 0);
        $pnl = (float)($a['total_pnl_usdt'] ?? 0);
        $pct = (float)($a['total_pnl_pct'] ?? 0);
        if ($c) {
            return '💼 میلانو | کیف: ' . $this->n($equity, 1) . ' USDT | سود کل ' . $this->signed($pnl, 1)
                . ' (' . $this->signed($pct, 2) . '%) | باز: ' . (int)($a['open_count'] ?? 0);
        }
        $n = (int)($st['n'] ?? 0);
        $wins = (int)($st['wins'] ?? 0);
        $wr = $n > 0 ? round($wins * 100 / $n, 1) : 0.0;
        return "💼 گزارش کیف معاملاتی میلانو\n━━━━━━━━━━━━━━━━━━\n"
            . '🏦 ارزش کل: ' . $this->n($equity) . ' USDT (از ' . $this->n((float)($a['initial_usdt'] ?? 0), 0) . ' اولیه)'
            . "\n💵 نقد آزاد: " . $this->n($balance) . ' | در پوزیشن: ' . $this->n(max(0.0, $equity - $balance))
            . "\n📈 سود/زیان باز: " . $this->signed((float)($a['unrealized_usdt'] ?? 0))
            . "\n📊 سود/زیان کل: " . $this->signed($pnl) . ' USDT (' . $this->signed($pct, 2) . '%)'
            . ($n > 0 ? "\n🏆 وین‌ریت: {$wr}% از {$n} معامله | میانگین R: " . $this->n((float)($st['avg_r'] ?? 0), 2) : '')
            . "\n🔁 پوزیشن باز: " . (int)($a['open_count'] ?? 0)
            . "\n\n📡 میلانو تریدینگ اینتلیجنس";
    }

    /* ═══ ابزار قالب ═════════════════════════════════════════════════════ */

    private function n(float $v, int $dec = 2): string
    {
        return number_format($v, $dec, '.', ',');
    }

    private function pr(float $v): string
    {
        if ($v <= 0) {
            return '—';
        }
        if ($v >= 1000) {
            return $this->n($v, 1);
        }
        if ($v >= 1) {
            return $this->n($v, 3);
        }
        return $this->n($v, 6);
    }

    private function signed(float $v, int $dec = 2): string
    {
        return ($v > 0 ? '+' : '') . $this->n($v, $dec);
    }

    private function rr(float $entry, float $stop, float $target): string
    {
        if ($entry <= 0 || $stop <= 0 || $target <= 0 || $target === $entry) {
            return '';
        }
        $risk = abs($entry - $stop);
        if ($risk <= 0) {
            return '';
        }
        return $this->n(abs($target - $entry) / $risk, 1);
    }

    private function reasonLabel(string $r): string
    {
        $map = [
            'manual' => 'بستن دستی', 'signal' => 'سیگنال مخالف', 'stop' => 'برخورد به حد ضرر',
            'be_stop' => 'استاپ سربه‌سر', 'tp1' => 'هدف اول (TP1)', 'tp2' => 'هدف دوم (TP2)',
            'tp3' => 'هدف سوم (TP3)', 'timeout' => 'پایان افق زمانی',
        ];
        return $map[$r] ?? ($r !== '' ? $r : '—');
    }

    private function regimeLabel(string $r): string
    {
        $map = [
            'trend_up' => 'روند صعودی', 'trend_down' => 'روند نزولی',
            'range' => 'رِنج (محدوده)', 'ranging' => 'رِنج (محدوده)', 'volatile' => 'پرنوسان',
        ];
        return $map[$r] ?? ($r !== '' ? $r : 'نامشخص');
    }

    private function durationLabel(int $min): string
    {
        if ($min <= 0) {
            return '';
        }
        if ($min < 60) {
            return $min . ' دقیقه';
        }
        $h = intdiv($min, 60);
        $m = $min % 60;
        return $h . ' ساعت' . ($m > 0 ? ' و ' . $m . ' دقیقه' : '');
    }

    /** دادهٔ نمونه برای پیش‌نمایش پیام‌ها. */
    public static function samplePayload(string $event): array
    {
        switch ($event) {
            case 'signal':
                return ['symbol' => 'BTCUSDT', 'side' => 'BUY', 'tier' => 'A+', 'regime' => 'trend_up', 'combined_score' => 86,
                    'price' => 64250.0, 'risk' => ['entry' => 64250.0, 'stop_loss' => 62900.0, 'take_profit_1' => 65800.0, 'take_profit_2' => 67500.0, 'take_profit_3' => 70200.0]];
            case 'trade_opened':
                return ['symbol' => 'BTCUSDT', 'source' => 'auto', 'tier' => 'A+', 'entry_price' => 64250.0, 'entry_usdt' => 250.0,
                    'stop_loss' => 62900.0, 'take_profit_1' => 65800.0, 'take_profit_2' => 67500.0, 'take_profit_3' => 70200.0];
            case 'trade_closed':
                return ['symbol' => 'BTCUSDT', 'exit_reason' => 'tp2', 'duration_min' => 260, 'pnl_usdt' => 12.4, 'pnl_pct' => 4.98,
                    'r_multiple' => 1.9, 'entry_price' => 64250.0, 'exit_price' => 67430.0];
            case 'wallet_report':
                return ['account' => ['equity_usdt' => 2562.1, 'balance_usdt' => 1750.0, 'initial_usdt' => 2000.0, 'unrealized_usdt' => 32.1,
                    'total_pnl_usdt' => 562.1, 'total_pnl_pct' => 28.1, 'open_count' => 3],
                    'stats' => ['n' => 34, 'wins' => 21, 'avg_r' => 1.24]];
        }
        return [];
    }

    /* ═══ داخلی ══════════════════════════════════════════════════════════ */

    /** @return Channel|null */
    private function driver(string $id, array $ch)
    {
        $cls = self::channelClasses()[$id] ?? null;
        if ($cls === null) {
            return null;
        }
        return new $cls($this->http, $ch);
    }

    private function logReady(): bool
    {
        if ($this->logReady !== null) {
            return $this->logReady;
        }
        try {
            $this->logReady = $this->db->tableExists('notify_log');
        } catch (Throwable $e) {
            $this->logReady = false;
        }
        return $this->logReady;
    }

    private function throttled(string $channel, string $event): bool
    {
        $sec = max(0, (int)($this->cfg['throttle_sec'] ?? 45));
        if ($sec === 0 || !$this->logReady()) {
            return false;
        }
        try {
            $row = $this->db->selectOne(
                'SELECT created_ts FROM ' . $this->db->table('notify_log') . ' WHERE channel = :c AND event = :e ORDER BY id DESC LIMIT 1',
                ['c' => $channel, 'e' => $event]
            );
            return $row !== null && (time() - (int)$row['created_ts']) < $sec;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function logSend(string $channel, string $event, bool $ok, string $error, string $preview): void
    {
        if (!$this->logReady()) {
            return;
        }
        try {
            $this->db->insert('notify_log', [
                'channel' => $channel,
                'event' => $event,
                'ok' => $ok ? 1 : 0,
                'error' => $error !== '' ? mb_substr($error, 0, 500, 'UTF-8') : null,
                'preview' => $preview !== '' ? mb_substr($preview, 0, 280, 'UTF-8') : null,
                'created_ts' => time(),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) { /* لاگ هرگز ارسال را نمی‌شکند */ }
    }

    /** @return array<string,array> آخرین ارسال به‌ازای هر کانال */
    private function lastByChannel(): array
    {
        if (!$this->logReady()) {
            return [];
        }
        try {
            $rows = $this->db->select('SELECT * FROM ' . $this->db->table('notify_log') . ' ORDER BY id DESC LIMIT 400');
        } catch (Throwable $e) {
            return [];
        }
        $out = [];
        foreach ((array)$rows as $row) {
            $id = (string)$row['channel'];
            if (!isset($out[$id])) {
                $out[$id] = [
                    'event' => (string)$row['event'],
                    'ok' => (int)$row['ok'] === 1,
                    'error' => $row['error'] !== null ? (string)$row['error'] : null,
                    'at' => (string)$row['created_at'],
                ];
            }
        }
        return $out;
    }

    /** تاریخچهٔ اخیر ارسال‌ها. */
    public function recentLog(int $limit = 20): array
    {
        if (!$this->logReady()) {
            return [];
        }
        try {
            $rows = $this->db->select(
                'SELECT * FROM ' . $this->db->table('notify_log') . ' ORDER BY id DESC LIMIT ' . max(1, min(200, $limit))
            );
        } catch (Throwable $e) {
            return [];
        }
        $out = [];
        foreach ((array)$rows as $row) {
            $out[] = [
                'channel' => (string)$row['channel'],
                'event' => (string)$row['event'],
                'ok' => (int)$row['ok'] === 1,
                'error' => $row['error'] !== null ? (string)$row['error'] : null,
                'preview' => $row['preview'] !== null ? (string)$row['preview'] : null,
                'at' => (string)$row['created_at'],
            ];
        }
        return $out;
    }
}
