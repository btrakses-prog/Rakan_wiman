<?php
use function RakanWiman\Core\e;
$actionLabels = [
    'router.created' => 'Router ditambahkan',
    'router.updated' => 'Router diperbarui',
    'router.tested' => 'Uji koneksi router',
    'voucher.bulk_generated' => 'Voucher dibuat',
    'voucher.template_updated' => 'Template voucher diperbarui',
    'qris.settings_updated' => 'Pengaturan QRIS diperbarui',
    'whatsapp.settings_updated' => 'WhatsApp Gateway diperbarui',
    'admin.profile_updated' => 'Profil administrator diperbarui',
    'admin.created' => 'Akun administrator dibuat',
    'admin.status_changed' => 'Status akun administrator diubah',
];
$logIcon = static function (string $entity): string {
    return match ($entity) {
        'router' => 'bi-router',
        'voucher_batch', 'voucher' => 'bi-ticket-perforated',
        'qris', 'payment' => 'bi-qr-code',
        'whatsapp' => 'bi-whatsapp',
        'admin' => 'bi-person-gear',
        default => 'bi-activity',
    };
};
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
  <div><h4 class="fw-bold mb-1">Ringkasan Sistem</h4><p class="text-secondary mb-0">Selamat datang di pusat kendali RAKAN WIMAN.</p></div>
</div>
<?php
$activeCardValue = ($hotspotMonitorError !== null || ($hotspotLive['router_total'] > 0 && $hotspotLive['router_ok'] === 0)) ? '—' : number_format((int)$stats['active_users']);
$activeCardNote = $hotspotMonitorError !== null
    ? 'Gagal mengambil data live'
    : ($hotspotLive['router_total'] === 0
    ? 'Belum ada router terdaftar'
    : ($hotspotLive['complete']
        ? 'Live · ' . $hotspotLive['router_ok'] . '/' . $hotspotLive['router_total'] . ' router terbaca'
        : 'Data sebagian · ' . $hotspotLive['router_ok'] . '/' . $hotspotLive['router_total'] . ' router terbaca'));
$statCards = [
    ['Router Aktif', $stats['routers'], 'bi-router', null],
    ['Paket Aktif', $stats['packages'], 'bi-box-seam', null],
    ['Total Voucher', $stats['vouchers'], 'bi-ticket-perforated', null],
    ['Pembayaran Lunas', $stats['payments'], 'bi-qr-code', null],
    ['User Hotspot Aktif', $activeCardValue, 'bi-people-fill', $activeCardNote],
];
$formatTraffic = static function (int $bytes): string {
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
};
?>
<div class="row row-cols-2 row-cols-md-3 row-cols-xl-5 g-3 mb-4">
<?php foreach ($statCards as [$label,$value,$icon,$note]): ?>
  <div class="col"><div class="stat-card h-100"><div class="d-flex justify-content-between gap-2"><span class="text-secondary small"><?= e($label) ?></span><i class="bi <?= e($icon) ?> text-primary fs-5"></i></div><div class="display-6 fw-bold mt-2"><?= is_numeric($value) ? number_format((int)$value) : e((string)$value) ?></div><?php if ($note !== null): ?><div class="small text-secondary mt-1"><?= e($note) ?></div><?php endif; ?></div></div>
<?php endforeach; ?>
</div>

<div class="panel-card mb-3" id="hotspot-live">
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
    <div><h6 class="fw-bold mb-1"><i class="bi bi-wifi me-2 text-primary"></i>User Hotspot Aktif</h6><div class="small text-secondary">Sesi aktif dibaca langsung dari MikroTik · diperiksa <?= e(date('d/m/Y H:i:s', strtotime($hotspotLive['checked_at']))) ?></div></div>
    <div class="d-flex align-items-center gap-2"><span class="badge <?= $hotspotLive['complete'] ? 'text-bg-success' : 'text-bg-warning' ?>"><?= $hotspotLive['complete'] ? 'Data lengkap' : 'Data sebagian' ?></span><a href="dashboard#hotspot-live" class="btn btn-sm btn-outline-primary"><i class="bi bi-arrow-clockwise me-1"></i>Perbarui</a></div>
  </div>
  <?php if ($hotspotMonitorError): ?>
    <div class="alert alert-danger mb-0">Gagal mengambil data HotSpot aktif: <?= e($hotspotMonitorError) ?></div>
  <?php elseif (!$hotspotLive['routers']): ?>
    <div class="text-secondary small">Belum ada router aktif yang terdaftar.</div>
  <?php else: ?>
    <div class="table-responsive"><table class="table table-sm align-middle mb-2"><thead><tr><th>Router</th><th>Host</th><th>User aktif</th><th>Status pembacaan</th></tr></thead><tbody>
      <?php foreach ($hotspotLive['routers'] as $liveRouter): ?>
        <tr><td class="fw-semibold"><?= e($liveRouter['name']) ?></td><td><?= e($liveRouter['host']) ?></td><td class="fw-bold"><?= $liveRouter['ok'] ? number_format((int)$liveRouter['count']) : '—' ?></td><td><span class="badge <?= $liveRouter['ok'] ? 'text-bg-success' : 'text-bg-danger' ?>"><?= e($liveRouter['status']) ?></span><?php if (!$liveRouter['ok'] && $liveRouter['error']): ?><div class="small text-danger mt-1"><?= e($liveRouter['error']) ?></div><?php endif; ?></td></tr>
      <?php endforeach; ?>
    </tbody></table></div>
    <?php if ($hotspotLive['sessions']): ?>
      <details class="mt-2">
        <summary class="small fw-semibold text-primary" style="cursor:pointer">Lihat rincian sesi aktif (<?= number_format(count($hotspotLive['sessions'])) ?>)</summary>
        <div class="table-responsive mt-2"><table class="table table-sm table-hover align-middle mb-0"><thead><tr><th>Router</th><th>Username</th><th>IP Address</th><th>MAC Address</th><th>Server</th><th>Uptime</th><th>Upload</th><th>Download</th></tr></thead><tbody>
          <?php foreach (array_slice($hotspotLive['sessions'], 0, 50) as $session): ?>
            <tr><td><?= e($session['router_name']) ?></td><td class="fw-semibold"><?= e($session['user']) ?></td><td><?= e($session['address']) ?></td><td><small><?= e($session['mac_address']) ?></small></td><td><?= e($session['server']) ?></td><td><?= e($session['uptime']) ?></td><td><?= e($formatTraffic((int)$session['bytes_in'])) ?></td><td><?= e($formatTraffic((int)$session['bytes_out'])) ?></td></tr>
          <?php endforeach; ?>
        </tbody></table></div>
        <?php if (count($hotspotLive['sessions']) > 50): ?><div class="small text-secondary mt-2">Menampilkan 50 dari <?= number_format(count($hotspotLive['sessions'])) ?> sesi aktif.</div><?php endif; ?>
      </details>
    <?php else: ?>
      <div class="small text-secondary">Tidak ada sesi HotSpot aktif pada router yang berhasil dibaca.</div>
    <?php endif; ?>
    <?php if (!$hotspotLive['complete']): ?><div class="small text-warning mt-2">Catatan: total User Aktif hanya menjumlah router yang berhasil dibaca. Router yang gagal dihubungi tidak dihitung.</div><?php endif; ?>
  <?php endif; ?>
</div>

<div class="row g-3">
  <div class="col-xl-7">
    <div class="panel-card h-100">
      <div class="d-flex justify-content-between align-items-center mb-3"><h6 class="fw-bold mb-0">Router Terdaftar</h6><a href="routers" class="btn btn-sm btn-outline-primary">Kelola Router</a></div>
      <?php if (!$recentRouters): ?>
        <div class="empty-state"><i class="bi bi-router"></i><p class="fw-semibold mb-1">Belum ada router</p><p class="small text-secondary">Tambahkan router MikroTik pertama untuk memulai.</p><a class="btn btn-primary btn-sm" href="routers">Tambah Router</a></div>
      <?php else: ?>
        <div class="table-responsive"><table class="table align-middle"><thead><tr><th>Nama</th><th>Host</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($recentRouters as $router): ?>
          <tr><td class="fw-semibold"><?= e($router['name']) ?></td><td><?= e($router['host']) ?></td><td><span class="badge <?= $router['connection_status'] === 'online' ? 'text-bg-success' : ($router['connection_status'] === 'offline' ? 'text-bg-danger' : 'text-bg-secondary') ?>"><?= e($router['connection_status']) ?></span></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-xl-5">
    <div class="panel-card h-100">
      <div class="d-flex align-items-center justify-content-between gap-2 mb-3"><h6 class="fw-bold mb-0"><i class="bi bi-journal-text me-2 text-primary"></i>Logs</h6><span class="badge text-bg-light border"><?= count($recentLogs) ?> terbaru</span></div>
      <?php if (!$recentLogs): ?>
        <div class="empty-state py-4"><i class="bi bi-journal-check"></i><p class="fw-semibold mb-1">Belum ada aktivitas</p><p class="small text-secondary mb-0">Aktivitas administrator dan perubahan sistem akan muncul di sini.</p></div>
      <?php else: ?>
        <div class="rw-dashboard-logs">
        <?php foreach ($recentLogs as $log):
          $action = (string)($log['action'] ?? '');
          $label = $actionLabels[$action] ?? ucwords(str_replace(['.','_'], ' ', $action));
          $description = trim((string)($log['description'] ?? ''));
          $time = !empty($log['created_at']) ? date('d/m/Y H:i', strtotime((string)$log['created_at'])) : '-';
        ?>
          <div class="rw-log-item">
            <span class="rw-log-icon"><i class="bi <?= e($logIcon((string)($log['entity_type'] ?? ''))) ?>"></i></span>
            <div class="min-w-0 flex-grow-1">
              <div class="d-flex justify-content-between align-items-start gap-2"><strong class="small"><?= e($label) ?></strong><time class="text-secondary rw-log-time"><?= e($time) ?></time></div>
              <?php if ($description !== ''): ?><div class="small text-secondary rw-log-description"><?= e($description) ?></div><?php endif; ?>
              <div class="rw-log-meta"><?= e((string)$log['actor']) ?><?php if (!empty($log['ip_address'])): ?> · <?= e((string)$log['ip_address']) ?><?php endif; ?></div>
            </div>
          </div>
        <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<style>
.rw-dashboard-logs{max-height:560px;overflow:auto;padding-right:3px}.rw-log-item{display:flex;gap:10px;padding:11px 0;border-bottom:1px solid var(--line,#e8edf4)}.rw-log-item:last-child{border-bottom:0}.rw-log-icon{width:30px;height:30px;flex:0 0 30px;display:grid;place-items:center;border-radius:9px;background:var(--bs-primary-bg-subtle,#eaf2ff);color:var(--bs-primary,#0d6efd)}.rw-log-time{font-size:10px;white-space:nowrap}.rw-log-description{margin-top:3px;overflow-wrap:anywhere}.rw-log-meta{font-size:10px;color:var(--muted,#64748b);margin-top:4px}
</style>
