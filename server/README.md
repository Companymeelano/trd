# MeeLano Trading Intelligence — v4.0 Pro

## هدف
هسته تحلیل چندلایه بازار با Binance، چهار Gate مستقل، مدیریت ریسک، بک‌تست، کش، circuit breaker و SQLite.

## نصب روی cPanel
1. کل پروژه را در `public_html/trader` قرار دهید.
2. فایل `.env.example` را به `.env` کپی کنید.
3. یک `APP_TOKEN` تصادفی حداقل 64 کاراکتری بسازید و در `.env` قرار دهید.
4. مطمئن شوید PHP 8.1+ و افزونه‌های `curl` و `pdo_sqlite` فعال هستند.
5. دسترسی نوشتن برای `storage/cache` و `storage/logs` و `data` فراهم باشد.
6. `/trader/api/health.php` را باز کنید؛ باید `status=ok` دریافت شود.
7. توکن را فقط داخل رابط کاربری وارد کنید؛ آن را داخل کد یا URL قرار ندهید.

## امنیت
- `.env`، SQLite، log و cache از دسترسی مستقیم وب مسدود شده‌اند.
- API از `X-API-Token` استفاده می‌کند.
- health بدون افشای secrets، وضعیت واقعی DB و PDO SQLite را گزارش می‌کند.
- در صورت افشای هر توکن/رمز قبلی، حتماً آن را rotate کنید.

## API
- `GET /api/health.php`
- `GET /api/auth-check.php`
- `POST /api/analyze.php`
- `GET /api/history.php?limit=50`
- `POST /api/backtest.php`
- `GET /api/scan.php?quote=USDT`

## نکته مهم
این موتور ابزار تصمیم‌یار است و تضمین سود یا پیش‌بینی قطعی بازار ارائه نمی‌کند. قبل از استفاده واقعی، بک‌تست خارج از نمونه، walk-forward و paper trading انجام دهید.
