#!/usr/bin/env python3
"""
Static consistency checks for the hybrid Android client (no JVM required).

Same idea as tests/android_static_check.py, plus the cross-language checks the
hybrid app needs, because its contract spans three languages:

 * every R.string.* referenced from Kotlin exists in values/ and values-en/
 * every resource referenced from AndroidManifest.xml exists
 * every custom composable that is called is also defined
 * brace/paren balance per Kotlin file
 * the injected bridge agrees with assets/www/hybrid-bridge.js:
     - injected namespace / published global
     - every @JavascriptInterface method is actually called from JS
     - the action allow-list is identical in Kotlin and JavaScript
 * the DOM event the shell dispatches is the one the offline console waits for
 * every asset path referenced from Kotlin exists on disk

Usage: python3 tests/android_hybrid_static_check.py
"""
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
ANDROID = ROOT / "android-hybrid"
MAIN = ANDROID / "app" / "src" / "main"
ASSETS = MAIN / "assets"
KT = sorted(MAIN.rglob("*.kt")) + sorted((ANDROID / "app" / "src" / "test").rglob("*.kt"))
BRIDGE_JS = ASSETS / "www" / "hybrid-bridge.js"
OFFLINE_HTML = ASSETS / "www" / "offline.html"

failures = []

if not KT:
    print("FAILURES:\n - no Kotlin sources found under android-hybrid/")
    sys.exit(1)

# ── 1) string resources ────────────────────────────────────────────────────────


def strings_of(path: Path) -> set:
    return set(re.findall(r'<string name="([^"]+)"', path.read_text(encoding="utf-8")))


fa = strings_of(MAIN / "res" / "values" / "strings.xml")
en = strings_of(MAIN / "res" / "values-en" / "strings.xml")
if fa != en:
    failures.append(f"string locale mismatch: fa-only={sorted(fa - en)} en-only={sorted(en - fa)}")

used = set()
for kt in KT:
    used |= set(re.findall(r"R\.string\.(\w+)", kt.read_text(encoding="utf-8")))
missing_fa = used - fa
missing_en = used - en
if missing_fa:
    failures.append(f"R.string missing in values/strings.xml: {sorted(missing_fa)}")
if missing_en:
    failures.append(f"R.string missing in values-en/strings.xml: {sorted(missing_en)}")

# ── 2) manifest references ─────────────────────────────────────────────────────

manifest_path = MAIN / "AndroidManifest.xml"
manifest = manifest_path.read_text(encoding="utf-8")
res_dir = MAIN / "res"
themes = (res_dir / "values" / "themes.xml").read_text(encoding="utf-8")
colors = (res_dir / "values" / "colors.xml").read_text(encoding="utf-8")
for kind, name in re.findall(r"@(string|mipmap|style|color|drawable)/([\w.]+)", manifest):
    if kind == "string" and name not in fa:
        failures.append(f"manifest references missing string {name}")
    if kind == "mipmap" and not list(res_dir.glob(f"mipmap-*/{name}.png")) and not list(res_dir.glob(f"mipmap-*/{name}.xml")):
        failures.append(f"manifest references missing mipmap {name}")
    if kind == "style" and f'"{name}"' not in themes:
        failures.append(f"manifest references missing style {name}")
    if kind == "color" and f'"{name}"' not in colors:
        failures.append(f"manifest references missing color {name}")

activity = re.search(r'android:name="\.(\w+)"', manifest)
if not activity:
    failures.append("manifest declares no activity")
else:
    activity_file = MAIN / "java" / "ir" / "meelano" / "hybrid" / f"{activity.group(1)}.kt"
    if not activity_file.exists():
        failures.append(f"manifest activity .{activity.group(1)} has no {activity_file.name}")

# ── 2b) resources referenced from other res XML files ──────────────────────────
#
# The manifest is not the only place that references resources: adaptive icons
# point at drawables/colors. Those references are resolved by aapt2 at link
# time, so a missing one fails the APK build long after these checks pass.

available_drawables = {p.stem for p in res_dir.glob("drawable*/*")}
available_mipmaps = {p.stem for p in res_dir.glob("mipmap-*/*")}
available_colors = set(re.findall(r'<color name="([^"]+)"', colors))
available_strings = set(fa)
available_styles = set(re.findall(r'<style name="([^"]+)"', themes))
available_xml = {p.stem for p in res_dir.glob("xml/*")}

for xml_file in sorted(res_dir.glob("*/*.xml")):
    if xml_file.parent.name.startswith("values"):
        continue
    text = xml_file.read_text(encoding="utf-8")
    for kind, name in set(re.findall(r'"@(string|mipmap|style|color|drawable|xml)/([\w.]+)"', text)):
        rel = f"{xml_file.parent.name}/{xml_file.name}"
        if kind == "drawable" and name not in available_drawables:
            failures.append(f"{rel}: references missing @drawable/{name}")
        if kind == "mipmap" and name not in available_mipmaps:
            failures.append(f"{rel}: references missing @mipmap/{name}")
        if kind == "color" and name not in available_colors:
            failures.append(f"{rel}: references missing @color/{name}")
        if kind == "string" and name not in available_strings:
            failures.append(f"{rel}: references missing @string/{name}")
        if kind == "style" and name not in available_styles:
            failures.append(f"{rel}: references missing @style/{name}")
        if kind == "xml" and name not in available_xml:
            failures.append(f"{rel}: references missing @xml/{name}")

# ── 3) composables ─────────────────────────────────────────────────────────────

all_src = "\n".join(kt.read_text(encoding="utf-8") for kt in KT)
defined = set(re.findall(r"fun (\w+)\(", all_src))
custom = {
    "HybridApp", "ShellHeader", "ShellBottomBar", "StatusScreen", "SetupScreen",
    "ConsoleScreen", "GlassCard", "StatusChip", "StatusDot", "InfoRow", "HintNote",
    "HybridTheme",
}
undefined = custom - defined
if undefined:
    failures.append(f"composables called but never defined: {sorted(undefined)}")

called = set()
for kt in KT:
    text = kt.read_text(encoding="utf-8")
    called |= set(re.findall(r"\b(HybridApp|StatusScreen|SetupScreen|ConsoleScreen|GlassCard|StatusChip|InfoRow|HintNote)\(", text))
never_called = custom - called - {"ShellHeader", "ShellBottomBar", "StatusDot", "HybridTheme"}
if never_called:
    failures.append(f"composables defined but never used: {sorted(never_called)}")

# ── 3b) backtick test names must be legal JVM identifiers ──────────────────────
#
# Kotlin allows spaces in `backtick names`, but the JVM forbids . ; [ / < > : in
# method names — compileDebugUnitTestKotlin fails with
# "Name contains illegal characters".

ILLEGAL_NAME_CHARS = set(".;[]/<>:")
for kt in KT:
    for name in re.findall(r"fun `([^`]+)`\s*\(", kt.read_text(encoding="utf-8")):
        bad = sorted(set(name) & ILLEGAL_NAME_CHARS)
        if bad:
            failures.append(f"{kt.name}: test name `{name}` contains illegal characters {bad}")

# ── 4) brace balance ───────────────────────────────────────────────────────────


def scan_balance(path: Path):
    """Depth-0 check that understands Kotlin escapes, raw strings and comments.

    The naive scanner in tests/android_static_check.py trips over char literals
    such as '\\\\' (it reads the escaped backslash as escaping the closing
    quote), which this file needs because BridgeContract.jsonString is written
    with exactly those literals.
    """
    src = path.read_text(encoding="utf-8")
    errors = []
    depth = 0
    line = 1
    i = 0
    n = len(src)
    while i < n:
        c = src[i]
        if c == "\n":
            line += 1
            i += 1
            continue
        if src.startswith("//", i):
            while i < n and src[i] != "\n":
                i += 1
            continue
        if src.startswith("/*", i):
            end = src.find("*/", i + 2)
            if end == -1:
                errors.append(f"line {line}: unterminated block comment")
                break
            line += src.count("\n", i, end)
            i = end + 2
            continue
        if src.startswith('"""', i):
            end = src.find('"""', i + 3)
            if end == -1:
                errors.append(f"line {line}: unterminated raw string")
                break
            line += src.count("\n", i, end)
            i = end + 3
            continue
        if c in ('"', "'"):
            quote = c
            i += 1
            while i < n:
                if src[i] == "\\":
                    i += 2
                    continue
                if src[i] == "\n":
                    line += 1
                if src[i] == quote:
                    i += 1
                    break
                i += 1
            continue
        if c == "{":
            depth += 1
        elif c == "}":
            depth -= 1
            if depth < 0:
                errors.append(f"line {line}: unbalanced }}")
                depth = 0
        i += 1
    if depth != 0:
        errors.append(f"unbalanced braces (depth {depth})")
    return errors


for kt in KT:
    for problem in scan_balance(kt):
        failures.append(f"{kt.name}: {problem}")

# ── 5) assets referenced from Kotlin exist ─────────────────────────────────────

config_src = (MAIN / "java" / "ir" / "meelano" / "hybrid" / "core" / "HybridConfig.kt").read_text(encoding="utf-8")
asset_paths = set(re.findall(r'const val \w+_ASSET_PATH = "([^"]+)"', config_src))
if not asset_paths:
    failures.append("HybridConfig declares no *_ASSET_PATH constants")
for relative in sorted(asset_paths):
    if not (ASSETS / relative).exists():
        failures.append(f"HybridConfig asset path missing on disk: assets/{relative}")

# ── 6) Kotlin <-> JavaScript bridge contract ───────────────────────────────────

if not BRIDGE_JS.exists():
    failures.append(f"missing asset {BRIDGE_JS.relative_to(ROOT)}")
if not OFFLINE_HTML.exists():
    failures.append(f"missing asset {OFFLINE_HTML.relative_to(ROOT)}")

contract_src = (MAIN / "java" / "ir" / "meelano" / "hybrid" / "core" / "BridgeContract.kt").read_text(encoding="utf-8")
endpoint_src = (MAIN / "java" / "ir" / "meelano" / "hybrid" / "web" / "EndpointMap.kt").read_text(encoding="utf-8")
bridge_kt = (MAIN / "java" / "ir" / "meelano" / "hybrid" / "web" / "MeelanoBridge.kt").read_text(encoding="utf-8")

if BRIDGE_JS.exists():
    js = BRIDGE_JS.read_text(encoding="utf-8")
    offline = OFFLINE_HTML.read_text(encoding="utf-8") if OFFLINE_HTML.exists() else ""

    namespace_kt = re.search(r'const val JS_NAMESPACE = "([^"]+)"', contract_src)
    global_kt = re.search(r'const val JS_GLOBAL = "([^"]+)"', contract_src)
    if not namespace_kt or not global_kt:
        failures.append("BridgeContract must declare JS_NAMESPACE and JS_GLOBAL")
    else:
        if f"var JS_NAMESPACE = '{namespace_kt.group(1)}'" not in js:
            failures.append(f"JS_NAMESPACE mismatch: Kotlin={namespace_kt.group(1)} not declared in hybrid-bridge.js")
        if f"window.{global_kt.group(1)}" not in js:
            failures.append(f"JS_GLOBAL mismatch: hybrid-bridge.js never publishes window.{global_kt.group(1)}")
        if offline and f"window.{global_kt.group(1)}" not in offline:
            failures.append(f"offline.html does not use window.{global_kt.group(1)}")

    # every injected method must actually be called from JS
    injected = set(re.findall(r"@JavascriptInterface\s+fun (\w+)\(", bridge_kt))
    if not injected:
        failures.append("MeelanoBridge declares no @JavascriptInterface methods")
    for method in sorted(injected):
        if f"native.{method}(" not in js:
            failures.append(f"@JavascriptInterface method `{method}` is never called from hybrid-bridge.js")
    proguard = (ANDROID / "app" / "proguard-rules.pro").read_text(encoding="utf-8")
    if "JavascriptInterface" not in proguard or "MeelanoBridge" not in proguard:
        failures.append("proguard-rules.pro must keep the @JavascriptInterface methods of MeelanoBridge")

    # action allow-lists must match across Kotlin and JavaScript
    allowed_kt = set(re.findall(r'"([a-z-]+)",?', contract_src.split("val ALLOWED_ACTIONS")[1].split(")")[0]))
    js_actions = set(re.findall(r"'([a-z-]+)'", js.split("var ALLOWED_ACTIONS = [")[1].split("]")[0]))
    endpoint_actions = set(re.findall(r'"([a-z-]+)"\s+to\s+Endpoint\(', endpoint_src))
    native_segment = endpoint_src.split("val NATIVE_ONLY_ACTIONS")[1].split("\n")[0]
    native_only = set(re.findall(r'"([a-z-]+)"', native_segment))
    for constant in re.findall(r"listOf\(([^)]*)\)", native_segment):
        for name in [c.strip() for c in constant.split(",") if c.strip() and not c.strip().startswith('"')]:
            declared = re.search(r'const val %s = "([^"]+)"' % re.escape(name), endpoint_src)
            if declared:
                native_only.add(declared.group(1))
    if allowed_kt != js_actions:
        failures.append(f"action allow-list drift: kotlin-only={sorted(allowed_kt - js_actions)} js-only={sorted(js_actions - allowed_kt)}")
    if allowed_kt != endpoint_actions | native_only:
        failures.append(
            "BridgeContract.ALLOWED_ACTIONS != EndpointMap.allActions(): "
            f"contract-only={sorted(allowed_kt - (endpoint_actions | native_only))} "
            f"endpoints-only={sorted((endpoint_actions | native_only) - allowed_kt)}"
        )

    # the ready event the shell dispatches must be the one the page waits for
    injector_src = (MAIN / "java" / "ir" / "meelano" / "hybrid" / "web" / "Injector.kt").read_text(encoding="utf-8")
    event = re.search(r"const val READY_EVENT = \"([^\"]+)\"", injector_src)
    if not event:
        failures.append("Injector must declare READY_EVENT")
    elif offline and f"'{event.group(1)}'" not in offline and f'"{event.group(1)}"' not in offline:
        failures.append(f"offline.html never listens for the `{event.group(1)}` event")

    if offline and "hybrid-bridge.js" not in offline:
        failures.append("offline.html does not load hybrid-bridge.js")

# ── 7) BuildConfig requires the gradle flag ────────────────────────────────────

gradle_app = (ANDROID / "app" / "build.gradle.kts").read_text(encoding="utf-8")
if "BuildConfig." in all_src and "buildConfig = true" not in gradle_app:
    failures.append("Kotlin uses BuildConfig but app/build.gradle.kts does not enable buildConfig")
if "ir.meelano.hybrid" not in gradle_app:
    failures.append("app/build.gradle.kts does not set the ir.meelano.hybrid namespace")

if failures:
    print("FAILURES:")
    for f in failures:
        print(" -", f)
    sys.exit(1)

print(
    f"android-hybrid static checks: OK "
    f"({len(KT)} kotlin files, {len(used)} string refs, {len(injected)} bridge methods, "
    f"{len(allowed_kt)} bridge actions, {len(asset_paths)} assets)"
)
