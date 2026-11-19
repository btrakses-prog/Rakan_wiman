<?php use function RakanWiman\Core\e; use function RakanWiman\Core\csrf_field; ?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
  <div><h4 class="fw-bold mb-1">WhatsApp Gateway Configuration</h4><p class="text-secondary mb-0">Konfigurasi Go WhatsApp Multi-Device seperti integrasi PHPNuxBill.</p></div>
  <a class="btn btn-outline-secondary" href="whatsapp"><i class="bi bi-arrow-left me-1"></i> Kembali</a>
</div>
<?php if (!empty($error)): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<div class="row justify-content-center"><div class="col-xl-8 col-lg-9">
  <div class="panel-card" style="border:1px solid #6f35ff;border-radius:18px">
    <h5 class="fw-bold mb-3">Configuration</h5>
    <form method="post" action="whatsapp/config">
      <?= csrf_field() ?>
      <div class="mb-3">
        <label class="form-label">Server URL</label>
        <input class="form-control" type="url" name="wa_gateway_url" value="<?= e($values['gateway_url']) ?>" placeholder="http://localhost:3000/api/v1/whatsapp" required>
        <div class="d-flex justify-content-end"><a href="https://github.com/dimaskiddo/go-whatsapp-multidevice-rest" target="_blank" rel="noopener noreferrer" class="small text-decoration-none">Go WhatsApp Multi-Device</a></div>
        <div class="form-text">Isi alamat HTTP_BASE_URL dari server gateway. Default API: <code>/api/v1/whatsapp</code>.</div>
      </div>
      <div class="mb-3">
        <label class="form-label">Auth Basic Password</label>
        <div class="input-group">
          <input class="form-control" id="waGatewaySecret" type="password" name="wa_gateway_secret" value="<?= e($values['gateway_secret']) ?>" autocomplete="new-password" placeholder="AUTH_BASIC_PASSWORD" required>
          <button class="btn btn-outline-secondary" type="button" id="toggleWaSecret" aria-label="Tampilkan atau sembunyikan secret"><i class="bi bi-eye"></i></button>
        </div>
        <div class="form-text">Isi dengan nilai <code>AUTH_BASIC_PASSWORD</code> pada file <code>.env</code> Go WhatsApp Gateway. Nilai ini disimpan terenkripsi di RAKAN WIMAN. Jika diubah di sini, samakan juga di server gateway.</div>
      </div>
      <div class="mb-4">
        <label class="form-label">Kode Negara Telepon</label>
        <div class="input-group"><span class="input-group-text">+</span><input class="form-control" type="text" name="wa_country_code" value="<?= e($values['country_code']) ?>" placeholder="62" required></div>
        <div class="form-text">Jika diisi 62, nomor tujuan yang diawali 0 akan diubah menjadi 62. Contoh: 0812xxxx menjadi 62812xxxx.</div>
      </div>
      <div class="d-flex gap-2"><a class="btn btn-light flex-grow-1" href="whatsapp">back</a><button class="btn btn-primary flex-grow-2" type="submit"><i class="bi bi-save me-1"></i> Simpan</button></div>
    </form>
  </div>
  <div class="alert alert-warning mt-3 mb-0 small"><strong>Catatan keamanan:</strong> jangan membagikan Auth Basic Password. Gunakan HTTPS untuk koneksi ke gateway dan gunakan secret yang sama pada RAKAN WIMAN serta <code>AUTH_BASIC_PASSWORD</code> di server Go.</div>
</div></div>
<script>
(() => { const input=document.getElementById('waGatewaySecret'); const btn=document.getElementById('toggleWaSecret'); if(!input||!btn)return; btn.addEventListener('click',()=>{const show=input.type==='password';input.type=show?'text':'password';btn.innerHTML=show?'<i class="bi bi-eye-slash"></i>':'<i class="bi bi-eye"></i>';}); })();
</script>
