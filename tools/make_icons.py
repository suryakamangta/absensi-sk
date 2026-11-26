"""Membuat ikon launcher Absensi SK (dipanggil oleh workflow build APK)."""
import os
from PIL import Image, ImageDraw, ImageFont

FONT = "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf"
RES = os.path.join(os.path.dirname(__file__), "..", "android", "app", "src", "main", "res")


def icon(size):
    s = size * 4
    im = Image.new("RGBA", (s, s), (0, 0, 0, 0))
    d = ImageDraw.Draw(im)
    d.rounded_rectangle([0, 0, s - 1, s - 1], radius=int(s * 0.22), fill=(15, 42, 74, 255))
    d.rectangle([int(s * 0.18), int(s * 0.78), int(s * 0.82), int(s * 0.83)], fill=(242, 165, 22, 255))
    try:
        f = ImageFont.truetype(FONT, int(s * 0.42))
    except OSError:
        f = ImageFont.load_default()
    b = d.textbbox((0, 0), "SK", font=f)
    w, h = b[2] - b[0], b[3] - b[1]
    d.text(((s - w) / 2 - b[0], (s * 0.47 - h / 2) - b[1]), "SK", font=f, fill=(255, 255, 255, 255))
    return im.resize((size, size), Image.LANCZOS)


for name, sz in [("mdpi", 48), ("hdpi", 72), ("xhdpi", 96), ("xxhdpi", 144), ("xxxhdpi", 192)]:
    d = os.path.join(RES, "mipmap-" + name)
    os.makedirs(d, exist_ok=True)
    icon(sz).save(os.path.join(d, "ic_launcher.png"))
print("ikon dibuat")
