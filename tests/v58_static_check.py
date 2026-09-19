#!/usr/bin/env python3
"""
Static security/consistency checks for the v5.8 codebase (no PHP required).

Usage: python3 tests/v58_static_check.py
"""
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
failures = []
warnings = []

api_dir = ROOT / "api"
api_files = sorted(p for p in api_dir.glob("*.php"))
for php in api_files:
    name = php.name
    src = php.read_text(encoding="utf-8")
    if name in {"bootstrap.php"}:
        continue
    if name == "health.php":
        continue  # عمومی by design
    if name == "tracker.php":
        if "m_guard" not in src or "key" not in src:
            failures.append(f"api/{name}: cron key or guard missing")
        continue
    has_guard = "m_guard(" in src
    has_inline_auth = "Security::isLoggedIn()" in src
    has_csrf = "Security::requireCsrf()" in src or has_guard
    if not has_guard and not has_inline_auth:
        failures.append(f"api/{name}: no m_guard() and no inline auth — endpoint may be unauthenticated")
    elif has_inline_auth and not has_guard:
        if "Security::requireRateLimit(" not in src and "rateLimit" not in src:
            warnings.append(f"api/{name}: inline guard without rate limiting")
        if not has_csrf:
            warnings.append(f"api/{name}: inline guard without explicit CSRF (acceptable only for read-only GET)")

bootstrap = (api_dir / "bootstrap.php").read_text(encoding="utf-8")
if "$unsafe" not in bootstrap or "requireCsrf" not in bootstrap:
    failures.append("api/bootstrap.php: m_guard does not force CSRF on unsafe methods")

security = (ROOT / "includes" / "Security.php").read_text(encoding="utf-8")
rate_block = security[security.index("function rateLimit"):security.index("function requireRateLimit")]
if "$_SESSION" in rate_block:
    failures.append("Security::rateLimit still stores counters in the session (bypassable)")
if "flock" not in rate_block:
    warnings.append("Security::rateLimit has no file lock (race under concurrency)")
ip_block = security[security.index("function ip"):]
if "trust_proxy_headers" not in ip_block:
    failures.append("Security::ip trusts proxy headers unconditionally")

htaccess = (ROOT / ".htaccess").read_text(encoding="utf-8")
for needle in ("config", "includes", "storage", "tools", "tests"):
    if needle not in htaccess:
        failures.append(f".htaccess does not mention {needle}")

js_dir = ROOT / "assets" / "js"
targets = set()
for js in js_dir.glob("*.js"):
    targets |= set(re.findall(r"['\"](api/[\w.-]+\.php)", js.read_text(encoding="utf-8")))
for target in sorted(targets):
    if not (ROOT / target).exists():
        failures.append(f"JS calls missing endpoint: {target}")

main_activity = ROOT / "android" / "app" / "src" / "main" / "java" / "ir" / "meelano" / "trader" / "MainActivity.java"
if main_activity.exists():
    src = main_activity.read_text(encoding="utf-8")
    if "setAllowFileAccess(false)" not in src:
        failures.append("WebView: file access not disabled")
    if "MIXED_CONTENT_NEVER_ALLOW" not in src:
        failures.append("WebView: mixed content not blocked")
    if "handler.cancel()" not in src:
        failures.append("WebView: SSL errors not cancelled")
else:
    warnings.append("android WebView MainActivity not found")

login = (ROOT / "login.php").read_text(encoding="utf-8")
if "rateLimit" not in login:
    failures.append("login.php: no rate limiting on password attempts")

apk = list(ROOT.glob("*.apk"))
if apk:
    warnings.append(f"binary APK committed at repo root: {[a.name for a in apk]} (history bloat; prefer CI artifacts/releases)")

smali = ROOT / "android-hostlink" / "smali"
if smali.exists():
    warnings.append("android-hostlink/smali (decompiled build intermediates) is committed; consider removing")

print(f"v58 static checks: {len(api_files)} api files, {len(targets)} JS endpoint refs")
for w in warnings:
    print("WARN:", w)
if failures:
    print("FAILURES:")
    for f in failures:
        print(" -", f)
    sys.exit(1)
print("v58 static checks: OK")
