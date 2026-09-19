<?php
declare(strict_types=1);

/**
 * MeeLano Trading Intelligence — Backtest endpoint
 *
 * این فایل پیش‌تر یک موتور بک‌تست قدیمی و مستقل (api/decision.php) را اجرا
 * می‌کرد که با موتور ممیزی‌شده‌ی چهارگانه (AnalysisService::backtest) تفاوت
 * داشت و مسیر اصلاح‌شده‌ی روتر را دور می‌زد. اکنون صرفاً به روتر اصلی
 * (api/index.php) واگذار می‌کند تا یک مسیر واحد با احراز هویت، اعتبارسنجی و
 * موتور یکسان وجود داشته باشد.
 */
require __DIR__ . '/index.php';
