#!/usr/bin/env bash
# Flasht die Raumanzeige-Firmware auf ein per USB verbundenes reTerminal E1001.
#
# ——— Lokal / Kommandozeile (jederzeit nutzbar) ———
#   cd /pfad/zum/plugin
#   ./build-firmware.sh    # zuerst bauen (falls noch nicht)
#   ./flash.sh             # Gerät per USB verbinden, dann flashen
#
# Port bei mehreren Geräten:  ESPTOOL_PORT=/dev/cu.usbserial-110 ./flash.sh
#
# Mit unserer Firmware: Bootloader-Modus nicht nötig – ./flash.sh sendet "BOOTLOADER",
# das Gerät geht in den Download-Modus und esptool flasht. Ohne unsere Firmware oder
# wenn es fehlschlägt: Gerät manuell in Bootloader (BOOT halten, Power an), siehe FLASH_ANLEITUNG.md.
# Voraussetzung: esptool (pip install esptool). Optional: pyserial für Bootloader-Befehl (pip install pyserial).

set -e
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BINARIES_DIR="$SCRIPT_DIR/binaries"

if [ ! -f "$BINARIES_DIR/bootloader.bin" ] || [ ! -f "$BINARIES_DIR/partitions.bin" ] || [ ! -f "$BINARIES_DIR/app.bin" ]; then
  echo "Binaries fehlen. Zuerst ausführen: ./build-firmware.sh"
  exit 1
fi

# esptool (v5+) mit write-flash – keine Deprecation-Warnung. Fallback: esptool.py mit write_flash
if command -v esptool &>/dev/null; then
  ESPTOOL=esptool
  FLASH_CMD="write-flash"
elif command -v esptool.py &>/dev/null; then
  ESPTOOL=esptool.py
  FLASH_CMD="write_flash"
  echo "Hinweis: 'esptool.py' ist veraltet. Für aktuelle Befehle: pip install -U esptool"
else
  echo "esptool nicht gefunden. Installieren: pip install esptool  oder  brew install esptool"
  exit 1
fi

# Port: immer Liste anzeigen und Nummer wählen, damit kein falsches Gerät geflasht wird (ESPTOOL_PORT überschreibt)
PORT="${ESPTOOL_PORT:-}"
if [ -z "$PORT" ]; then
  PORTS=()
  for p in /dev/cu.usbserial* /dev/cu.Maker3* /dev/cu.usbmodem* /dev/cu.SLAB* /dev/cu.CH34* /dev/ttyUSB* /dev/ttyACM*; do
    [ -e "$p" ] && PORTS+=("$p")
  done
  if [ ${#PORTS[@]} -eq 0 ]; then
    echo "Kein USB-Serial-Port gefunden. Schild per USB verbinden (Power an), dann erneut ausführen."
    exit 1
  fi
  echo "Gerät auswählen (nur das Schild wählen, sonst falsches Gerät geflasht):"
  for i in "${!PORTS[@]}"; do
    echo "  $((i+1))) ${PORTS[$i]}"
  done
  echo "  0) Abbrechen"
  read -p "Nummer [1-${#PORTS[@]}]: " choice
  choice=$((choice))
  if [ "$choice" -lt 1 ] || [ "$choice" -gt ${#PORTS[@]} ]; then
    echo "Abgebrochen."
    exit 1
  fi
  PORT="${PORTS[$((choice-1))]}"
  echo "Gewählt: $PORT"
fi

# Mit unserer Firmware: "BOOTLOADER" per Serial senden → Gerät geht in Download-Modus (kein BOOT-Button nötig)
if python3 -c "import serial" 2>/dev/null; then
  echo "Gerät in Bootloader versetzen (Serial-Befehl) ..."
  python3 -c "
import serial, time, sys
try:
  s = serial.Serial('$PORT', 115200, timeout=0.5)
  s.write(b'BOOTLOADER\n')
  s.close()
  time.sleep(2.5)
except Exception as e:
  sys.exit(1)
" 2>/dev/null || true
fi

echo "Flash auf $PORT ..."
echo ""

cleanup_after_flash() {
  echo "Aufräumen: Binaries und Build-Cache entfernt."
  rm -f "$BINARIES_DIR/bootloader.bin" "$BINARIES_DIR/partitions.bin" "$BINARIES_DIR/app.bin"
  rm -rf "$SCRIPT_DIR/build_firmware"
  # firmware/.venv nicht löschen (wird für Pillow/Schriften genutzt)
}

# esptool: write-flash (v5) oder write_flash (ältere Versionen)
if [ "$FLASH_CMD" = "write-flash" ]; then
  if $ESPTOOL --chip esp32s3 -p "$PORT" write-flash \
    0x0 "$BINARIES_DIR/bootloader.bin" \
    0x8000 "$BINARIES_DIR/partitions.bin" \
    0x10000 "$BINARIES_DIR/app.bin"; then
    echo ""
    echo "Fertig. Gerät startet neu."
    cleanup_after_flash
    exit 0
  fi
else
  if $ESPTOOL --chip esp32s3 -p "$PORT" write_flash \
    0x0 "$BINARIES_DIR/bootloader.bin" \
    0x8000 "$BINARIES_DIR/partitions.bin" \
    0x10000 "$BINARIES_DIR/app.bin"; then
    echo ""
    echo "Fertig. Gerät startet neu."
    cleanup_after_flash
    exit 0
  fi
fi

echo ""
echo "Verbindung fehlgeschlagen. Gerät manuell in Bootloader: BOOT-Button halten, Power an, BOOT loslassen (siehe FLASH_ANLEITUNG.md), dann erneut: ./flash.sh"
exit 1
