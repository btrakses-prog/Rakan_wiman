<?php
use function RakanWiman\Core\e;
use function RakanWiman\Core\url;
$batchUserMode = (string)($batchVouchers[0]['user_mode'] ?? 'separate');
$showBatchPassword = $voucherTemplate['show_password'] && $batchUserMode !== 'same';
$batchUrl = 'vouchers/batch/' . (int)$batch['id'];
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 no-print">
  <div><h4 class="fw-bold mb-1">User List · Batch #<?= (int)$batch['id'] ?></h4><p class="text-secondary mb-0"><?= (int)$batch['quantity'] ?> voucher · <?= e($batch['package_name']) ?> · <?= e($batch['router_name']) ?> · <?= e($batch['created_at']) ?></p></div>
  <span class="badge text-bg-success">Tersimpan di MySQL</span>
</div>
<div class="panel-card mb-3 no-print"><div class="d-flex flex-wrap gap-2 align-items-center">
  <a class="btn btn-warning" href="<?= e(url('vouchers')) ?>"><i class="bi bi-x-lg me-1"></i> Close</a>
  <button class="btn btn-danger" type="button" id="show-user-list"><i class="bi bi-people-fill me-1"></i> User List</button>
  <a class="btn btn-secondary" target="_blank" rel="noopener" href="<?= e(url($batchUrl . '?layout=normal&print=1')) ?>"><i class="bi bi-printer me-1"></i> Print 52 / A4</a>
  <a class="btn btn-danger" target="_blank" rel="noopener" href="<?= e(url($batchUrl . '?layout=qr&print=1')) ?>"><i class="bi bi-qr-code me-1"></i> QR + Auto Login</a>
  <a class="btn btn-info text-white" target="_blank" rel="noopener" href="<?= e(url($batchUrl . '?layout=small&print=1')) ?>"><i class="bi bi-receipt me-1"></i> Small</a>
  <span class="small text-secondary ms-md-auto">Mode: <strong><?= $batchUserMode === 'same' ? 'USERNAME = PASSWORD' : 'USERNAME & PASSWORD' ?></strong></span>
</div></div>

<?php if ($printLayout === 'normal'): ?>
  <?php $voucherPages = array_chunk($batchVouchers, 52); ?>
  <div class="voucher-print-pages compact-52-pages">
    <?php foreach ($voucherPages as $pageIndex => $pageVouchers): ?>
      <section class="voucher-print-page" aria-label="Lembar <?= $pageIndex + 1 ?>">
        <div class="compact-52-grid">
          <?php foreach ($pageVouchers as $index => $v): $globalIndex = ($pageIndex * 52) + $index + 1; $sameCredentials = ($v['user_mode'] ?? 'separate') === 'same'; ?>
            <article class="compact-voucher">
              <div class="compact-voucher-head" style="background:<?= e($voucherTemplate['color']) ?>"><span><?= e($voucherTemplate['subtitle']) ?></span><strong><?= e($voucherTemplate['title']) ?></strong></div>
              <div class="compact-voucher-body">
                <div class="compact-voucher-package"><?= e($v['package_name']) ?> · <?= e($v['mikrotik_profile']) ?></div>
                <div class="compact-voucher-credential"><span>USER</span><strong><?= e($v['username']) ?></strong></div>
                <?php if ($voucherTemplate['show_password'] && !$sameCredentials): ?><div class="compact-voucher-credential"><span>PASS</span><strong><?= e($v['password']) ?></strong></div><?php endif; ?>
              </div>
              <div class="compact-voucher-foot"><span><?= e($voucherTemplate['footer']) ?></span><b>#<?= $globalIndex ?></b></div>
            </article>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endforeach; ?>
  </div>
<?php else: ?>
  <div id="voucher-sheet" class="voucher-sheet layout-<?= e($printLayout) ?>">
    <div class="sheet-heading"><div class="sheet-business"><?= e($voucherTemplate['subtitle']) ?></div><h2><?= e($voucherTemplate['title']) ?></h2><div class="sheet-batch">Batch #<?= (int)$batch['id'] ?> · <?= e($batch['package_name']) ?></div></div>
    <div class="voucher-grid">
      <?php foreach ($batchVouchers as $index => $v):
        $sameCredentials = ($v['user_mode'] ?? 'separate') === 'same';
        $qrPayload = $sameCredentials ? (string)$v['username'] : ((string)$v['username'] . ':' . (string)$v['password']);
      ?>
        <article class="voucher-card">
          <div class="voucher-band" style="background:<?= e($voucherTemplate['color']) ?>"><div class="voucher-index">#<?= $index + 1 ?></div><div class="voucher-brand"><?= e($voucherTemplate['subtitle']) ?></div></div>
          <div class="voucher-card-body">
            <div class="voucher-title"><?= e($voucherTemplate['title']) ?></div>
            <div class="voucher-package"><?= e($v['package_name']) ?> · Profile <?= e($v['mikrotik_profile']) ?></div>
            <?php if ($printLayout === 'qr'): ?><div class="voucher-qr"><?php try { echo \RakanWiman\Services\QrCode::svg($qrPayload, 3, 4); } catch (Throwable $qrError) { ?><code><?= e($qrPayload) ?></code><?php } ?><small>Scan QR di halaman login untuk masuk otomatis</small></div><?php endif; ?>
            <div class="credential-row"><span>USERNAME</span><strong><?= e($v['username']) ?></strong></div>
            <?php if ($voucherTemplate['show_password'] && !$sameCredentials): ?><div class="credential-row"><span>PASSWORD</span><strong><?= e($v['password']) ?></strong></div><?php endif; ?>
            <div class="voucher-meta">
              <?php if (!empty($v['effective_limit_uptime_seconds'])): ?><span>Time: <?= e(\RakanWiman\Services\VoucherService::formatDuration((int)$v['effective_limit_uptime_seconds'])) ?></span><?php endif; ?>
              <?php if (!empty($v['limit_bytes_total'])): ?><span>Kuota: <?= e(\RakanWiman\Services\VoucherService::formatBytes((int)$v['limit_bytes_total'])) ?></span><?php endif; ?>
              <?php if (!empty($v['hotspot_server']) && $v['hotspot_server'] !== 'all'): ?><span>Server: <?= e($v['hotspot_server']) ?></span><?php endif; ?>
            </div>
            <?php if (!empty($v['generation_comment'])): ?><div class="voucher-comment"><?= e($v['generation_comment']) ?></div><?php endif; ?>
          </div>
          <div class="voucher-footer"><?= e($voucherTemplate['footer']) ?></div>
        </article>
      <?php endforeach; ?>
    </div>
    <?php if ($printLayout === 'qr'): ?><p class="qr-security-note">QR membawa data login voucher. Simpan kartu dengan aman dan jangan membagikan foto QR kepada orang lain.</p><?php endif; ?>
  </div>
<?php endif; ?>

<div id="voucher-user-list" class="panel-card mt-3 no-print" hidden>
  <div class="d-flex justify-content-between align-items-center mb-3"><div><h5 class="fw-bold mb-1">Daftar Kredensial</h5><p class="small text-secondary mb-0"><?= $batchUserMode === 'same' ? 'Mode username = password. Password tidak ditampilkan terpisah.' : 'Password didekripsi hanya untuk ditampilkan kepada administrator yang sudah login.' ?></p></div><span class="badge text-bg-light border"><?= count($batchVouchers) ?> voucher</span></div>
  <div class="table-responsive"><table class="table table-bordered align-middle"><thead><tr><th>No</th><th>Username</th><?php if ($showBatchPassword): ?><th>Password</th><?php endif; ?><th>Sinkronisasi</th><th>Status</th></tr></thead><tbody><?php foreach ($batchVouchers as $i => $v): ?><tr><td><?= $i + 1 ?></td><td class="font-monospace fw-semibold"><?= e($v['username']) ?></td><?php if ($showBatchPassword): ?><td class="font-monospace"><?= e($v['password']) ?></td><?php endif; ?><td><span class="badge <?= $v['sync_status'] === 'synced' ? 'text-bg-success' : ($v['sync_status'] === 'failed' ? 'text-bg-danger' : 'text-bg-warning') ?>"><?= e($v['sync_status']) ?></span></td><td><?= e($v['usage_status']) ?></td></tr><?php endforeach; ?></tbody></table></div>
</div>
<style>
.compact-52-pages{display:flex;flex-direction:column;align-items:center;gap:18px;padding:8px 0}
.voucher-print-page{box-sizing:border-box;width:200mm;height:287mm;padding:0;margin:0 auto;background:#fff;color:#172033;overflow:hidden;box-shadow:0 2px 18px #17203318}
.compact-52-grid{box-sizing:border-box;width:100%;height:100%;display:grid;grid-template-columns:repeat(4,minmax(0,1fr));grid-template-rows:repeat(13,21mm);gap:1mm;align-content:start}
.compact-voucher{box-sizing:border-box;min-width:0;min-height:0;height:21mm;display:flex;flex-direction:column;overflow:hidden;border:.25mm solid #bfc9d6;border-radius:1mm;background:#fff;color:#172033;break-inside:avoid;page-break-inside:avoid}
.compact-voucher-head{box-sizing:border-box;flex:0 0 4mm;min-height:4mm;padding:.45mm 1mm;display:flex;align-items:center;justify-content:space-between;gap:1mm;color:#fff;font-size:5pt;line-height:1.05;-webkit-print-color-adjust:exact;print-color-adjust:exact;overflow:hidden}
.compact-voucher-head span{max-width:46%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;opacity:.95}.compact-voucher-head strong{max-width:54%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:5.5pt;text-align:right}
.compact-voucher-body{box-sizing:border-box;flex:1;min-height:0;padding:.65mm 1mm;display:flex;flex-direction:column;justify-content:center;gap:.15mm;overflow:hidden}
.compact-voucher-package{font-size:5pt;line-height:1.1;color:#64748b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:.35mm}
.compact-voucher-credential{min-width:0;display:flex;align-items:center;justify-content:space-between;gap:1mm;border-top:.15mm dashed #cbd5e1;padding-top:.25mm;line-height:1.05}.compact-voucher-credential span{flex:0 0 auto;font-size:4.5pt;letter-spacing:.04em;color:#64748b}.compact-voucher-credential strong{min-width:0;max-width:78%;font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:7pt;font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:clip;letter-spacing:-.02em}
.compact-voucher-foot{box-sizing:border-box;flex:0 0 2.4mm;min-height:2.4mm;padding:.35mm 1mm;border-top:.15mm solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;gap:1mm;font-size:4.2pt;line-height:1;color:#64748b;overflow:hidden}.compact-voucher-foot span{min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.compact-voucher-foot b{flex:0 0 auto;font-size:4.2pt;color:#94a3b8}
#voucher-sheet{background:#fff;color:#172033;padding:18px;border-radius:12px}.sheet-heading{text-align:center;margin-bottom:18px}.sheet-heading h2{font-size:22px;margin:3px 0;color:<?= e($voucherTemplate['color']) ?>}.sheet-business{font-size:12px;color:#64748b;font-weight:700;letter-spacing:.08em}.sheet-batch{font-size:11px;color:#64748b}.voucher-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.voucher-card{border:1px solid #cbd5e1;border-radius:9px;overflow:hidden;break-inside:avoid;page-break-inside:avoid;background:#fff}.voucher-band{color:#fff;-webkit-print-color-adjust:exact;print-color-adjust:exact;padding:6px 10px;display:flex;justify-content:space-between;align-items:center;gap:8px}.voucher-index{font-size:10px;opacity:.85}.voucher-brand{font-size:10px;font-weight:700;letter-spacing:.05em}.voucher-card-body{padding:10px}.voucher-title{font-weight:800;font-size:14px;color:#172033}.voucher-package{font-size:10px;color:#64748b;margin:2px 0 8px}.credential-row{display:flex;justify-content:space-between;gap:8px;border-top:1px dashed #cbd5e1;padding:5px 0;align-items:center}.credential-row span{font-size:9px;color:#64748b;letter-spacing:.05em}.credential-row strong{font-family:ui-monospace,monospace;font-size:13px;word-break:break-all}.voucher-meta{display:flex;flex-wrap:wrap;gap:4px 9px;font-size:9px;color:#475569;margin-top:6px}.voucher-comment{font-size:9px;color:#64748b;border-top:1px solid #e2e8f0;padding-top:5px;margin-top:5px}.voucher-footer{border-top:1px solid #e2e8f0;padding:5px 9px;font-size:9px;color:#64748b}.voucher-qr{text-align:center;padding:2px 0 7px}.voucher-qr svg{display:block;width:116px!important;height:116px!important;margin:0 auto}.voucher-qr small{display:block;font-size:8px;color:#64748b;margin-top:3px}.qr-security-note{text-align:center;font-size:10px;color:#64748b;margin:12px 0 0}
.layout-small .sheet-heading{margin-bottom:10px}.layout-small .sheet-heading h2{font-size:15px}.layout-small .voucher-grid{grid-template-columns:repeat(3,minmax(0,1fr));gap:6px}.layout-small .voucher-band{padding:3px 6px}.layout-small .voucher-card-body{padding:6px}.layout-small .voucher-title{font-size:10px}.layout-small .voucher-package{font-size:8px;margin-bottom:4px}.layout-small .credential-row{padding:3px 0}.layout-small .credential-row span{font-size:7px}.layout-small .credential-row strong{font-size:9px}.layout-small .voucher-meta,.layout-small .voucher-comment,.layout-small .voucher-footer{font-size:7px}.layout-small .voucher-qr svg{width:76px!important;height:76px!important}
.layout-qr .voucher-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.layout-qr .voucher-card-body{display:grid;grid-template-columns:minmax(0,1fr) 120px;column-gap:8px}.layout-qr .voucher-title,.layout-qr .voucher-package,.layout-qr .credential-row,.layout-qr .voucher-meta,.layout-qr .voucher-comment{grid-column:1}.layout-qr .voucher-qr{grid-column:2;grid-row:1 / span 5;align-self:center}.layout-qr .voucher-qr svg{width:112px!important;height:112px!important}
@media(max-width:767px){.voucher-grid,.layout-qr .voucher-grid{grid-template-columns:1fr}.layout-small .voucher-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.layout-qr .voucher-card-body{grid-template-columns:minmax(0,1fr) 100px}.layout-qr .voucher-qr svg{width:90px!important;height:90px!important}.compact-52-pages{align-items:flex-start;overflow-x:auto}.voucher-print-page{flex:0 0 200mm}}
@media print{ @page{size:A4 portrait;margin:5mm} html,body{margin:0!important;padding:0!important;background:#fff!important} body *{visibility:hidden!important}.main-area,.content-area{padding:0!important;margin:0!important}.compact-52-pages,.compact-52-pages *{visibility:visible!important}.compact-52-pages{position:absolute!important;left:0!important;top:0!important;width:200mm!important;display:block!important;padding:0!important;gap:0!important}.voucher-print-page{width:200mm!important;height:287mm!important;margin:0!important;padding:0!important;box-shadow:none!important;page-break-after:always;break-after:page;overflow:hidden!important}.voucher-print-page:last-child{page-break-after:auto;break-after:auto}.compact-52-grid{width:200mm!important;height:287mm!important;grid-template-columns:repeat(4,49.25mm)!important;grid-template-rows:repeat(13,21mm)!important;gap:1mm!important}.compact-voucher{width:49.25mm!important;height:21mm!important;max-height:21mm!important}.no-print,#voucher-user-list{display:none!important}#voucher-sheet,#voucher-sheet *{visibility:visible!important}#voucher-sheet{position:absolute;left:0;top:0;width:100%;padding:0!important;border:0!important;box-shadow:none!important;background:#fff!important}.sheet-heading{margin-bottom:10px}.voucher-grid{gap:7px}.voucher-card{break-inside:avoid;page-break-inside:avoid}.layout-small .voucher-grid{grid-template-columns:repeat(3,minmax(0,1fr))}.layout-qr .voucher-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
</style>
<script>
(() => {
  const toggle = document.getElementById('show-user-list');
  const panel = document.getElementById('voucher-user-list');
  if (toggle && panel) toggle.addEventListener('click', () => { panel.hidden = !panel.hidden; if (!panel.hidden) panel.scrollIntoView({behavior:'smooth',block:'start'}); });
})();
</script>
