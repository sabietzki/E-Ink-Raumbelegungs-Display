<?php
/**
 * Plugin Name: E-Ink Raumbelegungsplan
 * Plugin URI: https://github.com/sabietzki/
 * Description: Backend für E-Paper-Raumbelegungsplan-Schilder (z. B. reTerminal E1001). ICS-Kalender-Anbindung, REST API für Displays, pro Schild: Zeitzone, Update-Intervall, Nachtmodus, WLAN. Display aktualisiert nur bei geändertem Inhalt (Hash-Vergleich).
 * Version: 1.0.1
 * Author: Lars Sabietzki
 * Author URI: https://sabietzki.de
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: palestreet-raumanzeige
 */

if (!defined('ABSPATH')) {
    exit;
}

define('PALESTREET_RAUMANZEIGE_VERSION', '1.0.1');

require_once plugin_dir_path(__FILE__) . 'includes/class-ics-parser.php';

/**
 * Ressourcen (Räume) aus Optionen laden. Jede Ressource hat eine stabile ID (device_id).
 * Alte Einträge ohne 'id' erhalten beim Laden die Index-ID (Migration).
 */
function palestreet_raumanzeige_get_resources() {
    $resources = get_option('palestreet_raumanzeige_resources', []);
    if (!is_array($resources)) {
        return [];
    }
    foreach ($resources as $idx => $r) {
        if (!isset($r['id']) || $r['id'] === '') {
            $resources[$idx]['id'] = $idx;
        }
    }
    return $resources;
}

/**
 * Nächste freie device_id (max vorhandene ID + 1)
 */
function palestreet_raumanzeige_next_id($resources) {
    $max = -1;
    foreach ($resources as $r) {
        $id = isset($r['id']) ? (int) $r['id'] : -1;
        if ($id > $max) {
            $max = $id;
        }
    }
    return $max + 1;
}

/**
 * Ressourcen speichern – alle Räume in einer einzigen Option (ein DB-Eintrag).
 */
function palestreet_raumanzeige_save_resources($resources) {
    update_option('palestreet_raumanzeige_resources', $resources, false);
}

/**
 * Firmware-Version aus firmware.ino auslesen (#define FIRMWARE_VERSION "x.y.z").
 *
 * @return string Version (z. B. "1.0.0") oder leer bei Fehler
 */
function palestreet_raumanzeige_get_firmware_version() {
    $path = plugin_dir_path(__FILE__) . 'firmware/firmware.ino';
    if (!is_readable($path)) {
        return '';
    }
    $content = file_get_contents($path);
    if ($content === false) {
        return '';
    }
    if (preg_match('/#define\s+FIRMWARE_VERSION\s+"([^"]+)"/', $content, $m)) {
        return $m[1];
    }
    return '';
}

/**
 * Label für E-Paper auf max. 12 Zeichen kürzen: wenn länger, 9 Zeichen + "...".
 * Nutzt mb_* für UTF-8 (Umlaute, etc.).
 *
 * @param string $text  Text
 * @param int    $max   Max. Zeichen (Standard 12)
 * @return string
 */
function palestreet_raumanzeige_truncate_label($text, $max = 12) {
    $text = (string) $text;
    if ($max <= 0) {
        return $text;
    }
    $keep = max(1, $max - 3);  // Platz für "..."
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        $len = mb_strlen($text);
        if ($len <= $max) {
            return $text;
        }
        return mb_substr($text, 0, $keep) . '...';
    }
    $len = strlen($text);
    if ($len <= $max) {
        return $text;
    }
    return substr($text, 0, $keep) . '...';
}

/**
 * Prüft, ob die aktuelle Zeit (in $tz) im Nachtmodus-Fenster liegt (von/bis als "HH:MM").
 * Über Mitternacht: z. B. von "22:00" bis "06:00" = 22:00–23:59 und 00:00–05:59.
 *
 * @param DateTimeZone $tz
 * @param string       $from "HH:MM" oder "H:MM"
 * @param string       $to   "HH:MM" oder "H:MM"
 * @return bool
 */
function palestreet_raumanzeige_is_night_mode($tz, $from, $to) {
    $from = trim((string) $from);
    $to   = trim((string) $to);
    if ($from === '' || $to === '') {
        return false;
    }
    $now = new DateTime('now', $tz);
    $current_min = (int) $now->format('G') * 60 + (int) $now->format('i');
    $from_parts = array_map('intval', explode(':', $from));
    $to_parts   = array_map('intval', explode(':', $to));
    $from_min = isset($from_parts[0]) ? $from_parts[0] * 60 + (isset($from_parts[1]) ? $from_parts[1] : 0) : 0;
    $to_min   = isset($to_parts[0]) ? $to_parts[0] * 60 + (isset($to_parts[1]) ? $to_parts[1] : 0) : 0;
    $from_min = max(0, min(24 * 60, $from_min));
    $to_min   = max(0, min(24 * 60, $to_min));
    if ($from_min > $to_min) {
        return $current_min >= $from_min || $current_min < $to_min;
    }
    return $current_min >= $from_min && $current_min < $to_min;
}

/**
 * Build-Paket als ZIP ausliefern (Firmware, build-firmware.sh, flash.sh).
 */
function palestreet_raumanzeige_send_build_package_zip() {
    if (!class_exists('ZipArchive')) {
        wp_die(__('ZipArchive fehlt auf dem Server.', 'palestreet-raumanzeige'), '', ['response' => 500]);
    }
    $plugin_dir = plugin_dir_path(__FILE__);
    $fw_version = palestreet_raumanzeige_get_firmware_version();
    $zip_name   = $fw_version !== '' ? 'palestreet-raumanzeige-build-' . $fw_version . '.zip' : 'palestreet-raumanzeige-build.zip';
    $prefix     = 'palestreet-raumanzeige/';

    $zip = new ZipArchive();
    $tmp = wp_tempnam($zip_name);
    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        @unlink($tmp);
        wp_die(__('ZIP konnte nicht erstellt werden.', 'palestreet-raumanzeige'), '', ['response' => 500]);
    }

    $exclude = ['config.h', '.DS_Store', 'build_firmware', '.venv', '__pycache__'];

    // firmware/
    $firmware_dir   = $plugin_dir . 'firmware/';
    $wordpress_url  = rtrim(home_url(), '/');  // Aktuelle URL für config.example.h im ZIP
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($firmware_dir, RecursiveDirectoryIterator::SKIP_DOTS));
    foreach ($it as $fi) {
        if (!$fi->isFile()) {
            continue;
        }
        $path = $fi->getPathname();
        $rel  = substr($path, strlen($firmware_dir));
        if ($rel === 'config.h') {
            continue;
        }
        foreach ($exclude as $e) {
            if (strpos($rel, $e) !== false) {
                continue 2;
            }
        }
        $zip_path = $prefix . 'firmware/' . str_replace('\\', '/', $rel);
        if ($rel === 'config.example.h') {
            $content = file_get_contents($path);
            if ($content !== false && $wordpress_url !== '' && strpos($content, 'https://deine-seite.de') !== false) {
                $content = str_replace('https://deine-seite.de', $wordpress_url, $content);
            }
            if ($content !== false) {
                $zip->addFromString($zip_path, $content);
            } else {
                $zip->addFile($path, $zip_path);
            }
        } else {
            $zip->addFile($path, $zip_path);
        }
    }

    // build-firmware.sh, flash.sh
    if (is_file($plugin_dir . 'build-firmware.sh')) {
        $zip->addFile($plugin_dir . 'build-firmware.sh', $prefix . 'build-firmware.sh');
    }
    if (is_file($plugin_dir . 'flash.sh')) {
        $zip->addFile($plugin_dir . 'flash.sh', $prefix . 'flash.sh');
    }

    // README im ZIP (neutral für GitHub/Weitergabe)
    $readme = "E-Ink Raumbelegungsplan – Build & Flashen\n"
        . "============================================\n\n"
        . ($fw_version !== '' ? "Firmware-Version im Paket: " . $fw_version . "\n\n" : "")
        . "Die config.example.h im Paket enthält bereits die aktuelle WordPress-URL dieser Installation.\n\n"
        . "1. config.example.h zu config.h kopieren; nur DEVICE_ID und ggf. WORDPRESS_URL eintragen (WLAN/Passwort/Update-Intervall kommen von der API).\n"
        . "2. Schriften: Inter_ExtraBold.ttf und Inter_SemiBold.ttf in firmware/data/ legen.\n"
        . "3. Im entpackten Ordner (palestreet-raumanzeige/) ausführen:\n\n"
        . "   ./build-firmware.sh\n\n"
        . "   → Geräte-ID setzen (wird abgefragt)\n"
        . "   → Nach dem Build: automatischer Flash (oder mit N überspringen, dann später: ./flash.sh)\n"
        . "   → Bei mehreren USB-Ports: Gerät per Nummer auswählen\n\n"
        . "Neu flashen nur nötig bei Änderung von WordPress-URL oder neuer Firmware-Version; außerdem bei falschen WLAN-Zugangsdaten.\n";
    $zip->addFromString($prefix . 'README-BUILD.txt', $readme);

    $zip->close();

    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zip_name . '"');
    header('Content-Length: ' . filesize($tmp));
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');
    readfile($tmp);
    @unlink($tmp);
    exit;
}

// Admin-Auslieferungen (z. B. Build-Paket-Download)
add_action('admin_init', function () {
    if (!isset($_GET['page']) || $_GET['page'] !== 'palestreet-raumanzeige' || !isset($_GET['action']) || $_GET['action'] !== 'download_build_package') {
        return;
    }
    if (!current_user_can('manage_options')) {
        return;
    }
    $nonce = isset($_GET['_wpnonce']) ? $_GET['_wpnonce'] : '';
    if (!wp_verify_nonce($nonce, 'palestreet_raumanzeige_download_build')) {
        return;
    }
    palestreet_raumanzeige_send_build_package_zip();
});

add_action('admin_menu', function () {
    add_menu_page(
        __('Raumanzeige Ressourcen', 'palestreet-raumanzeige'),
        __('Raumanzeige', 'palestreet-raumanzeige'),
        'manage_options',
        'palestreet-raumanzeige',
        'palestreet_raumanzeige_options_page',
        'dashicons-grid-view',
        30
    );
});

/**
 * Vollbild-Anzeige (ohne WordPress-Theme) für SenseCraft HMI „Web“-Funktion.
 * URL: /raumanzeige-display/?device_id=0  oder  ?device_id=0&date=2026-01-29
 * date: optional, Y-m-d; leer = heute. Nützlich zum Testen oder für feste Tagesansicht.
 * Refresh: Tag 6–21 Uhr = 3 Min, Nacht = 15 Min (wie Firmware).
 */
add_action('init', function () {
    add_rewrite_rule('^raumanzeige-display/?$', 'index.php?palestreet_display=1', 'top');
});
add_filter('query_vars', function ($vars) {
    $vars[] = 'palestreet_display';
    return $vars;
});
register_activation_hook(__FILE__, function () {
    flush_rewrite_rules();
});
register_deactivation_hook(__FILE__, function () {
    flush_rewrite_rules();
});
add_action('template_redirect', function () {
    if (!get_query_var('palestreet_display')) {
        return;
    }
    $device_id = isset($_GET['device_id']) ? (int) $_GET['device_id'] : 0;
    $on_date = isset($_GET['date']) ? sanitize_text_field($_GET['date']) : null;
    if ($on_date === '') {
        $on_date = null;
    }
    palestreet_raumanzeige_output_display_page($device_id, $on_date);
    exit;
});

/**
 * Anzeige-Daten für eine device_id (Ressource + Kalender-Status + nächste Termine).
 * Für HTML-Seite und REST API nutzbar.
 *
 * @param int         $device_id Geräte-/Ressourcen-ID
 * @param string|null $on_date   Optional: Anzeige-Datum Y-m-d (z. B. 2026-01-29). Leer = heute.
 */
function palestreet_raumanzeige_get_display_data($device_id, $on_date = null) {
    $resources = palestreet_raumanzeige_get_resources();
    $resource = null;
    foreach ($resources as $r) {
        if ((isset($r['id']) ? (int) $r['id'] : 0) === $device_id) {
            $resource = $r;
            break;
        }
    }
    if ($resource === null && !empty($resources)) {
        $resource = $resources[0];
    }
    if ($resource === null) {
        return null;
    }
    $room_name = isset($resource['name']) ? $resource['name'] : '';
    $ics_url   = isset($resource['ics_url']) ? $resource['ics_url'] : '';
    $qr_url    = isset($resource['qr_url']) ? $resource['qr_url'] : '';

    if (!empty($resource['timezone'])) {
        try {
            $tz = new DateTimeZone(trim($resource['timezone']));
        } catch (Exception $e) {
            $tz = wp_timezone();
        }
    } else {
        $tz = wp_timezone();
    }
    $now = new DateTime('now', $tz);
    $today_ymd = $now->format('Ymd');
    $on_date_ymd = null;
    $filter_date = null;
    if ($on_date !== null && $on_date !== '') {
        $d = DateTime::createFromFormat('Y-m-d', trim($on_date), $tz);
        if ($d) {
            $on_date_ymd = $d->format('Ymd');
            $filter_date = $d->format('Y-m-d');
        }
    }

    $events = palestreet_raumanzeige_fetch_ics_events($ics_url, $filter_date, $tz);

    if ($on_date_ymd !== null) {
        if ($on_date_ymd < $today_ymd) {
            $now_min_override = 24 * 60;
        } elseif ($on_date_ymd > $today_ymd) {
            $now_min_override = -1;
        } else {
            $now_min_override = null;
        }
    } else {
        $now_min_override = null;
    }
    $status = palestreet_raumanzeige_compute_status($events, $now_min_override, $tz);

    $now_min = $now_min_override !== null ? $now_min_override : (int) $now->format('G') * 60 + (int) $now->format('i');

    $current_ev = null;
    foreach ($events as $ev) {
        $start = $ev['start_hour'] * 60 + $ev['start_min'];
        $end   = $ev['end_hour'] * 60 + $ev['end_min'];
        if ($now_min >= $start && $now_min < $end) {
            $current_ev = $ev;
            break;
        }
    }

    $next_events = [];
    foreach ($events as $ev) {
        $start = $ev['start_hour'] * 60 + $ev['start_min'];
        if ($start > $now_min) {
            $next_events[] = $ev;
        }
    }
    $next_events = array_slice($next_events, 0, 3);

    if ($status['occupied'] && $current_ev) {
        $status_label = $current_ev['summary'];
        $status_until = sprintf('%02d:%02d-%02d:%02d', $current_ev['start_hour'], $current_ev['start_min'], $current_ev['end_hour'], $current_ev['end_min']);
        $occupied = true;
    } else {
        $status_label = __('NICHT BESETZT', 'palestreet-raumanzeige');
        $status_until = sprintf(__('BIS %02d:%02d', 'palestreet-raumanzeige'), $status['until_h'], $status['until_m']);
        $occupied = false;
    }
    // Occupied-Label (Status) in API auf 20 Zeichen: bei mehr als 20 → 17 Zeichen + "..."
    $status_label = palestreet_raumanzeige_truncate_label($status_label, 20);

    // Nur unten (weitere Events) auf 12 Zeichen begrenzen
    $room_name    = palestreet_raumanzeige_truncate_label($room_name, 12);

    // Intervall nur pro Schild (refresh_interval_sec), Standard 5 Min
    $refresh_seconds = 300;
    if (isset($resource['refresh_interval_sec']) && (int) $resource['refresh_interval_sec'] >= 60) {
        $refresh_seconds = max(60, min(7200, (int) $resource['refresh_interval_sec']));
    }
    // Nachtmodus pro Schild: Intervall verdoppeln (Akku sparen), wenn Zeit in von–bis liegt
    $night_from = isset($resource['night_mode_from']) ? trim((string) $resource['night_mode_from']) : '';
    $night_to   = isset($resource['night_mode_to']) ? trim((string) $resource['night_mode_to']) : '';
    if (palestreet_raumanzeige_is_night_mode($tz, $night_from, $night_to)) {
        $refresh_seconds = (int) min(7200, $refresh_seconds * 2);
    }
    $interval_min = max(1, (int) ceil($refresh_seconds / 60));
    $update_interval_label = sprintf(__('Update alle %d Min.', 'palestreet-raumanzeige'), $interval_min);

    $events_api = [];
    foreach ($next_events as $ev) {
        $events_api[] = [
            'time'    => sprintf('%02d:%02d-%02d:%02d', $ev['start_hour'], $ev['start_min'], $ev['end_hour'], $ev['end_min']),
            'summary' => palestreet_raumanzeige_truncate_label($ev['summary'], 12),
        ];
    }

    $display_time = $now->format('d.m.Y H:i');

    // Content-Hash für „nur bei Änderung updaten“. refresh_seconds + debug_display mit drin (Nachtmodus-Wechsel, Debug an/aus → Display aktualisieren).
    $debug_flag = !empty($resource['debug_display']);
    $hash_payload = $room_name . '|' . $status_label . '|' . $status_until . '|' . ($occupied ? '1' : '0') . '|'
        . $update_interval_label . '|' . $refresh_seconds . '|' . ($debug_flag ? '1' : '0') . '|' . $qr_url . '|';
    foreach ($events_api as $ev) {
        $hash_payload .= $ev['time'] . '=' . $ev['summary'] . ';';
    }
    $content_hash = substr(hash('md5', $hash_payload), 0, 8);

    // Sekunden bis Start des nächsten Events (für Trigger-Update auf dem Schild)
    $seconds_until_next_event = 0;
    if (!empty($next_events)) {
        $next_start = clone $now;
        $next_start->setTime(
            (int) $next_events[0]['start_hour'],
            (int) $next_events[0]['start_min'],
            0
        );
        $seconds_until_next_event = $next_start->getTimestamp() - $now->getTimestamp();
        if ($seconds_until_next_event < 0) {
            $seconds_until_next_event = 0;
        }
    }

    return [
        'room_name'                 => $room_name,
        'status_label'               => $status_label,
        'status_until'               => $status_until,
        'occupied'                   => $occupied,
        'events'                     => $events_api,
        'update_interval_label'      => $update_interval_label,
        'qr_url'                     => $qr_url,
        'refresh_seconds'             => $refresh_seconds,
        'display_time'               => $display_time,
        'content_hash'               => $content_hash,
        'seconds_until_next_event'   => $seconds_until_next_event,
        'debug_display'              => !empty($resource['debug_display']),
        'wifi_ssid'                  => isset($resource['wifi_ssid']) ? (string) $resource['wifi_ssid'] : '',
        'wifi_pass'                  => isset($resource['wifi_pass']) ? (string) $resource['wifi_pass'] : '',
    ];
}

function palestreet_raumanzeige_output_display_page($device_id, $on_date = null) {
    $data = palestreet_raumanzeige_get_display_data($device_id, $on_date);
    if ($data === null) {
        status_header(404);
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body><p>Keine Ressource für device_id=' . (int) $device_id . '</p></body></html>';
        return;
    }
    $status_label = $data['status_label'];
    $status_until = $data['status_until'];
    $status_class = $data['occupied'] ? 'occupied' : '';
    $next_events = $data['events'];
    $update_interval_label = isset($data['update_interval_label']) ? $data['update_interval_label'] : __('Update alle 5 Min.', 'palestreet-raumanzeige');
    $qr_url = $data['qr_url'];
    $refresh_sec = $data['refresh_seconds'];
    $display_time = isset($data['display_time']) ? $data['display_time'] : '';
    $display_url = home_url('/raumanzeige-display/?device_id=' . (int) $device_id);

    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');

    $template = dirname(__FILE__) . '/templates/raum.php';
    if (is_readable($template)) {
        include $template;
    } else {
        echo '<!DOCTYPE html><html><body><p>Template „Raum“ nicht gefunden.</p></body></html>';
    }
}

/**
 * Shortcode für Raumanzeige (falls in normaler Seite eingebunden).
 * Für SenseCraft bevorzugen: /raumanzeige-display/?device_id=0 (ohne Theme).
 */
add_action('init', function () {
    add_shortcode('palestreet_raumanzeige_display', 'palestreet_raumanzeige_display_shortcode');
    add_shortcode('palestreet_raumanzeige_preview', 'palestreet_raumanzeige_preview_shortcode');
});

function palestreet_raumanzeige_display_shortcode($atts) {
    $atts = shortcode_atts(['device_id' => 0], $atts, 'palestreet_raumanzeige_display');
    $device_id = (int) $atts['device_id'];

    $resources = palestreet_raumanzeige_get_resources();
    $resource = null;
    foreach ($resources as $r) {
        if ((isset($r['id']) ? (int) $r['id'] : 0) === $device_id) {
            $resource = $r;
            break;
        }
    }
    if ($resource === null && !empty($resources)) {
        $resource = $resources[0];
    }
    if ($resource === null) {
        return '<p>' . esc_html__('Keine Ressource für diese device_id.', 'palestreet-raumanzeige') . '</p>';
    }

    $room_name = isset($resource['name']) ? $resource['name'] : '';
    $ics_url   = isset($resource['ics_url']) ? $resource['ics_url'] : '';
    $qr_url    = isset($resource['qr_url']) ? $resource['qr_url'] : '';

    $tz = wp_timezone();
    $events = palestreet_raumanzeige_fetch_ics_events($ics_url, null, $tz);
    $status = palestreet_raumanzeige_compute_status($events, null, $tz);
    $now = new DateTime('now', $tz);
    $now_min = (int) $now->format('G') * 60 + (int) $now->format('i');
    $next_events = [];
    foreach ($events as $ev) {
        $start = $ev['start_hour'] * 60 + $ev['start_min'];
        if ($start > $now_min) {
            $next_events[] = $ev;
        }
    }
    $next_events = array_slice($next_events, 0, 5);

    $status_text = $status['occupied']
        ? __('BESETZT', 'palestreet-raumanzeige') . ' – ' . sprintf(__('bis %02d:%02d', 'palestreet-raumanzeige'), $status['until_h'], $status['until_m'])
        : __('NICHT BESETZT', 'palestreet-raumanzeige') . ' – ' . sprintf(__('bis %02d:%02d', 'palestreet-raumanzeige'), $status['until_h'], $status['until_m']);

    $refresh_sec = 180;
    $html = '<div class="palestreet-raumanzeige-display" style="font-family:sans-serif;margin:0;padding:20px;background:#fff;color:#000;font-size:clamp(14px,4vw,22px);">'
        . '<style>.palestreet-raumanzeige-display .ra-display-room{font-size:1.4em;font-weight:bold;margin-bottom:0.5em;}'
        . '.palestreet-raumanzeige-display .ra-display-status{font-size:1.6em;font-weight:bold;margin:0.5em 0;}'
        . '.palestreet-raumanzeige-display .ra-display-status.besetzt{color:#c00;} .palestreet-raumanzeige-display .ra-display-status.frei{color:#080;}'
        . '.palestreet-raumanzeige-display .ra-display-next{margin-top:1em;} .palestreet-raumanzeige-display .ra-display-next ul{list-style:none;padding:0;margin:0.3em 0;} .palestreet-raumanzeige-display .ra-display-next li{margin:0.3em 0;}'
        . '.palestreet-raumanzeige-display .ra-display-qr{float:right;margin-left:1em;max-width:120px;height:auto;} @media (max-width:400px){.palestreet-raumanzeige-display .ra-display-qr{float:none;display:block;margin:1em 0;}}</style>';

    $html .= '<div class="ra-display-room">' . esc_html($room_name) . '</div>';
    $html .= '<div class="ra-display-status ' . ($status['occupied'] ? 'besetzt' : 'frei') . '">' . esc_html($status_text) . '</div>';

    if (!empty($next_events)) {
        $html .= '<div class="ra-display-next"><h3 style="font-size:1em;margin-bottom:0.3em;">' . esc_html__('Nächste Termine', 'palestreet-raumanzeige') . '</h3><ul>';
        foreach ($next_events as $ev) {
            $t = sprintf('%02d:%02d - %02d:%02d', $ev['start_hour'], $ev['start_min'], $ev['end_hour'], $ev['end_min']);
            $html .= '<li>' . esc_html($t . ' ' . $ev['summary']) . '</li>';
        }
        $html .= '</ul></div>';
    }

    if ($qr_url) {
        $html .= '<img class="ra-display-qr" src="' . esc_url($qr_url) . '" alt="QR" />';
    }

    $html .= '<script>setTimeout(function(){location.reload();},' . (int) $refresh_sec . '000);</script></div>';
    return $html;
}

/**
 * Shortcode: Web-Vorschau (iframe zur Display-Seite, inkl. QR-Code).
 * Nutzung: [palestreet_raumanzeige_preview device_id="0"]
 */
function palestreet_raumanzeige_preview_shortcode($atts) {
    $atts = shortcode_atts(['device_id' => 0, 'height' => 520], $atts, 'palestreet_raumanzeige_preview');
    $device_id = (int) $atts['device_id'];
    $height = (int) $atts['height'];
    if ($height < 300) {
        $height = 520;
    }
    $url = home_url('/raumanzeige-display/?device_id=' . $device_id);
    return '<iframe src="' . esc_url($url) . '" style="width:100%;max-width:820px;height:' . (int) $height . 'px;border:1px solid #ccc;" title="' . esc_attr__('Raumanzeige Vorschau', 'palestreet-raumanzeige') . '"></iframe>';
}

function palestreet_raumanzeige_options_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    $message = '';
    if (isset($_POST['palestreet_raumanzeige_save']) && check_admin_referer('palestreet_raumanzeige_options')) {
        $raw = isset($_POST['resources']) && is_array($_POST['resources']) ? $_POST['resources'] : [];
        $resources = [];
        $placeholders = [
            'name'       => 'z.B. Besprechungsraum A',
            'ics_url'    => 'https://calendar.google.com/calendar/ical/...',
            'qr_url'     => 'https://...',
            'wifi_ssid'  => '',
            'wifi_pass'  => '',
        ];
        foreach ($raw as $i => $row) {
            $id         = isset($row['id']) ? (int) $row['id'] : 0;
            $name       = isset($row['name']) ? trim(sanitize_text_field($row['name'])) : '';
            $ics_url    = isset($row['ics_url']) ? trim(esc_url_raw($row['ics_url'])) : '';
            $qr_url     = isset($row['qr_url']) ? trim(esc_url_raw($row['qr_url'])) : '';
            $wifi_ssid  = isset($row['wifi_ssid']) ? trim(sanitize_text_field($row['wifi_ssid'])) : '';
            $wifi_pass  = isset($row['wifi_pass']) ? trim($row['wifi_pass']) : ''; // Passwort: nur trim, kein sanitize
            $refresh_interval_min = isset($row['refresh_interval_min']) ? max(1, min(120, (int) $row['refresh_interval_min'])) : 5;
            $refresh_interval_sec = $refresh_interval_min * 60;
            $debug_display = isset($row['debug_display']) && $row['debug_display'];
            $night_mode_from = isset($row['night_mode_from']) ? sanitize_text_field($row['night_mode_from']) : '';
            $night_mode_to   = isset($row['night_mode_to']) ? sanitize_text_field($row['night_mode_to']) : '';
            if ($name === $placeholders['name'] || strlen($name) < 2) {
                $name = '';
            }
            if ($ics_url === $placeholders['ics_url'] || $ics_url === 'https://...' || strlen($ics_url) < 40) {
                $ics_url = '';
            }
            if ($qr_url === $placeholders['qr_url'] || strlen($qr_url) < 15) {
                $qr_url = '';
            }
            $timezone = isset($row['timezone']) ? trim(sanitize_text_field($row['timezone'])) : '';
            $template = isset($row['template']) ? sanitize_key($row['template']) : 'default';
            if (!in_array($template, ['default'], true)) {
                $template = 'default';
            }
            if ($name !== '' || $ics_url !== '' || $qr_url !== '' || $wifi_ssid !== '') {
                $resources[] = [
                    'id'                  => $id,
                    'name'                => $name,
                    'ics_url'             => $ics_url,
                    'qr_url'              => $qr_url,
                    'wifi_ssid'           => $wifi_ssid,
                    'wifi_pass'           => $wifi_pass,
                    'timezone'            => $timezone,
                    'template'            => $template,
                    'refresh_interval_sec'=> $refresh_interval_sec,
                    'debug_display'       => $debug_display,
                    'night_mode_from'     => $night_mode_from,
                    'night_mode_to'       => $night_mode_to,
                ];
            }
        }
        palestreet_raumanzeige_save_resources($resources);
        update_option('palestreet_raumanzeige_settings_updated_at', time(), false);
        $message = __('Einstellungen gespeichert.', 'palestreet-raumanzeige');
    }

    $resources = palestreet_raumanzeige_get_resources();
    $settings_updated_at = get_option('palestreet_raumanzeige_settings_updated_at', 0);
    $firmware_version   = palestreet_raumanzeige_get_firmware_version();
    $preview_base_url   = home_url('/raumanzeige-display/');
    $icons_base_url     = plugin_dir_url(__FILE__) . 'firmware/data/';
    ?>
    <style>
    .raumanzeige-resources-accordion { max-width: 100%; margin: 12px 0; }
    .raumanzeige-resources-accordion details { margin-bottom: 8px; border: 1px solid #c3c4c7; border-radius: 4px; background: #fff; }
    .raumanzeige-resources-accordion details[open] { border-color: #2271b1; box-shadow: 0 0 0 1px #2271b1; }
    .raumanzeige-resources-accordion summary { padding: 12px 16px; cursor: pointer; font-weight: 600; list-style: none; display: flex; align-items: center; justify-content: space-between; }
    .raumanzeige-resources-accordion summary::-webkit-details-marker { display: none; }
    .raumanzeige-resources-accordion summary::before { content: "▸ "; color: #2271b1; margin-right: 8px; }
    .raumanzeige-resources-accordion details[open] summary::before { content: "▾ "; }
    .raumanzeige-resources-accordion .summary-title { flex: 1; }
    .raumanzeige-resources-accordion .summary-id { color: #646970; font-weight: normal; margin-right: 12px; }
    .raumanzeige-resources-accordion .summary-actions { display: flex; gap: 8px; }
    .raumanzeige-resources-accordion .accordion-inner { padding: 16px; border-top: 1px solid #f0f0f1; }
    .raumanzeige-resources-accordion .form-row { margin-bottom: 16px; }
    .raumanzeige-resources-accordion .form-row:last-child { margin-bottom: 0; }
    .raumanzeige-resources-accordion .form-row label { display: block; font-weight: 600; margin-bottom: 4px; }
    .raumanzeige-resources-accordion .form-row input[type="text"],
    .raumanzeige-resources-accordion .form-row input[type="url"],
    .raumanzeige-resources-accordion .form-row input[type="time"],
    .raumanzeige-resources-accordion .form-row input[type="number"],
    .raumanzeige-resources-accordion .form-row select { width: 100%; max-width: 600px; }
    .raumanzeige-resources-accordion .form-row .description { font-size: 13px; color: #646970; margin-top: 4px; }
    .raumanzeige-resources-accordion .form-row-checkbox { display: flex; align-items: center; gap: 8px; }
    .raumanzeige-resources-accordion .form-row-checkbox label { font-weight: normal; margin: 0; }
    .raumanzeige-accordion { max-width: 720px; margin: 12px 0; }
    .raumanzeige-accordion details { margin-bottom: 2px; border: 1px solid #c3c4c7; border-radius: 4px; background: #fff; }
    .raumanzeige-accordion details[open] { border-color: #8c8f94; }
    .raumanzeige-accordion summary { padding: 10px 12px; cursor: pointer; font-weight: 600; list-style: none; }
    .raumanzeige-accordion summary::-webkit-details-marker { display: none; }
    .raumanzeige-accordion summary::before { content: "▸ "; }
    .raumanzeige-accordion details[open] summary::before { content: "▾ "; }
    .raumanzeige-accordion .accordion-inner { padding: 0 12px 12px; border-top: 1px solid #f0f0f1; }
    .raumanzeige-accordion .accordion-inner p:first-child { margin-top: 12px; }
    .raumanzeige-error-icons { display: flex; gap: 24px; flex-wrap: wrap; margin: 12px 0; align-items: flex-start; }
    .raumanzeige-error-icons figure { margin: 0; text-align: center; }
    .raumanzeige-error-icons img { width: 64px; height: 64px; object-fit: contain; display: block; margin: 0 auto 6px; }
    .raumanzeige-error-icons figcaption { font-size: 12px; color: #1d2327; }
    </style>
    <div class="wrap">
        <h1><?php echo esc_html(__('Raumanzeige – Ressourcen', 'palestreet-raumanzeige')); ?></h1>
        <?php if ($message) : ?>
            <div class="notice notice-success"><p><?php echo esc_html($message); ?></p></div>
        <?php endif; ?>

        <form method="post" id="raumanzeige-form">
            <?php wp_nonce_field('palestreet_raumanzeige_options'); ?>
            <div class="raumanzeige-resources-accordion" id="raumanzeige-rows">
                <?php
                if (empty($resources)) {
                    $resources = [['id' => 0, 'name' => '', 'ics_url' => '', 'qr_url' => '', 'wifi_ssid' => '', 'wifi_pass' => '', 'timezone' => '', 'template' => 'default', 'refresh_interval_sec' => 300, 'debug_display' => false, 'night_mode_from' => '', 'night_mode_to' => '']];
                }
                $next_id = palestreet_raumanzeige_next_id($resources);
                foreach ($resources as $idx => $r) :
                    $rid = isset($r['id']) ? (int) $r['id'] : $idx;
                    $name = isset($r['name']) ? $r['name'] : '';
                    $ics = isset($r['ics_url']) ? $r['ics_url'] : '';
                    $qr = isset($r['qr_url']) ? $r['qr_url'] : '';
                    $wifi_ssid = isset($r['wifi_ssid']) ? $r['wifi_ssid'] : '';
                    $wifi_pass = isset($r['wifi_pass']) ? $r['wifi_pass'] : '';
                    $r_tz = isset($r['timezone']) ? $r['timezone'] : '';
                    $r_tpl = isset($r['template']) ? $r['template'] : 'default';
                    $r_refresh_min = isset($r['refresh_interval_sec']) && (int) $r['refresh_interval_sec'] >= 60
                        ? max(1, min(120, (int) ($r['refresh_interval_sec'] / 60)))
                        : 5;
                    $r_debug = !empty($r['debug_display']);
                    $r_night_from = isset($r['night_mode_from']) ? $r['night_mode_from'] : '';
                    $r_night_to   = isset($r['night_mode_to']) ? $r['night_mode_to'] : '';
                    $display_name = $name !== '' ? esc_html($name) : __('Neues Schild', 'palestreet-raumanzeige');
                ?>
                <details data-resource-index="<?php echo (int) $idx; ?>">
                    <summary>
                        <span class="summary-title">
                            <span class="summary-id"><?php echo sprintf(__('ID: %d', 'palestreet-raumanzeige'), (int) $rid); ?></span>
                            <?php echo $display_name; ?>
                        </span>
                        <span class="summary-actions">
                            <a href="<?php echo esc_url($preview_base_url . '?device_id=' . $rid); ?>" target="_blank" rel="noopener" class="button button-small" onclick="event.stopPropagation();"><?php esc_html_e('Vorschau', 'palestreet-raumanzeige'); ?></a>
                            <button type="button" class="button button-small raumanzeige-delete-row" aria-label="<?php esc_attr_e('Schild löschen', 'palestreet-raumanzeige'); ?>" onclick="event.stopPropagation();"><?php esc_html_e('Löschen', 'palestreet-raumanzeige'); ?></button>
                        </span>
                    </summary>
                    <div class="accordion-inner">
                        <input type="hidden" name="resources[<?php echo (int) $idx; ?>][id]" value="<?php echo (int) $rid; ?>" />
                        
                        <div class="form-row">
                            <label for="resource-<?php echo (int) $idx; ?>-name"><?php esc_html_e('Raum / Bezeichnung', 'palestreet-raumanzeige'); ?></label>
                            <input type="text" id="resource-<?php echo (int) $idx; ?>-name" name="resources[<?php echo (int) $idx; ?>][name]" value="<?php echo esc_attr($name); ?>" class="regular-text" placeholder="<?php esc_attr_e('z.B. Besprechungsraum A', 'palestreet-raumanzeige'); ?>" />
                        </div>

                        <div class="form-row">
                            <label for="resource-<?php echo (int) $idx; ?>-ics"><?php esc_html_e('ICS-URL', 'palestreet-raumanzeige'); ?></label>
                            <input type="url" id="resource-<?php echo (int) $idx; ?>-ics" name="resources[<?php echo (int) $idx; ?>][ics_url]" value="<?php echo esc_attr($ics); ?>" class="large-text" placeholder="https://calendar.google.com/calendar/ical/..." />
                            <p class="description"><?php esc_html_e('Öffentlich abrufbare ICS-Kalender-URL (z. B. Google Kalender „Geheime Adresse iCal“)', 'palestreet-raumanzeige'); ?></p>
                        </div>

                        <div class="form-row">
                            <label for="resource-<?php echo (int) $idx; ?>-qr"><?php esc_html_e('QR-Code-URL', 'palestreet-raumanzeige'); ?></label>
                            <input type="url" id="resource-<?php echo (int) $idx; ?>-qr" name="resources[<?php echo (int) $idx; ?>][qr_url]" value="<?php echo esc_attr($qr); ?>" class="large-text" placeholder="https://..." />
                            <p class="description"><?php esc_html_e('Optional: URL für QR-Code auf dem Display', 'palestreet-raumanzeige'); ?></p>
                        </div>

                        <div class="form-row">
                            <label for="resource-<?php echo (int) $idx; ?>-wifi-ssid"><?php esc_html_e('WLAN (SSID)', 'palestreet-raumanzeige'); ?></label>
                            <input type="text" id="resource-<?php echo (int) $idx; ?>-wifi-ssid" name="resources[<?php echo (int) $idx; ?>][wifi_ssid]" value="<?php echo esc_attr($wifi_ssid); ?>" class="regular-text" placeholder="SSID" autocomplete="off" />
                        </div>

                        <div class="form-row">
                            <label for="resource-<?php echo (int) $idx; ?>-wifi-pass"><?php esc_html_e('WLAN-Passwort', 'palestreet-raumanzeige'); ?></label>
                            <input type="text" id="resource-<?php echo (int) $idx; ?>-wifi-pass" name="resources[<?php echo (int) $idx; ?>][wifi_pass]" value="<?php echo esc_attr($wifi_pass); ?>" class="regular-text" placeholder="***" autocomplete="new-password" />
                        </div>

                        <div class="form-row">
                            <label for="resource-<?php echo (int) $idx; ?>-timezone"><?php esc_html_e('Zeitzone', 'palestreet-raumanzeige'); ?></label>
                            <input type="text" id="resource-<?php echo (int) $idx; ?>-timezone" name="resources[<?php echo (int) $idx; ?>][timezone]" value="<?php echo esc_attr($r_tz); ?>" class="regular-text" placeholder="Europe/Berlin" />
                            <p class="description"><?php esc_html_e('Zeitzone für Kalender-Termine (z. B. Europe/Berlin, America/New_York)', 'palestreet-raumanzeige'); ?></p>
                        </div>

                        <div class="form-row">
                            <label for="resource-<?php echo (int) $idx; ?>-interval"><?php esc_html_e('Update-Intervall (Minuten)', 'palestreet-raumanzeige'); ?></label>
                            <input type="number" id="resource-<?php echo (int) $idx; ?>-interval" name="resources[<?php echo (int) $idx; ?>][refresh_interval_min]" value="<?php echo (int) $r_refresh_min; ?>" min="1" max="120" step="1" style="width:100px;" />
                            <p class="description"><?php esc_html_e('Alle X Minuten wird das Schild aktualisiert', 'palestreet-raumanzeige'); ?></p>
                        </div>

                        <div class="form-row">
                            <label><?php esc_html_e('Nachtmodus', 'palestreet-raumanzeige'); ?></label>
                            <div style="display: flex; gap: 16px; align-items: center; flex-wrap: wrap;">
                                <div>
                                    <label for="resource-<?php echo (int) $idx; ?>-night-from" style="font-weight: normal; margin-bottom: 4px; display: block;"><?php esc_html_e('Von', 'palestreet-raumanzeige'); ?></label>
                                    <input type="time" id="resource-<?php echo (int) $idx; ?>-night-from" name="resources[<?php echo (int) $idx; ?>][night_mode_from]" value="<?php echo esc_attr($r_night_from); ?>" />
                                </div>
                                <div>
                                    <label for="resource-<?php echo (int) $idx; ?>-night-to" style="font-weight: normal; margin-bottom: 4px; display: block;"><?php esc_html_e('Bis', 'palestreet-raumanzeige'); ?></label>
                                    <input type="time" id="resource-<?php echo (int) $idx; ?>-night-to" name="resources[<?php echo (int) $idx; ?>][night_mode_to]" value="<?php echo esc_attr($r_night_to); ?>" />
                                </div>
                            </div>
                            <p class="description"><?php esc_html_e('In diesem Zeitfenster wird das Update-Intervall verdoppelt (spart Akku)', 'palestreet-raumanzeige'); ?></p>
                        </div>

                        <div class="form-row">
                            <label for="resource-<?php echo (int) $idx; ?>-template"><?php esc_html_e('Template', 'palestreet-raumanzeige'); ?></label>
                            <select id="resource-<?php echo (int) $idx; ?>-template" name="resources[<?php echo (int) $idx; ?>][template]">
                                <option value="default" <?php selected($r_tpl, 'default'); ?>><?php esc_html_e('Raum', 'palestreet-raumanzeige'); ?></option>
                            </select>
                        </div>

                        <div class="form-row form-row-checkbox">
                            <input type="checkbox" id="resource-<?php echo (int) $idx; ?>-debug" name="resources[<?php echo (int) $idx; ?>][debug_display]" value="1" <?php checked($r_debug); ?> />
                            <label for="resource-<?php echo (int) $idx; ?>-debug"><?php esc_html_e('Debug-Anzeige aktivieren', 'palestreet-raumanzeige'); ?></label>
                            <p class="description" style="margin-left: 0;"><?php esc_html_e('Zeigt Version, IP, Raum-ID und Akku % auf dem Schild', 'palestreet-raumanzeige'); ?></p>
                        </div>
                    </div>
                </details>
                <?php endforeach; ?>
            </div>
            <p>
                <button type="button" class="button" id="raumanzeige-add-row"><?php esc_html_e('+ Schild hinzufügen', 'palestreet-raumanzeige'); ?></button>
            </p>

            <p class="submit">
                <input type="submit" name="palestreet_raumanzeige_save" class="button button-primary" value="<?php esc_attr_e('Speichern', 'palestreet-raumanzeige'); ?>" />
            </p>
        </form>

        <hr />

        <h2><?php esc_html_e('E-Paper: Firmware bauen & flashen', 'palestreet-raumanzeige'); ?></h2>
        <p><?php esc_html_e('Build-Paket herunterladen, entpacken. WLAN und Update-Intervall kommen von hier (API).', 'palestreet-raumanzeige'); ?></p>
        <p>
            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=palestreet-raumanzeige&action=download_build_package'), 'palestreet_raumanzeige_download_build')); ?>" class="button button-primary"><?php esc_html_e('Build-Paket herunterladen', 'palestreet-raumanzeige'); ?><?php echo $firmware_version !== '' ? ' (v' . esc_html($firmware_version) . ')' : ''; ?></a>
        </p>
        <p><strong><?php esc_html_e('Lokal ausführen:', 'palestreet-raumanzeige'); ?></strong></p>
        <p><label for="raumanzeige-build-commands" class="screen-reader-text"><?php esc_html_e('Kommandozeilen-Befehle (zum Markieren und Kopieren)', 'palestreet-raumanzeige'); ?></label></p>
        <pre id="raumanzeige-build-commands" class="raumanzeige-commands" style="background:#1e1e1e;color:#d4d4d4;padding:14px;max-width:720px;overflow-x:auto;border-radius:4px;user-select:all;-webkit-user-select:all;-moz-user-select:all;-ms-user-select:all;" aria-label="<?php esc_attr_e('Befehle zum Kopieren', 'palestreet-raumanzeige'); ?>">cd palestreet-raumanzeige
./build-firmware.sh</pre>
        <ul style="margin:0.5em 0 0 1.2em; padding:0;">
            <li><strong><?php esc_html_e('Geräte-ID setzen', 'palestreet-raumanzeige'); ?></strong> — <?php esc_html_e('wird beim Build abgefragt.', 'palestreet-raumanzeige'); ?></li>
            <li><strong><?php esc_html_e('Automatischer Flash', 'palestreet-raumanzeige'); ?></strong> — <?php esc_html_e('nach dem Build. Alternativ: ./flash.sh ausführen.', 'palestreet-raumanzeige'); ?></li>
            <li><strong><?php esc_html_e('Gerät auswählen', 'palestreet-raumanzeige'); ?></strong> — <?php esc_html_e('bei mehreren USB-Ports wird eine Nummer abgefragt.', 'palestreet-raumanzeige'); ?></li>
        </ul>
        <p class="description"><?php esc_html_e('Befehle kopieren (Strg+A im Kasten) und im entpackten Ordner ausführen.', 'palestreet-raumanzeige'); ?></p>

        <div class="raumanzeige-accordion">
            <details>
                <summary><?php esc_html_e('Hardware', 'palestreet-raumanzeige'); ?></summary>
                <div class="accordion-inner">
                    <p><strong><?php esc_html_e('Display:', 'palestreet-raumanzeige'); ?></strong> <a href="https://www.seeedstudio.com/reTerminal-E1001-p-6534.html?sensecap_affiliate=VcrMFpJ&referring_service=link" target="_blank" rel="noopener noreferrer">reTerminal E1001 bei Seeed Studio</a></p>
                    <p><strong><?php esc_html_e('Zubehör Festverkabelung Stromversorgung:', 'palestreet-raumanzeige'); ?></strong> <a href="https://amzn.to/3Onrnps" target="_blank" rel="noopener noreferrer">Amazon</a></p>
                </div>
            </details>
            <details>
                <summary><?php esc_html_e('macOS: arduino-cli & ESP32', 'palestreet-raumanzeige'); ?></summary>
                <div class="accordion-inner">
                    <p><code>brew install arduino-cli</code> — <?php esc_html_e('dann:', 'palestreet-raumanzeige'); ?> <code>arduino-cli core update-index &amp;&amp; arduino-cli core install esp32:esp32</code></p>
                </div>
            </details>
            <details>
                <summary><?php esc_html_e('Ubuntu: arduino-cli & ESP32', 'palestreet-raumanzeige'); ?></summary>
                <div class="accordion-inner">
                    <p><code>curl -fsSL https://raw.githubusercontent.com/arduino/arduino-cli/master/install.sh | sh</code></p>
                    <p><code>arduino-cli core update-index &amp;&amp; arduino-cli core install esp32:esp32</code></p>
                </div>
            </details>
            <details>
                <summary><?php esc_html_e('Erstes Flashen: Boot-Modus', 'palestreet-raumanzeige'); ?></summary>
                <div class="accordion-inner">
                    <ol style="margin:0.5em 0 0 1.2em; padding:0;">
                        <li><?php esc_html_e('Grünen Knopf drücken und halten.', 'palestreet-raumanzeige'); ?></li>
                        <li><?php esc_html_e('USB einstecken.', 'palestreet-raumanzeige'); ?></li>
                        <li><?php esc_html_e('Knopf halten, bis Display weiß wird.', 'palestreet-raumanzeige'); ?></li>
                        <li><code>./flash.sh</code> <?php esc_html_e('ausführen.', 'palestreet-raumanzeige'); ?></li>
                    </ol>
                </div>
            </details>
            <details>
                <summary><?php esc_html_e('Wann neu flashen?', 'palestreet-raumanzeige'); ?></summary>
                <div class="accordion-inner">
                    <p><?php esc_html_e('Nur nötig bei: neuer WordPress-URL, neuer Firmware-Version oder falschem WLAN (Schild verbindet sich nicht).', 'palestreet-raumanzeige'); ?></p>
                    <p><?php esc_html_e('Nicht nötig bei Änderung von Raumname, Kalender, WLAN, Intervall, Zeitzone, Debug – das Schild holt das bei jedem Update.', 'palestreet-raumanzeige'); ?></p>
                </div>
            </details>
            <details>
                <summary><?php esc_html_e('Akku', 'palestreet-raumanzeige'); ?></summary>
                <div class="accordion-inner">
                    <p><?php esc_html_e('Display wird nur bei geändertem Inhalt aktualisiert – spart Strom. Nachtmodus („Nacht von“/„bis“): Intervall wird verdoppelt.', 'palestreet-raumanzeige'); ?></p>
                    <p><?php esc_html_e('Unter 2 % Akku: Fehleranzeige, danach Deep Sleep. Grüner Knopf weckt und aktualisiert.', 'palestreet-raumanzeige'); ?></p>
                </div>
            </details>
            <details>
                <summary><?php esc_html_e('Kalender (ICS)', 'palestreet-raumanzeige'); ?></summary>
                <div class="accordion-inner">
                    <p><?php esc_html_e('ICS-URL muss öffentlich abrufbar sein (ohne Login). Pro Termin: DTSTART, DTEND, SUMMARY. Nur Termine des Tages werden angezeigt.', 'palestreet-raumanzeige'); ?></p>
                </div>
            </details>
            <details>
                <summary><?php esc_html_e('Fehler-Anzeige (WLAN / Akku / Server)', 'palestreet-raumanzeige'); ?></summary>
                <div class="accordion-inner">
                    <p><?php esc_html_e('Bei Problemen zeigt das Schild bis zu drei Symbole:', 'palestreet-raumanzeige'); ?></p>
                    <div class="raumanzeige-error-icons">
                        <figure>
                            <img src="<?php echo esc_url($icons_base_url . 'no_wifi.png'); ?>" alt="" width="64" height="64" />
                            <figcaption><strong>WLAN?</strong><br><?php esc_html_e('Keine WLAN-Verbindung.', 'palestreet-raumanzeige'); ?></figcaption>
                        </figure>
                        <figure>
                            <img src="<?php echo esc_url($icons_base_url . 'low_battery.png'); ?>" alt="" width="64" height="64" />
                            <figcaption><strong>Akku!</strong><br><?php esc_html_e('Akku unter 2 %.', 'palestreet-raumanzeige'); ?></figcaption>
                        </figure>
                        <figure>
                            <img src="<?php echo esc_url($icons_base_url . 'no_connection.png'); ?>" alt="" width="64" height="64" />
                            <figcaption><strong>Server?</strong><br><?php esc_html_e('Backend nicht erreichbar.', 'palestreet-raumanzeige'); ?></figcaption>
                        </figure>
                    </div>
                </div>
            </details>
            <details>
                <summary><?php esc_html_e('Shortcode & API', 'palestreet-raumanzeige'); ?></summary>
                <div class="accordion-inner">
                    <p><strong><?php esc_html_e('Web-Vorschau (Display-Seite mit QR-Code):', 'palestreet-raumanzeige'); ?></strong></p>
                    <p><code><?php echo esc_html(home_url('/raumanzeige-display/?device_id=0')); ?></code></p>
                    <p><strong><?php esc_html_e('Shortcode Vorschau (iframe):', 'palestreet-raumanzeige'); ?></strong> <code>[palestreet_raumanzeige_preview device_id="0"]</code></p>
                    <p><strong><?php esc_html_e('Shortcode Inline:', 'palestreet-raumanzeige'); ?></strong> <code>[palestreet_raumanzeige_display device_id="0"]</code></p>
                    <p><strong><?php esc_html_e('API (E-Paper-Schild):', 'palestreet-raumanzeige'); ?></strong></p>
                    <p><code><?php echo esc_html(rest_url('palestreet-raumanzeige/v1/display')); ?>?device_id=0</code></p>
                </div>
            </details>
        </div>

        <hr />
    </div>
    <script>
    (function() {
        var container = document.getElementById('raumanzeige-rows');
        function getNextFormIndex() {
            var max = -1;
            container.querySelectorAll('input[name^="resources["]').forEach(function(inp) {
                var m = inp.name.match(/resources\[(\d+)\]/);
                if (m) max = Math.max(max, parseInt(m[1], 10));
            });
            return max + 1;
        }
        function getNextDeviceId() {
            var max = -1;
            container.querySelectorAll('input[name$="[id]"]').forEach(function(inp) {
                var v = parseInt(inp.value, 10);
                if (!isNaN(v)) max = Math.max(max, v);
            });
            return max + 1;
        }
        function updateSummaryTitle(details) {
            var nameInput = details.querySelector('input[name$="[name]"]');
            var summaryTitle = details.querySelector('.summary-title');
            if (nameInput && summaryTitle) {
                var name = nameInput.value.trim();
                var idSpan = summaryTitle.querySelector('.summary-id');
                var idText = idSpan ? idSpan.textContent : '';
                summaryTitle.innerHTML = idText + ' ' + (name || '<?php echo esc_js(__('Neues Schild', 'palestreet-raumanzeige')); ?>');
            }
        }

        document.getElementById('raumanzeige-add-row').addEventListener('click', function() {
            var formIndex = getNextFormIndex();
            var newId = getNextDeviceId();
            var previewUrl = '<?php echo esc_js($preview_base_url); ?>?device_id=' + newId;
            var details = document.createElement('details');
            details.setAttribute('data-resource-index', formIndex);
            details.innerHTML = '<summary>' +
                '<span class="summary-title">' +
                '<span class="summary-id">ID: ' + newId + '</span> ' +
                '<?php echo esc_js(__('Neues Schild', 'palestreet-raumanzeige')); ?>' +
                '</span>' +
                '<span class="summary-actions">' +
                '<a href="' + previewUrl + '" target="_blank" rel="noopener" class="button button-small" onclick="event.stopPropagation();"><?php echo esc_js(__('Vorschau', 'palestreet-raumanzeige')); ?></a> ' +
                '<button type="button" class="button button-small raumanzeige-delete-row" aria-label="<?php echo esc_js(__('Schild löschen', 'palestreet-raumanzeige')); ?>" onclick="event.stopPropagation();"><?php echo esc_js(__('Löschen', 'palestreet-raumanzeige')); ?></button>' +
                '</span>' +
                '</summary>' +
                '<div class="accordion-inner">' +
                '<input type="hidden" name="resources[' + formIndex + '][id]" value="' + newId + '" />' +
                '<div class="form-row">' +
                '<label for="resource-' + formIndex + '-name"><?php echo esc_js(__('Raum / Bezeichnung', 'palestreet-raumanzeige')); ?></label>' +
                '<input type="text" id="resource-' + formIndex + '-name" name="resources[' + formIndex + '][name]" value="" class="regular-text" placeholder="<?php echo esc_js(__('z.B. Besprechungsraum A', 'palestreet-raumanzeige')); ?>" />' +
                '</div>' +
                '<div class="form-row">' +
                '<label for="resource-' + formIndex + '-ics"><?php echo esc_js(__('ICS-URL', 'palestreet-raumanzeige')); ?></label>' +
                '<input type="url" id="resource-' + formIndex + '-ics" name="resources[' + formIndex + '][ics_url]" value="" class="large-text" placeholder="https://calendar.google.com/calendar/ical/..." />' +
                '<p class="description"><?php echo esc_js(__('Öffentlich abrufbare ICS-Kalender-URL (z. B. Google Kalender „Geheime Adresse iCal")', 'palestreet-raumanzeige')); ?></p>' +
                '</div>' +
                '<div class="form-row">' +
                '<label for="resource-' + formIndex + '-qr"><?php echo esc_js(__('QR-Code-URL', 'palestreet-raumanzeige')); ?></label>' +
                '<input type="url" id="resource-' + formIndex + '-qr" name="resources[' + formIndex + '][qr_url]" value="" class="large-text" placeholder="https://..." />' +
                '<p class="description"><?php echo esc_js(__('Optional: URL für QR-Code auf dem Display', 'palestreet-raumanzeige')); ?></p>' +
                '</div>' +
                '<div class="form-row">' +
                '<label for="resource-' + formIndex + '-wifi-ssid"><?php echo esc_js(__('WLAN (SSID)', 'palestreet-raumanzeige')); ?></label>' +
                '<input type="text" id="resource-' + formIndex + '-wifi-ssid" name="resources[' + formIndex + '][wifi_ssid]" value="" class="regular-text" placeholder="SSID" autocomplete="off" />' +
                '</div>' +
                '<div class="form-row">' +
                '<label for="resource-' + formIndex + '-wifi-pass"><?php echo esc_js(__('WLAN-Passwort', 'palestreet-raumanzeige')); ?></label>' +
                '<input type="text" id="resource-' + formIndex + '-wifi-pass" name="resources[' + formIndex + '][wifi_pass]" value="" class="regular-text" placeholder="***" autocomplete="new-password" />' +
                '</div>' +
                '<div class="form-row">' +
                '<label for="resource-' + formIndex + '-timezone"><?php echo esc_js(__('Zeitzone', 'palestreet-raumanzeige')); ?></label>' +
                '<input type="text" id="resource-' + formIndex + '-timezone" name="resources[' + formIndex + '][timezone]" value="" class="regular-text" placeholder="Europe/Berlin" />' +
                '<p class="description"><?php echo esc_js(__('Zeitzone für Kalender-Termine (z. B. Europe/Berlin, America/New_York)', 'palestreet-raumanzeige')); ?></p>' +
                '</div>' +
                '<div class="form-row">' +
                '<label for="resource-' + formIndex + '-interval"><?php echo esc_js(__('Update-Intervall (Minuten)', 'palestreet-raumanzeige')); ?></label>' +
                '<input type="number" id="resource-' + formIndex + '-interval" name="resources[' + formIndex + '][refresh_interval_min]" value="5" min="1" max="120" step="1" style="width:100px;" />' +
                '<p class="description"><?php echo esc_js(__('Alle X Minuten wird das Schild aktualisiert', 'palestreet-raumanzeige')); ?></p>' +
                '</div>' +
                '<div class="form-row">' +
                '<label><?php echo esc_js(__('Nachtmodus', 'palestreet-raumanzeige')); ?></label>' +
                '<div style="display: flex; gap: 16px; align-items: center; flex-wrap: wrap;">' +
                '<div>' +
                '<label for="resource-' + formIndex + '-night-from" style="font-weight: normal; margin-bottom: 4px; display: block;"><?php echo esc_js(__('Von', 'palestreet-raumanzeige')); ?></label>' +
                '<input type="time" id="resource-' + formIndex + '-night-from" name="resources[' + formIndex + '][night_mode_from]" value="" />' +
                '</div>' +
                '<div>' +
                '<label for="resource-' + formIndex + '-night-to" style="font-weight: normal; margin-bottom: 4px; display: block;"><?php echo esc_js(__('Bis', 'palestreet-raumanzeige')); ?></label>' +
                '<input type="time" id="resource-' + formIndex + '-night-to" name="resources[' + formIndex + '][night_mode_to]" value="" />' +
                '</div>' +
                '</div>' +
                '<p class="description"><?php echo esc_js(__('In diesem Zeitfenster wird das Update-Intervall verdoppelt (spart Akku)', 'palestreet-raumanzeige')); ?></p>' +
                '</div>' +
                '<div class="form-row">' +
                '<label for="resource-' + formIndex + '-template"><?php echo esc_js(__('Template', 'palestreet-raumanzeige')); ?></label>' +
                '<select id="resource-' + formIndex + '-template" name="resources[' + formIndex + '][template]">' +
                '<option value="default"><?php echo esc_js(__('Raum', 'palestreet-raumanzeige')); ?></option>' +
                '</select>' +
                '</div>' +
                '<div class="form-row form-row-checkbox">' +
                '<input type="checkbox" id="resource-' + formIndex + '-debug" name="resources[' + formIndex + '][debug_display]" value="1" />' +
                '<label for="resource-' + formIndex + '-debug"><?php echo esc_js(__('Debug-Anzeige aktivieren', 'palestreet-raumanzeige')); ?></label>' +
                '<p class="description" style="margin-left: 0;"><?php echo esc_js(__('Zeigt Version, IP, Raum-ID und Akku % auf dem Schild', 'palestreet-raumanzeige')); ?></p>' +
                '</div>' +
                '</div>';
            container.appendChild(details);
            details.open = true;
            var nameInput = details.querySelector('input[name$="[name]"]');
            if (nameInput) {
                nameInput.addEventListener('input', function() { updateSummaryTitle(details); });
            }
            bindDelete();
        });

        function bindDelete() {
            container.querySelectorAll('.raumanzeige-delete-row').forEach(function(btn) {
                if (btn._bound) return;
                btn._bound = true;
                btn.addEventListener('click', function() {
                    var details = btn.closest('details');
                    if (container.querySelectorAll('details').length > 1) {
                        details.remove();
                    } else {
                        details.querySelectorAll('input[type="text"], input[type="url"], input[type="time"], input[type="number"]').forEach(function(inp) { 
                            if (inp.type !== 'hidden') inp.value = ''; 
                        });
                        details.querySelectorAll('input[type="checkbox"]').forEach(function(inp) { inp.checked = false; });
                        updateSummaryTitle(details);
                    }
                });
            });
        }
        
        // Update summary titles when name inputs change
        container.querySelectorAll('input[name$="[name]"]').forEach(function(inp) {
            var details = inp.closest('details');
            if (details) {
                inp.addEventListener('input', function() { updateSummaryTitle(details); });
            }
        });
        
        bindDelete();
    })();
    </script>
    <?php
}

/**
 * REST API: Display-Daten und Ressourcen-Liste (E-Paper nutzt nur /display)
 */
add_action('rest_api_init', function () {
    register_rest_route('palestreet-raumanzeige/v1', '/resources', [
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'callback' => function () {
            $resources = palestreet_raumanzeige_get_resources();
            $list = [];
            foreach ($resources as $r) {
                $list[] = [
                    'device_id' => isset($r['id']) ? (int) $r['id'] : 0,
                    'room_name' => isset($r['name']) ? $r['name'] : '',
                    'ics_url'   => isset($r['ics_url']) ? $r['ics_url'] : '',
                    'qr_url'    => isset($r['qr_url']) ? $r['qr_url'] : '',
                ];
            }
            return rest_ensure_response($list);
        },
    ]);

    register_rest_route('palestreet-raumanzeige/v1', '/display', [
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'args'                => [
            'device_id' => [
                'default'           => 0,
                'sanitize_callback' => 'absint',
            ],
            'date' => [
                'default'           => '',
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ],
        'callback' => function ($request) {
            $device_id = (int) $request->get_param('device_id');
            $on_date = $request->get_param('date');
            if ($on_date === '') {
                $on_date = null;
            }
            $data = palestreet_raumanzeige_get_display_data($device_id, $on_date);
            if ($data === null) {
                return new WP_Error('not_found', 'Keine Ressource für device_id.', ['status' => 404]);
            }
            // refresh_seconds explizit als Integer für Firmware (Update-Intervall on the fly)
            $data['refresh_seconds'] = (int) (isset($data['refresh_seconds']) ? $data['refresh_seconds'] : 300);
            $data['refresh_seconds'] = max(60, min(7200, $data['refresh_seconds']));
            // wifi_ssid / wifi_pass für Firmware (WLAN on the fly, kein Reflash nötig)
            $data['wifi_ssid'] = isset($data['wifi_ssid']) ? (string) $data['wifi_ssid'] : '';
            $data['wifi_pass'] = isset($data['wifi_pass']) ? (string) $data['wifi_pass'] : '';
            return rest_ensure_response($data);
        },
    ]);
});
