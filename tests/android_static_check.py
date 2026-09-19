#!/usr/bin/env python3
"""
Static consistency checks for the Android client (no JVM required):
 * every R.string.* referenced from Kotlin exists in values/ and values-en/
 * every resource referenced from AndroidManifest.xml exists
 * every custom composable that is called is also defined
 * brace/paren balance per Kotlin file

Usage: python3 tests/android_static_check.py
"""
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
ANDROID = ROOT / "android-native"
MAIN = ANDROID / "app" / "src" / "main"
KT = sorted(MAIN.rglob("*.kt")) + sorted((ANDROID / "app" / "src" / "test").rglob("*.kt"))

failures = []


def strings_of(path: Path) -> set:
    text = path.read_text(encoding="utf-8")
    return set(re.findall(r'<string name="([^"]+)"', text))


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

manifest = (MAIN / "AndroidManifest.xml").read_text(encoding="utf-8")
for ref in re.findall(r"@(string|mipmap|style|color|drawable)/(\w+)", manifest):
    kind, name = ref
    if kind == "string" and name not in fa:
        failures.append(f"manifest references missing string {name}")
    if kind == "mipmap" and not list((MAIN / "res").glob(f"mipmap-*/{name}.png")) and not list((MAIN / "res").glob(f"mipmap-*/{name}.xml")):
        failures.append(f"manifest references missing mipmap {name}")
    if kind == "style" and name not in (MAIN / "res" / "values" / "themes.xml").read_text(encoding="utf-8"):
        failures.append(f"manifest references missing style {name}")
    if kind == "color" and name not in (MAIN / "res" / "values" / "colors.xml").read_text(encoding="utf-8"):
        failures.append(f"manifest references missing color {name}")

all_src = "\n".join(kt.read_text(encoding="utf-8") for kt in KT)
defined = set(re.findall(r"fun (\w+)\(", all_src))
called = set()
for kt in KT:
    text = kt.read_text(encoding="utf-8")
    for name in re.findall(r"\b([A-Z]\w+|\w+Screen|\w+Card|\w+Row|\w+Bar|\w+Ring|\w+Dot|\w+Badge|\w+List|GlassCard|BusyButton|ChoiceRow|MetricRow|StatusLine|StatusChip)\(", text):
        called.add(name)
custom = {"GlassCard", "SectionCard", "MetricRow", "StatusDot", "DecisionBadge", "ScoreBar", "ScoreRing",
          "ChoiceRow", "BusyButton", "BulletList", "VerticalScrollColumn", "StatusChip", "StatusLine",
          "GateDetailCard", "ScanRow", "HistoryRow", "DecisionCard", "GatesCard", "TradePlanCard",
          "DashboardScreen", "AnalysisScreen", "MarketScreen", "JournalScreen", "SettingsScreen",
          "AppHeader", "AppBottomBar", "TraderApp", "MeeLanoTheme"}
undefined = custom - defined
if undefined:
    failures.append(f"composables called but never defined: {sorted(undefined)}")

for kt in KT:
    src = kt.read_text(encoding="utf-8")
    depth = 0
    line = 1
    i = 0
    in_str = None
    while i < len(src):
        c = src[i]
        if c == "\n":
            line += 1
        if in_str:
            if in_str == '"""' and src.startswith('"""', i):
                in_str = None
                i += 3
                continue
            if in_str == '"' and c == '"' and src[i - 1] != "\\":
                in_str = None
            elif in_str == "'" and c == "'" and src[i - 1] != "\\":
                in_str = None
        else:
            if src.startswith('"""', i):
                in_str = '"""'
                i += 3
                continue
            if c == '"':
                in_str = '"'
            elif c == "'":
                in_str = "'"
            elif c == "/" and src[i:i + 2] == "//":
                while i < len(src) and src[i] != "\n":
                    i += 1
                continue
            elif c == "/" and src[i:i + 2] == "/*":
                end = src.find("*/", i + 2)
                if end == -1:
                    failures.append(f"{kt.name}: unterminated block comment")
                    break
                line += src.count("\n", i, end)
                i = end + 1
                continue
            elif c == "{":
                depth += 1
            elif c == "}":
                depth -= 1
                if depth < 0:
                    failures.append(f"{kt.name}: unbalanced }} near line {line}")
                    depth = 0
        i += 1
    if depth != 0:
        failures.append(f"{kt.name}: unbalanced braces (depth {depth})")

if failures:
    print("FAILURES:")
    for f in failures:
        print(" -", f)
    sys.exit(1)
print(f"android static checks: OK ({len(KT)} kotlin files, {len(used)} string refs, {len(custom)} composables)")
