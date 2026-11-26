"""Membuat ikon launcher Absensi SK (dipanggil oleh workflow build APK)."""
import os
from PIL import Image, ImageDraw, ImageFont

FONT = "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf"
RES = os.path.join(os.path.dirname(__file__), "..", "android", "app", "src", "main", "res")


def icon(size):
    """Ikon launcher: logo resmi SK (lingkaran merah) di atas latar putih bulat."""
    s = size * 4
    im = Image.new("RGBA", (s, s), (0, 0, 0, 0))
    d = ImageDraw.Draw(im)
    d.ellipse([0, 0, s - 1, s - 1], fill=(255, 255, 255, 255))
    logo = Image.open(os.path.join(os.path.dirname(__file__), "logo_sk.png")).convert("RGBA")
    m = int(s * 0.12)
    logo = logo.resize((s - 2 * m, s - 2 * m), Image.LANCZOS)
    im.alpha_composite(logo, (m, m))
    return im.resize((size, size), Image.LANCZOS)


for name, sz in [("mdpi", 48), ("hdpi", 72), ("xhdpi", 96), ("xxhdpi", 144), ("xxxhdpi", 192)]:
    d = os.path.join(RES, "mipmap-" + name)
    os.makedirs(d, exist_ok=True)
    icon(sz).save(os.path.join(d, "ic_launcher.png"))
print("ikon dibuat")
