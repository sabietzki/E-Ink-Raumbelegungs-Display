#!/usr/bin/env python3
"""
Prüft die PNG-Icons in data/ und optional die eingebetteten Daten in icons_data.h.
- PNG-Magic, IHDR (Größe, Bit-Tiefe, Farbtyp, Interlace)
- Chunk-Reihenfolge (IHDR, optional tEXt/PLTE/tRNS, IDAT, IEND)
- Byte-Gleichheit mit icons_data.h (falls --embed geprüft werden soll)
"""
import os
import re
import struct
import sys

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
DATA_DIR = os.path.join(SCRIPT_DIR, "data")
ICONS = ("no_wifi", "low_battery", "no_connection")
PNG_MAGIC = b"\x89PNG\r\n\x1a\n"


def check_png_structure(path):
    """Prüft PNG-Struktur; gibt (ok, message, chunks) zurück."""
    with open(path, "rb") as f:
        data = f.read()
    if len(data) < 8:
        return False, "Datei zu kurz", []
    if data[:8] != PNG_MAGIC:
        return False, "Kein gültiger PNG-Magic-Header", []
    if len(data) < 8 + 4 + 4 + 13 + 4:
        return False, "IHDR unvollständig", []
    # IHDR
    length = struct.unpack(">I", data[8:12])[0]
    ctype = data[12:16].decode("ascii")
    if ctype != "IHDR" or length != 13:
        return False, f"Erwartet IHDR(13), gefunden {ctype}({length})", []
    ihdr = data[16:29]
    w, h = struct.unpack(">II", ihdr[0:8])
    depth, color_type, comp, filter_type, interlace = ihdr[8], ihdr[9], ihdr[10], ihdr[11], ihdr[12]
    # Chunks durchgehen
    chunks = []
    i = 8
    while i + 12 <= len(data):
        length = struct.unpack(">I", data[i : i + 4])[0]
        ctype = data[i + 4 : i + 8].decode("ascii", errors="replace")
        end = i + 8 + length + 4
        if end > len(data):
            return False, f"Chunk {ctype} bei {i}: Länge {length}, Datei endet bei {len(data)}", chunks
        chunks.append((ctype, length))
        i = end
        if ctype == "IEND":
            break
    if chunks and chunks[-1][0] != "IEND":
        return False, "IEND-Chunk fehlt", chunks
    if i != len(data):
        return False, f"Überhängende Bytes nach IEND: {len(data) - i}", chunks
    # PNGdec: color_type 3 (Palette), depth 8, interlace 0 werden unterstützt
    if color_type not in (0, 2, 3, 4, 6):
        return False, f"Farbtyp {color_type} ggf. problematisch für PNGdec", chunks
    if interlace != 0:
        return False, "Interlaced PNG (1) kann bei manchen Decodern Probleme machen", chunks
    return True, f"OK {w}x{h} depth={depth} color={color_type} ({len(data)} B)", chunks


def check_embed_match():
    """Vergleicht data/*.png mit den in icons_data.h eingebetteten Bytes."""
    h_path = os.path.join(SCRIPT_DIR, "icons_data.h")
    if not os.path.isfile(h_path):
        return []
    with open(h_path, "r") as f:
        content = f.read()
    results = []
    for name in ICONS:
        var = "ICON_" + name.upper().replace("-", "_") + "_PNG"
        len_var = var + "_LEN"
        pat = rf"const unsigned char {re.escape(var)}\[\] PROGMEM = \{{([^}}]+)\}};"
        m = re.search(pat, content, re.DOTALL)
        len_m = re.search(rf"const unsigned int {re.escape(len_var)} = (\d+);", content)
        fpath = os.path.join(DATA_DIR, name + ".png")
        if not m or not len_m:
            results.append((name, False, "Array/LEN in .h nicht gefunden"))
            continue
        declared_len = int(len_m.group(1))
        hex_bytes = re.findall(r"0x[0-9a-fA-F]{2}", m.group(1))
        actual_len = len(hex_bytes)
        if actual_len != declared_len:
            results.append((name, False, f"Länge in .h: deklariert {declared_len}, Bytes {actual_len}"))
            continue
        embedded = bytes([int(h, 16) for h in hex_bytes])
        if not os.path.isfile(fpath):
            results.append((name, False, f"Datei {fpath} fehlt"))
            continue
        with open(fpath, "rb") as f:
            raw = f.read()
        if embedded != raw:
            for i in range(min(len(embedded), len(raw))):
                if embedded[i] != raw[i]:
                    results.append((name, False, f"Erster Unterschied bei Byte {i}"))
                    break
            else:
                results.append((name, False, f"Längen unterschiedlich: emb={len(embedded)} file={len(raw)}"))
        else:
            results.append((name, True, f"Embedding stimmt mit Datei überein ({len(raw)} B)"))
    return results


def main():
    check_embed = "--embed" in sys.argv or "-e" in sys.argv
    all_ok = True
    print("PNG-Icons in data/:")
    for name in ICONS:
        path = os.path.join(DATA_DIR, name + ".png")
        if not os.path.isfile(path):
            print(f"  {name}.png: FEHLT")
            all_ok = False
            continue
        ok, msg, chunks = check_png_structure(path)
        chunk_list = " ".join(c[0] for c in chunks)
        status = "OK" if ok else "FEHLER"
        print(f"  {name}.png: [{status}] {msg}")
        if chunks and not ok:
            print(f"    Chunks: {chunk_list}")
        if not ok:
            all_ok = False
    if check_embed:
        print("\nVergleich mit icons_data.h (eingebettete Daten):")
        for name, ok, msg in check_embed_match():
            status = "OK" if ok else "FEHLER"
            print(f"  {name}: [{status}] {msg}")
            if not ok:
                all_ok = False
    sys.exit(0 if all_ok else 1)


if __name__ == "__main__":
    main()
