# گزارش کامل پروژه — میلانو تریدینگ اینتلیجنس

**تاریخ:** ۱۴۰۴/۰۶/۲۸ · **شاخه:** `arena/01a0a94d-trd` · **تهیه‌شده توسط:** بررسی و تست خودکار + دستی (Arena Agent)

این گزارش نتیجهٔ یک بررسی موشکافانهٔ کامل از مخزن است: معماری، قابلیت‌ها، نحوهٔ استفاده،
پیاده‌سازی نهایی، تست‌های اجراشده و همهٔ اصلاحات انجام‌شده در این نشست.

---

## ۱) خلاصهٔ مدیریتی

مخزن اکنون **دو تبار کد** دارد که هر دو سالم و قابل استفاده‌اند:

| تبار | مسیر | توضیح |
|---|---|---|
| **v5.8.1 — پلتفرم اصلی (PHP)** | ریشهٔ مخزن (`index.php`, `trade.php`, `api/`, `includes/`, …) | سامانهٔ کامل تحلیل و سیگنال کریپتو با «قیف تصمیم نهادی»، ۳۴ فیلتر در ۷ لایه، معامله‌گر خودکار کاغذی، اتصال صرافی‌ها (بایننس + نوبیتکس + والکس)، اطلاع‌رسانی ۵ کاناله، موتور یادگیری و اپ اندروید WebView |
| **v4.0-pro — هستهٔ قدیمی + اپ نیتیو** | `server/` (هستهٔ PHP v4) و `android-native/` (اپ Compose) | نسخهٔ داخل زیپ `meelano-trading-intelligence-v4.0-pro.zip` با اصلاحات امنیتی/عملکردی این نشست + یک اپ اندروید **نیتیو Jetpack Compose** که در همین پروژه ساخته شد |

**وضعیت اصلاحات:** ۳ آسیب‌پذیری امنیتی واقعی در v5.8.1 و ۵ باگ در تبار v4 یافت و رفع شد؛
۴ مجموعه تست جدید (قابل اجرا بدون PHP/Android SDK) اضافه شد و همه سبز هستند.

**وضعیت APK:** GitHub Actions در سطح مخزن/حساب همچنان **غیرفعال** است (حتی ورک‌فلو
`android-apk.yml` خود پروژه روی `main` هم در ۴ ثانیه بدون هیچ استپی شکست می‌خورد). تا فعال‌سازی
Actions، فایل `MeelanoTrader-v5.8.1-HostLink.apk` در ریشهٔ مخزن نسخهٔ آمادهٔ نصب اندروید است
(اپ WebView رسمی v5.8.1). اپ نیتیو `android-native/` با Android Studio یا `./gradlew` به‌صورت
محلی قابل ساخت است.

---

## ۲) معماری پلتفرم v5.8.1

### ۲٫۱ قیف تصمیم (Decision Funnel)

مسیر داده تا سیگنال — هر مرحله می‌تواند سیگنال را **وتو** کند:

```
دادهٔ زنده (Binance/CoinGecko/Nobitex/Wallex)
  → تشخیص رژیم بازار (روند صعودی/نزولی/رنج/پرنوسان)
  → ۳۴ فیلتر وزن‌دار در ۷ لایهٔ مستقل
  → تأیید چند تایم‌فریمی (1h/4h/1d — سیگنال خلاف‌جهت هرگز صادر نمی‌شود)
  → اجماع چندمدلی AI (حداقل ۳ هم‌راستا از ۷ ارائه‌دهنده)
  → دروازهٔ رژیم بیت‌کوین (در نزول قوی BTC فقط A+ مجاز)
  → پلن ریسک ساختاری (استاپ پشت سوئینگ ± بافر ATR، نردبان 1.5R/2.5R/4R، سربه‌سر)
  → درجه‌بندی کیفیت (A+/A/B/C)
  → خنک‌کردن تکرار (cooldown ۱۲ ساعته برای هر نماد)
```

### ۲٫۲ لایه‌های هفت‌گانهٔ فیلتر (`includes/Crypto/Filters.php`)

1. **تکنیکال کلاسیک:** RSI، MACD، باندهای بولینگر، EMAها، ADX، Stochastic، حجم نسبی
2. **ساختار بازار:** سویپ نقدینگی، شکاف ارزش منصفانه (FVG)، نقطهٔ کنترل (POC)، ایچیموکو
3. **جریان سرمایه:** OBV، واگرایی حجم/قیمت
4. **مشتقات:** فاندینگ ریت، تغییرات اوپن اینترست
5. **کلان:** قدرت نسبی به BTC (RS/BTC)، شاخص ترس و طمع، سشن معاملاتی
6. **تأیید اجرا (Precision Edge):** شاخص بریدگی CHOP، ضدتعقیب (فاصله از EMA21 بر حسب ATR)،
   الگوی کندل تأیید (انگالفینگ/چکش/ستارهٔ ثاقب)، قدرت پایانهٔ کندل (CLV)، انسداد حجم
   (تفکیک اوج دمیده‌شده از فلش تسلیم)، کف حداقلی استاپ ۰٫۹ ATR
7. **سنتیمنت آن‌چین:** فشردگی بولینگر، جهت‌یاب DI، فیلتر ۳۴ «ریسک خبری» از ترکیب ترس‌وطمع +
   مومنتوم کلان + وزن‌دهی کلیدواژه‌ای اخبار RSS در پنجرهٔ ۲۴ ساعت

ضرایب فیلترها **وابسته به رژیم** هستند (`key@regime`) و توسط موتور یادگیری تنظیم می‌شوند.

### ۲٫۳ اجزای کلیدی (`includes/Crypto/`)

| فایل | نقش |
|---|---|
| `SignalEngine.php` | ارکستراتور قیف تصمیم |
| `Indicators.php` | ریاضی اندیکاتورها (فقط کندل‌های بسته) |
| `Regime.php` | طبقه‌بندی رژیم بازار |
| `MultiTimeframe.php` | تأیید 1h/4h/1d |
| `RiskManager.php` | پلن ریسک ساختاری و سایز پوزیشن |
| `Backtest.php` | بک‌تست **بدون نشت**: ورود در کندل بعدی، اولویت استاپ، کارمزد لحاظ‌شده |
| `Robustness.php` | walk-forward ۷۰/۳۰ با دروازهٔ تعویق + مونت‌کارلو |
| `LearningEngine.php` | یادگیری رژیم‌آگاه با نیم‌عمر ۴۵ روز، ژورنال near-miss |
| `AutoTrader.php` | اجرای خودکار روی کیف کاغذی + پایش خروج نردبانی (TP1/2/3، سربه‌سر) |
| `BinanceSpot.php` | اتصال Binance Spot (HMAC-SHA256، تست‌نت + لایوِ دومرحله‌قفل) |
| `Nobitex.php` / `Wallex.php` | کانکتور صرافی‌های ایرانی |
| `ConnectorFactory.php` | انتخاب کانکتور: `binance` / `nobitex` / `wallex` |
| `SignalTracker.php` | داوری خودکار سیگنال‌ها با R واقعی (کران ساعتی) |
| `Sentiment.php` | لایهٔ سنتیمنت/اخبار |
| `Notifier.php` + `Notify/` | ۵ کانال: تلگرام، بله، روبیکا، واتساپ، پیامک (throttle ضداسپم) |
| `MarketData.php` | کش دولایه + CircuitBreaker برای APIهای بیرونی |

لایهٔ AI در `includes/Ai/`: `Registry` (۷ ارائه‌دهنده)، `Router`، `Client` با
`CurlTransport`/`MockTransport` (تست‌پذیر)، `Health` و `AiValidator` (وتوی red-team).

### ۲٫۴ نقاط پایان API (`api/` — ۱۸ فایل)

همه (جز `health.php` عمومی و `tracker.php` با کلید کران) از `m_guard()` در `api/bootstrap.php`
عبور می‌کنند: **ورود مدیر (نشست) + سهمیهٔ نرخ + CSRF**.

`scan` (اسکن زنده) · `market` (دادهٔ بازار) · `backtest` · `signals` (تاریخچه) · `tracker` (داوری) ·
`paper` (کیف کاغذی/پوزیشن‌ها) · `exchange` (ذخیرهٔ کلید، تست اتصال، `enable_live` دومرحله‌ای با
تأیید صریح `ENABLE-LIVE`) · `notify` (کانال‌ها/تست/تاریخچه) · `sentiment` · `learning` ·
`db_install` (SSE با نوار پیشرفت) · `db_status` · `db_test` · `ai_test` · `ai_autoroute` ·
`settings_save`.

### ۲٫۵ اپ اندروید v5.8.1 (`android/` — بستهٔ `ir.meelano.trader`)

اپ **WebView ایمن** روی پنل وب است، با سخت‌گیری قابل تحسین:

- `setAllowFileAccess(false)` و `setAllowContentAccess(false)` — بدون دسترسی `file://`
- `MIXED_CONTENT_NEVER_ALLOW` + SSL اجباری؛ `onReceivedSslError` → `handler.cancel()` (هرگز ردِ بی‌صدا)
- Safe Browsing: در `onSafeBrowsingHit` صفحهٔ خطرناک مسدود و کاربر آگاه می‌شود
- ناوبری محصور به دامنهٔ پنل (`app_url` در `strings.xml`)؛ دامنه‌های دیگر و اسکیم‌های
  `mailto/tel/intent/tg` به بیرون از اپ سپرده می‌شوند (ضدفیشینگ)
- دانلود خروجی‌ها (CSV/گزارش) با `DownloadManager` سیستم
- کش/بازگشت هوشمند، نوار پیشرفت، صفحهٔ خطای فارسی

APK رسمی این نسخه در ریشهٔ مخزن کامیت شده: `MeelanoTrader-v5.8.1-HostLink.apk`.

---

## ۳) راهنمای استفاده (نصب و راه‌اندازی)

### ۳٫۱ نیازمندی‌ها

- PHP **۷٫۴ تا ۸٫۴** با افزونه‌های `curl`, `pdo_mysql` یا `pdo_sqlite`, `mbstring`, `openssl`
- هاست اشتراکی/cPanel کفایت می‌کند (کد عمداً بدون enum/readonly/match نوشته شده)
- برای دادهٔ زنده: اینترنت خروجی به Binance/CoinGecko (و نوبیتکس/والکس در صورت استفاده)

### ۳٫۲ نصب روی cPanel

1. کل ریشهٔ مخزن (بدون `docs/`, `server/`, `android-native/`, `.github/`) را در
   `public_html/trader` آپلود کنید.
2. در مرورگر باز کنید: `https://yourdomain.com/trader/`
3. **رمز پیش‌فرض مدیر: `meelano-admin`** — فوراً از `settings.php` تغییر دهید
   (هش با `password_hash` در `config/settings.php` ذخیره می‌شود؛ این فایل gitignore است).
4. تب دیتابیس → `db_install` را اجرا کنید (جداول با نوار پیشرفت زنده ساخته می‌شوند).
5. تب امنیت: کلید کران `tracker` را یادداشت کنید و یک **کران ساعتی** بگذارید:
   `curl "https://yourdomain.com/trader/api/tracker.php?action=run&key=کلید"` (داوری خودکار سیگنال‌ها)
6. در `settings.php`: کلیدهای AI (حداقل یکی) و در صورت نیاز کلید صرافی را وارد کنید
   (کلیدهای صرافی با **AES-256-GCM** و کلید اپ در `config/.app_key` — gitignore — رمز می‌شوند).
7. معاملهٔ واقعی **دومرحله‌ای قفل** است: ذخیرهٔ کلید + تأیید صریح `ENABLE-LIVE` + ممیزی.

### ۳٫۳ صفحات پنل

| صفحه | کاربرد |
|---|---|
| `index.php` | داشبورد: تیکرهای زندهٔ WebSocket (با بازاتصال + fallback پولینگ)، امتیاز/درجه، قیف فیلترها، تاریخچه |
| `trade.php` | معامله‌گر خودکار: کیف کاغذی USDT مجازی، اجرای خودکار بر پایهٔ درجه/اعتماد، پایش خروج نردبانی، کارنامه با R واقعی |
| `notify.php` | کانال‌های اطلاع‌رسانی (تلگرام/بله/روبیکا/واتساپ/پیامک)، فیلتر درجه، تست اتصال، پیش‌نمایش و تاریخچه |
| `settings.php` | امنیت، دیتابیس، AI، صرافی‌ها، یادگیری، ممیزی |
| `login.php` | ورود مدیر (با محدودسازی نرخ) |

### ۳٫۴ اپ اندروید

- **نصب فوری:** همان `MeelanoTrader-v5.8.1-HostLink.apk` ریشهٔ مخزن را نصب کنید؛ آدرس پنل
  (`ainetmee.ir/trader` در `android/app/src/main/res/values/strings.xml`) را در صورت نیاز به
  دامنهٔ خود تغییر دهید و از `android/` بیلد بگیرید.
- **ساخت با CI:** ورک‌فلو `.github/workflows/android-apk.yml` با هر push روی `main` (مسیر
  `android/**`) APK را می‌سازد و در تگ ریلیز `apk-latest` منتشر می‌کند — **به شرط فعال بودن
  GitHub Actions** (بخش ۶).
- **اپ نیتیو جایگزین:** `android-native/` یک اپ کاملاً نیتیو (Jetpack Compose، RTL، ۵ صفحه:
  داشبورد/تحلیل/بک‌تست/اسکن/تنظیمات) برای هستهٔ v4 در `server/` است:
  ```bash
  cd android-native
  ./gradlew testDebugUnitTest assembleDebug assembleRelease
  # خروجی: app/build/outputs/apk/debug/app-debug.apk
  ```
  در تنظیمات اپ، Base URL را به آدرس نصب `server/` (مثلاً `https://yourdomain.com/api5`)
  و توکن API را به مقدار `AUTH_TOKEN` در `.env` سمت سرور تنظیم کنید.

---

## ۴) تست‌ها

### ۴٫۱ تست رسمی پروژه (نیاز به PHP)

```bash
php tests/run.php
```
**۳۱۷ بررسی** که کد واقعی را اجرا می‌کنند (اندیکاتورها، ریسک، MTF، بک‌تست بدون نشت،
خط‌لولهٔ کامل، ممیزی و …). در محیط سندباکس این نشست PHP وجود نداشت، لذا اجرای آن ممکن نبود؛
به‌جای آن ۴ مجموعهٔ زیر ساخته و اجرا شد.

### ۴٫۲ تست‌های جدید این نشست (بدون PHP/SDK — همه سبز ✅)

| مجموعه | اجرا | پوشش |
|---|---|---|
| `tests/v58_static_check.py` | `python3 tests/v58_static_check.py` | احراز هویت همهٔ ۱۸ نقطهٔ پایان API، CSRF روی روش‌های ناامن، نرخ‌محدودِ سمت-سرور (بدون نشست)، سیاست پروکسی `Security::ip`، پوشش `.htaccess`، وجود همهٔ ۱۵ اندپوینتی که JS صدا می‌زند، سخت‌گیری WebView، نرخ‌محدود `login.php` |
| `tests/web-console-pure.test.mjs` | `node tests/web-console-pure.test.mjs` | توابع خالص کنسول وب v4 (`server/index.html`) با استاب DOM |
| `tests/contract_fixtures_test.py` | `python3 tests/contract_fixtures_test.py` | اعتبارسنجی **۵۷ payload واقعی** تاریخچهٔ تحلیل v4 (sqlite) در برابر قرارداد گیت‌ها/تصمیم‌ها |
| `tests/android_static_check.py` | `python3 tests/android_static_check.py` | ۲۱ فایل Kotlin اپ نیتیو: توازن براستها، ۱۲۵ ارجاع رشته‌ای fa==en، ۲۸ کامپوزبل |

تست واحد اپ نیتیو (۱۰ تست JUnit شامل دو تست جدید برای **payloadهای میراثی بدون `trade_plan`**)
در CI با `./gradlew testDebugUnitTest` اجرا می‌شود.

### ۴٫۳ نتیجهٔ اجرا در این نشست

```
v58 static checks: 18 api files, 15 JS endpoint refs → OK
android static checks: OK (21 kotlin files, 125 string refs, 28 composables)
web console pure-helper tests: OK
contract fixtures: OK (57 legacy rows validated)
```

---

## ۵) مشکلات یافت‌شده و اصلاحات (لاگ کامل)

### ۵٫۱ v5.8.1 — سه اصلاح امنیتی واقعی (کامیت `58de797`)

| # | مشکل | ریسک | اصلاح |
|---|---|---|---|
| S1 | `Security::rateLimit()` شمارنده را در `$_SESSION` نگه می‌داشت | با دور انداختن کوکی، محدودسازی نرخِ ورود (۸ تلاش/دقیقه) **کاملاً بی‌اثر** می‌شد → بروت‌فورس روی رمز مدیر | شمارنده به فایل سمت سرور (`storage/cache/rl_*.json`) با قفل `flock` منتقل شد؛ کلید = سطل + IP |
| S2 | `Security::ip()` سرصفحه‌های `X-Forwarded-For`/`CF-Connecting-IP` را **بدون قید** می‌پذیرفت | جعل IP → فرار از سهمیهٔ نرخ و آلوده‌سازی ممیزی | فقط `REMOTE_ADDR` مگر آنکه `security.trust_proxy_headers` در تنظیمات روشن باشد (پشت Cloudflare/پروکسی معتبر) |
| S3 | همهٔ APIها `m_guard(false, …)` صدا می‌شدند، یعنی **CSRF عملاً غیرفعال** — با احراز هویت کوکی‌محور، سایت مخرب می‌توانست به نام مدیرِ لاگین‌شده پوزیشن کاغذی باز/بسته کند، اسکن سنگین راه بیندازد یا تنظیمات اعلان را تغییر دهد | CSRF روی همهٔ عملیات حساس | `m_guard()` اکنون برای روش‌های ناامن (POST/PUT/PATCH/DELETE) **همیشه** توکن CSRF می‌خواهد؛ رابط وب از قبل سرصفحهٔ `X-CSRF-Token` را از `core.js` می‌فرستد، پس UI نمی‌شکند؛ GETهای خواندنی و کران `tracker` (کلید مستقل) دست‌نخورده |

نکته‌های مثبت بررسی (بدون نیاز به اصلاح): استاپ‌های SSL هرگز بی‌صدا رد نمی‌شوند،
`enable_live` دومرحله‌ای با ممیزی است، کلیدهای صرافی AES-256-GCM، `.htaccess` همهٔ مسیرهای
حساس را می‌بندد، خروجی‌ها با `meelano_e()` فرار می‌شوند، `hash_equals` برای مقایسهٔ توکن/رمز،
و `session_regenerate_id(true)` بعد از ورود.

### ۵٫۲ تبار v4 — پنج باگ + CI + تست (کامیت‌های `86aa061` و پیش از آن)

| # | فایل | مشکل | اصلاح |
|---|---|---|---|
| P1 | `server/api/*` | باگ بازکردن **پاکت کش** (Cache envelope) — دادهٔ کش‌شده با لایهٔ اضافه برگردانده می‌شد | unwrap صحیح |
| P2 | `server/api/backtest.php` | مسیر legacy به روتر جدید نمی‌رسید | رپر روتر |
| P3 | `server/api/index.php` | هویت نرخ‌محدود بر پایهٔ IP خام (جعل‌پذیر/مشترک) + تایم‌اوت PHP برای بک‌تست‌های سنگین | هویت = هش توکن، `@set_time_limit(240)` |
| P4 | `server/api/notify.php` | وب‌هوک می‌توانست به آدرس‌های داخلی/لوکال درخواست بزند (**SSRF**) | `webhook_url_is_safe()`: فقط HTTPS، بدون private/loopback/`.local`/`.internal` + بررسی DNS |
| W1 | `server/index.html` | کلید تاریخچه `final_score` جا می‌افتاد + تایم‌اوت‌های کوتاه fetch | کلید افزوده؛ analyze=60s، backtest/scan=240s |
| CI1 | `.github/workflows/android-build.yml` | نبود `permissions`، نبود تست مخزن، لاگ gradle در شکست گم می‌شد | permissions (contents+issues)، استپ تست (node+python)، `pipefail`+`tee`، کامنت خودکارِ دمِ لاگ روی PR هنگام شکست، آینه‌سازی APK به `deliver/apk` |
| A1 | `android-native/.../SignalParserTest.kt` | پارसर برای payloadهای **میراثی واقعی** تست نداشت (۵۷ رکورد sqlite همگی بدون `trade_plan`/`data_quality` با کلیدهای تخت ریشه) | دو تست جدید: analyze میراثی (کلیدهای تخت + fallback قیمت از metrics گیت‌ها) و backtest بدون کلید `engine` |

### ۵٫۳ ساختار مخزن

- ادغام `main` (v5.8.1) با شاخهٔ نشست — تاریخچه‌ها نامرتبط بودند
  (`--allow-unrelated-histories`)؛ تنها تضاد `.gitignore` بود که با اجتماعِ دو سیاست حل شد.
- برای جلوگیری از تداخل، اپ نیتیو Compose از `android/` به **`android-native/`** منتقل شد
  (ورک‌فلو، تست ایستا و مستندات به‌روزرسانی شدند). `android/` اکنون فقط اپ WebView رسمی v5.8.1 است.

---

## ۶) وضعیت ساخت APK و GitHub Actions

- همهٔ اجراهای Actions — از جمله `android-apk.yml` **خود پروژه روی `main`** — در ۳–۵ ثانیه،
  بدون هیچ استپ و بدون رانر، شکست می‌خورند ⇒ Actions در سطح مخزن/سازمان هنوز غیرفعال است.
- شما پیش‌تر گزینهٔ «فعال‌سازی Actions» را انتخاب کردید؛ پس از فعال‌سازی در
  **Settings → Actions → General** (و اطمینان از بودجه/مجوز رانر)، یک push جدید (یا
  «Re-run» روی ورک‌فلو `android-apk.yml`) باعث می‌شود:
  1. APK اپ WebView v5.8.1 ساخته و در تگ ریلیز **`apk-latest`** منتشر شود؛
  2. اپ نیتیو `android-native/` هم توسط ورک‌فلو `android-build.yml` با تست‌های واحد ساخته و
     artifact شود (و در شکست، دمِ لاگ gradle خودکار روی PR #1 کامنت می‌شود).
- تا آن زمان، **APK آمادهٔ نصب** در ریشهٔ مخزن موجود است:
  `MeelanoTrader-v5.8.1-HostLink.apk` (۶۰KB — پوستهٔ WebView رسمی).

---

## ۷) نکات باقی‌مانده و پیشنهادات

1. **رمز پیش‌فرض `meelano-admin`** را فوراً پس از نصب عوض کنید (در `settings.php`).
2. **ناسازگاری شمارهٔ نسخه:** `MEELANO_VERSION=5.7.0` در `includes/bootstrap.php` در برابر
   APK با نام 5.8.1 و `versionName 5.0.0` در `android/app/build.gradle` — یکسان‌سازی پیشنهاد می‌شود.
3. `android-hostlink/smali` (خروجی دی‌کامپایل) و APK باینری در ریشه، تاریخچهٔ git را سنگین
   می‌کنند؛ بهتر است APK از ریلیزها/CI تحویل شود (تست ایستای من این دو را به‌صورت WARN
   گزارش می‌کند، چون حذفشان تصمیم تحویل شماست).
4. `api/db_status.php` نگهبان درون‌خطی بدون نرخ‌محدود دارد (خواندنی و کم‌خطر — WARN در تست).
5. اگر پشت Cloudflare هستید، `security.trust_proxy_headers` را روشن کنید تا IP واقعی در
   ممیزی/نرخ‌محدود استفاده شود (پیش‌فرض: خاموش = امن).
6. هستهٔ v4 در `server/` فقط برای اپ نیتیو `android-native/` لازم است؛ اگر فقط پلتفرم v5.8.1
   را نگه می‌دارید، می‌توان `server/` و زیپ v4 را بایگانی کرد.

---

## ۸) نقشهٔ فایل‌ها برای توسعه‌دهنده

```
/                      پلتفرم v5.8.1
├── index.php          داشبورد (WebSocket زنده)
├── trade.php          معامله‌گر خودکار / کیف کاغذی
├── notify.php         مرکز اطلاع‌رسانی ۵ کاناله
├── settings.php       تنظیمات + امنیت + دیتابیس
├── login.php          ورود مدیر (نرخ‌محدود ۸/دقیقه بر اساس IP — پس از اصلاح S1)
├── api/               ۱۸ نقطهٔ پایان (bootstrap مشترک: نگهبان+CSRF+نرخ)
├── includes/          Config, Db, Security, Logger, Schema, Installer, layout, helpers
│   ├── Crypto/        موتور تحلیل (۲۳ کلاس) — قلب سامانه
│   └── Ai/            Registry/Router/Client/Health/Validator
├── tests/run.php      ۳۱۷ بررسی رسمی (php tests/run.php)
├── tools/             build_icon.py, import_secrets.php
├── android/           اپ WebView رسمی (ir.meelano.trader)
├── .github/workflows/ android-apk.yml (پروژه) + android-build.yml (اپ نیتیو + تست‌ها)
│
├── server/            هستهٔ PHP v4.0-pro (اصلاح‌شده) — بک‌اند اپ نیتیو
├── android-native/    اپ نیتیو Jetpack Compose (Kotlin 2.0، minSdk 24، RTL، ۱۲ تست واحد)
├── tests/             ۴ مجموعهٔ تستِ بدون-PHP این نشست
└── docs/              PROJECT_REVIEW_fa.md (بررسی v4) + REPORT_fa.md (همین گزارش)
```

**فرمان‌های کلیدی:**

```bash
php tests/run.php                        # ۳۱۷ تست رسمی v5.8.1 (نیاز به PHP)
python3 tests/v58_static_check.py        # بررسی‌های امنیتی ایستا v5.8.1
node tests/web-console-pure.test.mjs     # کنسول وب v4
python3 tests/contract_fixtures_test.py  # قرارداد ۵۷ payload واقعی v4
python3 tests/android_static_check.py    # اپ نیتیو
cd android-native && ./gradlew testDebugUnitTest assembleDebug   # ساخت اپ نیتیو
```
