<?php use function RakanWiman\Core\e; use function RakanWiman\Core\csrf_field; ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
  <div><h4 class="fw-bold mb-1">WhatsApp Gateway</h4><p class="text-secondary mb-0">Kelola nomor pengirim, pairing perangkat, API pengiriman, dan antrean pesan.</p></div>
  <div class="d-flex flex-wrap gap-2">
    <button class="btn btn-success" type="button" data-bs-toggle="modal" data-bs-target="#testWhatsAppModal" <?= (empty($values['configured']) || empty($connectedSenders)) ? 'disabled' : '' ?>><i class="bi bi-send-check me-1"></i> Test WhatsApp</button>
    <a class="btn btn-outline-primary" href="whatsapp/config"><i class="bi bi-gear me-1"></i> Configuration</a>
  </div>
</div>
<?php if (empty($values['configured'])): ?>
  <div class="alert alert-warning d-flex flex-wrap align-items-center justify-content-between gap-2"><span>Server URL dan Auth Basic Password harus dikonfigurasi terlebih dahulu.</span><a class="btn btn-sm btn-primary" href="whatsapp/config">Buka Configuration</a></div>
<?php endif; ?>
<div class="modal fade" id="testWhatsAppModal" tabindex="-1" aria-labelledby="testWhatsAppModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="post" action="whatsapp/test">
        <?= csrf_field() ?>
        <div class="modal-header"><h5 class="modal-title fw-bold" id="testWhatsAppModalLabel"><i class="bi bi-whatsapp text-success me-1"></i>Test WhatsApp Gateway</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button></div>
        <div class="modal-body">
          <p class="small text-secondary">Masukkan nomor tujuan. Pesan uji akan dikirim langsung melalui sender yang sudah terhubung, bukan melalui antrean voucher.</p>
          <div class="mb-3"><label class="form-label" for="waTestSender">Kirim menggunakan sender</label><select class="form-select" id="waTestSender" name="sender_id" required>
            <?php foreach (($connectedSenders ?? []) as $sender): ?><option value="<?= (int)$sender['id'] ?>"><?= e($sender['phone_number']) ?></option><?php endforeach; ?>
          </select></div>
          <div class="mb-2"><label class="form-label" for="waTestRecipient">Nomor tujuan</label><input class="form-control" id="waTestRecipient" name="recipient" type="tel" inputmode="tel" autocomplete="tel" placeholder="0812xxxxxxx atau 62812xxxxxxx" maxlength="24" pattern="[0-9+() .-]{8,24}" required></div>
          <div class="form-text">Nomor yang diawali 0 otomatis menggunakan kode negara <?= e((string)($values['country_code'] ?? '62')) ?>.</div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Batal</button><button type="submit" class="btn btn-success" <?= empty($connectedSenders) ? 'disabled' : '' ?>><i class="bi bi-send me-1"></i>Kirim Pesan Uji</button></div>
      </form>
    </div>
  </div>
</div>
<div class="row g-3 mb-3">
  <div class="col-lg-4">
    <div class="panel-card h-100">
      <h5 class="fw-bold mb-1"><i class="bi bi-plus-circle me-1"></i> Add Phone</h5>
      <p class="small text-secondary">Masukkan nomor WhatsApp dengan kode negara. RAKAN WIMAN akan meminta JWT melalui endpoint <code>/auth</code>.</p>
      <form method="post" action="whatsapp/sender"><?= csrf_field() ?>
        <label class="form-label">Phone Number</label>
        <input class="form-control mb-2" name="phone_number" type="tel" placeholder="62812xxxxxxxx" required <?= empty($values['configured']) ? 'disabled' : '' ?>>
        <div class="form-text mb-3">Gunakan format internasional, misalnya 62812xxxxxxx.</div>
        <button class="btn btn-success w-100" <?= empty($values['configured']) ? 'disabled' : '' ?>><i class="bi bi-plus-lg me-1"></i> Add Phone</button>
      </form>
    </div>
  </div>
  <div class="col-lg-8">
    <div class="panel-card h-100">
      <div class="d-flex justify-content-between align-items-center gap-2 mb-3"><div><h5 class="fw-bold mb-1">Registered Phone</h5><p class="small text-secondary mb-0">Setiap nomor mempunyai JWT sendiri. Hubungkan dengan QRCode atau Paircode.</p></div><span class="badge text-bg-light border"><?= count($senders) ?> sender</span></div>
      <?php if (!$senders): ?><div class="text-center text-secondary py-4"><i class="bi bi-whatsapp fs-2 d-block mb-2"></i>Belum ada sender WhatsApp.</div><?php else: ?>
      <div class="table-responsive"><table class="table table-hover align-middle"><thead><tr><th>Phone Number</th><th>Status</th><th>Last Connected</th><th>Action</th></tr></thead><tbody>
        <?php foreach ($senders as $sender): ?><tr>
          <td class="fw-semibold font-monospace"><?= e($sender['phone_number']) ?></td>
          <td><span class="badge <?= $sender['status']==='connected'?'text-bg-success':($sender['status']==='error'?'text-bg-danger':'text-bg-secondary') ?>"><?= e($sender['status']==='connected'?'Logged in':($sender['status']==='error'?'Error':'Not logged in')) ?></span></td>
          <td class="small text-secondary"><?= e($sender['last_connected_at'] ?: '-') ?></td>
          <td><div class="d-flex flex-wrap gap-1"><a class="btn btn-sm btn-outline-primary" href="whatsapp/login?sender_id=<?= (int)$sender['id'] ?>&mode=qr"><i class="bi bi-qr-code me-1"></i>QRCode</a><a class="btn btn-sm btn-outline-primary" href="whatsapp/login?sender_id=<?= (int)$sender['id'] ?>&mode=pair"><i class="bi bi-phone me-1"></i>Paircode</a><form method="post" action="whatsapp/delete" onsubmit="return confirm('Hapus sender <?= e($sender['phone_number']) ?>?')"><?= csrf_field() ?><input type="hidden" name="sender_id" value="<?= (int)$sender['id'] ?>"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form></div></td>
        </tr><?php endforeach; ?>
      </tbody></table></div><?php endif; ?>
    </div>
  </div>
</div>
<div class="panel-card mb-3">
  <h5 class="fw-bold mb-1">API to send directly</h5>
  <p class="small text-secondary">Endpoint kompatibel untuk pengiriman langsung dari aplikasi lain. Pesan masuk ke antrean RAKAN WIMAN dan akan diproses cron.</p>
  <div class="input-group"><input class="form-control font-monospace small" id="waDirectApiUrl" type="text" readonly value="<?= e($sendApiUrl) ?>"><button class="btn btn-outline-primary" type="button" onclick="const i=document.getElementById('waDirectApiUrl');i.select();navigator.clipboard?.writeText(i.value)"><i class="bi bi-clipboard me-1"></i>Copy</button></div>
  <div class="form-text">Ganti <code>[number]</code> dengan nomor tujuan dan <code>[text]</code> dengan pesan. Secret URL adalah MD5 dari Auth Basic Password, mengikuti pola plugin PHPNuxBill. Simpan URL ini secara rahasia.</div>
</div>
<div class="panel-card mb-3">
  <h5 class="fw-bold mb-1">Template Pesan Voucher</h5><p class="small text-secondary">Pesan otomatis setelah transaksi QRIS terkonfirmasi. Placeholder: {username}, {password}, {package}, {sync_status}.</p>
  <form method="post" action="whatsapp/template"><?= csrf_field() ?><textarea class="form-control" name="wa_message_template" rows="3" required><?= e($values['message_template']) ?></textarea><button class="btn btn-primary mt-3"><i class="bi bi-save me-1"></i> Simpan Template Pesan</button></form>
</div>
<div class="panel-card">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3"><div><h5 class="fw-bold mb-1">Sending WhatsApp / Audit</h5><p class="small text-secondary mb-0">Status pengiriman, jumlah percobaan, dan pesan error.</p></div><span class="badge text-bg-light border"><?= count($messages) ?> log terbaru</span></div>
  <?php if (!$messages): ?><p class="text-secondary mb-0">Belum ada pesan pada antrean.</p><?php else: ?><div class="table-responsive"><table class="table table-sm table-hover align-middle"><thead><tr><th>Created</th><th>Number</th><th>Status</th><th>Attempts</th><th>Detail / Action</th></tr></thead><tbody>
    <?php foreach ($messages as $msg): ?><tr><td class="small"><?= e($msg['created_at']) ?></td><td><?= e($msg['recipient']) ?></td><td><span class="badge <?= $msg['status']==='sent'?'text-bg-success':($msg['status']==='failed'?'text-bg-danger':'text-bg-warning') ?>"><?= e($msg['status']) ?></span></td><td><?= (int)$msg['attempts'] ?></td><td><small><?= e($msg['last_error'] ?: ($msg['sent_at'] ? 'Sent ' . $msg['sent_at'] : '-')) ?></small><?php if (($msg['related_type'] ?? '') === 'test'): ?> <span class="badge text-bg-info">Test</span><?php endif; ?><?php if ($msg['status']==='failed' && ($msg['related_type'] ?? '') !== 'test'): ?><form method="post" action="whatsapp/retry" class="d-inline ms-2"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$msg['id'] ?>"><button class="btn btn-sm btn-outline-primary">Retry</button></form><?php endif; ?></td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
</div>
