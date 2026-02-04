<?php
/**
 * Template: Raum
 * Anzeige-Layout für /raumanzeige-display/ (wie E-Paper-Schild: API-Update im Intervall).
 * Variablen: $data, $status_label, $status_class, $next_events, $update_interval_label, $qr_url, $refresh_sec, $display_time
 */
if (!defined('ABSPATH')) {
    exit;
}
$room_name = isset($data['room_name']) ? $data['room_name'] : '';
$display_time = isset($data['display_time']) ? $data['display_time'] : '';
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=800, height=480, initial-scale=0.5">
  <title><?php echo esc_html($room_name ?: 'Raumanzeige'); ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    * { box-sizing: border-box; }
    body { margin: 0; padding: 0; background: #000; font-family: 'Inter', sans-serif; display: flex; justify-content: center; align-items: center; min-height: 100vh; letter-spacing: .5px; }
    .display { width: 800px; height: 480px; background: #fff; color: #000; position: relative; }
    .status-label { position: absolute; left: 35px; width: 590px; top: 136px; font-size: 52px; text-align: center; text-transform: uppercase; font-weight: 700; }
    .status-until { position: absolute; left: 35px; width: 590px; top: 206px; font-size: 52px; text-align: center; text-transform: uppercase; font-weight: 700; }
    .event { position: absolute; bottom: 25px; font-size: 18px; text-align: center; width: 170px; font-weight: 600; }
    .event-1 { left: 35px; } .event-2 { left: 245px; } .event-3 { left: 455px; }
    .event-time { font-size: 18px; color: #333; font-weight: 700; } .event-name { font-weight: 400; margin-top: 4px; text-transform: uppercase; }
    .update-interval { position: absolute; right: 25px; top: 25px; font-size: 12px; text-align: right; line-height: 1.4; font-weight: 500; }
    .buchen-label { position: absolute; right: 25px; bottom: 130px; width: 110px; font-size: 14px; text-align: center; font-weight: 500; }
    .qr-box { position: absolute; right: 25px; bottom: 25px; width: 110px; height: 110px; display: flex; align-items: center; justify-content: center; overflow: hidden; }
    .qr-box img { max-width: 100%; max-height: 100%; object-fit: contain; }
  </style>
</head>
<body>
  <div class="display">
    <div id="ra-status-label" class="status-label <?php echo esc_attr($status_class); ?>"><?php echo esc_html($status_label); ?></div>
    <div id="ra-status-until" class="status-until"><?php echo esc_html($status_until); ?></div>
    <?php for ($i = 0; $i < 3; $i++) : $ev = isset($next_events[$i]) ? $next_events[$i] : null; ?>
    <div class="event event-<?php echo $i + 1; ?>">
      <div class="event-time"><?php echo $ev ? esc_html($ev['time']) : ''; ?></div>
      <div class="event-name"><?php echo $ev ? esc_html($ev['summary']) : ''; ?></div>
    </div>
    <?php endfor; ?>
    <div id="ra-update-interval" class="update-interval">
      <span id="ra-display-time"><?php echo esc_html($display_time); ?></span><br>
      <span id="ra-update-label"><?php echo esc_html($update_interval_label); ?></span>
    </div>
    <div class="buchen-label">BUCHEN</div>
    <div id="ra-qr-box" class="qr-box"><?php
      if ($qr_url) {
        echo '<img id="ra-qr-img" src="' . esc_url($qr_url) . '" alt="QR" />';
      } elseif (!empty($display_url)) {
        echo '<img id="ra-qr-preview" src="https://api.qrserver.com/v1/create-qr-code/?' . esc_attr(http_build_query(['size' => '110x110', 'data' => $display_url])) . '" alt="QR Vorschau" width="110" height="110" />';
      } else {
        echo '<span>QR</span>';
      }
    ?></div>
  </div>
  <script>
(function(){
  var deviceId = (function(){ var m = /device_id=(\d+)/.exec(location.search); return m ? m[1] : '0'; })();
  var dateParam = (function(){ var m = /date=([^&]+)/.exec(location.search); return m ? decodeURIComponent(m[1].replace(/\+/g,' ')) : ''; })();
  var apiUrl = '<?php echo esc_js(rest_url('palestreet-raumanzeige/v1/display')); ?>?device_id=' + deviceId + (dateParam ? '&date=' + encodeURIComponent(dateParam) : '');
  var refreshMs = <?php echo (int) $refresh_sec; ?> * 1000;
  var lastSuccess = Date.now();
  var maxStaleMs = Math.max(10 * 60 * 1000, refreshMs * 5);

  function setQr(url) {
    var box = document.getElementById('ra-qr-box');
    if (!box) return;
    if (url) {
      var img = box.querySelector('img');
      if (img) { img.src = url; } else {
        box.innerHTML = '';
        var i = document.createElement('img');
        i.id = 'ra-qr-img';
        i.alt = 'QR';
        i.src = url;
        box.appendChild(i);
      }
    } else { box.innerHTML = '<span>QR</span>'; }
  }

  function update() {
    fetch(apiUrl).then(function(r){ return r.ok ? r.json() : Promise.reject(); }).then(function(d){
      var sl = document.getElementById('ra-status-label');
      var su = document.getElementById('ra-status-until');
      if (sl) { sl.textContent = d.status_label; sl.classList.toggle('occupied', d.occupied); }
      if (su) su.textContent = d.status_until;
      var dt = document.getElementById('ra-display-time');
      if (dt) dt.textContent = d.display_time || '';
      var lbl = document.getElementById('ra-update-label');
      if (lbl && d.update_interval_label) lbl.textContent = d.update_interval_label;
      if (d.qr_url !== undefined) setQr(d.qr_url || '');
      var events = document.querySelectorAll('.event');
      for (var i = 0; i < 3; i++) {
        var ev = events[i];
        if (!ev) continue;
        var timeEl = ev.querySelector('.event-time');
        var nameEl = ev.querySelector('.event-name');
        var e = d.events && d.events[i];
        if (timeEl) timeEl.textContent = e ? e.time : '–';
        if (nameEl) nameEl.textContent = e ? e.summary : '–';
      }
      if (d.refresh_seconds) refreshMs = d.refresh_seconds * 1000;
      lastSuccess = Date.now();
    }).catch(function(){});
    setTimeout(update, refreshMs);
  }

  setInterval(function(){
    if (Date.now() - lastSuccess > maxStaleMs) location.reload();
  }, 30000);

  setTimeout(update, refreshMs);
})();
  </script>
</body>
</html>
