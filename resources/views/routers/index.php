<?php
use function RakanWiman\Core\csrf_field;
use function RakanWiman\Core\e;
$editRouter = $editRouter ?? null;
$isEditing = is_array($editRouter);
$formAction = $isEditing ? 'routers/update' : 'routers';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
  <div>
    <h4 class="fw-bold mb-1">Manajemen Router MikroTik</h4>
    <p class="text-secondary mb-0">Daftarkan dan kelola koneksi router yang digunakan RAKAN WIMAN.</p>
  </div>
  <span class="badge text-bg-light border">Multi-router</span>
</div>

<?php if (!empty($error)): ?>
  <div class="alert alert-danger alert-dismissible fade show" role="alert">
    <?= e($error) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
  </div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-xl-5">
    <div class="panel-card">
      <div class="d-flex align-items-center justify-content-between mb-3">
        <h6 class="fw-bold mb-0"><?= $isEditing ? 'Edit Router' : 'Tambah Router' ?></h6>
        <?php if ($isEditing): ?><span class="badge text-bg-warning">ID #<?= (int)$editRouter['id'] ?></span><?php endif; ?>
      </div>
      <form method="post" action="<?= e($formAction) ?>">
        <?= csrf_field() ?>
        <?php if ($isEditing): ?><input type="hidden" name="id" value="<?= (int)$editRouter['id'] ?>"><?php endif; ?>

        <div class="mb-3">
          <label class="form-label">Nama Router</label>
          <input class="form-control" name="name" placeholder="Contoh: Router Utama" maxlength="120" value="<?= e($editRouter['name'] ?? '') ?>" required>
        </div>

        <div class="row g-2">
          <div class="col-8 mb-3">
            <label class="form-label">IP / Hostname</label>
            <input class="form-control" name="host" placeholder="192.168.88.1" maxlength="255" value="<?= e($editRouter['host'] ?? '') ?>" required>
          </div>
          <div class="col-4 mb-3">
            <label class="form-label">API Port</label>
            <input class="form-control" type="number" name="api_port" value="<?= e((string)($editRouter['api_port'] ?? 8728)) ?>" min="1" max="65535" required>
          </div>
        </div>

        <div class="mb-3">
          <label class="form-label">Username API</label>
          <input class="form-control" name="username" maxlength="120" value="<?= e($editRouter['username'] ?? '') ?>" required autocomplete="off">
        </div>

        <div class="mb-3">
          <label class="form-label">Password API <?= $isEditing ? '<span class="text-secondary fw-normal">(opsional)</span>' : '' ?></label>
          <input class="form-control" type="password" name="password" autocomplete="new-password" <?= $isEditing ? 'placeholder="Kosongkan jika tidak ingin mengubah password"' : 'required' ?>>
          <?php if ($isEditing): ?><div class="form-text">Password lama tidak ditampilkan. Kosongkan kolom ini untuk mempertahankan password yang tersimpan.</div><?php endif; ?>
        </div>

        <div class="mb-3">
          <label class="form-label">Hotspot Server (opsional)</label>
          <input class="form-control" name="hotspot_server" maxlength="120" placeholder="all" value="<?= e($editRouter['hotspot_server'] ?? '') ?>">
        </div>

        <div class="form-check mb-3">
          <input class="form-check-input" type="checkbox" name="api_ssl" id="api_ssl" value="1" <?= !empty($editRouter['api_ssl']) ? 'checked' : '' ?>>
          <label class="form-check-label" for="api_ssl">Gunakan API SSL (umumnya port 8729)</label>
        </div>

        <div class="d-flex flex-wrap gap-2">
          <button class="btn btn-primary flex-grow-1">
            <i class="bi <?= $isEditing ? 'bi-save' : 'bi-plus-lg' ?> me-1"></i>
            <?= $isEditing ? 'Simpan Perubahan' : 'Simpan Router' ?>
          </button>
          <?php if ($isEditing): ?><a class="btn btn-outline-secondary" href="routers"><i class="bi bi-x-lg me-1"></i>Batal</a><?php endif; ?>
        </div>
        <p class="text-secondary small mt-3 mb-0">Password router disimpan terenkripsi. Setelah perubahan disimpan, lakukan Uji Koneksi kembali.</p>
      </form>
    </div>
  </div>

  <div class="col-xl-7">
    <div class="panel-card">
      <h6 class="fw-bold mb-3">Daftar Router <span class="badge text-bg-light border"><?= count($routers) ?></span></h6>
      <?php if (!$routers): ?>
        <div class="empty-state py-5">
          <i class="bi bi-router"></i>
          <p class="fw-semibold mb-1">Belum ada router terdaftar</p>
          <p class="small text-secondary">Isi formulir untuk menambahkan router pertama.</p>
        </div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table align-middle">
            <thead><tr><th>Router</th><th>API</th><th>Status</th><th class="text-end">Aksi</th></tr></thead>
            <tbody>
              <?php foreach ($routers as $r): ?>
                <tr>
                  <td>
                    <div class="fw-semibold"><?= e($r['name']) ?></div>
                    <small class="text-secondary"><?= e($r['host']) ?></small>
                  </td>
                  <td>
                    <?= (int)$r['api_port'] ?><?= $r['api_ssl'] ? ' SSL' : '' ?>
                    <br><small class="text-secondary"><?= e($r['username']) ?></small>
                  </td>
                  <td>
                    <span class="badge <?= $r['connection_status'] === 'online' ? 'text-bg-success' : ($r['connection_status'] === 'offline' ? 'text-bg-secondary' : 'text-bg-warning') ?>"><?= e($r['connection_status']) ?></span>
                    <?php if ($r['last_error']): ?><div class="small text-danger mt-1"><?= e($r['last_error']) ?></div><?php endif; ?>
                    <?php if ($r['last_seen_at']): ?><div class="small text-secondary mt-1">Terakhir online: <?= e($r['last_seen_at']) ?></div><?php endif; ?>
                  </td>
                  <td>
                    <div class="d-flex flex-wrap gap-1 justify-content-end">
                      <a class="btn btn-sm btn-outline-secondary" href="routers/edit/<?= (int)$r['id'] ?>" title="Edit router"><i class="bi bi-pencil-square me-1"></i>Edit</a>
                      <form method="post" action="routers/test">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                        <button class="btn btn-sm btn-outline-primary"><i class="bi bi-plug me-1"></i>Uji koneksi</button>
                      </form>
                      <form method="post" action="routers/delete" onsubmit="return confirm('Hapus router ini dari daftar aktif RAKAN WIMAN? Router akan diarsipkan. Riwayat voucher/pembayaran tetap disimpan, antrean sinkronisasi dibatalkan, dan akun yang sudah ada di MikroTik tidak dihapus.');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                        <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1"></i>Hapus</button>
                      </form>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
