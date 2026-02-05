#!/usr/bin/env bash
# Erzeugt bootloader.bin, partitions.bin, app.bin und legt sie in binaries/.
# Voraussetzung: arduino-cli installiert (brew install arduino-cli), Board XIAO ESP32S3.
#
# ——— Lokal / Kommandozeile (jederzeit nutzbar) ———
#   cd /pfad/zum/plugin   # z. B. in palestreet-raumanzeige/
#   ./build-firmware.sh    # fragt device_id, baut, fragt „Jetzt flashen?“ (URL aus config.h/ZIP)
#   ./flash.sh             # falls nicht beim Build geflasht: Gerät per USB, dann ausführen
#
# Optional ohne Abfragen:  DEVICE_ID=0 WP_URL=https://meine-seite.de ./build-firmware.sh
#
# ——— Aufruf aus WordPress (USB-Flash-Button) ———
#   NONINTERACTIVE=1 DEVICE_ID=<id> WP_URL=<url> ./build-firmware.sh  (nur Build, Flash separat)

set -e
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SKETCH_DIR="$SCRIPT_DIR/firmware"
BINARIES_DIR="$SCRIPT_DIR/binaries"
BUILD_DIR="$SCRIPT_DIR/build_firmware"

cd "$SKETCH_DIR"

# Von WordPress übergebene config.h (Temp-Datei) nach firmware/config.h kopieren
if [ -n "${CONFIG_H_OVERRIDE:-}" ] && [ -r "$CONFIG_H_OVERRIDE" ]; then
  if ! cp "$CONFIG_H_OVERRIDE" "$SKETCH_DIR/config.h"; then
    echo "Fehler: Konnte vorbereitete config.h nicht nach firmware/config.h kopieren."
    echo "Bitte das Verzeichnis firmware/ für den Webserver-Benutzer beschreibbar machen (z.B. chmod 775 firmware)."
    exit 1
  fi
  [ "$NONINTERACTIVE" = "1" ] || echo "config.h aus CONFIG_H_OVERRIDE übernommen."
fi

# config.h aus Beispiel anlegen, falls nicht vorhanden (ZIP enthält config.example.h mit gesetzter WordPress-URL)
if [ ! -f config.h ]; then
  cp config.example.h config.h
  echo "config.h aus config.example.h erstellt."
fi

# Device-ID (Umgebung oder Abfrage). WLAN/URL/refresh stehen in config.h (ZIP/Beispiel).
CURRENT_DEVICE_ID=$(grep -E '^#define[[:space:]]+DEVICE_ID' config.h 2>/dev/null | sed -E 's/^#define[[:space:]]+DEVICE_ID[[:space:]]+//;s/[^0-9].*//' || echo "0")
if [ -n "${DEVICE_ID:-}" ]; then
  DEVICE_ID=$((DEVICE_ID))
  [ "$NONINTERACTIVE" = "1" ] || echo "device_id aus Umgebung: $DEVICE_ID"
else
  echo ""
  read -p "Welche device_id nutzen? [$CURRENT_DEVICE_ID]: " INPUT_DEVICE_ID
  DEVICE_ID="${INPUT_DEVICE_ID:-$CURRENT_DEVICE_ID}"
  DEVICE_ID=$((DEVICE_ID))
fi
sed -E "s|^#define[[:space:]]+DEVICE_ID[[:space:]]+.*|#define DEVICE_ID       $DEVICE_ID|" config.h > config.h.tmp && mv config.h.tmp config.h
echo "config.h: DEVICE_ID=$DEVICE_ID (WLAN/URL aus config.h bzw. ZIP)"
echo ""
echo "——— Build-Fortschritt ———"

# API-Daten abrufen und ausgeben, die in die Config (bzw. ans Gerät) gehen
WP_URL_FROM_CONFIG=$(grep -E '^#define[[:space:]]+WORDPRESS_URL' config.h 2>/dev/null | sed -E 's/^#define[[:space:]]+WORDPRESS_URL[[:space:]]+"([^"]+)".*/\1/' | head -1)
API_BASE="${WP_URL:-$WP_URL_FROM_CONFIG}"
if [ -n "$API_BASE" ] && [ "$DEVICE_ID" -ge 0 ] 2>/dev/null; then
  echo "[1/4] API abrufen ..."
  API_URL="${API_BASE%/}/wp-json/palestreet-raumanzeige/v1/display?device_id=$DEVICE_ID"
  if command -v curl &>/dev/null; then
    API_JSON=$(curl -sS -f -L --max-time 15 "$API_URL" 2>/dev/null) || API_JSON=""
    if [ -n "$API_JSON" ]; then
      printf '%s' "$API_JSON" | python3 "$SKETCH_DIR/update_config_from_api.py" 2>/dev/null || echo "$API_JSON"
    else
      echo "  (API-Abruf fehlgeschlagen oder leer: $API_URL)"
    fi
  else
    echo "  (curl nicht vorhanden – API nicht abrufbar)"
  fi
  echo "————————————————————————————————————————————————————————————"
else
  echo "[1/4] API übersprungen (keine WP-URL / device_id)."
fi
echo ""

# Fehler-Icons als BMP einbetten (icons_data.h) – nur wenn .bmp in data/ geändert oder .h fehlt
icons_needed=0
if [ -f "data/no_wifi.bmp" ] || [ -f "data/low_battery.bmp" ] || [ -f "data/no_connection.bmp" ]; then
  if [ ! -f "icons_data.h" ]; then icons_needed=1
  else
    for bmp in data/no_wifi.bmp data/low_battery.bmp data/no_connection.bmp; do
      [ -f "$bmp" ] && [ "$bmp" -nt icons_data.h ] && icons_needed=1 && break
    done
  fi
  if [ "$icons_needed" = 1 ]; then
    echo "[2/4] Icons einbetten ..."
    python3 embed_icons.py 2>/dev/null || true
  fi
fi
if [ ! -f "Inter_extrabold_27pt7b.h" ] && [ ! -f "Inter_semibold_9pt7b.h" ]; then
  if [ -f "data/Inter_ExtraBold.ttf" ] || [ -f "data/Inter_SemiBold.ttf" ] || [ -f "data/Inter_bold.ttf" ]; then
    echo "[2/4] Schriften erzeugen (ttf_to_gfx.py) ..."
    .venv/bin/python ttf_to_gfx.py 2>/dev/null || python3 ttf_to_gfx.py || { echo "Fehler: freetype-py fehlt. Installieren: pip install freetype-py"; exit 1; }
  fi
  if [ ! -f "Inter_extrabold_27pt7b.h" ] && [ ! -f "Inter_semibold_9pt7b.h" ]; then
    echo ""
    echo "Inter-Schriften fehlen. So beheben:"
    echo "  1. Inter von https://fonts.google.com/specimen/Inter herunterladen (ExtraBold + SemiBold, oder Bold + Medium)."
    echo "  2. Inter_ExtraBold.ttf und Inter_SemiBold.ttf (oder Inter_bold.ttf + Inter_medium.ttf) nach firmware/data/ legen."
    echo "  3. Im Ordner firmware ausführen:  .venv/bin/python ttf_to_gfx.py  bzw.  python3 ttf_to_gfx.py"
    echo "  4. Danach erneut: ./build-firmware.sh"
    echo ""
    exit 1
  fi
fi
if ! command -v arduino-cli &>/dev/null; then
  echo "arduino-cli nicht gefunden. Installieren:"
  echo "  Ubuntu/Linux: curl -fsSL https://raw.githubusercontent.com/arduino/arduino-cli/master/install.sh | sh"
  echo "  oder: sudo snap install arduino-cli"
  echo "  macOS: brew install arduino-cli"
  echo "Dann einmalig: arduino-cli core update-index && arduino-cli core install esp32:esp32"
  exit 1
fi

# Nur bei Bedarf installieren (kein update-index bei jedem Build – spart mehrere Minuten)
if ! arduino-cli core list 2>/dev/null | grep -q "esp32:esp32"; then
  echo "ESP32-Core nicht installiert. Index wird einmalig aktualisiert, dann wird esp32:esp32 installiert..."
  arduino-cli core update-index && arduino-cli core install esp32:esp32
fi
for lib in PNGdec GxEPD2; do
  if ! arduino-cli lib list 2>/dev/null | grep -qi "$lib"; then
    echo "Bibliothek $lib wird installiert..."
    arduino-cli lib install "$lib" 2>/dev/null || true
  fi
done

# Build mit Custom Partition Table (partitions.csv liegt im Sketch-Ordner)
# XIAO ESP32S3 FQBN
echo "[3/4] Kompilieren (kann 1–2 Min. dauern) ..."
arduino-cli compile \
  --fqbn "esp32:esp32:XIAO_ESP32S3" \
  --build-path "$BUILD_DIR" \
  .
echo "[3/4] Kompilieren fertig."
echo ""
echo "[4/4] Binaries kopieren ..."

# Build-Ordner: bootloader/partitions oft als firmware.ino.bootloader.bin / .partitions.bin
BOOTLOADER=$(find "$BUILD_DIR" -name 'bootloader*.bin' -type f | head -1)
[ -z "$BOOTLOADER" ] && BOOTLOADER=$(find "$BUILD_DIR" -name '*.bootloader.bin' -type f | head -1)
PARTITIONS=$(find "$BUILD_DIR" -name 'partitions*.bin' -type f | head -1)
[ -z "$PARTITIONS" ] && PARTITIONS=$(find "$BUILD_DIR" -name '*.partitions.bin' -type f | head -1)
APP=$(find "$BUILD_DIR" -name '*.ino.bin' -type f ! -name '*.bootloader.bin' ! -name '*.partitions.bin' ! -name '*.merged.bin' | head -1)

if [ -z "$BOOTLOADER" ] || [ -z "$PARTITIONS" ] || [ -z "$APP" ]; then
  echo "Nicht alle .bin-Dateien gefunden im Build-Ordner: $BUILD_DIR"
  ls -la "$BUILD_DIR" 2>/dev/null || true
  exit 1
fi

cp "$BOOTLOADER" "$BINARIES_DIR/bootloader.bin"
cp "$PARTITIONS" "$BINARIES_DIR/partitions.bin"
cp "$APP" "$BINARIES_DIR/app.bin"
echo "[4/4] Fertig."
echo ""
echo "——— Build abgeschlossen ———"
echo "Dateien in $BINARIES_DIR: bootloader.bin, partitions.bin, app.bin"
# Aufräumen: Build-Cache entfernen (bei KEEP_BUILD=1 behalten → nächster Build deutlich schneller)
# firmware/.venv wird nicht gelöscht (für Pillow/freetype-py bei Icons/Schriften)
if [ "${KEEP_BUILD:-0}" != "1" ]; then
  rm -rf "$BUILD_DIR"
  echo "Aufräumen: build_firmware/ entfernt."
else
  echo "KEEP_BUILD=1: Build-Verzeichnis bleibt für schnelleren nächsten Build."
fi

# Einmal flashen (optional; bei NONINTERACTIVE=1 überspringen – z. B. WordPress ruft Build + Flash getrennt auf)
if [ "$NONINTERACTIVE" = "1" ]; then
  echo "Build fertig (NONINTERACTIVE). Flash wird separat ausgeführt."
  exit 0
fi
echo ""
read -p "Jetzt flashen? [j/N]: " DO_FLASH
case "$DO_FLASH" in j|J|y|Y)
  if "$SCRIPT_DIR/flash.sh"; then
    echo "Build und Flash erfolgreich."
  else
    echo "Flash fehlgeschlagen (nur ein Versuch). Gerät prüfen, ggf. ./flash.sh manuell ausführen."
    exit 1
  fi
  ;;
*) echo "Übersprungen. Später: ./flash.sh" ;;
esac
