# Daten für die Raumanzeige (SPIFFS / Assets)

Inhalt von `data/` mit **Sketch → Upload SPIFFS Data** auf das reTerminal hochladen (falls genutzt).

---

## Fehler-Split-Screen (BMP)

Wenn **kein WLAN**, **Akku unter Schwellwert** (z. B. &lt; 2 %) oder **keine Server-Antwort**: Anzeige wechselt in einen **2×2-Split-Screen**, alle anderen Infos werden ausgeblendet.

| Quadrant    | Bedingung      | Datei (data/)      |
|-------------|----------------|--------------------|
| Oben links  | Kein WLAN      | **no_wifi.bmp**    |
| Oben rechts | Akku niedrig   | **low_battery.bmp** |
| Unten links | Kein Server    | **no_connection.bmp** |
| Unten rechts | —            | leer               |

- **Format:** 1-bit BMP, **max. 160×160** Pixel (empfohlen 150×150). Dateinamen: `no_wifi.bmp`, `low_battery.bmp`, `no_connection.bmp`. Erstellung z. B. in Photoshop – Anleitung steht im WordPress-Backend unter „Fehler-Anzeige auf dem Schild“ → „Icons selbst erstellen (Photoshop)“.
- **Einbettung:** Beim Aufruf von `./build-firmware.sh` werden die BMPs aus `data/` in die Firmware eingebettet (`embed_icons.py` → `icons_data.h`). **Kein SPIFFS-Upload nötig.** Zu große oder nicht 1-bit BMPs werden nicht eingebettet (dann erscheinen die Text-Labels).
- **Refresh:** Grüner Button aktualisiert immer (auch im Fehler-Screen).

---

## Schriften: Inter (TTF → .h)

- **TTF in `data/`:** `Inter_ExtraBold.ttf` und `Inter_SemiBold.ttf` (oder Inter-Bold, Inter-Medium) hier ablegen.
- **Generierung:** Im Ordner **firmware** (eine Ebene über `data/`):
  ```bash
  python3 ttf_to_gfx.py
  ```
  Erzeugt die Inter-*.h-Dateien im Sketch-Ordner. Benötigt: `pip install freetype-py`.

---

## Kurz

| Datei                 | Ablage / Verwendung |
|-----------------------|----------------------|
| `no_wifi.bmp`         | In `data/` → Build erzeugt icons_data.h. Fehler-Screen: kein WLAN. |
| `low_battery.bmp`     | In `data/` → Build erzeugt icons_data.h. Fehler-Screen: Akku niedrig. |
| `no_connection.bmp`   | In `data/` → Build erzeugt icons_data.h. Fehler-Screen: keine Server-Antwort. |
| Inter-TTF             | In `data/` → `python3 ttf_to_gfx.py` im firmware-Ordner → .h-Dateien. |
