# MeelanoTrader — قواعد ProGuard/R8
# این اپ فقط WebView است و کتابخانهٔ خارجی ندارد؛ قواعد پیش‌فرض کافی است.
# برای انتشار release با minifyEnabled true:
-keepclassmembers class ir.meelano.trader.** { *; }
-dontwarn android.webkit.**
