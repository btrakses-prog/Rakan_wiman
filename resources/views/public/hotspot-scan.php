<?php use function RakanWiman\Core\e; ?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="referrer" content="origin">
<meta name="theme-color" content="#10213b">
<title><?= e($title ?? 'Scan Voucher') ?> · RAKAN WIMAN</title>
<style>
:root{--navy:#10213b;--blue:#2563eb;--muted:#64748b;--line:#e2e8f0}*{box-sizing:border-box}body{margin:0;background:#f3f6fb;color:#172033;font:15px/1.5 system-ui,-apple-system,Segoe UI,Roboto,sans-serif}.wrap{max-width:560px;margin:24px auto;padding:0 16px}.card{background:#fff;border:1px solid var(--line);border-radius:18px;padding:22px;box-shadow:0 12px 34px #10213b12}.brand{display:flex;align-items:center;gap:11px;margin-bottom:18px}.mark{width:42px;height:42px;border-radius:13px;display:grid;place-items:center;background:linear-gradient(145deg,#3176f4,#153d9e);color:white;font-weight:850}.brand strong{display:block;color:var(--navy)}.brand small{display:block;color:var(--muted);font-size:10px;letter-spacing:.12em}.title{font-size:23px;font-weight:800;margin:0 0 5px;color:var(--navy)}.muted{color:var(--muted)}.camera{width:100%;aspect-ratio:4/3;background:#0b1220;border-radius:14px;overflow:hidden;margin:18px 0 12px;position:relative}.camera video{display:block;width:100%;height:100%;object-fit:cover}.camera:after{content:"";position:absolute;inset:18% 12%;border:2px solid #60a5fa;border-radius:16px;box-shadow:0 0 0 999px #0003;pointer-events:none}.actions{display:flex;gap:9px;flex-wrap:wrap}.btn{border:0;border-radius:10px;padding:12px 16px;font:inherit;font-weight:750;cursor:pointer}.primary{background:var(--blue);color:white}.secondary{background:#eff6ff;color:#1d4ed8}.btn:disabled{opacity:.5;cursor:not-allowed}.status{padding:11px 13px;border-radius:10px;background:#eff6ff;color:#1e40af;margin:12px 0;font-size:13px}.status.error{background:#fff1f2;color:#9f1239}.status.success{background:#dcfce7;color:#166534}.result{margin-top:13px;border:1px solid var(--line);border-radius:12px;padding:13px;background:#f8fafc}.result code{word-break:break-all}.footer{margin:16px 0 0;text-align:center;color:#94a3b8;font-size:11px}
</style>
</head>
<body>
<div class="wrap">
  <section class="card">
    <div class="brand"><div class="mark">RW</div><div><strong>RAKAN WIMAN</strong><small>HOTSPOT VOUCHER SCANNER</small></div></div>
    <h1 class="title">Scan QR Voucher</h1>
    <p class="muted">Izinkan akses kamera, lalu arahkan kamera belakang ke QR pada kartu voucher. Kredensial akan dikirim kembali ke halaman login HotSpot.</p>
    <div class="camera"><video id="rw-scan-video" autoplay muted playsinline></video></div>
    <div class="actions">
      <button class="btn primary" id="rw-start-camera" type="button">Aktifkan Kamera</button>
      <button class="btn secondary" id="rw-stop-camera" type="button" disabled>Matikan Kamera</button>
    </div>
    <div class="status" id="rw-scan-status" role="status">Pemindai menggunakan kamera browser pada halaman HTTPS ini.</div>
    <div class="result" id="rw-scan-result" hidden></div>
  </section>
  <p class="footer">Kredensial QR diproses di perangkat dan tidak dikirim ke server RAKAN WIMAN.</p>
</div>
<script>
(function () {
  'use strict';
  var video = document.getElementById('rw-scan-video');
  var startButton = document.getElementById('rw-start-camera');
  var stopButton = document.getElementById('rw-stop-camera');
  var statusBox = document.getElementById('rw-scan-status');
  var resultBox = document.getElementById('rw-scan-result');
  var params = new URLSearchParams(window.location.search);
  var returnOrigin = params.get('return_origin') || '';
  var stream = null;
  var detector = null;
  var running = false;
  var scanTimer = null;
  var delivered = false;

  function setStatus(message, type) {
    statusBox.textContent = message;
    statusBox.className = 'status' + (type ? ' ' + type : '');
  }

  function validReturnContext() {
    if (!returnOrigin || !document.referrer) return false;
    try {
      var target = new URL(returnOrigin);
      var referrer = new URL(document.referrer);
      return target.origin === referrer.origin && (target.protocol === 'http:' || target.protocol === 'https:');
    } catch (error) { return false; }
  }

  function parseVoucher(raw) {
    var value = String(raw || '').trim();
    if (!value) return null;
    if (value.indexOf('RWV1|') === 0) {
      var parts = value.split('|');
      if (!parts[1]) return null;
      return { username: parts[1], password: parts[2] || parts[1] };
    }
    var separator = value.indexOf(':');
    if (separator > 0) {
      return { username: value.slice(0, separator).trim(), password: value.slice(separator + 1).trim() };
    }
    // Username = Password vouchers store only their username in the QR.
    return { username: value, password: value };
  }

  function stopCamera() {
    running = false;
    if (scanTimer) { clearTimeout(scanTimer); scanTimer = null; }
    if (stream) {
      stream.getTracks().forEach(function (track) { track.stop(); });
      stream = null;
    }
    video.srcObject = null;
    startButton.disabled = false;
    stopButton.disabled = true;
  }

  function deliver(raw) {
    if (delivered) return;
    var voucher = parseVoucher(raw);
    if (!voucher || !voucher.username || !voucher.password) {
      setStatus('QR terbaca, tetapi format voucher tidak dikenali.', 'error');
      return;
    }
    delivered = true;
    stopCamera();
    if (validReturnContext() && window.opener && !window.opener.closed) {
      try {
        window.opener.postMessage({ type: 'RW_HOTSPOT_VOUCHER', username: voucher.username, password: voucher.password }, returnOrigin);
        setStatus('Voucher berhasil dibaca. Mengirim kredensial ke halaman login HotSpot…', 'success');
        resultBox.hidden = true;
        window.setTimeout(function () { window.close(); }, 800);
        return;
      } catch (error) { /* Show local fallback below if the browser refuses the handoff. */ }
    }
    resultBox.hidden = false;
    resultBox.innerHTML = '<strong>Voucher berhasil dibaca</strong><p>Username: <code></code></p><p>Password: <code></code></p><p class="muted">Pengisian otomatis tidak tersedia karena halaman login asal tidak terverifikasi. Buka pemindai melalui tombol Scan QR pada halaman login HotSpot.</p>';
    var codes = resultBox.querySelectorAll('code');
    codes[0].textContent = voucher.username;
    codes[1].textContent = voucher.password;
    setStatus('QR berhasil dibaca.', 'success');
  }

  async function scanLoop() {
    if (!running || delivered) return;
    try {
      if (video.readyState >= 2) {
        var codes = await detector.detect(video);
        if (codes && codes.length && codes[0].rawValue) {
          deliver(codes[0].rawValue);
          return;
        }
      }
    } catch (error) {
      setStatus('Kamera aktif, tetapi QR belum terbaca. Dekatkan kartu dan jaga agar QR berada di dalam bingkai.', '');
    }
    if (running && !delivered) scanTimer = window.setTimeout(scanLoop, 180);
  }

  startButton.addEventListener('click', async function () {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      setStatus('Browser tidak mengizinkan kamera. Pastikan halaman pemindai dibuka melalui HTTPS dan gunakan browser terbaru.', 'error');
      return;
    }
    if (!('BarcodeDetector' in window)) {
      setStatus('Browser ini belum mendukung pemindaian QR kamera. Gunakan Chrome atau Edge versi terbaru pada Android.', 'error');
      return;
    }
    try {
      detector = new BarcodeDetector({ formats: ['qr_code'] });
      stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } }, audio: false });
      video.srcObject = stream;
      await video.play();
      running = true;
      delivered = false;
      startButton.disabled = true;
      stopButton.disabled = false;
      setStatus('Kamera aktif. Arahkan QR ke dalam bingkai.', 'success');
      scanLoop();
    } catch (error) {
      stopCamera();
      var message = error && error.name === 'NotAllowedError' ? 'Izin kamera ditolak. Izinkan kamera untuk domain RAKAN WIMAN pada pengaturan browser.' : 'Kamera tidak dapat dibuka: ' + (error && error.message ? error.message : 'kesalahan tidak diketahui');
      setStatus(message, 'error');
    }
  });
  stopButton.addEventListener('click', function () { stopCamera(); setStatus('Kamera dimatikan. Tekan Aktifkan Kamera untuk mencoba lagi.'); });
  window.addEventListener('pagehide', stopCamera);
})();
</script>
</body>
</html>
