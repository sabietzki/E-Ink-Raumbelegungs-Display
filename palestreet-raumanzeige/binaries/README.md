# Firmware-Binärdateien

Die drei Dateien für reTerminal E1001 (XIAO ESP32S3):

1. **bootloader.bin** – Bootloader
2. **partitions.bin** – Partitionstabelle (aus `firmware/partitions.csv`)
3. **app.bin** – Raumanzeige-Sketch

**Flashen nur per Kommandozeile** (nicht über WordPress). Siehe `firmware/README.md` und `flash.sh`.

## Build (lokal)

Im Plugin-Ordner (eine Ebene über `binaries/`):

```bash
./build-firmware.sh
```

Voraussetzung: `arduino-cli` (z. B. `brew install arduino-cli`), Board ESP32:esp32, XIAO ESP32S3.

Die drei .bin-Dateien landen in `binaries/`.

## Flash (Kommandozeile)

Gerät per USB verbinden, in Bootloader-Modus versetzen (Boot-Button beim Start gedrückt halten), dann:

```bash
./flash.sh
```

Oder manuell mit esptool.py:

```bash
esptool.py --chip esp32s3 -p /dev/cu.usbmodem* write_flash 0x0 binaries/bootloader.bin 0x8000 binaries/partitions.bin 0x10000 binaries/app.bin
```

WLAN, device_id und ggf. Update-Intervall werden in der Firmware in `firmware/config.h` eingetragen (oder später per eigener Config-Partition).
