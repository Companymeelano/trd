# نسخه اندروید — MeeLano Trading Intelligence 4.0 Pro

کلاینت بومی اندروید برای هسته PHP موجود در این مخزن (`api5`). رابط کاربری با
Jetpack Compose و کاملاً راست‌چین (مانند کنسول وب) پیاده‌سازی شده و تمام
هفت نمای کنسول وب را پوشش می‌دهد:

| نمای وب | محل در اپ اندروید |
|---|---|
| داشبورد فرماندهی | تب «داشبورد» |
| اتاق تحلیل ۴گانه | تب «تحلیل ۴گانه» |
| رادار بازار و اسکنر | تب «رادار بازار» |
| تست استراتژی (بک‌تست) | تب «ژورنال و بک‌تست» |
| مانیتور ریسک و پوزیشن | کارت «مانیتور ریسک و پوزیشن» در داشبورد + جزئیات گیت Risk |
| ژورنال و تاریخچه سیگنال | تب «ژورنال و بک‌تست» |
| مرکز کنترل و تنظیمات | تب «تنظیمات» |

## امکانات
- اتصال به `api/index.php` با هدر `X-API-Token` (همه endpointها: `health.php`,
  `auth-check.php`, `analyze.php`, `history.php`, `scan.php`, `backtest.php`, `notify.php`).
- ذخیره توکن و تنظیمات فقط در حافظه خصوصی اپلیکیشن (`SharedPreferences` خصوصی؛
  `allowBackup=false` تا توکن هرگز در بک‌آپ ابری نرود).
- نمایش چهار گیت (Quantitative / Technical / Sentiment-Macro / Risk Management)
  با سنجه‌ها و دلایل، برنامه معامله (ورود/حد ضرر/TP1/TP2/RR/حجم)، حلقه امتیاز و
  نوارهای پیشرفت مشابه کنسول وب.
- اسکن ۱۲ نماد، بک‌تست ۸۰۰ کندلی، ژورنال ۱۰۰ رکوردی.
- ارسال سیگنال به تلگرام/وب‌هوک از طریق `notify.php` با همان قالب HTML کنسول وب.
- حالت نمایشی آفلاین («بارگذاری سیگنال نمونه» در تنظیمات) برای بررسی رابط بدون سرور.
- مدیریت خطا: تایم‌اوت، rate limit (429)، توکن نامعتبر (401) و خطای سرور (5xx)
  با پیام فارسی.

## پیش‌نیاز ساخت
- JDK 17
- Android SDK با `compileSdk 35` (Gradle Wrapper خود توزیع Gradle 8.9 را می‌گیرد)
- Android Studio یا خط فرمان

## ساخت محلی
```bash
cd android
./gradlew testDebugUnitTest   # تست‌های واحد
./gradlew assembleDebug       # APK قابل نصب: app/build/outputs/apk/debug/app-debug.apk
./gradlew assembleRelease     # APK ریلیز (با کلید امضای دلخواه یا کلید debug)
```

## ساخت خودکار (CI)
ورک‌فلوی `.github/workflows/android-build.yml` روی هر push به این شاخه اجرا می‌شود:
تست‌های واحد + `assembleDebug` + `assembleRelease` و آپلود هر دو APK به‌صورت
Artifact همان Run. برای دریافت APK:

```bash
gh run list --workflow=android-build.yml
gh run download <RUN_ID> -n meelano-trader-debug-apk
```

## امضای ریلیز (اختیاری)
اگر خواستید APK ریلیز با کلید خودتان امضا شود، یک keystore بسازید و مقادیر را
به‌صورت property یا متغیر محیط به Gradle بدهید؛ در غیر این صورت ریلیز با کلید
debug امضا می‌شود (قابل نصب، اما مناسب انتشار در Play نیست):

```bash
./gradlew assembleRelease \
  -PMEELANO_STORE_FILE=/path/keystore.jks \
  -PMEELANO_STORE_PASSWORD=*** \
  -PMEELANO_KEY_ALIAS=*** \
  -PMEELANO_KEY_PASSWORD=***
```

## پیکربندی داخل اپ
1. تب «تنظیمات» → آدرس سرور را وارد کنید؛ هم `https://domain/trader` و هم
   `https://domain/trader/api` پذیرفته می‌شود و به `.../api/` نرمال می‌شود.
2. توکن همان مقدار `APP_TOKEN` فایل `.env` سرور است (حداقل ۳۲ کاراکتر).
3. با «بررسی سلامت هسته» و «بررسی توکن» وضعیت `health.php` و `auth-check.php`
   در چیپ‌های بالای اپ نمایش داده می‌شود.

## حداقل نسخه اندروید
`minSdk 24` (اندروید ۷) — آیکون adaptive برای ۸ به بالا و PNG برای نسخه‌های قدیمی‌تر.
