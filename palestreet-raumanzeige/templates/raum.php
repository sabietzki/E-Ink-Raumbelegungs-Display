<?php
/**
 * Template: Raum
 * Anzeige-Layout für /raumanzeige-display/ (wie E-Paper-Schild: API-Update im Intervall).
 * Variablen: $data, $status_label, $status_class, $next_events, $update_interval_label, $qr_url, $refresh_sec, $display_time, $next_day_icon_url
 * Optional für Inline: $inline (true = nur Display-Block, kein html/body), $inline_device_id
 */
if (!defined('ABSPATH')) {
    exit;
}
$inline = !empty($inline);
$inline_device_id = isset($inline_device_id) ? (int) $inline_device_id : 0;
$room_name = isset($data['room_name']) ? $data['room_name'] : '';
$display_time = isset($data['display_time']) ? $data['display_time'] : '';
$next_day_icon_url = isset($next_day_icon_url) ? $next_day_icon_url : '';
$first_next_day_idx = -1;
for ($i = 0; $i < 3; $i++) {
    if (isset($next_events[$i]) && !empty($next_events[$i]['is_next_day'])) {
        $first_next_day_idx = $i;
        break;
    }
}
$event_col_width = 170;
$event_xs = [35, 245, 455];
$line_left = 10;
if ($first_next_day_idx >= 0) {
    if ($first_next_day_idx > 0) {
        $line_left = (int) (($event_xs[$first_next_day_idx - 1] + $event_col_width + $event_xs[$first_next_day_idx]) / 2 - 1);
    }
}
?>
<?php if (!$inline) : ?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=800, height=480, initial-scale=0.5">
  <title><?php echo esc_html($room_name ?: 'Raumanzeige'); ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<?php else : ?>
<div class="palestreet-raumanzeige-inline" style="display:block;max-width:800px;width:100%;overflow:auto;background:#000;padding:0;font-family:'Inter',sans-serif;">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<?php endif; ?>
  <style>
    <?php if (!$inline) : ?>body { margin: 0; padding: 0; background: #000; font-family: 'Inter', sans-serif; display: flex; justify-content: center; align-items: center; min-height: 100vh; letter-spacing: .5px; }
    <?php endif; ?>.palestreet-raumanzeige-inline .display,
    .display { width: 800px; height: 480px; background: #fff; color: #000; position: relative; }
    .status-label { position: absolute; left: 35px; width: 590px; top: 136px; font-size: 52px; text-align: center; text-transform: uppercase; font-weight: 700; }
    .status-until { position: absolute; left: 35px; width: 590px; top: 206px; font-size: 52px; text-align: center; text-transform: uppercase; font-weight: 700; }
    .event { position: absolute; bottom: 25px; font-size: 18px; text-align: center; width: 170px; font-weight: 600; }
    .event-1 { left: 35px; } .event-2 { left: 245px; } .event-3 { left: 455px; }
    .event-time { font-size: 18px; color: #333; font-weight: 700; } .event-name { font-weight: 400; margin-top: 4px; text-transform: uppercase; }
    .next-day-indicator { position: absolute; top: 405px; left: 10px; width: 2px; height: 70px; background: #000; pointer-events: none; }
    .next-day-indicator.hidden { display: none; }
    .next-day-indicator .next-day-icon { position: absolute; left: 50%; bottom: 100%; margin-left: -15px; margin-bottom: 5px; width: 30px; height: 30px; object-fit: contain; max-width:35px;}
    .update-interval { position: absolute; right: 25px; top: 25px; font-size: 12px; text-align: right; line-height: 1.4; font-weight: 500; }
    .buchen-label { position: absolute; right: 25px; bottom: 145px; width: 110px; font-size: 14px; text-align: center; font-weight: 500; }
    .qr-box { position: absolute; right: 25px; bottom: 25px; width: 110px; height: 110px; display: flex; align-items: center; justify-content: center; overflow: hidden; }
    .qr-box img { max-width: 100%; max-height: 100%; object-fit: contain; }
  </style>
<?php if (!$inline) : ?>
</head>
<body>
<?php endif; ?>
  <div class="display">
    <div id="ra-status-label" class="status-label <?php echo esc_attr($status_class); ?>"><?php echo esc_html($status_label); ?></div>
    <div id="ra-status-until" class="status-until"><?php echo esc_html($status_until); ?></div>
    <div id="ra-next-day-indicator" class="next-day-indicator <?php echo $first_next_day_idx < 0 ? 'hidden' : ''; ?>" style="<?php echo $first_next_day_idx >= 0 ? 'left: ' . (int) $line_left . 'px;' : ''; ?>"><?php if ($next_day_icon_url) { ?><img class="next-day-icon" src="<?php echo esc_url($next_day_icon_url); ?>" alt="" width="30" height="30" /><?php } ?></div>
    <?php for ($i = 0; $i < 3; $i++) : $ev = isset($next_events[$i]) ? $next_events[$i] : null; ?>
    <div class="event event-<?php echo $i + 1; ?>" data-is-next-day="<?php echo ($ev && !empty($ev['is_next_day'])) ? '1' : '0'; ?>">
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
      $qr_data_url = !empty($qr_url) ? $qr_url : (isset($display_url) ? $display_url : '');
      if ($qr_data_url) {
        $qr_img_src = 'https://api.qrserver.com/v1/create-qr-code/?' . http_build_query(['size' => '110x110', 'data' => $qr_data_url]);
        echo '<img id="ra-qr-img" src="' . esc_url($qr_img_src) . '" alt="QR" width="110" height="110" />';
      } else {
        echo '<span>QR</span>';
      }
    ?></div>
  </div>
  <script>
(function(){
  var deviceId = <?php if ($inline) { echo (int) $inline_device_id; } else { echo "(function(){ var m = /device_id=(\\d+)/.exec(location.search); return m ? m[1] : '0'; })()"; } ?>;
  var dateParam = (function(){ var m = /date=([^&]+)/.exec(location.search); return m ? decodeURIComponent(m[1].replace(/\+/g,' ')) : ''; })();
  var apiUrl = '<?php echo esc_js(rest_url('palestreet-raumanzeige/v1/display')); ?>?device_id=' + deviceId + (dateParam ? '&date=' + encodeURIComponent(dateParam) : '');
  var displayUrlForQr = '<?php echo esc_js(isset($display_url) ? $display_url : ''); ?>';
  var refreshMs = <?php echo (int) $refresh_sec; ?> * 1000;
  var lastSuccess = Date.now();
  var maxStaleMs = Math.max(10 * 60 * 1000, refreshMs * 5);

  function setQr(dataUrl) {
    var box = document.getElementById('ra-qr-box');
    if (!box) return;
    var urlToEncode = dataUrl || displayUrlForQr || '';
    if (urlToEncode) {
      var qrImgSrc = 'https://api.qrserver.com/v1/create-qr-code/?size=110x110&data=' + encodeURIComponent(urlToEncode);
      var img = box.querySelector('img');
      if (img) { img.src = qrImgSrc; } else {
        box.innerHTML = '';
        var i = document.createElement('img');
        i.id = 'ra-qr-img';
        i.alt = 'QR';
        i.width = 110;
        i.height = 110;
        i.src = qrImgSrc;
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
        ev.setAttribute('data-is-next-day', (e && e.is_next_day) ? '1' : '0');
      }
      var evList = d.events || [];
      var firstNextDayIdx = -1;
      for (var j = 0; j < 3; j++) {
        if (evList[j] && evList[j].is_next_day) { firstNextDayIdx = j; break; }
      }
      var ind = document.getElementById('ra-next-day-indicator');
      if (ind) {
        if (firstNextDayIdx < 0) {
          ind.classList.add('hidden');
        } else {
          ind.classList.remove('hidden');
          var eventXs = [35, 245, 455];
          var colWidth = 170;
          var lineLeft = firstNextDayIdx === 0 ? 10 : (eventXs[firstNextDayIdx - 1] + colWidth + eventXs[firstNextDayIdx]) / 2 - 1;
          ind.style.left = lineLeft + 'px';
        }
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
<?php if (!$inline) : ?>
</body>
</html>
<?php else : ?>
</div>
<?php endif; ?>
