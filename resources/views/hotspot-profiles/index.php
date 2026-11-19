<?php
use function RakanWiman\Core\csrf_field;
use function RakanWiman\Core\e;
$profileForm = $editProfile ?: [
    'id'=>0,'name'=>'','shared_users'=>1,'rate_limit'=>'','idle_timeout'=>'none',
    'keepalive_timeout'=>'none','status_autorefresh'=>'none','add_mac_cookie'=>1,
    'mac_cookie_timeout'=>'3d','address_pool'=>'none','address_list'=>'','transparent_proxy'=>1,'is_managed'=>1,
];
if (!$editProfile && !empty($prefillProfileName)) {
    $profileForm['name'] = (string)$prefillProfileName;
}
$statusMap = [
    'synced'=>['Tersinkron','text-bg-success'],
    'missing'=>['Belum ada di MikroTik','text-bg-danger'],
    'different'=>['Konfigurasi berbeda','text-bg-warning'],
    'failed'=>['Sinkronisasi gagal','text-bg-danger'],
    'legacy_present'=>['Ada di MikroTik · belum dikelola','text-bg-warning'],
    'legacy_missing'=>['Profil lama · tidak ditemukan','text-bg-secondary'],
];
?>
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
  <div><h4 class="fw-bold mb-1">HotSpot Profile Manager</h4><p class="text-secondary mb-0">Master profil berada di database RAKAN WIMAN. Sinkronisasi berjalan satu arah ke MikroTik.</p></div>
  <span class="badge text-bg-primary">Database = Master</span>
</div>
<?php if (!empty($error)): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<div class="panel-card mb-3">
  <div class="row g-3 align-items-end">
    <div class="col-lg-5">
      <label class="form-label fw-semibold" for="profile-router-select">Router untuk pemeriksaan status</label>
      <form method="get" action="hotspot-profiles" id="profile-router-form">
        <select class="form-select" name="router_id" id="profile-router-select" onchange="this.form.submit()">
          <?php if (!$routers): ?><option value="0">Belum ada router aktif</option><?php endif; ?>
          <?php foreach ($routers as $r): ?><option value="<?= (int)$r['id'] ?>" <?= (int)$r['id']===$selectedRouterId?'selected':'' ?>><?= e($r['name']) ?> · <?= e($r['host']) ?></option><?php endforeach; ?>
        </select>
      </form>
      <?php if ($selectedRouter): ?><div class="form-text">Status yang terlihat di bawah ini khusus untuk <?= e($selectedRouter['name']) ?>.</div><?php endif; ?>
    </div>
    <div class="col-lg-7 d-flex flex-wrap gap-2">
      <form method="post" action="hotspot-profiles" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="refresh"><input type="hidden" name="router_id" value="<?= $selectedRouterId ?>"><button class="btn btn-outline-primary" <?= !$selectedRouter?'disabled':'' ?>><i class="bi bi-arrow-repeat me-1"></i>Baca Profil MikroTik</button></form>
      <form method="post" action="hotspot-profiles" class="d-flex gap-2 flex-grow-1"><?= csrf_field() ?><input type="hidden" name="action" value="sync"><select class="form-select" name="target_router" aria-label="Target sinkronisasi" <?= !$routers?'disabled':'' ?>><option value="selected">Router terpilih</option><option value="all">Semua router aktif</option></select><input type="hidden" name="router_id" value="<?= $selectedRouterId ?>"><button class="btn btn-primary" <?= !$routers?'disabled':'' ?>><i class="bi bi-cloud-arrow-up me-1"></i>Sinkronkan DB → MikroTik</button></form>
    </div>
  </div>
  <?php if ($routerCheckedAt): ?><div class="small text-secondary mt-2">Pemeriksaan terakhir: <?= e($routerCheckedAt) ?>. Daftar profil hanya di MikroTik tidak diimpor ke database.</div><?php endif; ?>
</div>
<div class="row g-3">
  <div class="col-xl-4">
    <div class="panel-card">
      <div class="d-flex align-items-center justify-content-between mb-3"><h6 class="fw-bold mb-0"><?= $editProfile ? 'Edit Master Profil' : 'Buat Profil HotSpot' ?></h6><?php if ($editProfile): ?><a class="btn btn-sm btn-outline-secondary" href="hotspot-profiles?router_id=<?= $selectedRouterId ?>">Batal</a><?php endif; ?></div>
      <?php if ($editProfile && (int)$editProfile['is_managed']===0): ?><div class="alert alert-warning small">Ini profil lama yang sebelumnya hanya direferensikan oleh paket. Setelah disimpan, konfigurasi di bawah menjadi master RAKAN WIMAN dan akan dikirim ke router.</div><?php endif; ?>
      <?php if (!$editProfile && !empty($prefillProfileName)): ?><div class="alert alert-warning small"><strong><?= e($prefillProfileName) ?></strong> ditemukan di MikroTik tetapi belum ada di database. Hanya nama yang diisi otomatis. Parameter di bawah tidak disalin dari MikroTik; atur nilai master yang diinginkan sebelum menyimpan. Setelah disimpan, konfigurasi database akan disinkronkan ke router aktif.</div><?php endif; ?>
      <form method="post" action="hotspot-profiles">
        <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="profile_id" value="<?= (int)$profileForm['id'] ?>">
        <div class="mb-3"><label class="form-label">Nama Profil</label><input class="form-control" name="name" maxlength="120" required value="<?= e((string)$profileForm['name']) ?>" <?= $editProfile?'readonly':'' ?> placeholder="Contoh: RW-10M-1H"><div class="form-text">Nama profil menjadi identitas dan tidak dapat diubah setelah dibuat.</div></div>
        <div class="row g-2">
          <div class="col-4 mb-3"><label class="form-label">Shared Users</label><input class="form-control" type="number" name="shared_users" min="1" max="1000" value="<?= (int)$profileForm['shared_users'] ?>" required></div>
          <div class="col-8 mb-3"><label class="form-label">Rate Limit</label><input class="form-control" name="rate_limit" maxlength="120" value="<?= e((string)$profileForm['rate_limit']) ?>" placeholder="Contoh: 10M/10M"><div class="form-text">Kosong = tanpa pembatasan rate dari profil.</div></div>
        </div>
        <div class="row g-2">
          <div class="col-6 mb-3"><label class="form-label">Idle Timeout</label><input class="form-control" name="idle_timeout" value="<?= e((string)$profileForm['idle_timeout']) ?>" placeholder="none"></div>
          <div class="col-6 mb-3"><label class="form-label">Keepalive Timeout</label><input class="form-control" name="keepalive_timeout" value="<?= e((string)$profileForm['keepalive_timeout']) ?>" placeholder="none"></div>
          <div class="col-6 mb-3"><label class="form-label">Status Auto Refresh</label><input class="form-control" name="status_autorefresh" value="<?= e((string)$profileForm['status_autorefresh']) ?>" placeholder="none"></div>
          <div class="col-6 mb-3"><label class="form-label">MAC Cookie Timeout</label><input class="form-control" name="mac_cookie_timeout" value="<?= e((string)$profileForm['mac_cookie_timeout']) ?>" placeholder="3d"></div>
        </div>
        <div class="mb-3"><label class="form-label">Address Pool</label><input class="form-control" name="address_pool" maxlength="120" value="<?= e((string)$profileForm['address_pool']) ?>" placeholder="none"></div>
        <div class="mb-3"><label class="form-label">Address List</label><input class="form-control" name="address_list" maxlength="120" value="<?= e((string)$profileForm['address_list']) ?>" placeholder="Kosong bila tidak digunakan"></div>
        <div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="add_mac_cookie" name="add_mac_cookie" value="1" <?= (int)$profileForm['add_mac_cookie']===1?'checked':'' ?>><label class="form-check-label" for="add_mac_cookie">Add MAC Cookie</label></div>
        <div class="form-check mb-3"><input class="form-check-input" type="checkbox" id="transparent_proxy" name="transparent_proxy" value="1" <?= (int)$profileForm['transparent_proxy']===1?'checked':'' ?>><label class="form-check-label" for="transparent_proxy">Transparent Proxy</label></div>
        <button class="btn btn-primary w-100"><i class="bi bi-save me-1"></i><?= $editProfile?'Simpan Master & Sinkronkan':'Simpan Profil & Buat di MikroTik' ?></button>
        <div class="form-text mt-2">Saat disimpan, konfigurasi menjadi master database dan antrean sinkronisasi dibuat untuk semua router aktif. Router offline akan dicoba ulang oleh cron.</div>
      </form>
    </div>
  </div>
  <div class="col-xl-8">
    <div class="panel-card mb-3">
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3"><h6 class="fw-bold mb-0">Master Profil RAKAN WIMAN</h6><span class="badge text-bg-light border"><?= count($profileRows) ?> profil</span></div>
      <?php if (!$profileRows): ?><div class="empty-state py-4"><i class="bi bi-diagram-3"></i><p class="mb-1 fw-semibold">Belum ada master profil</p><p class="small text-secondary">Buat profil pertama di formulir sebelah kiri.</p></div><?php else: ?>
      <div class="table-responsive"><table class="table align-middle"><thead><tr><th>Profil</th><th>Pengaturan</th><th>Status Router</th><th class="text-end">Aksi</th></tr></thead><tbody>
      <?php foreach ($profileRows as $p): $state=$statusMap[$p['router_sync_status']??'']??null; ?>
        <tr>
          <td><div class="fw-semibold"><?= e($p['name']) ?></div><small class="text-secondary"><?= (int)$p['is_managed']===1?'Master dikelola':'Profil legacy · belum dikelola' ?></small></td>
          <td><small>Shared: <?= (int)$p['shared_users'] ?><br>Rate: <?= e($p['rate_limit'] ?: 'No limit') ?><br>Idle: <?= e($p['idle_timeout']) ?></small></td>
          <td><?php if ($state): ?><span class="badge <?= e($state[1]) ?>"><?= e($state[0]) ?></span><?php else: ?><span class="badge text-bg-secondary">Belum diperiksa</span><?php endif; ?><?php if (!empty($p['last_error']) && in_array($p['router_sync_status'],['failed','different','missing'],true)): ?><div class="small text-danger mt-1"><?= e($p['last_error']) ?></div><?php endif; ?><?php if (!empty($p['last_checked_at'])): ?><div class="small text-secondary mt-1"><?= e($p['last_checked_at']) ?></div><?php endif; ?></td>
          <td class="text-end"><div class="d-flex justify-content-end flex-wrap gap-1"><a class="btn btn-sm btn-outline-secondary" href="hotspot-profiles?router_id=<?= $selectedRouterId ?>&edit=<?= (int)$p['id'] ?>"><i class="bi bi-pencil me-1"></i>Atur</a><?php if ((int)$p['is_managed']===1 && $selectedRouterId>0): ?><form method="post" action="hotspot-profiles"><?= csrf_field() ?><input type="hidden" name="action" value="sync"><input type="hidden" name="target_router" value="selected"><input type="hidden" name="router_id" value="<?= $selectedRouterId ?>"><input type="hidden" name="profile_id" value="<?= (int)$p['id'] ?>"><button class="btn btn-sm btn-outline-primary"><i class="bi bi-arrow-repeat me-1"></i>Sync</button></form><?php endif; ?></div></td>
        </tr>
      <?php endforeach; ?>
      </tbody></table></div><?php endif; ?>
    </div>
    <div class="panel-card">
      <div class="d-flex justify-content-between align-items-center mb-2"><h6 class="fw-bold mb-0">Profil Hanya di MikroTik</h6><span class="badge text-bg-warning">Tidak diimpor</span></div>
      <p class="small text-secondary">Profil berikut ditemukan di router, tetapi belum ada pada database master. Gunakan tombol Buat Master + Sync untuk membuka formulir master dengan nama terisi. Atur parameter master terlebih dahulu; setelah disimpan, RAKAN WIMAN mengirim konfigurasi dari database ke router. Nilai profil MikroTik tidak diimpor.</p>
      <?php if (!$selectedRouter): ?><div class="text-secondary small">Tambahkan router aktif terlebih dahulu.</div>
      <?php elseif (!$routerCheckedAt): ?><div class="text-secondary small">Tekan “Baca Profil MikroTik” untuk melihat profil yang ada di router.</div>
      <?php elseif (!$routerOnly): ?><div class="text-success small"><i class="bi bi-check-circle me-1"></i>Tidak ada profil tambahan yang hanya berada di MikroTik.</div>
      <?php else: ?><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Nama Profil MikroTik</th><th>Shared Users</th><th>Rate Limit</th><th>Status / Aksi</th></tr></thead><tbody><?php foreach ($routerOnly as $rp): ?><tr><td class="fw-semibold"><?= e($rp['name']) ?></td><td><?= e((string)$rp['shared_users']) ?></td><td><?= e((string)($rp['rate_limit'] ?: 'No limit')) ?></td><td><div class="d-flex flex-wrap align-items-center gap-2"><span class="badge text-bg-warning">Belum ada di DB</span><a class="btn btn-sm btn-outline-primary" href="hotspot-profiles?router_id=<?= $selectedRouterId ?>&new_master=<?= rawurlencode((string)$rp['name']) ?>" title="Buat master database lalu sinkronkan ke MikroTik"><i class="bi bi-cloud-arrow-up me-1"></i>Buat Master + Sync</a></div></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
    </div>
  </div>
</div>
