# reTerminal E1001: In den Bootloader-Modus

**„No serial data received“** bedeutet fast immer: Das Gerät ist **nicht** im Bootloader-Modus. Der richtige Zeitpunkt ist wichtig.

## So geht’s (reTerminal E1001)

### Methode 1 (empfohlen)

1. **USB-Kabel einstecken** (Daten-USB-Kabel, nicht nur Ladekabel).
2. **Gerät ausschalten** (Power-Schalter auf der Rückseite auf AUS).
3. **Runden BOOT-Button finden** (Rückseite, oft **unter** oder **neben** dem Power-Schalter).
4. **BOOT gedrückt halten**.
5. **Während du BOOT hältst:** Power-Schalter auf **EIN** stellen.
6. **Ca. 2 Sekunden warten**, dann **BOOT loslassen**.

Danach sofort im Terminal flashen (siehe unten).

---

### Methode 2 (wenn Methode 1 nicht klappt)

1. **Gerät ausschalten**, USB abziehen.
2. **BOOT-Button gedrückt halten**.
3. **Während BOOT gehalten wird:** USB-Kabel einstecken.
4. **Weiter BOOT halten**, Power-Schalter auf **EIN**.
5. **2 Sekunden warten**, dann **BOOT loslassen**.

---

### Wichtig

- **BOOT** ist der **runde** Knopf (nicht der Power-Schalter).
- Bei manchen Geräten sitzt BOOT **unter** einer Klappe oder neben dem Schalter – im Zweifel in der Seeed-Wiki nach „reTerminal E1001 BOOT“ suchen.
- Wenn es wieder „No serial data received“ gibt: Gerät **10–15 Minuten** ausstecken, dann Methode 1 oder 2 wiederholen.

---

## Flashen (nach Bootloader-Modus)

Port prüfen:

```bash
ls /dev/cu.*
```

Typisch für reTerminal: **`/dev/cu.Maker3-187A`** (oder ähnlich).

Flashen (Port anpassen, wenn nötig):

```bash
cd palestreet-raumanzeige
esptool --chip esp32s3 -p /dev/cu.Maker3-187A write-flash \
  0x0 binaries/bootloader.bin \
  0x8000 binaries/partitions.bin \
  0x10000 binaries/app.bin
```

Falls dein esptool noch `write_flash` (mit Unterstrich) verlangt:

```bash
esptool --chip esp32s3 -p /dev/cu.Maker3-187A write_flash \
  0x0 binaries/bootloader.bin \
  0x8000 binaries/partitions.bin \
  0x10000 binaries/app.bin
```

Wenn die Verbindung instabil ist, langsame Baudrate probieren:

```bash
esptool --chip esp32s3 -p /dev/cu.Maker3-187A -b 115200 write-flash \
  0x0 binaries/bootloader.bin \
  0x8000 binaries/partitions.bin \
  0x10000 binaries/app.bin
```
