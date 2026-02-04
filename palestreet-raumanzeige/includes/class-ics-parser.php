<?php
/**
 * Einfacher ICS-Parser für heutige VEVENTs (DTSTART, DTEND, SUMMARY).
 */

if (!defined('ABSPATH')) {
    exit;
}

function palestreet_raumanzeige_fetch_ics_events($ics_url, $on_date = null, $tz = null) {
    if (empty($ics_url)) {
        return [];
    }
    $response = wp_remote_get($ics_url, ['timeout' => 15]);
    if (is_wp_error($response)) {
        return [];
    }
    $body = wp_remote_retrieve_body($response);
    if (empty($body)) {
        return [];
    }
    return palestreet_raumanzeige_parse_ics($body, $on_date, $tz);
}

/**
 * ICS-Zeilen entfalten (RFC 5545: Zeilen die mit LWSP fortgesetzt werden).
 */
function palestreet_raumanzeige_ics_unfold($ics_content) {
    $lines = preg_split('/\r\n|\r|\n/', $ics_content);
    $out = [];
    foreach ($lines as $line) {
        if (isset($out[0]) && (strpos($line, ' ') === 0 || strpos($line, "\t") === 0)) {
            $out[count($out) - 1] .= substr($line, 1);
        } else {
            $out[] = $line;
        }
    }
    return implode("\n", $out);
}

/**
 * @param string      $ics_content ICS-Rohdaten
 * @param string|null $on_date     Optional: Anzeige-Datum im Format Y-m-d (z. B. 2026-01-29). Leer = heute.
 * @param DateTimeZone|null $tz    Optional: Zeitzone für Anzeige (Termine + „heute“). Leer = wp_timezone().
 */
function palestreet_raumanzeige_parse_ics($ics_content, $on_date = null, $tz = null) {
    $ics_content = palestreet_raumanzeige_ics_unfold($ics_content);
    $events = [];
    if ($tz === null) {
        $tz = wp_timezone();
    }
    $now = new DateTime('now', $tz);
    if ($on_date !== null && $on_date !== '') {
        $d = DateTime::createFromFormat('Y-m-d', $on_date, $tz);
        $today = $d ? $d->format('Ymd') : $now->format('Ymd');
    } else {
        $today = $now->format('Ymd');
    }

    $pos = 0;
    while (($begin = strpos($ics_content, 'BEGIN:VEVENT', $pos)) !== false) {
        $end = strpos($ics_content, 'END:VEVENT', $begin);
        if ($end === false) {
            break;
        }
        $block = substr($ics_content, $begin, $end - $begin + strlen('END:VEVENT'));
        $pos = $end + 1;

        $dt_start = '';
        $dt_end = '';
        $summary = '';
        foreach (preg_split('/\r\n|\r|\n/', $block) as $line) {
            if (strpos($line, 'DTSTART') === 0) {
                $dt_start = trim(substr($line, strrpos($line, ':') + 1));
            } elseif (strpos($line, 'DTEND') === 0) {
                $dt_end = trim(substr($line, strrpos($line, ':') + 1));
            } elseif (strpos($line, 'SUMMARY') === 0) {
                $summary = trim(substr($line, strrpos($line, ':') + 1));
            }
        }

        $date_part = $dt_start;
        if (strpos($date_part, ':') !== false) {
            $date_part = substr($date_part, strrpos($date_part, ':') + 1);
        }
        $ev_date = substr($date_part, 0, 8);
        if ($ev_date !== $today) {
            continue;
        }

        $sh = 0;
        $sm = 0;
        $eh = 23;
        $em = 59;
        if (preg_match('/T(\d{2})(\d{2})/', $date_part, $m)) {
            $sh = (int) $m[1];
            $sm = (int) $m[2];
            if (strpos($dt_start, 'Z') !== false) {
                $utc = DateTime::createFromFormat('Ymd\THis\Z', $date_part, new DateTimeZone('UTC'));
                if (!$utc) {
                    $utc = @new DateTime($date_part, new DateTimeZone('UTC'));
                }
                if ($utc) {
                    $utc->setTimezone($tz);
                    $sh = (int) $utc->format('G');
                    $sm = (int) $utc->format('i');
                }
            }
        }
        $end_part = $dt_end;
        if (strpos($end_part, ':') !== false) {
            $end_part = substr($end_part, strrpos($end_part, ':') + 1);
        }
        if (preg_match('/T(\d{2})(\d{2})/', $end_part, $m)) {
            $eh = (int) $m[1];
            $em = (int) $m[2];
            if (strpos($dt_end, 'Z') !== false) {
                $utc = DateTime::createFromFormat('Ymd\THis\Z', $end_part, new DateTimeZone('UTC'));
                if (!$utc) {
                    $utc = @new DateTime($end_part, new DateTimeZone('UTC'));
                }
                if ($utc) {
                    $utc->setTimezone($tz);
                    $eh = (int) $utc->format('G');
                    $em = (int) $utc->format('i');
                }
            }
        }

        $events[] = [
            'start_hour' => $sh,
            'start_min'  => $sm,
            'end_hour'   => $eh,
            'end_min'    => $em,
            'summary'    => $summary,
        ];
    }

    usort($events, function ($a, $b) {
        $t1 = $a['start_hour'] * 60 + $a['start_min'];
        $t2 = $b['start_hour'] * 60 + $b['start_min'];
        return $t1 - $t2;
    });

    return $events;
}

/**
 * @param array       $events          Gefilterte Termine (ein Tag)
 * @param int|null    $now_min_override Optional: virtueller „jetzt“ in Minuten (0–1440). Für Tagesauswahl (date=).
 */
function palestreet_raumanzeige_compute_status($events, $now_min_override = null, $tz = null) {
    if ($tz === null) {
        $tz = wp_timezone();
    }
    $now = new DateTime('now', $tz);
    if ($now_min_override !== null) {
        $now_min = (int) $now_min_override;
    } else {
        $h = (int) $now->format('G');
        $m = (int) $now->format('i');
        $now_min = $h * 60 + $m;
    }

    foreach ($events as $ev) {
        $start = $ev['start_hour'] * 60 + $ev['start_min'];
        $end   = $ev['end_hour'] * 60 + $ev['end_min'];
        if ($now_min >= $start && $now_min < $end) {
            return [
                'occupied' => true,
                'until_h'  => $ev['end_hour'],
                'until_m'  => $ev['end_min'],
            ];
        }
    }

    $next_start = 24 * 60;
    foreach ($events as $ev) {
        $start = $ev['start_hour'] * 60 + $ev['start_min'];
        if ($start > $now_min && $start < $next_start) {
            $next_start = $start;
        }
    }
    $until_h = $next_start < 24 * 60 ? (int) floor($next_start / 60) : 23;
    $until_m = $next_start < 24 * 60 ? $next_start % 60 : 59;

    return [
        'occupied' => false,
        'until_h' => $until_h,
        'until_m' => $until_m,
    ];
}
