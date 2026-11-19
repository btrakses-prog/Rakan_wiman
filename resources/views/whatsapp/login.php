<?php use function RakanWiman\Core\e; use function RakanWiman\Core\csrf_field; ?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4"><div><h4 class="fw-bold mb-1">Login WhatsApp</h4><p class="text-secondary mb-0"><?= e($result['phone']) ?> · <?= e($result['mode']==='pair'?'Paircode':'QRCode') ?></p></div><a class="btn btn-outline-secondary" href="whatsapp">Kembali</a></div>
<div class="row justify-content-center"><div class="col-lg-7"><div class="panel-card text-center">
  <?php if ($result['connected']): ?><div class="alert alert-success"><i class="bi bi-check-circle-fill me-1"></i> Logged in — WhatsApp Client is Reconnected</div>
  <?php else: ?><div class="alert alert-info"><?= e($result['message'] ?: 'Silakan hubungkan perangkat WhatsApp.') ?></div><?php endif; ?>
  <?php if ($result['mode']==='pair' && $result['pair_code']): ?><div class="small text-secondary mb-2">Masukkan kode ini melalui WhatsApp → Perangkat tertaut → Tautkan dengan nomor telepon</div><div class="display-5 fw-bold font-monospace letter-spacing-2 mb-3"><?= e($result['pair_code']) ?></div><?php elseif ($result['mode']==='qr' && $result['qr']): ?>
    <?php $qrValue=$result['qr']; if (str_starts_with($qrValue,'data:image/')): ?><img src="<?= e($qrValue) ?>" alt="WhatsApp QR Code" style="max-width:280px;width:100%">
    <?php elseif (preg_match('#^https://#i',$qrValue)): ?><img src="<?= e($qrValue) ?>" alt="WhatsApp QR Code" style="max-width:280px;width:100%">
    <?php else: ?><div class="d-inline-block p-3 bg-white border rounded"><?php try { echo \RakanWiman\Services\QrCode::svg($qrValue,5); } catch (Throwable $qrError) { ?><code class="d-block text-break" style="max-width:420px"><?= e($qrValue) ?></code><?php } ?></div><?php endif; ?>
  <?php elseif ($result['mode']==='pair' && !$result['connected']): ?><p class="text-secondary">Kode pairing tidak dikirim oleh gateway pada respons ini. Periksa pesan API di bawah, atau coba QRCode.</p>
  <?php else: ?><p class="text-secondary">Gateway tidak menyertakan properti QR pada respons. Coba Paircode atau periksa respons API.</p><?php endif; ?>
  <?php if ($result['timeout']): ?><p class="small text-secondary mt-2">Timeout: <?= (int)$result['timeout'] ?> detik</p><?php endif; ?>
  <div class="d-flex justify-content-center flex-wrap gap-2 mt-3"><a class="btn btn-outline-primary" href="whatsapp/login?sender_id=<?= (int)$result['sender_id'] ?>&mode=qr">Muat QRCode</a><a class="btn btn-outline-primary" href="whatsapp/login?sender_id=<?= (int)$result['sender_id'] ?>&mode=pair">Muat Paircode</a></div>
  <details class="text-start mt-4"><summary class="small text-secondary">Respons detail API</summary><pre class="small bg-light p-3 rounded mt-2" style="white-space:pre-wrap;word-break:break-word"><?= e(json_encode($result['raw'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)) ?></pre></details>
</div></div></div>
