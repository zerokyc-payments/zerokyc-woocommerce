#!/usr/bin/env python3
"""Generate WP.org plugin assets from the ZeroKYC brand mark.

Mark (from the site logo): dark rounded square #10161F with #223041 stroke,
light "K" #E8EEF5, accent diagonal #00D68F. Rendered with 4x supersampling.
"""
from PIL import Image, ImageDraw, ImageFont
import os

BG = "#10161F"
BORDER = "#223041"
K_COLOR = "#E8EEF5"
ACCENT = "#00D68F"
BANNER_BG = "#0B0F14"

SS = 4  # supersample factor


def rounded_square(draw, box, r, fill, outline, width):
    draw.rounded_rectangle(box, radius=r, fill=fill, outline=outline, width=width)


def line_round(draw, p1, p2, fill, width):
    draw.line([p1, p2], fill=fill, width=width)
    r = width / 2
    for (x, y) in (p1, p2):
        draw.ellipse([x - r, y - r, x + r, y + r], fill=fill)


def draw_mark(size):
    """Render the 32x32-unit mark into an RGBA image of `size` px."""
    scale = size * SS / 32.0
    img = Image.new("RGBA", (size * SS, size * SS), (0, 0, 0, 0))
    d = ImageDraw.Draw(img)

    # Square: rect(1,1,31,31), rx 8, stroke 1.5
    pad = 1.0 * scale
    box = (pad, pad, 32 * scale - pad, 32 * scale - pad)
    rounded_square(d, box, 8 * scale, BG, BORDER, max(1, round(1.5 * scale)))

    w = round(2.5 * scale)
    # K: vertical stem + upper + lower arm (in 32-unit space)
    line_round(d, (12 * scale, 8 * scale), (12 * scale, 24 * scale), K_COLOR, w)
    line_round(d, (12 * scale, 16 * scale), (20 * scale, 8 * scale), K_COLOR, w)
    line_round(d, (12 * scale, 16 * scale), (20 * scale, 24 * scale), K_COLOR, w)
    # Accent slash
    line_round(d, (7 * scale, 25 * scale), (25 * scale, 7 * scale), ACCENT, w)

    return img.resize((size, size), Image.LANCZOS)


def font(size, bold=True):
    candidates = [
        r"C:\Windows\Fonts\arialbd.ttf" if bold else r"C:\Windows\Fonts\arial.ttf",
        r"C:\Windows\Fonts\segoeuib.ttf" if bold else r"C:\Windows\Fonts\segoeui.ttf",
        "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf",
        "/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf",
    ]
    for path in candidates:
        if os.path.exists(path):
            return ImageFont.truetype(path, size)
    return ImageFont.load_default()


def banner(w, h):
    """Banner: dark bg, accent slash, mark, wordmark + tagline."""
    img = Image.new("RGB", (w * SS, h * SS), BANNER_BG)
    d = ImageDraw.Draw(img)

    # subtle vertical accent line along the right edge
    d.rectangle([(w * SS - 10 * SS, 0), (w * SS, h * SS)], fill=BG)

    mark_size = round(h * 0.52 * SS)
    mark = draw_mark(mark_size // SS).resize((mark_size, mark_size), Image.LANCZOS)
    mx = round(56 * SS)
    my = (h * SS - mark_size) // 2
    img.paste(mark, (mx, my), mark)

    tx = mx + mark_size + round(28 * SS)
    title = font(round(44 * SS))
    tagline = font(round(21 * SS), bold=False)
    d.text((tx, my - round(6 * SS)), "ZeroKYC Pay", font=title, fill="#F2F6FA")
    d.text((tx, my + mark_size - round(24 * SS)), "Crypto payments for WooCommerce",
           font=tagline, fill="#8A97A8")

    # small accent underline under the wordmark
    line_round(d, (tx + 2 * SS, my - round(16 * SS)), (tx + round(150 * SS), my - round(16 * SS)),
               ACCENT, round(3 * SS))

    return img.resize((w, h), Image.LANCZOS)


def main():
    here = os.path.dirname(os.path.abspath(__file__))
    out = os.path.join(here, "..", ".wordpress-org")
    os.makedirs(out, exist_ok=True)

    draw_mark(256).save(os.path.join(out, "icon-256x256.png"))
    draw_mark(128).save(os.path.join(out, "icon-128x128.png"))
    banner(772, 250).save(os.path.join(out, "banner-772x250.png"))
    banner(1544, 500).save(os.path.join(out, "banner-1544x500.png"))
    print("assets written to", os.path.abspath(out))


if __name__ == "__main__":
    main()
