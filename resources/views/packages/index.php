<?php
use function RakanWiman\Core\csrf_field;
use function RakanWiman\Core\e;
$editMode = !empty($editPackage);
$values = $formValues ?? [];
$val = static fn(string $key, mixed $fallback = ''): string => e((string)($values[$key] ?? $fallback));
$profileValue = (string)($values['mikrotik_profile'] ?? '');
$uptimeSeconds = $values['limit_uptime_seconds'] ?? null;
if (array_key_exists('limit_uptime', $values)) {
    $uptimeValue = (string)$values['limit_uptime'];
} elseif ($uptimeSeconds !== null && $uptimeSeconds !== '') {
    $u = (int)$uptimeSeconds;
    $uptimeValue = sprintf('%02d:%02d:%02d', intdiv($u, 3600), intdiv($u % 3600, 60), $u % 60);
} else {
    $uptimeValue = '';
}
$validityValue = $values['validity_days'] ?? '';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2"><div><h4 class="fw-bold mb-1">Paket Hotspot</h4><p class="text-secondary mb-0">Atur profil MikroTik, batas pemakaian, dan masa berlaku voucher.</p></div></div>
<?php if(!empty($error)): ?><div class="alert alert-danger"><?=e($error)?></div><?php endif; ?>
<div class="row g-3">
  <div class="col-xl-5">
    <div class="panel-card">
      <div class="d-flex align-items-center justify-content-between mb-3"><h6 class="fw-bold mb-0"><?=$editMode?'Edit Paket':'Tambah Paket'?></h6><?php if($editMode): ?><span class="badge text-bg-warning">Paket #<?= (int)$editPackage['id'] ?></span><?php endif; ?></div>
      <form method="post" action="packages">
        <?=csrf_field()?>
        <input type="hidden" name="action" value="<?=$editMode?'update':'create'?>">
        <?php if($editMode): ?><input type="hidden" name="package_id" value="<?= (int)$editPackage['id'] ?>"><?php endif; ?>
        <div class="mb-3"><label class="form-label">Nama Paket</label><input class="form-control" name="name" maxlength="120" placeholder="Voucher 1 Hari" value="<?=$val('name')?>" required></div>
        <div class="row g-2"><div class="col-6 mb-3"><label class="form-label">Kode Paket</label><input class="form-control text-uppercase" name="code" maxlength="60" placeholder="VCR1D" value="<?=$val('code')?>" required></div><div class="col-6 mb-3"><label class="form-label">Harga (Rp)</label><input class="form-control" type="number" min="0" name="price" value="<?=$val('price','0')?>" required></div></div>
        <div class="mb-3"><label class="form-label">Profil HotSpot</label><select class="form-select" name="mikrotik_profile" required><option value="">Pilih master profil...</option><?php foreach(($profiles ?? []) as $hp): ?><option value="<?=e($hp['name'])?>" <?=$profileValue===$hp['name']?'selected':''?>><?=e($hp['name'])?></option><?php endforeach; ?></select><div class="form-text">Pilihan hanya menampilkan profil yang sudah dikelola sebagai master.</div><a class="small" href="hotspot-profiles">Buka HotSpot Profile Manager →</a></div>
        <div class="row g-2"><div class="col-6 mb-3"><label class="form-label">Limit Uptime</label><input class="form-control" name="limit_uptime" value="<?=e($uptimeValue)?>" placeholder="Contoh 05:00:00"><div class="form-text">Kosong = tanpa batas uptime. Format 05:00:00 atau 1d2h (bisa juga 1w).</div></div><div class="col-6 mb-3"><label class="form-label">Masa berlaku (hari)</label><input class="form-control" type="number" min="1" max="36500" name="validity_days" value="<?=$val('validity_days',$validityValue)?>" placeholder="Contoh 30"><div class="form-text">Dihitung sejak login pertama, bukan sejak voucher dibuat.</div></div></div>
        <div class="mb-3"><label class="form-label">Deskripsi</label><textarea class="form-control" name="description" rows="2" maxlength="2000"><?=$val('description')?></textarea></div>
        <div class="alert alert-info small py-2">Mengubah paket hanya berlaku untuk voucher dan transaksi baru. Voucher/transaksi yang sudah dibuat tetap memakai pengaturan saat diterbitkan.</div>
        <div class="d-flex gap-2"><button class="btn btn-primary flex-grow-1"><?=$editMode?'Simpan Perubahan':'Simpan Paket'?></button><?php if($editMode): ?><a class="btn btn-outline-secondary" href="packages">Batal</a><?php endif; ?></div>
      </form>
    </div>
  </div>
  <div class="col-xl-7">
    <div class="panel-card"><h6 class="fw-bold mb-3">Daftar Paket</h6><?php if(!$packages): ?><div class="empty-state py-4"><p class="mb-0">Belum ada paket. Tambahkan paket pertama.</p></div><?php else: ?><div class="table-responsive"><table class="table align-middle"><thead><tr><th>Paket</th><th>Harga</th><th>Batas</th><th>Status</th><th class="text-end">Aksi</th></tr></thead><tbody><?php foreach($packages as $p): ?><tr><td><div class="fw-semibold"><?=e($p['name'])?></div><small class="text-secondary"><?=e($p['code'])?> · Profile: <?=e($p['mikrotik_profile'])?></small></td><td>Rp <?=number_format((int)$p['price'],0,',','.')?></td><td><small><?= $p['limit_uptime_seconds'] ? ((int)floor((int)$p['limit_uptime_seconds']/86400)>0?(int)floor((int)$p['limit_uptime_seconds']/86400).'d ':'').e(gmdate('H:i:s',(int)$p['limit_uptime_seconds']%86400)) : 'Uptime ∞' ?><br><?= $p['validity_days'] ? (int)$p['validity_days'].' hari' : 'Kalender ∞' ?></small></td><td><span class="badge <?= $p['is_active']?'text-bg-success':'text-bg-secondary' ?>"><?= $p['is_active']?'Aktif':'Nonaktif' ?></span></td><td class="text-end"><a class="btn btn-sm btn-outline-primary" href="packages?edit=<?=(int)$p['id']?>"><i class="bi bi-pencil-square me-1"></i>Edit</a></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div>
  </div>
</div>
