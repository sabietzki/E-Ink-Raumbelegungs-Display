#!/usr/bin/env python3
"""
TTF → Adafruit GFX Font (.h) Konverter
Erzeugt Inter-.h-Dateien für die Firmware.
Benötigt: (Inter_ExtraBold.ttf oder Inter_bold.ttf) + (Inter_SemiBold.ttf oder Inter_medium.ttf/Inter_regular.ttf) in data/
Größen: Body SemiBold 9/11/13 pt, Überschriften ExtraBold 13/25/27 pt.
pip install freetype-py
"""
from __future__ import print_function
import os
import sys
import struct

try:
    import freetype
except ImportError:
    print("Bitte installieren: pip install freetype-py", file=sys.stderr)
    sys.exit(1)

# Adafruit GFX: 7-bit ASCII ' ' bis '~'
FIRST = ord(' ')
LAST = ord('~')
DPI = 141  # wie Adafruit fontconvert


def pack_glyph_bits(bitmap):
    """Glyph-Bitmap (width x height) in GFX-Format packen: zeilenweise, MSB first, am Ende auf Byte auffüllen."""
    w, h = bitmap.width, bitmap.rows
    if w == 0 or h == 0:
        return bytearray()
    buf = bitmap.buffer
    pitch = bitmap.pitch  # Bytes pro Zeile bei FreeType (oft (w+7)//8)
    out = []
    bits = []
    for y in range(h):
        for x in range(w):
            byte_idx = y * pitch + x // 8
            bit = 7 - (x % 8)
            if byte_idx < len(buf):
                bits.append(1 if (buf[byte_idx] & (1 << bit)) else 0)
            else:
                bits.append(0)
    # Bits zu Bytes packen (8 Bits = 1 Byte, MSB zuerst)
    for i in range(0, len(bits), 8):
        byte = 0
        for j in range(8):
            if i + j < len(bits):
                if bits[i + j]:
                    byte |= 0x80 >> j
        out.append(byte)
    return bytearray(out)


def convert_ttf_to_gfx(ttf_path, size_pt, out_name, out_path, no_hinting=False):
    """Eine TTF-Datei in eine GFX .h-Datei umwandeln. no_hinting=True für Regular: gleichmäßigere Strichstärken."""
    face = freetype.Face(ttf_path)
    # 26.6 fixed point
    face.set_char_size(size_pt << 6, 0, DPI, 0)

    load_flags = freetype.FT_LOAD_TARGET_MONO
    if no_hinting:
        load_flags |= freetype.FT_LOAD_NO_HINTING

    glyphs = []
    bitmap_bytes = bytearray()
    bitmap_offset = 0

    for code in range(FIRST, LAST + 1):
        c = chr(code)
        try:
            face.load_char(c, load_flags)
            face.glyph.render(freetype.FT_RENDER_MODE_MONO)
        except Exception:
            # Fallback: leeres Glyph
            glyphs.append((0, 0, 0, 0, 0, 0))
            continue

        slot = face.glyph
        bitmap = slot.bitmap
        w = bitmap.width
        h = bitmap.rows
        advance = slot.advance.x >> 6
        left = slot.bitmap_left
        top = slot.bitmap_top
        # GFX yOffset = 1 - top (Cursor auf Baseline, top ist Oberkante)
        y_offset = 1 - top

        packed = pack_glyph_bits(bitmap)
        glyphs.append((bitmap_offset, w, h, advance, left, y_offset))
        bitmap_bytes.extend(packed)
        # Auf Byte-Grenze auffüllen (Gesamt-Bits des Glyphs)
        total_bits = w * h
        pad_bits = (8 - (total_bits % 8)) % 8
        bitmap_offset += (total_bits + pad_bits) // 8

    # yAdvance aus Font-Metrik (z. B. Zeilenhöhe)
    face.load_char('A', load_flags)
    slot = face.glyph
    y_advance = face.size.height >> 6 if face.size.height else glyphs[0][2]

    # .h ausgeben
    safe_name = out_name.replace(" ", "_").replace("-", "_")
    for ch in list(safe_name):
        if ch not in "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_":
            safe_name = safe_name.replace(ch, "_")

    with open(out_path, "w") as f:
        f.write("// Generated from TTF by ttf_to_gfx.py – Adafruit GFX font\n")
        f.write("#ifndef FONT_%s_H\n#define FONT_%s_H\n\n" % (safe_name.upper(), safe_name.upper()))
        f.write("const uint8_t %sBitmaps[] PROGMEM = {\n " % safe_name)
        for i, b in enumerate(bitmap_bytes):
            if i > 0:
                f.write(",")
            if (i + 1) % 12 == 0:
                f.write("\n ")
            f.write("0x%02X" % b)
        f.write("\n};\n\n")
        f.write("const GFXglyph %sGlyphs[] PROGMEM = {\n" % safe_name)
        for i, (offset, w, h, adv, xoff, yoff) in enumerate(glyphs):
            f.write("  { %5d, %3d, %3d, %3d, %4d, %4d }" % (offset, w, h, adv, xoff, yoff))
            if i < len(glyphs) - 1:
                f.write(",")
            f.write(" // 0x%02X '%s'\n" % (FIRST + i, chr(FIRST + i) if 32 <= FIRST + i <= 126 else "?"))
        f.write("};\n\n")
        f.write("const GFXfont %s PROGMEM = {\n" % safe_name)
        f.write("  (uint8_t *)%sBitmaps,\n" % safe_name)
        f.write("  (GFXglyph *)%sGlyphs,\n" % safe_name)
        f.write("  0x%02X, 0x%02X, %d\n" % (FIRST, LAST, y_advance))
        f.write("};\n\n#endif\n")
    print("OK: %s -> %s" % (ttf_path, out_path))


def main():
    script_dir = os.path.dirname(os.path.abspath(__file__))
    data_dir = os.path.join(script_dir, "data")

    # Bevorzugt ExtraBold (kräftigere Überschriften); sonst Bold
    inter_heavy = os.path.join(data_dir, "Inter_ExtraBold.ttf")
    if not os.path.isfile(inter_heavy):
        inter_heavy = os.path.join(data_dir, "Inter-ExtraBold.ttf")
    if not os.path.isfile(inter_heavy):
        inter_heavy = os.path.join(data_dir, "Inter_bold.ttf")
    if not os.path.isfile(inter_heavy):
        inter_heavy = os.path.join(data_dir, "Inter_Bold.ttf")
    inter_bold = inter_heavy
    # Bevorzugt Inter_SemiBold (bessere Lesbarkeit); sonst Medium oder Regular
    inter_body = os.path.join(data_dir, "Inter_SemiBold.ttf")
    if not os.path.isfile(inter_body):
        inter_body = os.path.join(data_dir, "Inter-SemiBold.ttf")
    if not os.path.isfile(inter_body):
        inter_body = os.path.join(data_dir, "Inter_Medium.ttf")
    if not os.path.isfile(inter_body):
        inter_body = os.path.join(data_dir, "Inter_regular.ttf")
    if not os.path.isfile(inter_body):
        inter_body = os.path.join(data_dir, "Inter_Regular.ttf")
    if not os.path.isfile(inter_bold) or not os.path.isfile(inter_body):
        print("Inter-TTF fehlen. (Inter_ExtraBold.ttf oder Inter_bold.ttf) + (Inter_SemiBold.ttf oder Inter_medium.ttf/Inter_regular.ttf) in data/ ablegen.", file=sys.stderr)
        sys.exit(1)

    heavy_name = "Inter_extrabold" if "extrabold" in os.path.basename(inter_bold).lower() else "Inter_bold"
    body_name = "Inter_semibold" if "semibold" in os.path.basename(inter_body).lower() else "Inter_medium" if "medium" in os.path.basename(inter_body).lower() else "Inter_regular"
    name_13 = body_name + "_13pt7b"
    name_11 = body_name + "_11pt7b"
    name_9  = body_name + "_9pt7b"

    # Überschriften (ExtraBold/Bold): 13, 25, 27 pt
    convert_ttf_to_gfx(inter_bold, 27, heavy_name + "_27pt7b", os.path.join(script_dir, heavy_name + "_27pt7b.h"))
    convert_ttf_to_gfx(inter_bold, 25, heavy_name + "_25pt7b", os.path.join(script_dir, heavy_name + "_25pt7b.h"))
    convert_ttf_to_gfx(inter_bold, 13, heavy_name + "_13pt7b", os.path.join(script_dir, heavy_name + "_13pt7b.h"))
    # Body: 9, 11, 13 pt (statt 8, 10, 12)
    convert_ttf_to_gfx(inter_body, 13, name_13, os.path.join(script_dir, name_13 + ".h"), no_hinting=True)
    convert_ttf_to_gfx(inter_body, 11, name_11, os.path.join(script_dir, name_11 + ".h"), no_hinting=True)
    convert_ttf_to_gfx(inter_body, 9, name_9, os.path.join(script_dir, name_9 + ".h"), no_hinting=True)
    print("Fertig. Inter-Schriften erzeugt (%s 13/25/27 pt + %s 9/11/13 pt)." % (heavy_name, body_name))


if __name__ == "__main__":
    main()
