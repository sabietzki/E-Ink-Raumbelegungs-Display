# Alles auf das Schild bringen (reTerminal E1001)

Kurz-Anleitung: Firmware bauen → Gerät in Bootloader → flashen.

---

## 1. Voraussetzungen (einmalig)

- **Arduino CLI** (zum Bauen):  
  `brew install arduino-cli`  
  Danach:  
  `arduino-cli core update-index && arduino-cli core install esp32:esp32`

- **esptool** (zum Flashen):  
  `pip install esptool` oder `brew install esptool`

- **config.h** ist schon ausgefüllt (WLAN, WordPress-URL, DEVICE_ID). Bei Bedarf in `firmware/config.h` anpassen.

---

## 2. Firmware bauen

Im **Plugin-Ordner** `palestreet-raumanzeige` (dort wo `build-firmware.sh` und `flash.sh` liegen):

```bash
cd palestreet-raumanzeige
./build-firmware.sh
```

Erzeugt `binaries/bootloader.bin`, `partitions.bin`, `app.bin`. Bei Fehlern: z. B. fehlende Library „PNGdec“ → `arduino-cli lib install "PNGdec"`.

---

## 3. Flashen (Bootloader-Modus meist nicht nötig)

**Mit unserer Firmware** auf dem Schild: Einfach ausführen:

```bash
./flash.sh
```

Das Skript sendet über USB den Befehl **„BOOTLOADER“** an das Gerät. Die Firmware geht daraufhin in den Download-Modus, und esptool flasht die neuen Binaries. **BOOT-Button ist dafür nicht nötig.**

- Dafür brauchst du **pyserial**: `pip install pyserial` (oder `pip3 install pyserial`).
- Wenn pyserial fehlt oder das Gerät noch die Original-Firmware hat: Gerät **manuell in den Bootloader** versetzen (siehe unten), dann erneut `./flash.sh`.

**Manuell in den Bootloader** (nur nötig beim ersten Mal oder wenn der Serial-Befehl nicht ankommt):

1. **USB** verbinden, **Gerät ausschalten** (Power AUS).
2. **BOOT-Button** gedrückt halten → **Power auf EIN** → ca. 2 Sekunden warten → **BOOT loslassen**.
3. Danach sofort: `./flash.sh`

Details siehe **FLASH_ANLEITUNG.md**.

---

## 4. Nach dem Flash

Das Skript sucht den Port (z. B. `/dev/cu.Maker3-187A` oder `/dev/cu.CH34*`) und schreibt die drei .bin-Dateien. Danach startet das Schild neu und zeigt die Raumanzeige (WLAN, WordPress-API, Rutan-Schrift).

Falls kein Port gefunden wird:  
`ls /dev/cu.*` → Port manuell angeben, z. B.:

```bash
esptool --chip esp32s3 -p /dev/cu.Maker3-187A write-flash \
  0x0 binaries/bootloader.bin \
  0x8000 binaries/partitions.bin \
  0x10000 binaries/app.bin
```

---

## 5. Optional: SPIFFS (Batterie-Icon)

Wenn bei niedrigem Akku ein **Icon** statt „Batterie niedrig!“ angezeigt werden soll: Die Datei `firmware/data/battery_low.BMP` muss auf das Gerät (SPIFFS). Das geht z. B. mit der **Arduino IDE**: Sketch öffnen (`firmware/firmware.ino`), **Tools → ESP32 Sketch Data Upload**. Oder ein separates SPIFFS-Flash-Tool nutzen – die Haupt-Firmware läuft auch ohne SPIFFS.

---

## Kurz-Checkliste

| Schritt       | Befehl / Aktion |
|---------------|------------------|
| 1. Bauen      | `./build-firmware.sh` |
| 2. USB + Port | Gerät per USB verbinden (Power an) |
| 3. Flashen    | `./flash.sh` (sendet „BOOTLOADER“, dann esptool – BOOT-Button i. d. R. nicht nötig) |
| 4. Fertig     | Schild startet, holt Daten von WordPress, zeigt Raum + QR |

**Falls** `./flash.sh` mit „Verbindung fehlgeschlagen“ endet: Gerät manuell in Bootloader (BOOT halten, Power an, BOOT loslassen), dann erneut `./flash.sh`.
