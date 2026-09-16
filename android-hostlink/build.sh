#!/usr/bin/env bash
# ساخت MeelanoTrader HostLink APK — مسیر ابزارها را تنظیم کنید.
# پیش‌نیازها: java (JDK 8+)، aapt2، apktool.jar، openssl، python3
set -euo pipefail

JAVA=${JAVA:-java}
AAPT2=${AAPT2:-aapt2}
APKTOOL=${APKTOOL:-apktool.jar}

# ۱) منابع: کامپایل + لینک (framework = کتابخانهٔ منابع اندروید به‌عنوان -I)
"$AAPT2" compile --dir app/res -o res.zip
"$AAPT2" link -o base.apk -I framework-1.apk \
    --manifest app/AndroidManifest.xml \
    --min-sdk-version 21 --target-sdk-version 23 \
    --version-code 58 --version-name 5.8 \
    -R res.zip --auto-add-overlay

# ۲) کد: smali → classes.dex
cp -r smali dexbuild/smali
cp app/AndroidManifest.xml dexbuild/ 2>/dev/null || true
"$JAVA" -jar "$APKTOOL" b dexbuild -o out-dex.apk
# classes.dex در dexbuild/build/apk/classes.dex ساخته می‌شود

# ۳) بسته‌بندی + امضای v1
python3 pack_and_sign.py
