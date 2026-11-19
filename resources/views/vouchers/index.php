<?php
use function RakanWiman\Core\csrf_field;
use function RakanWiman\Core\e;
$old = static fn(string $key, mixed $default = ''): mixed => $formValues[$key] ?? $default;
?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
  <div><h4 class="fw-bold mb-1">Generate User</h4><p class="text-secondary mb-0">Buat voucher bulk dengan pengaturan kredensial dan tujuan HotSpot.</p></div>
  <span class="badge text-bg-light border">Voucher Bulk · Maks. 500 sekali generate</span>
</div>
<?php if (!empty($error)): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
<div class="panel-card mb-3">
  <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <a class="btn btn-warning" href="dashboard"><i class="bi bi-x-lg me-1"></i> Close</a>
    <a class="btn btn-danger" href="#voucher-history"><i class="bi bi-people-fill me-1"></i> User List</a>
    <button class="btn btn-info text-white" type="submit" form="voucher-generator"><i class="bi bi-floppy me-1"></i> Generate</button>
    <span class="small text-secondary ms-md-2">Voucher baru akan disimpan dahulu ke database, lalu masuk antrean sinkronisasi router.</span>
  </div>
  <form id="voucher-generator" method="post" action="vouchers">
    <?= csrf_field() ?>
    <input type="hidden" name="request_key" value="<?= e($batchToken) ?>">
    <div class="voucher-generator-form">
      <div class="row align-items-center g-2 generator-row"><label class="col-md-4 col-lg-3 col-form-label" for="qty">Qty</label><div class="col-md-8 col-lg-9"><input class="form-control" id="qty" type="number" name="quantity" min="1" max="500" value="<?= e((string)$old('quantity', 10)) ?>" required></div></div>
      <div class="row align-items-center g-2 generator-row"><label class="col-md-4 col-lg-3 col-form-label" for="router">Router</label><div class="col-md-8 col-lg-9"><select class="form-select" id="router" name="router_id" required><option value="">Pilih router tujuan...</option><?php foreach ($routers as $r): ?><option value="<?= (int)$r['id'] ?>" <?= (string)$old('router_id') === (string)$r['id'] ? 'selected' : '' ?>><?= e($r['name']) ?> · <?= e($r['host']) ?></option><?php endforeach; ?></select><?php if (!$routers): ?><div class="form-text text-danger">Belum ada router aktif. Tambahkan router terlebih dahulu.</div><?php endif; ?></div></div>
      <div class="row align-items-center g-2 generator-row"><label class="col-md-4 col-lg-3 col-form-label" for="hotspot-server-mode">Server</label><div class="col-md-8 col-lg-9"><div class="row g-2"><div class="col-sm-5"><select class="form-select" id="hotspot-server-mode" name="hotspot_server_mode"><option value="all" <?= $old('hotspot_server_mode', 'all') === 'all' ? 'selected' : '' ?>>all</option><option value="custom" <?= $old('hotspot_server_mode') === 'custom' ? 'selected' : '' ?>>Server tertentu</option></select></div><div class="col-sm-7"><input class="form-control" id="hotspot-server-custom" name="hotspot_server_custom" maxlength="120" placeholder="Nama Hotspot Server, contoh hotspot1" value="<?= e((string)$old('hotspot_server_custom')) ?>" <?= $old('hotspot_server_mode') === 'custom' ? 'required' : 'disabled' ?>></div></div><div class="form-text">all berarti voucher dapat dipakai di semua Hotspot Server pada router yang dipilih.</div></div></div>
      <div class="row align-items-center g-2 generator-row"><label class="col-md-4 col-lg-3 col-form-label" for="user-mode">User Mode</label><div class="col-md-8 col-lg-9"><select class="form-select" id="user-mode" name="user_mode"><option value="separate" <?= $old('user_mode', 'separate') === 'separate' ? 'selected' : '' ?>>Username &amp; Password</option><option value="same" <?= $old('user_mode') === 'same' ? 'selected' : '' ?>>Username = Password</option></select></div></div>
      <div class="row align-items-center g-2 generator-row"><label class="col-md-4 col-lg-3 col-form-label" for="name-length">Name Length</label><div class="col-md-8 col-lg-9"><select class="form-select" id="name-length" name="name_length"><?php foreach ([4,3,5,6,7,8] as $n): ?><option value="<?= $n ?>" <?= (string)$old('name_length', 4) === (string)$n ? 'selected' : '' ?>><?= $n ?></option><?php endforeach; ?></select></div></div>
      <div class="row align-items-center g-2 generator-row"><label class="col-md-4 col-lg-3 col-form-label" for="prefix">Prefix</label><div class="col-md-8 col-lg-9"><input class="form-control" id="prefix" name="prefix" maxlength="24" value="<?= e((string)$old('prefix', '')) ?>" placeholder="Contoh: RW (boleh dikosongkan)"></div></div>
      <div class="row align-items-center g-2 generator-row"><label class="col-md-4 col-lg-3 col-form-label" for="character-set">Character</label><div class="col-md-8 col-lg-9"><select class="form-select" id="character-set" name="character_set">
        <option value="lower" <?= $old('character_set') === 'lower' ? 'selected' : '' ?>>Random abcd</option>
        <option value="upper" <?= $old('character_set') === 'upper' ? 'selected' : '' ?>>Random ABCD</option>
        <option value="mixed_alpha" <?= $old('character_set') === 'mixed_alpha' ? 'selected' : '' ?>>Random aBcD</option>
        <option value="lower_alnum" <?= $old('character_set', 'lower_alnum') === 'lower_alnum' ? 'selected' : '' ?>>Random 5ab2c34d</option>
        <option value="upper_alnum" <?= $old('character_set') === 'upper_alnum' ? 'selected' : '' ?>>Random 5AB2C34D</option>
        <option value="mixed_alnum" <?= $old('character_set') === 'mixed_alnum' ? 'selected' : '' ?>>Random 5aB2c34D</option>
      </select><div class="form-text">Karakter yang mudah tertukar seperti O/0 dan I/1 tidak dipakai.</div></div></div>
      <div class="row align-items-center g-2 generator-row"><label class="col-md-4 col-lg-3 col-form-label" for="package">Profile</label><div class="col-md-8 col-lg-9"><select class="form-select" id="package" name="package_id" required><option value="">Pilih paket / profil...</option><?php foreach ($packages as $p): ?><option value="<?= (int)$p['id'] ?>" data-uptime="<?= (int)($p['limit_uptime_seconds'] ?? 0) ?>" <?= (string)$old('package_id') === (string)$p['id'] ? 'selected' : '' ?>><?= e($p['mikrotik_profile']) ?> · <?= e($p['name']) ?> (Rp <?= number_format((int)$p['price'], 0, ',', '.') ?>)</option><?php endforeach; ?></select><div class="form-text">Time Limit otomatis mengikuti limit-uptime yang diatur pada paket di menu Paket &amp; Profil. Tidak perlu diisi lagi di sini.</div><div id="profile-limit-hint" class="small text-primary mt-1" aria-live="polite"></div></div></div>
      <div class="row align-items-center g-2 generator-row"><label class="col-md-4 col-lg-3 col-form-label" for="data-limit">Data Limit</label><div class="col-md-8 col-lg-9"><div class="input-group"><input class="form-control" id="data-limit" name="data_limit" type="number" min="1" step="1" value="<?= e((string)$old('data_limit')) ?>" placeholder="Kosong = tanpa batas"><select class="form-select" name="data_limit_unit" aria-label="Satuan data limit" style="max-width:120px"><option value="KB" <?= $old('data_limit_unit') === 'KB' ? 'selected' : '' ?>>KB</option><option value="MB" <?= $old('data_limit_unit', 'MB') === 'MB' ? 'selected' : '' ?>>MB</option><option value="GB" <?= $old('data_limit_unit') === 'GB' ? 'selected' : '' ?>>GB</option></select></div><div class="form-text">Total kuota upload + download yang dipasang pada user Hotspot MikroTik.</div></div></div>
      <div class="row align-items-center g-2 generator-row"><label class="col-md-4 col-lg-3 col-form-label" for="comment">Comment</label><div class="col-md-8 col-lg-9"><input class="form-control" id="comment" name="comment" maxlength="255" value="<?= e((string)$old('comment')) ?>" placeholder="Catatan voucher (opsional)"></div></div>
    </div>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 border-top pt-3 mt-3"><div class="small text-secondary">Voucher tidak pernah dipakai ulang. Tombol Generate yang terulang dengan token sama hanya mengembalikan batch yang sama.</div><button class="btn btn-primary btn-lg" <?= (!$packages || !$routers) ? 'disabled' : '' ?>><i class="bi bi-magic me-1"></i> Generate Voucher</button></div>
  </form>
</div>
<div class="panel-card" id="voucher-history"><div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3"><div><h5 class="fw-bold mb-1">User List / Riwayat Batch</h5><p class="text-secondary small mb-0">Daftar batch yang dapat dibuka kembali untuk cetak normal, QR, atau ukuran kecil.</p></div><span class="badge text-bg-light border"><?= count($vouchers) ?> batch terbaru</span></div>
<?php if (!$vouchers): ?><div class="empty-state py-4"><i class="bi bi-ticket-perforated"></i><p class="mb-0">Belum ada voucher dibuat.</p></div><?php else: ?><div class="table-responsive"><table class="table align-middle"><thead><tr><th>Batch</th><th>Jumlah</th><th>Profile / Paket</th><th>Router</th><th>Dibuat</th><th>Aksi</th></tr></thead><tbody><?php foreach ($vouchers as $v): ?><tr><td class="font-monospace fw-semibold">#<?= (int)$v['batch_id'] ?></td><td><?= (int)$v['quantity'] ?></td><td><?= e($v['package_name']) ?></td><td><?= e($v['router_name']) ?></td><td><?= e($v['created_at']) ?></td><td><a class="btn btn-sm btn-outline-primary" href="vouchers/batch/<?= (int)$v['batch_id'] ?>"><i class="bi bi-list-ul me-1"></i> User List / Cetak</a></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
</div>
<style>
.generator-row{margin-bottom:.55rem}.generator-row .col-form-label{font-weight:500;color:var(--rw-text,#243247)}
@media(max-width:767.98px){.generator-row .col-form-label{padding-bottom:0}.generator-row .col-form-label+div{padding-top:0}}
</style>
<script>
(() => {
  const mode = document.getElementById('hotspot-server-mode');
  const custom = document.getElementById('hotspot-server-custom');
  if (!mode || !custom) return;
  const sync = () => { const active = mode.value === 'custom'; custom.disabled = !active; custom.required = active; if (!active) custom.value = ''; };
  mode.addEventListener('change', sync); sync();
  const packageSelect = document.getElementById('package');
  const limitHint = document.getElementById('profile-limit-hint');
  const formatDuration = (seconds) => {
    if (!seconds) return 'Time Limit profil: tidak dibatasi';
    const units = [['hari',86400],['jam',3600],['menit',60],['detik',1]];
    let left = seconds; const parts = [];
    for (const [label,size] of units) { const n = Math.floor(left / size); if (n) { parts.push(n + ' ' + label); left %= size; } }
    return 'Time Limit profil: ' + parts.join(' ');
  };
  const showProfileLimit = () => {
    if (!packageSelect || !limitHint) return;
    const selected = packageSelect.options[packageSelect.selectedIndex];
    limitHint.textContent = selected && selected.value ? formatDuration(Number(selected.dataset.uptime || 0)) : 'Time Limit akan mengikuti paket yang dipilih.';
  };
  if (packageSelect) { packageSelect.addEventListener('change', showProfileLimit); showProfileLimit(); }
})();
</script>
