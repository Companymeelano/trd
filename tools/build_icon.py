#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
سازندهٔ آیکون‌های اندروید پنل هوشمند میلانو.
از assets/icon/icon_master.png همهٔ چگالی‌ها و لایه‌های Adaptive Icon را می‌سازد
طوری که طرح اصلی بزرگ و بدون حاشیهٔ اضافه در لانچر دیده شود.

Meelano Studio Design — Milad Yaghoobi

اجرا:  python3 tools/build_icon.py
"""
import os
from PIL import Image, ImageFilter, ImageEnhance

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
MASTER = os.path.join(ROOT, 'assets', 'icon', 'icon_master.png')
RES = os.path.join(ROOT, 'android', 'app', 'src', 'main', 'res')

LEGACY = {'mdpi': 48, 'hdpi': 72, 'xhdpi': 96, 'xxhdpi': 144, 'xxxhdpi': 192}
ADAPTIVE = {'mdpi': 108, 'hdpi': 162, 'xhdpi': 216, 'xxhdpi': 324, 'xxxhdpi': 432}


def ensure(path):
    os.makedirs(path, exist_ok=True)
    return path


def radial_alpha(img, size, fill=0.9):
    """مقیاس master روی بوم شفاف به‌گونه‌ای که طرح بزرگ در safe-zone بماند."""
    canvas = Image.new('RGBA', (size, size), (0, 0, 0, 0))
    scaled_w = int(size * fill)
    scaled = img.convert('RGBA').resize((scaled_w, scaled_w), Image.LANCZOS)
    off = (size - scaled_w) // 2
    canvas.paste(scaled, (off, off), scaled)
    return canvas


def blurred_bg(img, size):
    """پس‌زمینهٔ Adaptive: هنر محو و تیره‌شده برای هم‌نشینی با foreground."""
    bg = img.convert('RGB').resize((size, size), Image.LANCZOS)
    bg = bg.filter(ImageFilter.GaussianBlur(max(4, size // 14)))
    bg = ImageEnhance.Brightness(bg).enhance(0.55)
    return bg.convert('RGBA')


def monochrome(img, size):
    """نسخهٔ تک‌رنگ (Themed Icon): نقاط روشن سفید، پس‌زمینه شفاف."""
    gray = img.convert('L').resize((size, size), Image.LANCZOS)
    # افزایش کنتراست تا طلایی‌ها سفیدِ پررنگ شوند
    gray = ImageEnhance.Contrast(gray).enhance(1.6)
    px = gray.load()
    out = Image.new('RGBA', (size, size), (0, 0, 0, 0))
    op = out.load()
    for y in range(size):
        for x in range(size):
            l = px[x, y]
            a = min(255, int(l * 1.4))  # روشن‌تر = شفاف‌تر سفید
            op[x, y] = (255, 255, 255, a)
    return out


def main():
    master = Image.open(MASTER).convert('RGBA')
    print('master:', master.size)

    for dpi, s in LEGACY.items():
        d = ensure(os.path.join(RES, 'mipmap-' + dpi))
        icon = master.resize((s, s), Image.LANCZOS)
        icon.save(os.path.join(d, 'ic_launcher.png'))
        icon.save(os.path.join(d, 'ic_launcher_round.png'))
        print('legacy', dpi, s)

    for dpi, s in ADAPTIVE.items():
        d = ensure(os.path.join(RES, 'mipmap-' + dpi))
        radial_alpha(master, s).save(os.path.join(d, 'ic_launcher_foreground.png'))
        blurred_bg(master, s).save(os.path.join(d, 'ic_launcher_background.png'))
        monochrome(master, s).save(os.path.join(d, 'ic_launcher_monochrome.png'))
        print('adaptive', dpi, s)

    print('done')


if __name__ == '__main__':
    main()
