"""Draws the fast-php logo, wordmarks and social card as SVG, then renders PNGs.

The mark is a profiler sunburst: the lavender ring is your PHP app's call profile, and the
one hot slice, the part that eats the time, is pulled out of it in Rust orange. That is
fast-php's whole idea: find the slice, lift it out, forge it in Rust, put it back.

The lettering is drawn here as strokes, not set in a font, so the SVGs look the same
everywhere. No dependencies; PNGs need rsvg-convert (librsvg).

    python3 scripts/logo.py
"""

import math
import shutil
import subprocess
from pathlib import Path

OUT = Path(__file__).resolve().parent.parent / "docs" / "assets"

# PHP lavender family and Rust orange.
LAVENDER = ["#4F5B93", "#6A72AE", "#8892BF", "#A3AAD6", "#777BB4"]
HOT_A, HOT_B = "#FFB347", "#F74C00"
TILE_A, TILE_B = "#1E2140", "#111325"

# Ring slices, clockwise from 12 o'clock: (degrees, colour index or "hot").
SLICES = [(62, 2), (88, "hot"), (44, 0), (70, 3), (54, 1), (42, 4)]
GAP = 7  # degrees between slices


def polar(cx, cy, r, deg):
    a = math.radians(deg - 90)
    return cx + r * math.cos(a), cy + r * math.sin(a)


def slice_path(cx, cy, r_in, r_out, a0, a1):
    large = 1 if a1 - a0 > 180 else 0
    x0, y0 = polar(cx, cy, r_out, a0)
    x1, y1 = polar(cx, cy, r_out, a1)
    x2, y2 = polar(cx, cy, r_in, a1)
    x3, y3 = polar(cx, cy, r_in, a0)
    return (
        f"M{x0:.1f} {y0:.1f} A{r_out} {r_out} 0 {large} 1 {x1:.1f} {y1:.1f} "
        f"L{x2:.1f} {y2:.1f} A{r_in} {r_in} 0 {large} 0 {x3:.1f} {y3:.1f} Z"
    )


def mark(prefix="m", tile=True):
    """The mark on a 1024 x 1024 canvas, as an SVG fragment."""
    cx, cy, r_in, r_out = 440, 522, 178, 290
    defs = f"""
    <linearGradient id="{prefix}tile" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0" stop-color="{TILE_A}"/><stop offset="1" stop-color="{TILE_B}"/>
    </linearGradient>
    <linearGradient id="{prefix}hot" x1="1" y1="0" x2="0" y2="1">
      <stop offset="0" stop-color="{HOT_A}"/><stop offset="1" stop-color="{HOT_B}"/>
    </linearGradient>
    <radialGradient id="{prefix}glow" cx="0.5" cy="0.5" r="0.5">
      <stop offset="0" stop-color="{HOT_B}" stop-opacity="0.55"/>
      <stop offset="1" stop-color="{HOT_B}" stop-opacity="0"/>
    </radialGradient>
    <filter id="{prefix}soft" x="-20%" y="-20%" width="140%" height="140%">
      <feGaussianBlur stdDeviation="10"/>
    </filter>
    <clipPath id="{prefix}clip"><rect x="32" y="32" width="960" height="960" rx="216"/></clipPath>"""
    body = []
    if tile:
        body.append(f'<rect x="32" y="32" width="960" height="960" rx="216" fill="url(#{prefix}tile)"/>')
        body.append(
            f'<rect x="33.5" y="33.5" width="957" height="957" rx="214.5" fill="none" '
            f'stroke="#FFFFFF" stroke-opacity="0.07" stroke-width="3"/>'
        )
    body.append(f'<g clip-path="url(#{prefix}clip)">' if tile else "<g>")
    angle = 0.0
    hot = None
    for size, colour in SLICES:
        a0, a1 = angle + GAP / 2, angle + size - GAP / 2
        angle += size
        if colour == "hot":
            hot = (a0, a1)
            continue
        d = slice_path(cx, cy, r_in, r_out, a0, a1)
        body.append(
            f'<path d="{d}" fill="{LAVENDER[colour]}" stroke="{LAVENDER[colour]}" '
            f'stroke-width="14" stroke-linejoin="round"/>'
        )
    # The hot slice, pulled out along its middle and a little bigger, with a glow behind it.
    a0, a1 = hot
    mid = (a0 + a1) / 2
    ox, oy = polar(0, 0, 62, mid)
    gx, gy = polar(cx + ox, cy + oy, (r_in + r_out) / 2 + 20, mid)
    body.append(f'<circle cx="{gx:.1f}" cy="{gy:.1f}" r="230" fill="url(#{prefix}glow)"/>')
    # Speed streaks in the gap the slice left, pointing the way it moved.
    for a, length, opacity in [(a0 + 14, 46, 0.45), (mid, 62, 0.7), (a1 - 14, 46, 0.45)]:
        sx, sy = polar(cx, cy, r_in + 10, a)
        dx, dy = polar(0, 0, 1, mid)
        body.append(
            f'<path d="M{sx:.1f} {sy:.1f} l{dx * length:.1f} {dy * length:.1f}" stroke="{HOT_A}" '
            f'stroke-opacity="{opacity}" stroke-width="14" stroke-linecap="round"/>'
        )
    d = slice_path(cx + ox, cy + oy, r_in + 22, r_out + 22, a0, a1)
    body.append(f'<path d="{d}" fill="{HOT_B}" opacity="0.6" filter="url(#{prefix}soft)"/>')
    body.append(
        f'<path d="{d}" fill="url(#{prefix}hot)" stroke="url(#{prefix}hot)" '
        f'stroke-width="14" stroke-linejoin="round"/>'
    )
    # Sparks off the hot slice.
    for r_extra, da, size in [(64, -14, 13), (100, -4, 9), (126, -16, 6)]:
        px, py = polar(cx + ox, cy + oy, r_out + r_extra, mid + da)
        body.append(f'<circle cx="{px:.1f}" cy="{py:.1f}" r="{size}" fill="{HOT_A}"/>')
    body.append("</g>")
    # The core: PHP's open tag, the thing every PHP file starts with.
    body.append(
        f'<path d="M{cx - 18} {cy - 62} L{cx - 92} {cy} L{cx - 18} {cy + 62}" fill="none" stroke="#C9CDEB" '
        f'stroke-width="34" stroke-linecap="round" stroke-linejoin="round"/>'
    )
    body.append(
        f'<path d="M{cx + 22} {cy - 40} C{cx + 22} {cy - 92} {cx + 104} {cy - 92} {cx + 104} {cy - 38} '
        f'C{cx + 104} {cy - 2} {cx + 58} {cy - 4} {cx + 58} {cy + 30}" fill="none" stroke="#C9CDEB" '
        f'stroke-width="34" stroke-linecap="round" stroke-linejoin="round"/>'
    )
    body.append(f'<circle cx="{cx + 58}" cy="{cy + 82}" r="19" fill="#C9CDEB"/>')
    return defs, "\n  ".join(body)


# ---------------------------------------------------------------- lettering

# Monoline glyphs on an em with x-height 100 (y 50..150), ascender at 0, descender at 200.
# Each glyph: (advance width, [path d with x offset applied]).
def glyphs(x):
    return {
        "f": (64, [f"M{x + 20} 150 V44 C{x + 20} 14 {x + 36} 4 {x + 62} 6", f"M{x} 58 H{x + 52}"]),
        "a": (
            108,
            [
                f"M{x + 96} 100 A46 46 0 1 0 {x + 96} 100.1",
                f"M{x + 96} 54 V150",
            ],
        ),
        "s": (
            82,
            [
                f"M{x + 74} 66 C{x + 62} 50 {x + 14} 46 {x + 10} 76 C{x + 6} 106 {x + 76} 96 {x + 74} 126 "
                f"C{x + 72} 156 {x + 18} 156 {x + 6} 134"
            ],
        ),
        "t": (62, [f"M{x + 20} 18 V122 C{x + 20} 146 {x + 34} 152 {x + 58} 148", f"M{x} 58 H{x + 52}"]),
        "-": (64, [f"M{x + 8} 104 H{x + 52}"]),
        "p": (108, [f"M{x + 4} 54 V200", f"M{x + 4} 100 A46 46 0 1 1 {x + 4} 100.1"]),
        "h": (98, [f"M{x + 4} 0 V150", f"M{x + 4} 96 C{x + 4} 70 {x + 22} 54 {x + 46} 54 C{x + 70} 54 {x + 88} 70 {x + 88} 96 V150"]),
    }


def word(text, x, colour, stroke=24, track=14):
    paths = []
    for ch in text:
        advance, ds = glyphs(x)[ch]
        for d in ds:
            paths.append(
                f'<path d="{d}" fill="none" stroke="{colour}" stroke-width="{stroke}" '
                f'stroke-linecap="round" stroke-linejoin="round"/>'
            )
        x += advance + track
    return paths, x


def wordmark(theme):
    """Mark + 'fast-php'. theme: dark (for dark backgrounds) or light."""
    fast = "#F4F5FB" if theme == "dark" else "#1B1E36"
    php = "#A3AAD6" if theme == "dark" else "#5B63A6"
    defs, body = mark("w")
    a, x = word("fast", 0, fast)
    b, x = word("-", x, HOT_B)
    c, x = word("php", x, php)
    width_text = x
    scale = 1.3
    tx = 450
    w = int(tx + width_text * scale + 120)
    h = 400
    return f"""<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {w} {h}" width="{w}" height="{h}">
  <title>fast-php</title>
  <defs>{defs}</defs>
  <g transform="translate(60 40) scale(0.3125)">
  {body}
  </g>
  <g transform="translate({tx} {200 - 100 * scale}) scale({scale})">
  {chr(10).join(a + b + c)}
  </g>
</svg>
"""


def logo_svg():
    defs, body = mark("m")
    return f"""<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1024 1024" width="1024" height="1024">
  <title>fast-php</title>
  <defs>{defs}</defs>
  {body}
</svg>
"""


def social_svg():
    defs, body = mark("s")
    a, x = word("fast", 0, "#F4F5FB")
    b, x = word("-", x, HOT_B)
    c, x = word("php", x, "#A3AAD6")
    return f"""<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1280 640" width="1280" height="640">
  <title>fast-php</title>
  <defs>{defs}
    <linearGradient id="bg" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0" stop-color="#1A1C35"/><stop offset="1" stop-color="#0C0D19"/>
    </linearGradient>
  </defs>
  <rect width="1280" height="640" fill="url(#bg)"/>
  <g transform="translate(90 130) scale(0.37)">
  {body}
  </g>
  <g transform="translate(560 210) scale(0.8)">
  {chr(10).join(a + b + c)}
  </g>
  <g font-family="Avenir Next, Inter, Helvetica Neue, Arial, sans-serif" fill="#C9CDEB">
    <text x="562" y="440" font-size="31" font-weight="600">Port the slow 5% of your PHP app to Rust.</text>
    <text x="562" y="484" font-size="23" fill="#8D92B8">Native extension · exact PHP values · proven on real calls</text>
  </g>
</svg>
"""


def main():
    OUT.mkdir(parents=True, exist_ok=True)
    files = {
        "logo.svg": logo_svg(),
        "wordmark-dark.svg": wordmark("dark"),
        "wordmark-light.svg": wordmark("light"),
        "social.svg": social_svg(),
    }
    for name, svg in files.items():
        (OUT / name).write_text(svg)
    renders = [
        ("logo.svg", "logo-512.png", 512),
        ("logo.svg", "logo-256.png", 256),
        ("logo.svg", "logo-64.png", 64),
        ("wordmark-dark.svg", "wordmark-dark.png", 1400),
        ("wordmark-light.svg", "wordmark-light.png", 1400),
        ("social.svg", "social.png", 1280),
    ]
    if not shutil.which("rsvg-convert"):
        print("rsvg-convert not found: wrote the SVGs only")
        return
    for src, dst, width in renders:
        subprocess.run(["rsvg-convert", "-w", str(width), "-o", OUT / dst, OUT / src], check=True)
    print("wrote", ", ".join(list(files) + [r[1] for r in renders]))


if __name__ == "__main__":
    main()
