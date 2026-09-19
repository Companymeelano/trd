#!/usr/bin/env bash
# نصب Android SDK داخل Codespace/devcontainer (بدون نیاز به GitHub Actions)
set -euo pipefail

SDK_ROOT="${ANDROID_HOME:-$HOME/android-sdk}"
mkdir -p "$SDK_ROOT/cmdline-tools"

if [ ! -x "$SDK_ROOT/cmdline-tools/latest/bin/sdkmanager" ]; then
  TMP_ZIP="$(mktemp /tmp/cmdtools-XXXX.zip)"
  URLS=(
    "https://dl.google.com/android/repository/commandlinetools-linux-13114758_latest.zip"
    "https://dl.google.com/android/repository/commandlinetools-linux-11076708_latest.zip"
  )
  ok=""
  for u in "${URLS[@]}"; do
    if curl -fsSL --retry 2 -o "$TMP_ZIP" "$u"; then ok="1"; break; fi
  done
  [ -n "$ok" ] || { echo "download failed"; exit 1; }

  TMP_DIR="$(mktemp -d /tmp/cmdtools-XXXX)"
  unzip -q "$TMP_ZIP" -d "$TMP_DIR"
  rm -f "$TMP_ZIP"
  mv "$TMP_DIR/cmdline-tools" "$SDK_ROOT/cmdline-tools/latest"
  rm -rf "$TMP_DIR"
fi

SDKMANAGER="$SDK_ROOT/cmdline-tools/latest/bin/sdkmanager"
yes 2>/dev/null | "$SDKMANAGER" --sdk_root="$SDK_ROOT" --licenses >/dev/null 2>&1 || true
"$SDKMANAGER" --sdk_root="$SDK_ROOT" \
  "platform-tools" "platforms;android-35" "platforms;android-34" \
  "build-tools;35.0.0" "build-tools;34.0.0" >/dev/null

grep -q 'android-sdk' "$HOME/.bashrc" 2>/dev/null || cat >> "$HOME/.bashrc" <<EOF

# Android SDK (devcontainer)
export ANDROID_HOME="$SDK_ROOT"
export ANDROID_SDK_ROOT="$SDK_ROOT"
export PATH="$SDK_ROOT/platform-tools:$SDK_ROOT/cmdline-tools/latest/bin:$PATH"
EOF

echo "Android SDK ready at $SDK_ROOT"
