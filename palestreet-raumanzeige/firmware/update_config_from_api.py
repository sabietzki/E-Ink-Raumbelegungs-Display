#!/usr/bin/env python3
"""Liest JSON von stdin (API-Antwort), gibt Config-Felder aus und schreibt WLAN in config.h."""
import sys
import json
import re
import os

def main():
    try:
        d = json.load(sys.stdin)
    except Exception as e:
        print("  (JSON fehlerhaft:", e, ")")
        print(sys.stdin.read()[:500])
        return 1
    for k in ['wifi_ssid', 'wifi_pass', 'refresh_seconds', 'room_name', 'update_interval_label', 'content_hash', 'debug_display']:
        v = d.get(k, '')
        if k == 'wifi_pass' and v:
            v = '********'
        print(f"  {k}: {v}")
    ssid = d.get('wifi_ssid', '').strip().replace('\n', '').replace('\r', '')
    pw = (d.get('wifi_pass') or '').replace('\n', '').replace('\r', '')
    if not ssid or not os.path.isfile('config.h'):
        return 0
    with open('config.h', 'r', encoding='utf-8', errors='replace') as f:
        content = f.read()

    def escape_c(s):
        return (s or '').replace('\\', '\\\\').replace('"', '\\"')

    ssid_esc = escape_c(ssid)
    pw_esc = escape_c(pw)

    def repl_ssid(m):
        return '#define WIFI_SSID       "' + ssid_esc + '"'

    def repl_pw(m):
        return '#define WIFI_PASSWORD   "' + pw_esc + '"'

    content = re.sub(r'^#define\s+WIFI_SSID\s+.*', repl_ssid, content, count=1, flags=re.MULTILINE)
    content = re.sub(r'^#define\s+WIFI_PASSWORD\s+.*', repl_pw, content, count=1, flags=re.MULTILINE)
    with open('config.h', 'w', encoding='utf-8', newline='\n') as f:
        f.write(content)
    print("  → config.h mit WLAN aus API aktualisiert (Gerät kann beim Start verbinden).")
    return 0

if __name__ == '__main__':
    sys.exit(main())
