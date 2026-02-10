/*
 * Raumanzeige / Räumbewegungsplan für reTerminal E1001
 * Speisung: WordPress REST API (/v1/display?device_id=X) → Kalender-Daten
 * Layout: Status (BESETZT/NICHT BESETZT BIS HH:MM), nächste Termine, „Update alle X Min.“, fester QR-Bereich
 * Update-Intervall: aus WordPress pro Schild (refresh_seconds in API)
 * Firmware wird per Kommandozeile geflasht (flash.sh, esptool), nicht über WordPress.
 */
#define FIRMWARE_VERSION "1.0.3"

#include <WiFi.h>
#include <HTTPClient.h>
#include <WiFiClientSecure.h>
#include <SPI.h>
#include <FS.h>
#include <SPIFFS.h>
#include <esp_partition.h>
#include <esp_sleep.h>
#include <GxEPD2_BW.h>
#ifdef __has_include
  #if __has_include("config.h")
    #include "config.h"
  #endif
  #if __has_include("icons_data.h")
    #include "icons_data.h"
  #endif
  #if __has_include("icons_gfx.h")
    #include "icons_gfx.h"
  #endif
#endif
// Schrift: Inter (Inter_*.ttf in data/, dann python3 ttf_to_gfx.py). SemiBold 9/11/13 pt, ExtraBold 13/25/27 pt.
#if __has_include("Inter_extrabold_27pt7b.h")
  #include "Inter_extrabold_27pt7b.h"
  #define FONT_STATUS  Inter_extrabold_27pt7b
#elif __has_include("Inter_bold_27pt7b.h")
  #include "Inter_bold_27pt7b.h"
  #define FONT_STATUS  Inter_bold_27pt7b
#elif __has_include("Inter_extrabold_25pt7b.h")
  #include "Inter_extrabold_25pt7b.h"
  #define FONT_STATUS  Inter_extrabold_25pt7b
#elif __has_include("Inter_bold_25pt7b.h")
  #include "Inter_bold_25pt7b.h"
  #define FONT_STATUS  Inter_bold_25pt7b
#else
  #include "Inter_extrabold_25pt7b.h"
  #define FONT_STATUS  Inter_extrabold_25pt7b
#endif
#if __has_include("Inter_semibold_13pt7b.h")
  #include "Inter_semibold_13pt7b.h"
  #include "Inter_semibold_11pt7b.h"
  #include "Inter_semibold_9pt7b.h"
  #define FONT_MED_13  Inter_semibold_13pt7b
  #define FONT_MED_11  Inter_semibold_11pt7b
  #define FONT_MED_9   Inter_semibold_9pt7b
  #define HAS_9PT
  #define HAS_11PT
  #define HAS_13PT
#elif __has_include("Inter_medium_12pt7b.h")
  #include "Inter_medium_12pt7b.h"
  #include "Inter_medium_10pt7b.h"
  #include "Inter_medium_8pt7b.h"
  #define FONT_MED_13  Inter_medium_12pt7b
  #define FONT_MED_11  Inter_medium_10pt7b
  #define FONT_MED_9   Inter_medium_8pt7b
  #define HAS_9PT
  #define HAS_11PT
  #define HAS_13PT
#else
  #include "Inter_regular_12pt7b.h"
  #include "Inter_regular_10pt7b.h"
  #include "Inter_regular_8pt7b.h"
  #define FONT_MED_13  Inter_regular_12pt7b
  #define FONT_MED_11  Inter_regular_10pt7b
  #define FONT_MED_9   Inter_regular_8pt7b
  #define HAS_9PT
  #define HAS_11PT
  #define HAS_13PT
#endif
#if __has_include("Inter_extrabold_13pt7b.h")
  #include "Inter_extrabold_13pt7b.h"
  #define FONT_BOLD_13 Inter_extrabold_13pt7b
#elif __has_include("Inter_bold_13pt7b.h")
  #include "Inter_bold_13pt7b.h"
  #define FONT_BOLD_13 Inter_bold_13pt7b
#else
  #include "Inter_extrabold_13pt7b.h"
  #define FONT_BOLD_13 Inter_extrabold_13pt7b
#endif
#include <PNGdec.h>
extern "C" {
#include "qrcodegen.h"
}

// Fallback falls config.h nicht geladen (WIFI_SSID etc.)
#ifndef WIFI_SSID
  #define WIFI_SSID       "WLAN-Name"
  #define WIFI_PASSWORD   "WLAN-Passwort"
  #define WORDPRESS_URL   "https://example.com"
  #define DEVICE_ID       0
#endif

// Fallback-Texte für Fehler-Anzeige (wenn Icons fehlen) – in config.h überschreibbar
#ifndef ERROR_TEXT_NO_WIFI
  #define ERROR_TEXT_NO_WIFI        "WLAN?"
#endif
#ifndef ERROR_TEXT_LOW_BATTERY
  #define ERROR_TEXT_LOW_BATTERY    "Akku!"
#endif
#ifndef ERROR_TEXT_NO_CONNECTION
  #define ERROR_TEXT_NO_CONNECTION  "Server?"
#endif

// Config aus Flash (WordPress „Config auf Gerät schreiben“) – Adresse = Partition „config“ (partitions.csv)
#define FLASH_CONFIG_MAGIC    "PALCONF"
#define FLASH_CONFIG_VERSION  1
#define FLASH_CONFIG_ADDR      0x3F0000   // 4MB Flash, letzte 64KB
#define FLASH_CONFIG_SIZE      4096

// Laufzeit-Config: aus Flash (WLAN + optional URL + device_id) oder aus config.h
String runtimeWifiSsid = String(WIFI_SSID);
String runtimeWifiPass = String(WIFI_PASSWORD);
String runtimeWpUrl    = String(WORDPRESS_URL);
int    runtimeDeviceId = (int)DEVICE_ID;
bool   flashConfigValid = false;

// reTerminal E1001 – Pins (Seeed Wiki)
#define EPD_SCK_PIN   7
#define EPD_MOSI_PIN  9
#define EPD_CS_PIN    10
#define EPD_DC_PIN    11
#define EPD_RES_PIN   12
#define EPD_BUSY_PIN  13
#define EPD_SELECT    0   // 0 = E1001 B&W
#define SERIAL_RX     44
#define SERIAL_TX     43

// Buttons (active-low)
#define BUTTON_KEY0   3   // Rechts (grüner Button) = manueller Refresh
#define BUTTON_KEY1   4
#define BUTTON_KEY2   5

// reTerminal E Series: Grüne Status-LED (GPIO6, Logik invertiert: LOW = an, HIGH = aus). Nur im Debug-Modus an.
// Rote Lade-LED: wird vom Lade-IC (PMIC) gesteuert, nicht vom ESP32 – per Software nicht abschaltbar.
#define LED_PIN       6

// reTerminal E Series: Akku-Spannung (Seeed Wiki – Battery Management System)
#define BATTERY_ADC_PIN     1   // GPIO1 – geteilte Batteriespannung
#define BATTERY_ENABLE_PIN  21  // GPIO21 – Enable für Akku-Messung (HIGH = aktiv)

// Bootloader per Serial: Magic-String "BOOTLOADER" auf Serial1 (USB) → RTC-WDT-Reset mit GPIO0 low
#define BOOTLOADER_MAGIC   "BOOTLOADER"
#define BOOTLOADER_GPIO    0   // GPIO0 = Boot-Strapping auf ESP32-S3 (low = Download-Modus)

// Layout 1:1 wie Template raum.php (800×480). Status 26pt, Abstände angepasst.
#define DISP_W                800
#define DISP_H                480
#define STATUS_LEFT           40
#define STATUS_WIDTH          570
#define STATUS_TOP             136   // Oberkante Status-Zeile (27pt ≈ 34px Zeilenhöhe)
#define STATUS_UNTIL_TOP       208   // Abstand für zweite Zeile (27pt + 8px Zwischenraum)
#define EVENT_BOTTOM           25
#define EVENT_X_1              25   // 10px nach links
#define EVENT_X_2              235
#define EVENT_X_3              445
#define EVENT_COL_WIDTH       170
#define UPDATE_TOP             42   // Oberkante Block (Zeitstempel + Update): Abstand oben ≈ RIGHT_MARGIN (25)
#define LOW_BATTERY_PERCENT    2    // Akku unter diesem Wert → Fehler-Split-Screen + Deep Sleep
#define ERROR_QUAD_W           400  // Split-Screen: Breite pro Quadrant (800/2)
#define ERROR_QUAD_H           240  // Split-Screen: Höhe pro Quadrant (480/2)
#define ERROR_ICON_MAX         160  // max. Icon-Größe (PNG eingebettet oder SPIFFS), 150 px Icons
#define BUCHEN_BOTTOM         140
#define QR_BOX_SIZE           110
// Rechte Kante: eine gemeinsame Kante für Datum/Update/Debug und QR-Box (bündig)
#define RIGHT_MARGIN           25
#define RIGHT_EDGE             (DISP_W - RIGHT_MARGIN)        // 775
#define QR_BOX_X               (RIGHT_EDGE - QR_BOX_SIZE)     // 665
#define QR_BOX_Y               (EVENT_TEXT_BOTTOM - QR_BOX_SIZE)       // 359
// QR-Box unten mit Event-Text ausrichten: Event-Baseline = DISP_H - EVENT_BOTTOM, Schrift ~14px hoch → Unterkante ~469
#define EVENT_TEXT_BOTTOM      (DISP_H - EVENT_BOTTOM + 14)   // Unterkante Event-Labels
#define QR_BOX_BOTTOM          (DISP_H - EVENT_TEXT_BOTTOM)   // 11

#ifdef REFRESH_SECONDS_FALLBACK
  #define REFRESH_SECONDS_DEFAULT  REFRESH_SECONDS_FALLBACK
#else
  #define REFRESH_SECONDS_DEFAULT  300
#endif
#define REFRESH_SECONDS_RETRY_ERROR  60   // bei WLAN- oder Server-Fehler: jede Minute erneut prüfen

// Display (E1001: 800×480)
#define GxEPD2_DISPLAY_CLASS    GxEPD2_BW
#define GxEPD2_DRIVER_CLASS     GxEPD2_750_GDEY075T7
#define MAX_DISPLAY_BUFFER_SIZE 16000
#define MAX_HEIGHT(EPD) ((EPD::HEIGHT <= MAX_DISPLAY_BUFFER_SIZE / (EPD::WIDTH/8)) ? EPD::HEIGHT : MAX_DISPLAY_BUFFER_SIZE/(EPD::WIDTH/8))

SPIClass hspi(HSPI);
GxEPD2_DISPLAY_CLASS<GxEPD2_DRIVER_CLASS, MAX_HEIGHT(GxEPD2_DRIVER_CLASS)> display(
  GxEPD2_DRIVER_CLASS(/*CS=*/EPD_CS_PIN, /*DC=*/EPD_DC_PIN, /*RST=*/EPD_RES_PIN, /*BUSY=*/EPD_BUSY_PIN));

// Anzeige-Daten nur aus Display-API (WordPress berechnet alles)
#define MAX_DISPLAY_EVENTS 3
String displayStatusLabel = "";
String displayStatusUntil = "";
String displayEventTime[MAX_DISPLAY_EVENTS];
String displayEventSummary[MAX_DISPLAY_EVENTS];
bool displayEventIsNextDay[MAX_DISPLAY_EVENTS];
int numDisplayEvents = 0;
String displayUpdateLabel = "";
String displayTime = "";
String displayRoomName = "";   // von API (room_name), für Debug-Anzeige „Name (ID)“
String qrUrl = "";
unsigned long displayRefreshSeconds = REFRESH_SECONDS_DEFAULT;  // von API, pro Schild einstellbar
unsigned long secondsUntilNextEvent = 0;  // von API: Trigger-Update zum Event-Start
// QR-Bild einmal vor dem Display-Update laden, nicht im Page-Loop (vermeidet mehrfachen HTTP-Aufruf)
static uint8_t* s_qrImageBuf = nullptr;
static size_t s_qrImageLen = 0;
bool wifiOk = false;
bool lastFetchOk = false;       // letzter API-Abruf erfolgreich (für Fehler-Split-Screen)
bool showDebugDisplay = false;  // von API (debug_display), über WordPress umschaltbar
bool wifiConfigChanged = false; // API hat WLAN geändert → am Ende von doUpdate() neu verbinden
String apiContentHash = "";     // von API (content_hash), für „nur bei Änderung updaten“
static String lastContentHash = "";  // letzter Hash → bei Gleichheit kein Display-Update (Akku sparen)
String lastBmpFailReason = "";  // letzter Grund warum drawBmpFromBuffer fehlschlug (für Debug)

// Config aus Flash-Partition „config“ lesen (WLAN + optional WordPress-URL + device_id)
void loadFlashConfig() {
  const esp_partition_t *p = esp_partition_find_first(ESP_PARTITION_TYPE_DATA, (esp_partition_subtype_t)0x40, "config");
  if (!p) return;
  uint8_t buf[FLASH_CONFIG_SIZE];
  if (esp_partition_read(p, 0, buf, sizeof(buf)) != ESP_OK) return;
  if (memcmp(buf, FLASH_CONFIG_MAGIC, 7) != 0) return;
  if (buf[7] != FLASH_CONFIG_VERSION) return;
  int pos = 8;
  auto readStr = [&](int maxLen) -> String {
    String s;
    for (int i = 0; i < maxLen && pos < (int)sizeof(buf) && buf[pos]; i++, pos++) s += (char)buf[pos];
    if (pos < (int)sizeof(buf)) pos++;
    return s;
  };
  runtimeWifiSsid = readStr(32);
  runtimeWifiPass = readStr(64);
  runtimeWpUrl     = readStr(200);
  if (pos + 4 <= (int)sizeof(buf)) {
    runtimeDeviceId = (int)(buf[pos] | (buf[pos+1]<<8) | (buf[pos+2]<<16) | (buf[pos+3]<<24));
  }
  if (runtimeWifiSsid.length() > 0) {
    flashConfigValid = true;
  }
  // Wenn nur WLAN geflasht wurde (keine URL in Partition): URL + device_id aus config.h behalten
  if (runtimeWpUrl.length() < 5) {
    runtimeWpUrl = String(WORDPRESS_URL);
    runtimeDeviceId = (int)DEVICE_ID;
  }
}

// ——— Display-API holen: WordPress liefert Status, Events, Zeit, qr_url (Schild nur Ausgabe) ———
static bool parseJsonString(const String& body, const char* key, String& out) {
  String search = String("\"") + key + "\":\"";
  int p = body.indexOf(search);
  if (p < 0) return false;
  p += search.length();
  int q = p;
  while (q < (int)body.length()) {
    char c = body[q];
    if (c == '\\' && q + 1 < (int)body.length()) { q += 2; continue; }
    if (c == '"') break;
    q++;
  }
  if (q <= p) return false;
  out = body.substring(p, q);
  out.replace("\\/", "/");
  return true;
}

static bool parseJsonBool(const String& body, const char* key, bool& out) {
  String search = String("\"") + key + "\":";
  int p = body.indexOf(search);
  if (p < 0) return false;
  p += (int)search.length();
  while (p < (int)body.length() && (body[p] == ' ' || body[p] == '\t')) p++;
  // true/True (JSON/Python-Stil) und false/False
  if (p + 4 <= (int)body.length()) {
    String four = body.substring(p, p + 4);
    if (four == "true" || four == "True") { out = true; return true; }
  }
  if (p + 5 <= (int)body.length()) {
    String five = body.substring(p, p + 5);
    if (five == "false" || five == "False") { out = false; return true; }
  }
  return false;
}

static bool fetchDisplay() {
  WiFiClientSecure client;
  client.setInsecure();
  HTTPClient http;
  String url = runtimeWpUrl + "/wp-json/palestreet-raumanzeige/v1/display?device_id=" + String(runtimeDeviceId) + "&nocache=" + String(millis());
  http.begin(client, url);
  http.addHeader("Cache-Control", "no-cache");
  http.setTimeout(15000);
  int code = http.GET();
  if (code != 200) { http.end(); return false; }
  String body = http.getString();
  http.end();
  if (body.length() < 20) return false;

  parseJsonString(body, "status_label", displayStatusLabel);
  parseJsonString(body, "status_until", displayStatusUntil);
  parseJsonString(body, "update_interval_label", displayUpdateLabel);
  parseJsonString(body, "display_time", displayTime);
  parseJsonString(body, "content_hash", apiContentHash);
  parseJsonString(body, "room_name", displayRoomName);
  parseJsonString(body, "qr_url", qrUrl);
  qrUrl.replace("\\/", "/");
  parseJsonBool(body, "debug_display", showDebugDisplay);
  // refresh_seconds: von API – sofort übernehmen (on the fly), nächster Sleep nutzt neuen Wert
  int refPos = body.indexOf("refresh_seconds");
  if (refPos < 0) refPos = body.indexOf("refresh_interval_sec");
  if (refPos >= 0) {
    int colonPos = body.indexOf(":", refPos);
    if (colonPos >= 0 && colonPos < (int)body.length() - 1) {
      refPos = colonPos + 1;
      while (refPos < (int)body.length() && (body[refPos] == ' ' || body[refPos] == '\t' || body[refPos] == '"')) refPos++;
      unsigned long val = 0;
      while (refPos < (int)body.length()) {
        char c = body[refPos++];
        if (c >= '0' && c <= '9') val = val * 10 + (c - '0');
        else break;
      }
      if (val >= 60 && val <= 7200) displayRefreshSeconds = val;
    }
  }
  if (displayRefreshSeconds == 0) displayRefreshSeconds = REFRESH_SECONDS_DEFAULT;
  // wifi_ssid / wifi_pass: von API (WLAN on the fly), bei Änderung am Ende von doUpdate() neu verbinden
  String apiWifiSsid, apiWifiPass;
  if (parseJsonString(body, "wifi_ssid", apiWifiSsid) && parseJsonString(body, "wifi_pass", apiWifiPass) && apiWifiSsid.length() > 0) {
    if (apiWifiSsid != runtimeWifiSsid || apiWifiPass != runtimeWifiPass) {
      runtimeWifiSsid = apiWifiSsid;
      runtimeWifiPass = apiWifiPass;
      wifiConfigChanged = true;
    }
  }
  // seconds_until_next_event: Trigger-Update zum Start des nächsten Events
  secondsUntilNextEvent = 0;
  int sevPos = body.indexOf("\"seconds_until_next_event\":");
  if (sevPos >= 0) {
    sevPos += 27;   // Länge von "\"seconds_until_next_event\":" (Zeiger hinter ':')
    while (sevPos < (int)body.length() && (body[sevPos] == ' ' || body[sevPos] == '\t')) sevPos++;
    unsigned long val = 0;
    while (sevPos < (int)body.length()) {
      char c = body[sevPos++];
      if (c >= '0' && c <= '9') val = val * 10 + (c - '0');
      else break;
    }
    if (val <= 86400) secondsUntilNextEvent = val;  // max 24 h
  }
  numDisplayEvents = 0;
  int pos = body.indexOf("\"events\":[");
  if (pos >= 0) {
    pos = body.indexOf("[", pos) + 1;
    while (numDisplayEvents < MAX_DISPLAY_EVENTS) {
      int timePos = body.indexOf("\"time\":\"", pos);
      if (timePos < 0 || timePos > (int)body.indexOf("]", pos)) break;
      int timeStart = timePos + 8;
      int timeEnd = body.indexOf("\"", timeStart);
      if (timeEnd < 0) break;
      displayEventTime[numDisplayEvents] = body.substring(timeStart, timeEnd);
      int sumPos = body.indexOf("\"summary\":\"", timeEnd);
      if (sumPos < 0) break;
      int sumStart = sumPos + 11;
      int sumEnd = sumStart;
      while (sumEnd < (int)body.length()) {
        char c = body[sumEnd];
        if (c == '\\' && sumEnd + 1 < (int)body.length()) { sumEnd += 2; continue; }
        if (c == '"') break;
        sumEnd++;
      }
      displayEventSummary[numDisplayEvents] = body.substring(sumStart, sumEnd);
      displayEventSummary[numDisplayEvents].replace("\\/", "/");
      // Prüfe auf is_next_day Flag
      displayEventIsNextDay[numDisplayEvents] = false;
      int nextDayPos = body.indexOf("\"is_next_day\":", sumEnd);
      if (nextDayPos >= 0 && nextDayPos < (int)body.indexOf("}", sumEnd)) {
        int valStart = nextDayPos + 14;
        while (valStart < (int)body.length() && (body[valStart] == ' ' || body[valStart] == '\t')) valStart++;
        if (valStart < (int)body.length() && body[valStart] == 't') {
          displayEventIsNextDay[numDisplayEvents] = true;
        }
      }
      numDisplayEvents++;
      pos = sumEnd + 1;
    }
  }

  if (displayStatusLabel.length() == 0) displayStatusLabel = "NICHT BESETZT";
  if (displayStatusUntil.length() == 0) displayStatusUntil = "BIS 23:59";
  return true;
}

// ——— PNG/BMP aus URL: Magic Bytes ———
#define QR_IMAGE_MAX_BYTES  65536
#define PNG_MAGIC_LEN 8
static const uint8_t PNG_MAGIC[PNG_MAGIC_LEN] = { 0x89, 0x50, 0x4E, 0x47, 0x0D, 0x0A, 0x1A, 0x0A };

// Error-Report für Icons: 1 = Ausgabe über Serial1 (115200), 0 = aus
#define ICON_ERROR_REPORT 1

#if ICON_ERROR_REPORT
static const char* s_iconReportLabel = nullptr;
#endif

// ——— PNG aus Puffer zeichnen (PNGdec); QR max 110×110, Icons max 120×120 ———
#define PNG_LINEBUF_MAX 160
static PNG pngQR;
struct QRDrawContext { int atX, atY; };
static int pngDrawCallback(PNGDRAW* pDraw) {
  QRDrawContext* ctx = (QRDrawContext*)pDraw->pUser;
  uint16_t lineBuf[PNG_LINEBUF_MAX];
  if (pDraw->iWidth > PNG_LINEBUF_MAX) return 1;
  pngQR.getLineAsRGB565(pDraw, lineBuf, PNG_RGB565_LITTLE_ENDIAN, 0x00FFFFFF);
  for (int x = 0; x < pDraw->iWidth; x++) {
    uint16_t c = lineBuf[x];
    uint8_t r = (c >> 11) * 255 / 31, g = ((c >> 5) & 63) * 255 / 63, b = (c & 31) * 255 / 31;
    uint16_t color = (r * 299 + g * 587 + b * 114) < 128 * 1000 ? GxEPD_BLACK : GxEPD_WHITE;
    display.drawPixel(ctx->atX + x, ctx->atY + pDraw->y, color);
  }
  return 1;
}
static bool drawPngFromBuffer(uint8_t* buf, size_t len, int atX, int atY, int maxW, int maxH) {
#if ICON_ERROR_REPORT
  const char* lbl = s_iconReportLabel ? s_iconReportLabel : "png";
#endif
  if (len < PNG_MAGIC_LEN) {
#if ICON_ERROR_REPORT
    Serial1.printf("[Icon] %s: Buffer zu klein len=%u\n", lbl, (unsigned)len);
#endif
    return false;
  }
  int openRc = pngQR.openRAM(buf, (int)len, pngDrawCallback);
  if (openRc != PNG_SUCCESS) {
#if ICON_ERROR_REPORT
    Serial1.printf("[Icon] %s: openRAM fehlgeschlagen rc=%d (PNG_SUCCESS=%d)\n", lbl, openRc, PNG_SUCCESS);
#endif
    return false;
  }
  int w = pngQR.getWidth(), h = pngQR.getHeight();
  if (w <= 0 || w > maxW || h <= 0 || h > maxH) {
#if ICON_ERROR_REPORT
    Serial1.printf("[Icon] %s: Größe ungültig w=%d h=%d maxW=%d maxH=%d\n", lbl, w, h, maxW, maxH);
#endif
    pngQR.close();
    return false;
  }
  QRDrawContext ctx = { atX, atY };
  int rc = pngQR.decode(&ctx, PNG_FAST_PALETTE);
  pngQR.close();
  if (rc != PNG_SUCCESS) {
#if ICON_ERROR_REPORT
    Serial1.printf("[Icon] %s: decode fehlgeschlagen rc=%d (PNG_SUCCESS=%d)\n", lbl, rc, PNG_SUCCESS);
#endif
    return false;
  }
  return true;
}

// ——— PNG aus PROGMEM (beim Build eingebettet) zeichnen ———
#define ERROR_ICON_MAX_BYTES 65536
#define ERROR_ICON_STACK_BUF 2048   // Icons bis 2 KB ohne malloc (vermeidet Heap-Fragmentierung)
static bool drawPngFromProgmem(const uint8_t* progmemBuf, size_t len, int atX, int atY, int maxW, int maxH) {
#if ICON_ERROR_REPORT
  const char* lbl = s_iconReportLabel ? s_iconReportLabel : "progmem";
#endif
  if (len == 0 || len > ERROR_ICON_MAX_BYTES) {
#if ICON_ERROR_REPORT
    Serial1.printf("[Icon] %s: Progmem len ungültig len=%u\n", lbl, (unsigned)len);
#endif
    return false;
  }
  bool ok;
  if (len <= ERROR_ICON_STACK_BUF) {
    uint8_t buf[ERROR_ICON_STACK_BUF];
    memcpy_P(buf, progmemBuf, len);
    ok = drawPngFromBuffer(buf, len, atX, atY, maxW, maxH);
  } else {
    uint8_t* buf = (uint8_t*)malloc(len);
    if (!buf) {
#if ICON_ERROR_REPORT
      Serial1.printf("[Icon] %s: malloc(%u) fehlgeschlagen\n", lbl, (unsigned)len);
#endif
      return false;
    }
    memcpy_P(buf, progmemBuf, len);
    ok = drawPngFromBuffer(buf, len, atX, atY, maxW, maxH);
    free(buf);
  }
#if ICON_ERROR_REPORT
  if (ok) Serial1.printf("[Icon] %s: Progmem OK len=%u\n", lbl, (unsigned)len);
#endif
  return ok;
}

// ——— PNG aus SPIFFS (Fallback wenn nicht eingebettet); max 64 KB ———
static bool drawPngFromSpiffs(const char* path, int atX, int atY, int maxW, int maxH) {
#if ICON_ERROR_REPORT
  const char* lbl = s_iconReportLabel ? s_iconReportLabel : path;
#endif
  if (!SPIFFS.exists(path)) {
#if ICON_ERROR_REPORT
    Serial1.printf("[Icon] %s: SPIFFS.exists(%s)=false\n", lbl, path);
#endif
    return false;
  }
  File f = SPIFFS.open(path, "r");
  if (!f) {
#if ICON_ERROR_REPORT
    Serial1.printf("[Icon] %s: SPIFFS.open(%s) fehlgeschlagen\n", lbl, path);
#endif
    return false;
  }
  size_t sz = f.size();
  if (sz > ERROR_ICON_MAX_BYTES) {
#if ICON_ERROR_REPORT
    Serial1.printf("[Icon] %s: Datei zu groß %u\n", lbl, (unsigned)sz);
#endif
    f.close();
    return false;
  }
  uint8_t* buf = (uint8_t*)malloc(sz);
  if (!buf) {
#if ICON_ERROR_REPORT
    Serial1.printf("[Icon] %s: malloc(%u) für SPIFFS fehlgeschlagen\n", lbl, (unsigned)sz);
#endif
    f.close();
    return false;
  }
  size_t n = f.read(buf, sz);
  f.close();
  bool ok = (n == sz) && drawPngFromBuffer(buf, n, atX, atY, maxW, maxH);
  free(buf);
#if ICON_ERROR_REPORT
  if (!ok) Serial1.printf("[Icon] %s: SPIFFS read=%u size=%u draw=%s\n", lbl, (unsigned)n, (unsigned)sz, (n != sz) ? "read fail" : "buffer draw fail");
#endif
  return ok;
}

// ——— BMP aus Puffer zeichnen (QR-Bild von URL + SPIFFS-Icons); max 120×120, 1/24/32 bpp ———
static bool drawBmpFromBuffer(const uint8_t* buf, size_t len, int atX, int atY, int maxW, int maxH) {
  lastBmpFailReason = "";
  if (len < 54) { lastBmpFailReason = "BMP:len<54"; return false; }
  if (buf[0] != 'B' || buf[1] != 'M') { lastBmpFailReason = "BMP:!BM"; return false; }
  uint32_t offset = (uint32_t)buf[10] | ((uint32_t)buf[11] << 8) | ((uint32_t)buf[12] << 16) | ((uint32_t)buf[13] << 24);
  int32_t bmpW = (int32_t)((uint32_t)buf[18] | ((uint32_t)buf[19] << 8) | ((uint32_t)buf[20] << 16) | ((uint32_t)buf[21] << 24));
  int32_t bmpH = (int32_t)((uint32_t)buf[22] | ((uint32_t)buf[23] << 8) | ((uint32_t)buf[24] << 16) | ((uint32_t)buf[25] << 24));
  if (bmpH < 0) bmpH = -bmpH;
  uint16_t bpp = (uint16_t)buf[28] | ((uint16_t)buf[29] << 8);
  if (bmpW <= 0 || bmpW > maxW || bmpH <= 0 || bmpH > maxH) {
    lastBmpFailReason = String("BMP:") + String((int)bmpW) + "x" + String((int)bmpH) + " max" + String((int)maxW);
    return false;
  }

  if (bpp == 1) {
    int rowBytes = ((bmpW + 31) / 32) * 4;
    size_t totalBytes = (size_t)rowBytes * (size_t)bmpH;
    if (offset + totalBytes > len || rowBytes > 64) {
      lastBmpFailReason = String("BMP:o") + String((unsigned)offset) + " tb" + String((unsigned)totalBytes) + " L" + String((unsigned)len);
      return false;
    }
    const uint8_t* p = buf + offset;
    // BMP 1-bit: erste Zeile in Datei = untere Zeile (positive Höhe). Zeichnen mit fillRect-Streifen (E-Paper zuverlässiger als viele drawPixel).
    for (int row = 0; row < bmpH; row++) {
      size_t rowOff = (size_t)(row * rowBytes);
      int drawY = atY + (bmpH - 1 - row);
      int col = 0;
      while (col < bmpW) {
        int byteIdx = col / 8;
        int bitIdx = 7 - (col % 8);
        uint8_t byte = (rowOff + (size_t)byteIdx < totalBytes) ? p[rowOff + byteIdx] : 0;
        uint16_t color = (byte & (1 << bitIdx)) ? GxEPD_BLACK : GxEPD_WHITE;
        int runStart = col;
        col++;
        while (col < bmpW) {
          int bi = 7 - (col % 8);
          int by = col / 8;
          uint8_t b = (rowOff + (size_t)by < totalBytes) ? p[rowOff + by] : 0;
          uint16_t c = (b & (1 << bi)) ? GxEPD_BLACK : GxEPD_WHITE;
          if (c != color) break;
          col++;
        }
        display.fillRect(atX + runStart, drawY, col - runStart, 1, color);
      }
    }
    return true;
  }

  if (bpp != 24 && bpp != 32) return false;
  int rowBytes = (bmpW * (bpp / 8) + 3) & ~3;
  size_t rowBufSize = (size_t)rowBytes;
  if (offset + rowBufSize * (size_t)bmpH > len) return false;
  if (rowBufSize > 512) return false;
  uint8_t rowBuf[512];
  const uint8_t* p = buf + offset;
  for (int row = bmpH - 1; row >= 0; row--) {
    if (p + rowBufSize > buf + len) return false;
    memcpy(rowBuf, p, rowBufSize);
    p += rowBufSize;
    for (int col = 0; col < bmpW; col++) {
      int idx = col * (bpp / 8);
      uint8_t r = rowBuf[idx + 2], g = rowBuf[idx + 1], b = rowBuf[idx];
      uint16_t color = (r * 299 + g * 587 + b * 114) < 128 * 1000 ? GxEPD_BLACK : GxEPD_WHITE;
      display.drawPixel(atX + col, atY + row, color);
    }
  }
  return true;
}

// ——— BMP aus PROGMEM (eingebettete Fehler-Icons, 1-bit, kein Dekompressor) ———
#define ERROR_ICON_BMP_MAX 4096   // 1-bit BMP 150×150 ≈ 3,6 KB (Heap statt Stack, ESP32 stack knapp)
#define BMP_DEBUG 1   // Debug-Ausgabe für Icons (Serial1)
static bool drawBmpFromProgmem(const uint8_t* progmemBuf, size_t len, int atX, int atY, int maxW, int maxH) {
  if (len < 54 || len > ERROR_ICON_BMP_MAX) {
    lastBmpFailReason = String("BMP:len ") + String((unsigned)len);
#if BMP_DEBUG
    Serial1.printf("[BMP] Progmem len ungültig: %u\n", (unsigned)len);
#endif
    return false;
  }
  uint8_t* buf = (uint8_t*)malloc(len);
  if (!buf) {
    lastBmpFailReason = "BMP:malloc";
#if BMP_DEBUG
    Serial1.println("[BMP] Progmem malloc fehlgeschlagen");
#endif
    return false;
  }
  memcpy_P(buf, progmemBuf, len);
  bool ok = drawBmpFromBuffer(buf, len, atX, atY, maxW, maxH);
#if BMP_DEBUG
  if (!ok) {
    int32_t w = (int32_t)((uint32_t)buf[18] | ((uint32_t)buf[19] << 8) | ((uint32_t)buf[20] << 16) | ((uint32_t)buf[21] << 24));
    int32_t h = (int32_t)((uint32_t)buf[22] | ((uint32_t)buf[23] << 8) | ((uint32_t)buf[24] << 16) | ((uint32_t)buf[25] << 24));
    uint16_t bpp = (uint16_t)buf[28] | ((uint16_t)buf[29] << 8);
    Serial1.printf("[BMP] Progmem draw fehlgeschlagen: %dx%d, %d bpp\n", (int)w, (int)h, (int)bpp);
  } else {
    Serial1.printf("[BMP] Progmem OK len=%u\n", (unsigned)len);
  }
#endif
  free(buf);
  return ok;
}

// ——— BMP aus SPIFFS zeichnen (Fehler-Screen oder kleine Icons) ———
static bool drawBmpFromSpiffs(const char* path, int atX, int atY, int maxW, int maxH) {
  if (!SPIFFS.exists(path)) return false;
  File f = SPIFFS.open(path, "r");
  if (!f) return false;
  size_t sz = f.size();
  if (sz > 16384) { f.close(); return false; }
  uint8_t* buf = (uint8_t*)malloc(sz);
  if (!buf) { f.close(); return false; }
  size_t n = f.read(buf, sz);
  f.close();
  bool ok = (n == sz) && drawBmpFromBuffer(buf, n, atX, atY, maxW, maxH);
  free(buf);
  return ok;
}

// ——— QR-Code-Bild von URL in Puffer laden (wird in doUpdate() aufgerufen, nicht im Page-Loop) ———
// Akzeptiert auch chunked Transfer (getSize() == -1): liest in Chunks bis max QR_IMAGE_MAX_BYTES.
// User-Agent setzen, damit Server das Gerät nicht ablehnt.
static bool fetchQRImageToBuffer() {
  if (qrUrl.length() < 10) return false;
  WiFiClientSecure client;
  client.setInsecure();
  HTTPClient http;
  String url = qrUrl;
  if (url.indexOf('?') >= 0) url += "&nocache="; else url += "?nocache=";
  url += String(millis());
  http.begin(client, url);
  http.setTimeout(20000);
  http.addHeader("User-Agent", "Palestreet-Raumanzeige/1.0");
  http.addHeader("Cache-Control", "no-cache");
  int code = http.GET();
  if (code != 200) { http.end(); return false; }
  int size = http.getSize();
  uint8_t* buf = nullptr;
  size_t got = 0;
  if (size > 0 && size <= (int)QR_IMAGE_MAX_BYTES) {
    buf = (uint8_t*)malloc((size_t)size);
    if (!buf) { http.end(); return false; }
    got = http.getStream().readBytes(buf, (size_t)size);
  } else {
    // Chunked oder unbekannte Größe: in Blöcken lesen
    size_t cap = QR_IMAGE_MAX_BYTES;
    buf = (uint8_t*)malloc(cap);
    if (!buf) { http.end(); return false; }
    const size_t chunk = 1024;
    while (got < cap) {
      size_t n = http.getStream().readBytes(buf + got, (got + chunk <= cap) ? chunk : (cap - got));
      if (n == 0) break;
      got += n;
    }
  }
  http.end();
  if (got == 0 || got > QR_IMAGE_MAX_BYTES) { if (buf) free(buf); return false; }
  if (s_qrImageBuf) free(s_qrImageBuf);
  s_qrImageBuf = buf;
  s_qrImageLen = got;
  return true;
}

// ——— QR aus URL-String erzeugen (Fallback wenn kein Bild von WordPress) ———
static bool drawQRFromUrlString(const String& url) {
  if (url.length() == 0 || url.length() > 200) return false;
  static uint8_t tempBuffer[qrcodegen_BUFFER_LEN_FOR_VERSION(10)];
  static uint8_t qrcodeBuffer[qrcodegen_BUFFER_LEN_FOR_VERSION(10)];
  bool ok = qrcodegen_encodeText(url.c_str(), tempBuffer, qrcodeBuffer,
    qrcodegen_Ecc_LOW, qrcodegen_VERSION_MIN, 10, qrcodegen_Mask_AUTO, true);
  if (!ok) return false;
  int size = qrcodegen_getSize(qrcodeBuffer);
  if (size <= 0 || size > 120) return false;
  int scale = QR_BOX_SIZE / size;
  if (scale < 1) scale = 1;
  int offsetX = QR_BOX_X + (QR_BOX_SIZE - size * scale) / 2;
  int offsetY = QR_BOX_Y + (QR_BOX_SIZE - size * scale) / 2;
  for (int y = 0; y < size; y++) {
    for (int x = 0; x < size; x++) {
      if (qrcodegen_getModule(qrcodeBuffer, x, y)) {
        display.fillRect(offsetX + x * scale, offsetY + y * scale, scale, scale, GxEPD_BLACK);
      }
    }
  }
  return true;
}

// ——— QR-Bereich: Bild aus Puffer, sonst QR aus URL erzeugen, sonst Platzhalter „QR“ ———
void drawQRInBox() {
  if (s_qrImageBuf && s_qrImageLen >= PNG_MAGIC_LEN) {
    if (memcmp(s_qrImageBuf, PNG_MAGIC, PNG_MAGIC_LEN) == 0) {
      if (drawPngFromBuffer(s_qrImageBuf, s_qrImageLen, QR_BOX_X, QR_BOX_Y, QR_BOX_SIZE, QR_BOX_SIZE))
        return;
    } else if (s_qrImageLen >= 54 && s_qrImageBuf[0] == 'B' && s_qrImageBuf[1] == 'M') {
      if (drawBmpFromBuffer(s_qrImageBuf, s_qrImageLen, QR_BOX_X, QR_BOX_Y, QR_BOX_SIZE, QR_BOX_SIZE))
        return;
    }
  }
  if (qrUrl.length() > 0 && drawQRFromUrlString(qrUrl))
    return;
#ifdef HAS_11PT
  display.setFont(&FONT_MED_11);
#else
  display.setFont(&FONT_MED_13);
#endif
  const char* qrTxt = "QR";
  int16_t x1, y1; uint16_t w, h;
  display.getTextBounds(qrTxt, 0, 0, &x1, &y1, &w, &h);
  display.setCursor(QR_BOX_X + (QR_BOX_SIZE - w) / 2, QR_BOX_Y + (QR_BOX_SIZE + h) / 2 - 2);
  display.print(qrTxt);
}

// ——— Display zeichnen: bei Fehler Split-Screen (2×2), sonst normale Raumanzeige ———
void drawScreen() {
  display.setRotation(0);
  display.fillScreen(GxEPD_WHITE);

  int w = display.width();
  int h = display.height();

  int batPct = readBatteryPercent();
  bool showErrorScreen = (!wifiOk || (batPct >= 0 && batPct < LOW_BATTERY_PERCENT) || !lastFetchOk);

  display.setFullWindow();
  display.firstPage();
  do {
    display.fillScreen(GxEPD_WHITE);
    int16_t x1, y1;
    uint16_t tw, th;

    if (showErrorScreen) {
      // ——— Fehler im 2×2-Raster: je Feld ein Fehler (WLAN + Akku oder Server + Akku möglich). Server nur bei WLAN ok. ———
      int cx0 = ERROR_QUAD_W / 2 - ERROR_ICON_MAX / 2;
      int cy0 = ERROR_QUAD_H / 2 - ERROR_ICON_MAX / 2;
      display.setTextColor(GxEPD_BLACK);
#ifdef HAS_9PT
      display.setFont(&FONT_MED_9);
#elif defined(HAS_11PT)
      display.setFont(&FONT_MED_11);
#else
      display.setFont(&FONT_MED_13);
#endif
      // Icons als BMP (einfach wie <img>: nur Header + Pixel, kein Dekompressor).
      bool wlanDrawn = false, akkuDrawn = false, serverDrawn = false;
      if (!wifiOk) {
        bool drawn = false;
#if defined(ICON_NO_WIFI_GFX_LEN) && (ICON_NO_WIFI_GFX_LEN > 0)
        display.drawBitmap(cx0, cy0, ICON_NO_WIFI_GFX, ICON_NO_WIFI_GFX_W, ICON_NO_WIFI_GFX_H, GxEPD_BLACK);
        drawn = true;
#endif
        if (!drawn) {
#ifdef ICON_NO_WIFI_BMP_LEN
          if (ICON_NO_WIFI_BMP_LEN > 0)
            drawn = drawBmpFromProgmem(ICON_NO_WIFI_BMP, ICON_NO_WIFI_BMP_LEN, cx0, cy0, ERROR_ICON_MAX, ERROR_ICON_MAX);
#endif
          if (!drawn) drawn = drawBmpFromSpiffs("/no_wifi.bmp", cx0, cy0, ERROR_ICON_MAX, ERROR_ICON_MAX);
        }
        wlanDrawn = drawn;
        if (!drawn) {
          display.getTextBounds(ERROR_TEXT_NO_WIFI, 0, 0, &x1, &y1, &tw, &th);
          display.setCursor(ERROR_QUAD_W / 2 - tw / 2, ERROR_QUAD_H / 2 - th / 2 - y1 / 2);
          display.print(ERROR_TEXT_NO_WIFI);
        }
      }
      if (batPct >= 0 && batPct < LOW_BATTERY_PERCENT) {
        int qx = ERROR_QUAD_W + cx0, qy = cy0;
        bool drawn = false;
#if defined(ICON_LOW_BATTERY_GFX_LEN) && (ICON_LOW_BATTERY_GFX_LEN > 0)
        display.drawBitmap(qx, qy, ICON_LOW_BATTERY_GFX, ICON_LOW_BATTERY_GFX_W, ICON_LOW_BATTERY_GFX_H, GxEPD_BLACK);
        drawn = true;
#endif
        if (!drawn) {
#ifdef ICON_LOW_BATTERY_BMP_LEN
          if (ICON_LOW_BATTERY_BMP_LEN > 0)
            drawn = drawBmpFromProgmem(ICON_LOW_BATTERY_BMP, ICON_LOW_BATTERY_BMP_LEN, qx, qy, ERROR_ICON_MAX, ERROR_ICON_MAX);
#endif
          if (!drawn) drawn = drawBmpFromSpiffs("/low_battery.bmp", qx, qy, ERROR_ICON_MAX, ERROR_ICON_MAX);
        }
        akkuDrawn = drawn;
        if (!drawn) {
          display.getTextBounds(ERROR_TEXT_LOW_BATTERY, 0, 0, &x1, &y1, &tw, &th);
          display.setCursor(ERROR_QUAD_W + ERROR_QUAD_W / 2 - tw / 2, ERROR_QUAD_H / 2 - th / 2 - y1 / 2);
          display.print(ERROR_TEXT_LOW_BATTERY);
        }
      }
      if (wifiOk && !lastFetchOk) {
        int qx = cx0, qy = ERROR_QUAD_H + cy0;
        bool drawn = false;
#if defined(ICON_NO_CONNECTION_GFX_LEN) && (ICON_NO_CONNECTION_GFX_LEN > 0)
        display.drawBitmap(qx, qy, ICON_NO_CONNECTION_GFX, ICON_NO_CONNECTION_GFX_W, ICON_NO_CONNECTION_GFX_H, GxEPD_BLACK);
        drawn = true;
#endif
        if (!drawn) {
#ifdef ICON_NO_CONNECTION_BMP_LEN
          if (ICON_NO_CONNECTION_BMP_LEN > 0)
            drawn = drawBmpFromProgmem(ICON_NO_CONNECTION_BMP, ICON_NO_CONNECTION_BMP_LEN, qx, qy, ERROR_ICON_MAX, ERROR_ICON_MAX);
#endif
          if (!drawn) drawn = drawBmpFromSpiffs("/no_connection.bmp", qx, qy, ERROR_ICON_MAX, ERROR_ICON_MAX);
        }
        serverDrawn = drawn;
        if (!drawn) {
          display.getTextBounds(ERROR_TEXT_NO_CONNECTION, 0, 0, &x1, &y1, &tw, &th);
          display.setCursor(ERROR_QUAD_W / 2 - tw / 2, ERROR_QUAD_H + ERROR_QUAD_H / 2 - th / 2 - y1 / 2);
          display.print(ERROR_TEXT_NO_CONNECTION);
        }
      }
      // Unten rechts: leer, oder Debug-Anzeige wenn aktiviert (ohne W/A/S-Zeile)
      if (showDebugDisplay) {
        display.getTextBounds("0", 0, 0, &x1, &y1, &tw, &th);
        int lineH = th + 2;
        int debugY = ERROR_QUAD_H + 8;  // Start im unteren Bereich (rechte Hälfte)
        int debugRight = DISP_W - 10;   // Rechter Rand für Debug-Text

        String ipStr = WiFi.isConnected() ? WiFi.localIP().toString() : String("--");
        String line1 = String("v") + String(FIRMWARE_VERSION);
        display.getTextBounds(line1.c_str(), 0, 0, &x1, &y1, &tw, &th);
        display.setCursor(debugRight - tw, debugY);
        display.print(line1);
        if (apiContentHash.length() > 0) {
          display.getTextBounds(apiContentHash.c_str(), 0, 0, &x1, &y1, &tw, &th);
          display.setCursor(debugRight - tw, debugY + lineH);
          display.print(apiContentHash.c_str());
        }
        display.getTextBounds(ipStr.c_str(), 0, 0, &x1, &y1, &tw, &th);
        display.setCursor(debugRight - tw, debugY + 2 * lineH);
        display.print(ipStr);
        String lineRoom = displayRoomName.length() > 0 ? displayRoomName + String(" (") + String(runtimeDeviceId) + String(")") : String("(") + String(runtimeDeviceId) + String(")");
        display.getTextBounds(lineRoom.c_str(), 0, 0, &x1, &y1, &tw, &th);
        display.setCursor(debugRight - tw, debugY + 3 * lineH);
        display.print(lineRoom);
        String lineBat = String("Akku:");
        if (batPct >= 0) lineBat += String(" ") + String(batPct) + String("%");
        else lineBat += String(" --");
        display.getTextBounds(lineBat.c_str(), 0, 0, &x1, &y1, &tw, &th);
        display.setCursor(debugRight - tw, debugY + 4 * lineH);
        display.print(lineBat);
        if (lastBmpFailReason.length() > 0) {
          String r = lastBmpFailReason.length() > 22 ? lastBmpFailReason.substring(0, 22) : lastBmpFailReason;
          display.getTextBounds(r.c_str(), 0, 0, &x1, &y1, &tw, &th);
          display.setCursor(debugRight - tw, debugY + 5 * lineH);
          display.print(r);
        }
      }
    } else {
    // ——— Hauptstatus + BIS/Zeitraum: 26pt, Schwarz (NICHT BESETZT / BESETZT) ———
    display.setTextColor(GxEPD_BLACK);
    display.setFont(&FONT_STATUS);
    String statusStr = displayStatusLabel.length() > 0 ? displayStatusLabel : "NICHT BESETZT";
    statusStr.toUpperCase();
    display.getTextBounds(statusStr.c_str(), 0, 0, &x1, &y1, &tw, &th);
    display.setCursor(STATUS_LEFT + (STATUS_WIDTH - tw) / 2, STATUS_TOP - y1);
    display.print(statusStr.c_str());

    display.setFont(&FONT_STATUS);
    const char* untilStrP = displayStatusUntil.length() > 0 ? displayStatusUntil.c_str() : "BIS 23:59";
    display.getTextBounds(untilStrP, 0, 0, &x1, &y1, &tw, &th);
    display.setCursor(STATUS_LEFT + (STATUS_WIDTH - tw) / 2, STATUS_UNTIL_TOP - y1);
    display.print(untilStrP);

    // ——— Rest: Events, Datum, BUCHEN, QR ———
    display.setTextColor(GxEPD_BLACK);

    // ——— Nächste 3 Termine: Uhrzeit Bold 12pt, Titel Regular 12pt ———
    const int eventYTime = h - EVENT_BOTTOM;
    const int eventYName = eventYTime - 14 - 14 - 5;   // Uhrzeit 5px nach oben
    const int eventXs[3] = { EVENT_X_1, EVENT_X_2, EVENT_X_3 };
    
    // Finde das erste Event vom nächsten Tag für Strich und Icon
    int firstNextDayIdx = -1;
    for (int i = 0; i < numDisplayEvents && i < 3; i++) {
      if (displayEventIsNextDay[i]) {
        firstNextDayIdx = i;
        break;
      }
    }
    
    // Zeichne Events
    for (int i = 0; i < numDisplayEvents && i < 3; i++) {
      int colCenter = eventXs[i] + EVENT_COL_WIDTH / 2;
      String timeStr = displayEventTime[i];
      timeStr.replace("\xe2\x80\x93", "-");  // UTF-8 En-Dash -> ASCII Bindestrich (Font nur 7-bit)
      
      // Text normal zeichnen (wie die anderen Events)
      display.setFont(&FONT_BOLD_13);   // Uhrzeit: Bold
      display.getTextBounds(timeStr.c_str(), 0, 0, &x1, &y1, &tw, &th);
      int timeX = colCenter - tw / 2;
      int timeY = eventYName;
      display.setCursor(timeX, timeY);
      display.print(timeStr.c_str());
      
      String title = displayEventSummary[i].substring(0, 12);  // API liefert max 12 Zeichen (9+...)
      title.toUpperCase();   // Event-Titel: Regular
      display.setFont(&FONT_MED_13);
      display.getTextBounds(title.c_str(), 0, 0, &x1, &y1, &tw, &th);
      int titleX = colCenter - tw / 2;
      int titleY = eventYTime;
      display.setCursor(titleX, titleY);
      display.print(title.c_str());
    }
    
    // Zeichne vertikalen Strich (2px breit) und Icon zwischen letztem heutigen und erstem morgigen Event
    if (firstNextDayIdx >= 0) {
      int iconSize = 30;
      int iconX, iconY;
      int lineX, lineTop, lineBottom;
      
      if (firstNextDayIdx > 0) {
        // Strich zwischen Event firstNextDayIdx-1 und firstNextDayIdx
        int leftEventRight = eventXs[firstNextDayIdx - 1] + EVENT_COL_WIDTH;
        int rightEventLeft = eventXs[firstNextDayIdx];
        lineX = (leftEventRight + rightEventLeft) / 2 - 1;  // Mitte zwischen Events, 2px breit
        lineTop = eventYName - 20;  // Etwas über der Uhrzeit
        lineBottom = eventYTime + 14;  // Etwas unter dem Titel
        display.fillRect(lineX, lineTop, 2, lineBottom - lineTop, GxEPD_BLACK);
        // Icon zentriert über dem Strich, 5px Abstand vom Strich
        iconX = lineX - iconSize / 2 + 1;
        iconY = lineTop - iconSize - 5;  // Icon 5px über dem Strich
      } else {
        // Erstes Event ist vom nächsten Tag: Strich und Icon weit links am Rand
        lineX = 10;  // 10px vom linken Rand
        lineTop = eventYName - 20;  // Gleiche Höhe wie bei Termin 2/3
        lineBottom = eventYTime + 14;  // Gleiche Höhe wie bei Termin 2/3
        display.fillRect(lineX, lineTop, 2, lineBottom - lineTop, GxEPD_BLACK);
        // Icon weit links, zentriert über dem Strich, 5px Abstand vom Strich
        iconX = lineX - iconSize / 2 + 1;
        iconY = lineTop - iconSize - 5;  // Icon 5px über dem Strich
      }
      
      // Sicherstellen, dass Icon nicht außerhalb des Bildschirms ist
      if (iconY < 0) iconY = 5;  // Mindestens 5px vom oberen Rand
      if (iconX < 0) iconX = 5;
      if (iconX + iconSize > w) iconX = w - iconSize - 5;
      
      bool iconDrawn = false;
      // Versuche zuerst GFX-Format (eingebettet) - bevorzugt für kleine Icons
#if defined(ICON_NEXT_DAY_GFX_LEN) && (ICON_NEXT_DAY_GFX_LEN > 0) && defined(ICON_NEXT_DAY_GFX_W) && defined(ICON_NEXT_DAY_GFX_H)
      if (ICON_NEXT_DAY_GFX_W > 0 && ICON_NEXT_DAY_GFX_H > 0) {
        display.drawBitmap(iconX, iconY, ICON_NEXT_DAY_GFX, ICON_NEXT_DAY_GFX_W, ICON_NEXT_DAY_GFX_H, GxEPD_BLACK);
        iconDrawn = true;
      }
#endif
      // Dann BMP aus PROGMEM
      if (!iconDrawn) {
#ifdef ICON_NEXT_DAY_BMP_LEN
        if (ICON_NEXT_DAY_BMP_LEN > 0)
          iconDrawn = drawBmpFromProgmem(ICON_NEXT_DAY_BMP, ICON_NEXT_DAY_BMP_LEN, iconX, iconY, 50, 50);
#endif
      }
      // Fallback: SPIFFS
      if (!iconDrawn) {
        iconDrawn = drawBmpFromSpiffs("/next_day.bmp", iconX, iconY, 50, 50);
      }
#if BMP_DEBUG
      if (!iconDrawn) {
        Serial1.printf("[next_day] Icon nicht gezeichnet. X=%d Y=%d, GFX_LEN=%d, BMP_LEN=%d\n", 
          iconX, iconY,
#if defined(ICON_NEXT_DAY_GFX_LEN)
          ICON_NEXT_DAY_GFX_LEN,
#else
          0,
#endif
#ifdef ICON_NEXT_DAY_BMP_LEN
          ICON_NEXT_DAY_BMP_LEN
#else
          0
#endif
        );
        if (lastBmpFailReason.length() > 0) {
          Serial1.printf("[next_day] Fehler: %s\n", lastBmpFailReason.c_str());
        }
      } else {
        Serial1.printf("[next_day] Icon gezeichnet bei X=%d Y=%d\n", iconX, iconY);
      }
#endif
      if (!iconDrawn) {
        // Fallback: kleines Icon-Symbol zeichnen falls BMP nicht gefunden
        display.fillRect(iconX + iconSize/2 - 5, iconY + iconSize/2 - 5, 10, 10, GxEPD_BLACK);
      }
    }

    // ——— Oben rechts: Zeitstempel (von API, nicht im content_hash → löst kein Display-Update aus), darunter „Update alle X Min.“ ———
#ifdef HAS_9PT
    display.setFont(&FONT_MED_9);
#elif defined(HAS_11PT)
    display.setFont(&FONT_MED_11);
#else
    display.setFont(&FONT_MED_13);
#endif
    unsigned long intervalMin = displayRefreshSeconds / 60;
    if (intervalMin < 1) intervalMin = 1;
    String updateLabelStr = String("Update alle ") + String((unsigned long)intervalMin) + String(" Min.");
    display.getTextBounds(updateLabelStr.c_str(), 0, 0, &x1, &y1, &tw, &th);
    int lineH = th + 2;
    int updateBlockBottom;  // Unterkante Zeitstempel + Update-Zeile für Debug-Start

    if (displayTime.length() > 0) {
      // Zeitstempel über „Update alle X Min.“ (wie im Template)
      int16_t x1t, y1t;
      uint16_t twt, tht;
      display.getTextBounds(displayTime.c_str(), 0, 0, &x1t, &y1t, &twt, &tht);
      display.setCursor(RIGHT_EDGE - twt, UPDATE_TOP - lineH - y1t);
      display.print(displayTime.c_str());
      display.setCursor(RIGHT_EDGE - tw, UPDATE_TOP - y1);
      display.print(updateLabelStr);
    } else {
      display.setCursor(RIGHT_EDGE - tw, UPDATE_TOP - y1);
      display.print(updateLabelStr);
    }
    // Unterkante Update-Block → Baseline erste Debug-Zeile
    updateBlockBottom = UPDATE_TOP + th + 6 - y1;

    // ——— Debug: Version, Hash, IP, Name (ID), Akku ———
    if (showDebugDisplay) {
      String ipStr = WiFi.isConnected() ? WiFi.localIP().toString() : String("--");
      int debugY = updateBlockBottom;

      // Zeile 0: Version
      String line1 = String("v") + String(FIRMWARE_VERSION);
      display.getTextBounds(line1.c_str(), 0, 0, &x1, &y1, &tw, &th);
      display.setCursor(RIGHT_EDGE - tw, debugY);
      display.print(line1);
      // Zeile 1: Hash (kurz)
      if (apiContentHash.length() > 0) {
        display.getTextBounds(apiContentHash.c_str(), 0, 0, &x1, &y1, &tw, &th);
        display.setCursor(RIGHT_EDGE - tw, debugY + lineH);
        display.print(apiContentHash.c_str());
      }
      // Zeile 2: IP
      display.getTextBounds(ipStr.c_str(), 0, 0, &x1, &y1, &tw, &th);
      display.setCursor(RIGHT_EDGE - tw, debugY + 2 * lineH);
      display.print(ipStr);
      // Zeile 3: Raum (ID)
      String lineRoom = displayRoomName.length() > 0 ? displayRoomName + String(" (") + String(runtimeDeviceId) + String(")") : String("(") + String(runtimeDeviceId) + String(")");
      display.getTextBounds(lineRoom.c_str(), 0, 0, &x1, &y1, &tw, &th);
      display.setCursor(RIGHT_EDGE - tw, debugY + 3 * lineH);
      display.print(lineRoom);
      // Zeile 4: Akku
      String lineBat = String("Akku:");
      if (batPct >= 0) lineBat += String(" ") + String(batPct) + String("%");
      else lineBat += String(" --");
      display.getTextBounds(lineBat.c_str(), 0, 0, &x1, &y1, &tw, &th);
      display.setCursor(RIGHT_EDGE - tw, debugY + 4 * lineH);
      display.print(lineBat);
      if (lastBmpFailReason.length() > 0) {
        String r = lastBmpFailReason.length() > 22 ? lastBmpFailReason.substring(0, 22) : lastBmpFailReason;
        display.getTextBounds(r.c_str(), 0, 0, &x1, &y1, &tw, &th);
        display.setCursor(RIGHT_EDGE - tw, debugY + 5 * lineH);
        display.print(r);
      }
    }

    // ——— BUCHEN (gleiche Schriftgröße wie oben rechts), mittig über QR-Box ———
#ifdef HAS_9PT
    display.setFont(&FONT_MED_9);
#elif defined(HAS_11PT)
    display.setFont(&FONT_MED_11);
#else
    display.setFont(&FONT_MED_13);
#endif
    display.getTextBounds("BUCHEN", 0, 0, &x1, &y1, &tw, &th);
    display.setCursor(QR_BOX_X + (QR_BOX_SIZE - tw) / 2, QR_BOX_Y - th + 6);
    display.print("BUCHEN");
    drawQRInBox();   // ohne Rahmen

    }  // end else (normale Raumanzeige)
  } while (display.nextPage());
}

// ——— Akku-Prozent auslesen (reTerminal E Series: GPIO1 + GPIO21, Spannungsteiler 2:1) ———
static int readBatteryPercent() {
  pinMode(BATTERY_ENABLE_PIN, OUTPUT);
  digitalWrite(BATTERY_ENABLE_PIN, HIGH);
  delay(5);
  int mv = analogReadMilliVolts(BATTERY_ADC_PIN);
  digitalWrite(BATTERY_ENABLE_PIN, LOW);
  if (mv <= 0 || mv > 2500) return -1;  // ungültig oder außerhalb LiPo-Bereich
  float voltage = (mv / 1000.0f) * 2.0f;  // Spannungsteiler 2:1 → Batterie = 2× gemessen
  if (voltage < 3.0f || voltage > 4.3f) return -1;
  // LiPo typisch 3.3 V (leer) … 4.2 V (voll) → Prozent linear
  float pct = (voltage - 3.3f) / (4.2f - 3.3f) * 100.0f;
  if (pct < 0) pct = 0;
  if (pct > 100) pct = 100;
  return (int)(pct + 0.5f);
}

// ——— Bootloader per Serial: "BOOTLOADER" auf Serial1 (USB) → GPIO0 low + Neustart ———
// Hinweis: Ob der ROM-Bootloader GPIO0 beim Software-Reset neu liest, ist boardabhängig.
// Falls es nicht klappt: BOOT-Button halten und ./flash.sh erneut ausführen.
static void enterBootloaderMode() {
  pinMode(BOOTLOADER_GPIO, OUTPUT);
  digitalWrite(BOOTLOADER_GPIO, LOW);
  delay(50);
  esp_restart();
}

static void checkSerialBootloaderRequest() {
  static char buf[32];
  static int len = 0;
  while (Serial1.available() && len < (int)sizeof(buf) - 1) {
    char c = (char)Serial1.read();
    buf[len++] = c;
    if (c == '\n' || c == '\r') {
      buf[len] = '\0';
      if (strstr(buf, BOOTLOADER_MAGIC)) {
        Serial1.println("-> Bootloader in 1s ...");
        enterBootloaderMode();
      }
      len = 0;
      return;
    }
  }
  if (len >= (int)sizeof(buf) - 1) len = 0;
}

// Update: Display-API holen; nur bei geändertem content_hash Display neu zeichnen (Akku sparen).
// forceRefresh=true: grüner Button → immer neu zeichnen; bei keinem WLAN: Reconnect versuchen, danach immer draw.
void doUpdate(bool forceRefresh) {
  if (forceRefresh) lastContentHash = "";  // nächster Vergleich ergibt „geändert“ → immer draw
  display.init(0);
  wifiOk = (WiFi.status() == WL_CONNECTED);

  if (!wifiOk && forceRefresh) {
    // Manueller Reload ohne WLAN: Reconnect versuchen, danach in jedem Fall Display aktualisieren
    WiFi.disconnect();
    delay(200);
    WiFi.begin(runtimeWifiSsid.c_str(), runtimeWifiPass.c_str());
    for (int i = 0; i < 20; i++) {
      if (WiFi.status() == WL_CONNECTED) break;
      delay(500);
    }
    wifiOk = (WiFi.status() == WL_CONNECTED);
  }

  if (wifiOk) {
    lastFetchOk = fetchDisplay();
    bool contentChanged = (apiContentHash.length() > 0 && apiContentHash != lastContentHash);
    if (contentChanged) {
      lastContentHash = apiContentHash;
      if (qrUrl.length() > 0) {
        if (qrUrl[0] == '/' && runtimeWpUrl.length() > 8) {
          int pathStart = runtimeWpUrl.indexOf('/', 8);
          String base = (pathStart > 0) ? runtimeWpUrl.substring(0, pathStart) : runtimeWpUrl;
          qrUrl = base + qrUrl;
        }
        fetchQRImageToBuffer();
      }
    }
    // Zeichnen: bei Inhalt geändert, bei Knopfdruck, oder bei Server-Fehler (Fehler-Screen)
    if (contentChanged || forceRefresh || !lastFetchOk) {
      drawScreen();
    }
  } else {
    lastFetchOk = false;
    lastContentHash = "";  // bei keinem WLAN Hash zurücksetzen, nächster Erfolg zeichnet wieder
    // Immer Fehler-Screen zeichnen (auch ohne Knopfdruck), damit er automatisch erscheint
    drawScreen();
  }
  display.hibernate();
  // LED nur im Debug-Modus an (Logik invertiert: LOW = an)
  pinMode(LED_PIN, OUTPUT);
  digitalWrite(LED_PIN, showDebugDisplay ? LOW : HIGH);
  // WLAN von API geändert → neu verbinden für nächsten Zyklus
  if (wifiConfigChanged) {
    wifiConfigChanged = false;
    WiFi.disconnect();
    delay(200);
    WiFi.begin(runtimeWifiSsid.c_str(), runtimeWifiPass.c_str());
  }
  // Akku niedrig → Deep Sleep bis Akku wieder über Schwellwert (spart Energie)
  int batPct = readBatteryPercent();
  if (batPct >= 0 && batPct < LOW_BATTERY_PERCENT) {
    Serial1.printf("[Akku] %d%% < %d%% → Deep Sleep 1h, dann erneut prüfen\n", batPct, LOW_BATTERY_PERCENT);
    delay(1000);  // Display-Update abschließen
    esp_sleep_enable_timer_wakeup(3600ULL * 1000000ULL);  // 1 Stunde
    esp_sleep_enable_ext0_wakeup((gpio_num_t)BUTTON_KEY0, 0);  // grüner Button (LOW) weckt sofort
    esp_deep_sleep_start();
  }
}

void setup() {
  // LED sofort aus (vor allem anderen), nur bei Debug-Anzeige später an (Logik invertiert: HIGH = aus)
  pinMode(LED_PIN, OUTPUT);
  digitalWrite(LED_PIN, HIGH);

  Serial1.begin(115200, SERIAL_8N1, SERIAL_RX, SERIAL_TX);
  pinMode(BUTTON_KEY0, INPUT_PULLUP);
  pinMode(BUTTON_KEY1, INPUT_PULLUP);
  pinMode(BUTTON_KEY2, INPUT_PULLUP);

  // Akku-Messung (reTerminal E Series): ADC 12 Bit, 11 dB Dämpfung
  analogReadResolution(12);
  analogSetPinAttenuation(BATTERY_ADC_PIN, ADC_11db);

  hspi.begin(EPD_SCK_PIN, -1, EPD_MOSI_PIN, -1);
  display.epd2.selectSPI(hspi, SPISettings(2000000, MSBFIRST, SPI_MODE0));
  display.init(0);

  SPIFFS.begin(true);

  loadFlashConfig();
  if (flashConfigValid) {
    Serial1.println("[Config] WLAN aus Flash-Partition gelesen.");
  } else {
    Serial1.println("[Config] Keine Config in Flash, nutze config.h");
  }

  WiFi.mode(WIFI_STA);
  WiFi.begin(runtimeWifiSsid.c_str(), runtimeWifiPass.c_str());
  int w = 0;
  while (WiFi.status() != WL_CONNECTED && w < 30) {
    delay(500);
    w++;
  }

  // Nach Aufwachen aus Deep Sleep: grüner Button → sofort Refresh, sonst normales Update
  if (esp_sleep_get_wakeup_cause() == ESP_SLEEP_WAKEUP_EXT0) {
    doUpdate(true);   // Aufweck-Button = manueller Refresh
  } else {
    doUpdate(false);
  }
}

static bool isRefreshButtonPressed() {
  return (digitalRead(BUTTON_KEY0) == LOW || digitalRead(BUTTON_KEY1) == LOW || digitalRead(BUTTON_KEY2) == LOW);
}

void loop() {
  checkSerialBootloaderRequest();

  if (isRefreshButtonPressed()) {
    delay(150);
    while (isRefreshButtonPressed()) delay(50);
    doUpdate(true);   // grüner Button: immer Display neu zeichnen
  }

  // Bei WLAN- oder Server-Fehler jede Minute erneut prüfen, sonst Intervall aus API (refresh_seconds)
  unsigned long delaySec = (!wifiOk || !lastFetchOk) ? (unsigned long)REFRESH_SECONDS_RETRY_ERROR : displayRefreshSeconds;
  if (secondsUntilNextEvent > 0 && secondsUntilNextEvent < delaySec) {
    delaySec = secondsUntilNextEvent;
  }
  for (unsigned long s = 0; s < delaySec; s++) {
    delay(1000);
    checkSerialBootloaderRequest();
    if (isRefreshButtonPressed()) {
      delay(150);
      while (isRefreshButtonPressed()) delay(50);
      doUpdate(true);   // grüner Button: immer Display neu zeichnen
      s = 0;
      delaySec = (!wifiOk || !lastFetchOk) ? (unsigned long)REFRESH_SECONDS_RETRY_ERROR : displayRefreshSeconds;
      if (secondsUntilNextEvent > 0 && secondsUntilNextEvent < delaySec) delaySec = secondsUntilNextEvent;
    }
  }
  doUpdate(false);   // Intervall-Update: nur zeichnen wenn Hash geändert
}
