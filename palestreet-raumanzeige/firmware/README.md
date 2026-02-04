# Raumanzeige-Firmware (reTerminal E1001)

Minimaler Aufbau: **Akku-Anzeige**, **Website-Daten** (REST API), **kein Android-Layout**. Maximal akku-schonend.

## Konzept

- **Config auf dem Gerät:** In `config.h` (oder später SPIFFS) werden eingetragen: WLAN, WordPress-URL, device_id, **Update-Intervall Tag/Nacht**. Das Intervall muss nicht von der Website kommen – bleibt im Gerät.
- **QR-Code:** Entweder **Bild von WordPress** (qr_url aus der API, PNG/BMP) oder **generiert aus der URL** (Fallback mit qrcodegen). Ohne Bild und ohne URL: Platzhalter „QR“.
- **Anzeige:** Status, BIS-Zeit, drei nächste Termine, „Update alle X Min.“, BUCHEN + QR-Box.
- **Sleep:** Gerät schläft zwischen Updates. Beim Aufwachen: WiFi an → JSON von `/wp-json/palestreet-raumanzeige/v1/display?device_id=X` holen → **nur die vier Bereiche** partiell aktualisieren (partial update), nicht das ganze Display. Danach wieder schlafen.

## Build

- **Schriften:** Inter-TTF in `data/` legen, dann im Ordner `firmware/` einmal `python3 ttf_to_gfx.py` ausführen (benötigt `freetype-py`). Erzeugt die Inter-.h-Dateien. Optional: `python3 -m venv .venv` und `pip install freetype-py` – `.venv/` ist in .gitignore und kann gelöscht werden, um Speicher zu sparen (~17 MB); beim nächsten Schriften-Build ggf. neu anlegen.

Im Plugin-Ordner (eine Ebene über `firmware/`):

```bash
./build-firmware.sh
```

Erzeugt `binaries/bootloader.bin`, `partitions.bin`, `app.bin`.

## Flash (Kommandozeile)

Firmware wird **nur per Kommandozeile** geflasht, nicht über WordPress.

1. **esptool** installieren: `pip install esptool` oder `brew install esptool`
2. reTerminal per USB verbinden, in den **Bootloader-Modus** versetzen (Boot-Button beim Start gedrückt halten).
3. Im Plugin-Ordner:

```bash
./flash.sh
```

Oder manuell (Port ggf. anpassen):

```bash
esptool.py --chip esp32s3 -p /dev/cu.usbmodem* write_flash 0x0 binaries/bootloader.bin 0x8000 binaries/partitions.bin 0x10000 binaries/app.bin
```

## config.h

Vor dem Build `config.example.h` als `config.h` kopieren und anpassen:

- `WIFI_SSID` / `WIFI_PASSWORD` – WLAN
- `WORDPRESS_URL` – Basis-URL der WordPress-Installation (z. B. `https://palestreet.club`)
- `DEVICE_ID` – device_id des Schilds (0, 1, …), muss zur Ressource in WordPress passen.

Update-Intervall: lokal (Akku 5 Min, USB/Netz ab 4,2 V → 3 Min). Optional in `config.h`: `POWER_VOLTAGE_THRESHOLD` anpassen.

## Fehler-Icons (WLAN? / Akku! / Server?)

Die Fehler-Anzeige nutzt **1-bit BMP** aus `data/no_wifi.bmp`, `low_battery.bmp`, `no_connection.bmp`. Beim Build erzeugt `embed_icons.py` daraus `icons_data.h`. Ohne Icons werden die Texte „WLAN?“, „Akku!“, „Server?“ angezeigt.

## WordPress

Im Plugin werden pro Schild nur noch gesteuert: **Zeitzone**, **Update-Intervall** (für Web-Anzeige), **Template**. Das Schild ruft die Display-API auf und zeigt das HTML bzw. die vier Elemente. Kein Firmware-Flash mehr aus WordPress.
