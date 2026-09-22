# اپ واحد هیبریدی — MeeLano Hybrid 6.0

> طراحی و توسعه: **Milad Yaghoobi** · **Meelano Studio Design**
> یک APK، دو سطح: **پوستهٔ بومی (Kotlin + Compose)** و **کنسول وب (PHP)** با **سقوط خودکار به کنسول آفلاین**.

تا امروز سه اپ جدا در مخزن بود: `android/` (WebView ساده)، `android-hostlink/` (کنسول HOST LINK با smali
دست‌نویس) و `android-native/` (کلاینت کامل Kotlin + Compose). هر کدام تنظیمات، توکن و مسیر اتصال خودش را
داشت. **`android-hybrid/` این سه را در یک پوستهٔ واحد جمع می‌کند**: یک آدرس هاست، یک توکن، یک موتور تصمیم —
و این پوسته تصمیم می‌گیرد هر صفحه از کجا بیاید.

---

## معماری: چه چیزی بومی است، چه چیزی وب

| تب | سطح | چرا |
|---|---|---|
| **وضعیت** | بومی | باید بدون هاست هم بالا بیاید؛ نتیجهٔ سلامت‌سنجی و دلیل انتخاب سطح را نشان می‌دهد |
| **کنسول** | وب (`<host>/index.php`) | کل کنسول v5.x با ۳۴ فیلتر، ترید خودکار و اطلاع‌رسانی — بدون بازنویسی |
| **اتصال** | بومی | صفحه‌ای که از هاست سرو می‌شود نمی‌تواند جای درستِ تنظیم همان هاست باشد |

اگر هاست در دسترس نباشد (DNS، TLS، timeout، 5xx یا قطع اینترنت)، تب کنسول به‌جای اسپینر،
**کنسول آفلاینِ همراه اپ** را از `assets/www/offline.html` نشان می‌دهد؛ همان پل بومی در آن هم تزریق
می‌شود، پس به‌محض بازگشت اتصال، همان درخواست‌ها به هستهٔ PHP می‌رسند.

```
┌────────────────────────── یک APK ──────────────────────────┐
│  پوستهٔ Compose (بومی)                                     │
│   ├─ تب وضعیت   ← HybridRouter ← HostProbe(api/health.php) │
│   ├─ تب اتصال   ← HybridSettings (هاست + توکن، خصوصی)      │
│   └─ تب کنسول   ← WebView                                  │
│         ├─ https://<host>/index.php      (هاست سالم)       │
│         └─ appassets…/assets/www/offline.html  (سقوط)      │
│                     │                                      │
│         window.MeelanoBridge  ←─ assets/www/hybrid-bridge.js│
│                     │                                      │
│              CoreApi (OkHttp) → <host>/api/*.php           │
└────────────────────────────────────────────────────────────┘
```

## پل بومی (چرا توکن وارد JavaScript نمی‌شود)

صفحهٔ وب **هرگز** مستقیم به هستهٔ PHP درخواست نمی‌زند. صفحه `window.MeelanoHybrid.request('analyze', {...})`
را صدا می‌کند؛ `hybrid-bridge.js` آن را با یک شناسه به آبجکت جاوایی `MeelanoBridge` می‌سپارد؛ `CoreApi`
هدر `X-API-Token` را اضافه می‌کند و پاسخ را با
`window.MeelanoHybrid.deliver(envelope)` برمی‌گرداند. نتیجه:

- توکن API هیچ‌وقت در DOM، `localStorage` یا کنسول JS دیده نمی‌شود؛
- مشکل CORS از ریشه حذف می‌شود (درخواست از اپ می‌رود، نه از صفحه)؛
- صفحهٔ وب همان JSON آشنای `api/*.php` را می‌گیرد، پس کنسول v5.x بدون تغییر کار می‌کند.

فهرست مجاز اکشن‌ها در سه جا **یکسان** است و توسط تست قفل شده:
`BridgeContract.ALLOWED_ACTIONS` (Kotlin) = `EndpointMap` + اکشن بومی `settings` = `ALLOWED_ACTIONS` در JS.

| اکشن | مقصد | احراز |
|---|---|---|
| `health` | `GET api/health.php` | خیر |
| `auth-check` | `GET api/auth-check.php` | بله |
| `history` | `GET api/history.php` | بله |
| `scan` | `POST api/scan.php` | بله |
| `analyze` | `POST api/analyze.php` | بله |
| `backtest` | `POST api/backtest.php` | بله |
| `notify` | `POST api/notify.php` | بله |
| `settings` | **بومی** — از `HybridSettings` پاسخ می‌گیرد، نه سرور | — |

## سلامت‌سنجی: یک کاوش، سه نتیجه

`HostProbe` پاسخ `api/health.php` را به یک حکم قطعی تبدیل می‌کند و آن حکم تصمیم می‌گیرد WebView اجازهٔ
بارگذاری کنسول راه دور را دارد یا نه:

| پاسخ | حکم | کنسول راه دور |
|---|---|---|
| 200 + دیتابیس متصل + نسخهٔ ≥ ۴٫۰ | `ONLINE` | بله |
| 200 اما کند (> ۴ ثانیه) | `ONLINE` با پیام کندی | بله |
| 200 + دیتابیس قطع یا هستهٔ قدیمی | `DEGRADED` | بله (با هشدار) |
| **503** (هسته بالا اما degraded) | `DEGRADED` | بله |
| 200 با بدنهٔ غیر JSON | `BAD_PAYLOAD` | خیر |
| 401 / 403 | `UNAUTHORIZED` | خیر |
| 404 | `NOT_INSTALLED` | خیر |
| 429 | `RATE_LIMITED` | خیر |
| 5xx | `SERVER_ERROR` | خیر |
| timeout / DNS / TLS / refused / IO | همان خطا | خیر → کنسول آفلاین |

هر دو شکل `health.php` موجود در مخزن فهمیده می‌شود: `server/api/health.php` با
`{"status":"ok","version":"4.0.0","database":"connected"}` و `api/health.php` با
`{"checks":{"db":{"ok":true}}}`.

## امنیت

- `allowBackup="false"` — توکن وارد پشتیبان‌گیری ابری نمی‌شود.
- `proguard-rules.pro` متدهای `@JavascriptInterface` را نگه می‌دارد (R8 آن‌ها را بر اساس نام صدا می‌زند).
- assetها با `WebViewAssetLoader` روی مبدأ مصنوعی `https://appassets.androidplatform.net` سرو می‌شوند،
  نه `file://` — بدون نیاز به `allowFileAccess`.
- ناوبری بیرون از ریشهٔ هاست و assetهای همراه **مسدود** و لاگ می‌شود (`HybridConfig.isInternalTarget`).
- `BridgeContract.jsonString` علاوه بر کاراکترهای اجباری JSON، `<`، `>`، `&`، U+2028 و U+2029 را هم
  escape می‌کند تا payload نتواند از `<script>` یا `evaluateJavascript` بیرون بزند.
- HTTPS پیش‌فرض است (`normalizeHost` به آدرس برهنه `https://` اضافه می‌کند) و در صورت http هشدار نمایش
  داده می‌شود.

## ساخت

```bash
cd android-hybrid
./gradlew testDebugUnitTest assembleDebug      # تست واحد + APK قابل نصب
./gradlew assembleRelease                      # با کلید دیباگ، مگر secrets داده شود
```

امضای نسخهٔ رسمی (اختیاری):

```bash
./gradlew assembleRelease \
  -PMEELANO_STORE_FILE=/path/to/keystore.jks \
  -PMEELANO_STORE_PASSWORD=*** \
  -PMEELANO_KEY_ALIAS=*** \
  -PMEELANO_KEY_PASSWORD=***
```

## تست

```bash
# بدون JVM — از ریشهٔ مخزن
python3 tests/android_hybrid_static_check.py   # منابع، مانیفست، توازن براکت، قرارداد Kotlin↔JS
node tests/hybrid-bridge.test.mjs              # ۱۴ تست روی hybrid-bridge.js واقعی

# با JVM — داخل android-hybrid
./gradlew testDebugUnitTest                    # HybridRouter / HostProbe / BridgeContract / HybridConfig
```

`tests/android_hybrid_static_check.py` علاوه بر چک‌های معمول (برابری رشته‌های fa/en، وجود منابع
مانیفست، توازن براکت‌ها) این‌ها را هم کنترل می‌کند: هر متد `@JavascriptInterface` واقعاً از JS صدا زده
شود، فهرست اکشن‌ها در سه تعریف یکسان بماند، رویداد `meelano-ready` همان باشد که `offline.html`
گوش می‌دهد، و هر asset که از Kotlin ارجاع شده روی دیسک وجود داشته باشد.

## ساختار

```
android-hybrid/
├── app/src/main/java/ir/meelano/hybrid/
│   ├── core/HybridConfig.kt      نرمال‌سازی آدرس، مبدأها، مسیر assetها (خالص)
│   ├── core/HybridRouter.kt      کدام تب، کدام سطح، به کدام دلیل (خالص)
│   ├── core/HostProbe.kt         کاوش سلامت → حکم و اجازهٔ کنسول (خالص)
│   ├── core/BridgeContract.kt    فرمت JSON پل + escape امن (خالص)
│   ├── data/HybridSettings.kt    هاست/توکن/نماد/تایم‌فریم در حافظهٔ خصوصی
│   ├── web/EndpointMap.kt        اکشن → endpoint هستهٔ PHP
│   ├── web/CoreApi.kt            OkHttp + هدر X-API-Token
│   ├── web/MeelanoBridge.kt      آبجکت تزریق‌شده به صفحه (@JavascriptInterface)
│   ├── web/ConsoleWeb.kt         ساخت WebView + AssetLoader + مهار ناوبری
│   ├── web/Injector.kt           تزریق پل و رویداد meelano-ready
│   ├── HybridViewModel.kt        حلقهٔ تصمیم: تنظیمات → کاوش → روتر → سطح
│   └── ui/                       پوستهٔ Compose (سه تب)
├── app/src/main/assets/www/
│   ├── hybrid-bridge.js          نیمهٔ JavaScript پل (در هر صفحه‌ای تزریق می‌شود)
│   └── offline.html              کنسول آفلاین همراه اپ
└── app/src/test/…                تست‌های JVM روتر، کاوش، قرارداد و آدرس‌ها
```

## ⚠️ سلب مسئولیت

این اپ ابزار تصمیم‌یار است؛ سیگنال‌ها پیشنهاد معامله نیستند و هیچ سودی تضمین نمی‌شود.
