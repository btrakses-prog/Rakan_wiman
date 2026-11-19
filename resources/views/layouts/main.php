<?php
use RakanWiman\Core\Auth;
use function RakanWiman\Core\e;
use function RakanWiman\Core\csrf_field;
use function RakanWiman\Core\flash;
$user = Auth::user();
$requestPath = trim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/');
$appBasePath = trim(parse_url(getenv('APP_URL') ?: '', PHP_URL_PATH) ?: '', '/');
if ($appBasePath !== '' && ($requestPath === $appBasePath || str_starts_with($requestPath, $appBasePath . '/'))) $requestPath = trim(substr($requestPath, strlen($appBasePath)), '/');
$active = static fn(array $prefixes): string => in_array($requestPath, $prefixes, true) ? 'active' : '';
?>
<!doctype html>
<html lang="id"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($title ?? 'Dashboard') ?> · RAKAN WIMAN</title>
<base href="<?= e(rtrim(getenv('APP_URL') ?: '', '/') . '/') ?>">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="assets/app.css" rel="stylesheet">
</head><body>
<div class="app-shell">
<aside class="sidebar">
  <a class="brand text-decoration-none" href="dashboard"><span class="brand-mark">RW</span><span><strong>RAKAN WIMAN</strong><small>NETWORK MANAGEMENT</small></span></a>
  <div class="nav-label">MENU UTAMA</div>
  <a class="nav-link <?= $active(['dashboard']) ?>" href="dashboard"><i class="bi bi-grid-1x2"></i> Dashboard</a>
  <a class="nav-link <?= $active(['routers']) ?>" href="routers"><i class="bi bi-router"></i> Router MikroTik</a>
  <a class="nav-link <?= $active(['hotspot-profiles']) ?>" href="hotspot-profiles"><i class="bi bi-diagram-3"></i> HotSpot Profile</a>
  <a class="nav-link <?= $active(['packages']) ?>" href="packages"><i class="bi bi-box-seam"></i> Paket Internet</a>
  <a class="nav-link <?= $active(['vouchers']) ?>" href="vouchers"><i class="bi bi-ticket-perforated"></i> Voucher</a>
  <a class="nav-link <?= $active(['voucher-template']) ?>" href="voucher-template"><i class="bi bi-palette"></i> Template Voucher</a>
  <div class="nav-label mt-3">PEMBAYARAN & NOTIFIKASI</div>
  <a class="nav-link <?= $active(['qris']) ?>" href="qris"><i class="bi bi-qr-code"></i> QRIS</a>
  <a class="nav-link <?= $active(['whatsapp','whatsapp/config','whatsapp/login']) ?>" href="whatsapp"><i class="bi bi-whatsapp"></i> WhatsApp Gateway</a>
  <div class="nav-label mt-3">ADMINISTRASI</div>
  <a class="nav-link <?= $active(['settings']) ?>" href="settings"><i class="bi bi-gear"></i> Pengaturan</a>
  <div class="sidebar-footer"><span class="small text-white-50">Versi 0.4.21 · RAKAN WIMAN HotSpot Portal</span></div>
</aside>
<main class="main-area">
<header class="topbar">
  <button class="btn btn-light d-lg-none" onclick="document.body.classList.toggle('sidebar-open')"><i class="bi bi-list"></i></button>
  <div><div class="fw-semibold"><?= e($title ?? 'Dashboard') ?></div><div class="small text-secondary">Panel administrasi RAKAN WIMAN</div></div>
  <div class="ms-auto d-flex align-items-center gap-3"><div class="text-end d-none d-sm-block"><div class="small fw-semibold"><?= e($user['name'] ?? '') ?></div><div class="small text-secondary"><?= e($user['role'] ?? '') ?></div></div><form method="post" action="logout"><?= csrf_field() ?><button class="btn btn-outline-secondary btn-sm" title="Keluar"><i class="bi bi-box-arrow-right"></i></button></form></div>
</header>
<section class="content-area">
<?php if ($msg = flash('success')): ?><div class="alert alert-success alert-dismissible fade show" role="alert"><?= e($msg) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
<?php if ($msg = flash('error')): ?><div class="alert alert-danger alert-dismissible fade show" role="alert"><?= e($msg) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
<?= $content ?>
</section></main></div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body></html>
