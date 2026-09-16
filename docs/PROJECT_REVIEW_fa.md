# بررسی فنی پروژه — MeeLano Trading Intelligence v4.0 Pro

تاریخ بررسی: ۲۰۲۶-۰۹-۱۶ · شاخه: `arena/01a0a94d-trd` · منبع: `meelano-trading-intelligence-v4.0-pro.zip`

## ۱) معماری فعلی

```
api5/
├── index.html            کنسول وب (PWA، راست‌چین، تک‌فایل، Vanilla JS)
├── config.php            بارگذاری .env + کلاس Config با اعتبارسنجی و محدودسازی مقادیر
├── api/
│   ├── index.php         روتر اصلی JSON (health, auth-check, analyze, history, scan, backtest)
│   ├── notify.php        ارسال تلگرام/وب‌هوک (مستقل از روتر)
│   ├── config.php        سازگاری قدیمی + require_auth/json_response
│   └── market/decision/indicators/check.php   فایل‌های داخلی (با .htaccess مسدود از وب)
├── src/
│   ├── Core/             AnalysisEngine (۴ گیت)، ScoreCalculator، Context/Result/Decision
│   ├── Experts/          QuantitativeFilter, TechnicalExpert, SentimentMacroExpert, RiskManagementGate
│   ├── Market/           BinanceProvider, MarketDataService, BitcoinMarketGuard
│   ├── Indicators/       RSI/ATR (Wilder)، EMA، MACD، Ichimoku، Volume/Momentum/Divergence
│   ├── Repositories/     لاگ تحلیل، سیگنال، اسنپ‌شات بازار (SQLite)
│   └── Services/         WhaleActivityDetector, RiskCalculator, ConsensusService
├── data/signals.sqlite   پایگاه SQLite (WAL) — ۵۷ رکورد تاریخی داخل بسته توزیع
└── storage/              کش و لاگ‌ها
```

### مسیر تصمیم
۱) دریافت ۲۵۰ کندل + دفتر سفارش از Binance (با کش ۱۵ ثانیه‌ای و Circuit Breaker)
۲) اجرای سریال چهار گیت؛ هر گیت REJECT ⇒ تصمیم نهایی REJECT و توقف زنجیره
۳) امتیاز وزن‌دار: Quantitative 30% · Technical 35% · Sentiment/Macro 15% · Risk 20%
۴) ACCEPT فقط اگر همه گیت‌ها ACCEPT باشند و امتیاز ≥ ۷۵؛ در غیر این صورت WATCH/REJECT
۵) برنامه معامله از ATR: ورود ±۰.۵٪، SL = ورود − ۱.۲۵×ATR، TP1 = ۲.۵R، TP2 = ۴R
۶) ثبت کامل در SQLite (analysis_runs + analysis_gate_results + signals)

## ۲) نقاط قوت
- احراز هویت با `X-API-Token` و مقایسه `hash_equals`؛ health بدون افشای secrets.
- محدودسازی نرخ درخواست (۶۰/دقیقه/IP) با قفل فایل؛ اعتبارسنجی نماد/تایم‌فریم/ریسک در سرور.
- سقف ریسک سخت ۱٪ سرمایه هم در API و هم در گیت ریسک.
- `.htaccess` برای `.env`، `data/`، `storage/`، `src/` و فایل‌های داخلی.
- لایه داده یکپارچه SQLite با migration، WAL، ایندکس و cascade.
- بک‌تست بدون نشت داده رو به جلو (برش تاریخی کندل‌ها و رژیم BTC در هر گام).
- مستدات استقرار و `.env.example` امن؛ گزارش ممیزی (AUDIT_REPORT.md) صادقانه است.

## ۳) یافته‌ها و ریسک‌ها (به ترتیب اهمیت)

| # | یافته | شدت | پیشنهاد |
|---|---|---|---|
| ۱ | `data/signals.sqlite` با ۵۷ رکورد تحلیل واقعی داخل بسته توزیع است | متوسط | پیش از توزیع مجدد، پایگاه را خالی کنید (`VACUUM`/حذف رکوردها) |
| ۲ | `notify.php` توکن تلگرام/URL وب‌هوک را از بدنه درخواست می‌پذیرد ⇒ سرور می‌تواند به هر URL دلخواه درخواست بفرستد (SSRF محدود به کلاینت احراز هویت‌شده) | متوسط | فقط تنظیمات سروری `.env` یا whitelist دامنه |
| ۳ | هویت rate limit = `REMOTE_ADDR`؛ پشت CDN/پروکسی همه کاربران یک سطل می‌شوند | متوسط | استفاده از `X-Forwarded-For` معتبر یا کلید توکن |
| ۴ | `scan.php` دوازده تحلیل سریال و `backtest.php` ≈۷۴۰ اجرای موتور را در یک درخواست HTTP انجام می‌دهد ⇒ احتمال عبور از `max_execution_time` و تایم‌اوت کلاینت | متوسط | صف/کرون پس‌زمینه + پاسخ ناهمگرا؛ کلاینت اندروید فعلاً تایم‌اوت ۱۵۰ ثانیه دارد |
| ۵ | کنسول وب توکن را در `localStorage` نگه می‌دارد (آسیب‌پذیر به XSS) | متوسط | نسخه اندروید از SharedPreferences خصوصی استفاده می‌کند؛ برای وب حداقل `sessionStorage` |
| ۶ | ستون امتیاز تاریخچه `final_score` است ولی وب `composite_score` را می‌خواند ⇒ در جدول وب «—» نمایش می‌دهد | کم | اصلاح کلید در `index.html` (اندروید هر دو را پوشش می‌دهد) |
| ۷ | `api/market.php`، `api/decision.php`، `api/indicators.php`، `api/check.php` کد مرده/مسدود هستند | کم | حذف یا ادغام در روتر |
| ۸ | SQLite+WAL روی برخی هاست‌های اشتراکی (فس NFS) کار نمی‌کند | کم | بررسی `PRAGMA journal_mode` در health |
| ۹ | موتور فقط Long است و Sentiment یک فلگ دستی است (خود پروژه مستند کرده) | اطلاع | در نقشه راه v5 |

## ۴) قرارداد API که کلاینت اندروید پیاده‌سازی می‌کند

| Endpoint | متد | احراز هویت | خروجی کلیدی |
|---|---|---|---|
| `api/health.php` | GET | خیر | status, database, config, php, pdo_sqlite, time |
| `api/auth-check.php` | GET | بله | authenticated |
| `api/analyze.php` | POST | بله | decision, final_score, confidence, results{4 gate}, trade_plan, warnings, price, data_quality |
| `api/history.php?limit=` | GET | بله | items[{symbol,timeframe,decision,final_score,rejected_by,created_at}] |
| `api/scan.php?quote=USDT` | GET | بله | items[همان ساختار analyze] |
| `api/backtest.php` | POST | بله | result{total_trades,win_rate,profit_factor,profit_pct,max_drawdown,final_equity} |
| `api/notify.php` | POST | بله | results{telegram,webhook} |

خطاها: `401` توکن نامعتبر · `429` rate limit · `400` پارامتر نامعتبر · `5xx` خطای سرور/بالادست.

## ۵) نسخه اندروید
پروژه کامل در `android/` (Kotlin + Jetpack Compose + OkHttp) ساخته شد؛ جزئیات و
راهنمای ساخت در `android/README.md`. بیلد واقعی APK از طریق GitHub Actions
(`.github/workflows/android-build.yml`) انجام می‌شود چون محیط توسعه محلی این
جلسه فاقد JDK/Android SDK است.
