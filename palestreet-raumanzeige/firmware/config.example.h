// Umbenennen zu config.h und Werte anpassen (nur Fallback – WordPress flasht WLAN/URL/device_id)
#define WIFI_SSID       "DeinWLAN"
#define WIFI_PASSWORD   "DeinPasswort"
#define WORDPRESS_URL   "https://deine-seite.de"   // Ohne abschließenden Slash
#define DEVICE_ID       0                           // Index der Ressource (0 = erste Zeile im WordPress)
#define REFRESH_SECONDS_FALLBACK 300                 // Fallback-Intervall (Sek.), wenn API kein refresh_seconds liefert

// Fallback-Texte, wenn Fehler-Icons (PNG) nicht angezeigt werden können – kurz halten (z. B. ≤ 8 Zeichen)
// #define ERROR_TEXT_NO_WIFI        "WLAN?"
// #define ERROR_TEXT_LOW_BATTERY    "Akku!"
// #define ERROR_TEXT_NO_CONNECTION  "Server?"
