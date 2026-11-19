<?php
use function RakanWiman\Core\e;
use function RakanWiman\Core\url;
$printLayout = in_array(($printLayout ?? 'normal'), ['normal','qr','small'], true) ? $printLayout : 'normal';
$perPage = $printLayout === 'qr' ? 10 : ($printLayout === 'small' ? 24 : 52);
$voucherPages = array_chunk($batchVouchers, $perPage);
$layoutLabel = $printLayout === 'qr' ? 'QR AUTO LOGIN · 10 / A4' : ($printLayout === 'small' ? 'SMALL · 24 / A4' : '52 VOUCHER / A4');
$batchReturnUrl = url('vouchers/batch/' . (int)$batch['id']);
?>
<style>
*{box-sizing:border-box}html,body{margin:0;padding:0;font-family:Arial,Helvetica,sans-serif;color:#172033;background:#e8edf4}
.print-toolbar{position:sticky;top:0;z-index:5;display:flex;align-items:center;gap:10px;padding:12px 18px;background:#fff;border-bottom:1px solid #dbe3ee;box-shadow:0 2px 8px #17203312}.print-toolbar strong{margin-right:auto;font-size:14px}.print-toolbar button,.print-toolbar a{border:0;border-radius:7px;padding:9px 14px;font-weight:700;cursor:pointer;text-decoration:none;display:inline-block}.print-now{background:#2563eb;color:#fff}.print-close{background:#e8edf4;color:#172033}
.print-page{box-sizing:border-box;width:200mm;height:287mm;margin:18px auto;padding:0;background:#fff;box-shadow:0 5px 28px #17203318;overflow:hidden;position:relative;break-after:page;page-break-after:always}.print-page:last-child{break-after:auto;page-break-after:auto}
/* Normal: 4 columns x 13 rows = 52 vouchers per A4. */
.normal-grid{box-sizing:border-box;width:200mm;height:287mm;display:grid;grid-template-columns:repeat(4,49.25mm);grid-template-rows:repeat(13,21mm);grid-auto-flow:row;gap:1mm;align-content:start}
.compact-voucher{box-sizing:border-box;width:49.25mm;height:21mm;min-width:0;min-height:0;display:flex;flex-direction:column;overflow:hidden;border:.25mm solid #bfc9d6;border-radius:1mm;background:#fff;color:#172033;break-inside:avoid;page-break-inside:avoid}
.compact-voucher-head{box-sizing:border-box;flex:0 0 4mm;min-height:4mm;padding:.45mm 1mm;display:flex;align-items:center;justify-content:space-between;gap:1mm;color:#fff;font-size:5pt;line-height:1.05;-webkit-print-color-adjust:exact;print-color-adjust:exact;overflow:hidden}.compact-voucher-head span{max-width:46%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;opacity:.95}.compact-voucher-head strong{max-width:54%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:5.5pt;text-align:right}
.compact-voucher-body{box-sizing:border-box;flex:1;min-height:0;padding:.65mm 1mm;display:flex;flex-direction:column;justify-content:center;gap:.15mm;overflow:hidden}.compact-voucher-package{font-size:5pt;line-height:1.1;color:#64748b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:.35mm}.compact-voucher-credential{min-width:0;display:flex;align-items:center;justify-content:space-between;gap:1mm;border-top:.15mm dashed #cbd5e1;padding-top:.25mm;line-height:1.05}.compact-voucher-credential span{flex:0 0 auto;font-size:4.5pt;letter-spacing:.04em;color:#64748b}.compact-voucher-credential strong{min-width:0;max-width:78%;font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:7pt;font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:clip;letter-spacing:-.02em}
.compact-voucher-foot{box-sizing:border-box;flex:0 0 2.4mm;min-height:2.4mm;padding:.35mm 1mm;border-top:.15mm solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;gap:1mm;font-size:4.2pt;line-height:1;color:#64748b;overflow:hidden}.compact-voucher-foot span{min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.compact-voucher-foot b{flex:0 0 auto;font-size:4.2pt;color:#94a3b8}
/* QR: 2 columns x 5 rows = 10 cards per A4; large enough for camera recognition. */
.qr-grid{box-sizing:border-box;width:200mm;height:287mm;display:grid;grid-template-columns:repeat(2,99.5mm);grid-template-rows:repeat(5,56.6mm);gap:1mm;align-content:start}
.qr-voucher{width:99.5mm;height:56.6mm;min-width:0;min-height:0;border:.3mm solid #bfc9d6;border-radius:2mm;overflow:hidden;background:#fff;display:flex;flex-direction:column;break-inside:avoid;page-break-inside:avoid}
.qr-head{height:7mm;flex:0 0 7mm;padding:1mm 2mm;color:#fff;display:flex;justify-content:space-between;align-items:center;font-size:8pt;font-weight:800;-webkit-print-color-adjust:exact;print-color-adjust:exact}.qr-body{flex:1;min-height:0;display:flex;align-items:center;gap:3mm;padding:2mm 3mm}.qr-info{min-width:0;flex:1}.qr-title{font-size:10pt;font-weight:800;margin-bottom:1mm}.qr-package{font-size:7pt;color:#64748b;margin-bottom:2mm;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.qr-credential{border-top:.2mm dashed #cbd5e1;padding:1.3mm 0;display:flex;flex-direction:column;gap:.3mm}.qr-credential span{font-size:6.2pt;color:#64748b;letter-spacing:.04em}.qr-credential strong{font:800 12pt ui-monospace,SFMono-Regular,Consolas,monospace;word-break:break-all}.qr-extra{font-size:6.5pt;color:#64748b;margin-top:1mm}.qr-code{flex:0 0 31mm;width:31mm;height:31mm;display:flex;align-items:center;justify-content:center;background:#fff}.qr-code svg{width:30mm!important;height:30mm!important;max-width:none!important;display:block}.qr-code-label{text-align:center;font-size:5.8pt;color:#64748b;margin-top:.6mm}.qr-foot{height:5mm;flex:0 0 5mm;border-top:.2mm solid #e2e8f0;padding:1mm 2mm;font-size:6pt;color:#64748b;display:flex;justify-content:space-between;align-items:center}
/* Small: 3 columns x 8 rows = 24 cards per A4. */
.small-grid{box-sizing:border-box;width:200mm;height:287mm;display:grid;grid-template-columns:repeat(3,66mm);grid-template-rows:repeat(8,35.75mm);gap:1mm;align-content:start}.small-voucher{width:66mm;height:35.75mm;border:.25mm solid #bfc9d6;border-radius:1.2mm;overflow:hidden;display:flex;flex-direction:column;background:#fff;break-inside:avoid;page-break-inside:avoid}.small-head{height:5mm;flex:0 0 5mm;padding:.6mm 1.5mm;display:flex;justify-content:space-between;align-items:center;color:#fff;font-size:6pt;font-weight:800;-webkit-print-color-adjust:exact;print-color-adjust:exact}.small-body{flex:1;padding:1.6mm 2mm;display:flex;flex-direction:column;justify-content:center;min-height:0}.small-title{font-size:8pt;font-weight:800}.small-package{font-size:6pt;color:#64748b;margin-bottom:1mm}.small-credential{display:flex;justify-content:space-between;gap:1mm;border-top:.2mm dashed #d6deea;padding:.9mm 0;font-size:6pt}.small-credential strong{font:800 8pt ui-monospace,SFMono-Regular,Consolas,monospace}.small-foot{height:4mm;flex:0 0 4mm;border-top:.2mm solid #e2e8f0;padding:.7mm 1.5mm;font-size:5.5pt;color:#64748b;overflow:hidden;white-space:nowrap;text-overflow:ellipsis}
@media print{@page{size:A4 portrait;margin:5mm}html,body{margin:0!important;padding:0!important;background:#fff!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}.print-toolbar{display:none!important}.print-page{width:200mm!important;height:287mm!important;margin:0!important;padding:0!important;box-shadow:none!important;overflow:hidden!important;break-after:page;page-break-after:always}.print-page:last-child{break-after:auto;page-break-after:auto}.normal-grid{width:200mm!important;height:287mm!important;grid-template-columns:repeat(4,49.25mm)!important;grid-template-rows:repeat(13,21mm)!important;gap:1mm!important}.compact-voucher{width:49.25mm!important;height:21mm!important;max-height:21mm!important}.qr-grid{width:200mm!important;height:287mm!important;grid-template-columns:repeat(2,99.5mm)!important;grid-template-rows:repeat(5,56.6mm)!important;gap:1mm!important}.qr-voucher{width:99.5mm!important;height:56.6mm!important}.small-grid{width:200mm!important;height:287mm!important;grid-template-columns:repeat(3,66mm)!important;grid-template-rows:repeat(8,35.75mm)!important;gap:1mm!important}.small-voucher{width:66mm!important;height:35.75mm!important}}
</style>
<div class="print-toolbar">
  <strong>Batch #<?= (int)$batch['id'] ?> · <?= count($batchVouchers) ?> Voucher · <?= count($voucherPages) ?> Halaman A4 · <?= e($layoutLabel) ?></strong>
  <button class="print-now" type="button" onclick="window.print()">Cetak / Simpan PDF</button>
  <a class="print-close" href="<?= e($batchReturnUrl) ?>">Kembali ke Batch</a>
</div>
<?php foreach ($voucherPages as $pageIndex => $pageVouchers): ?>
<section class="print-page" aria-label="Halaman <?= $pageIndex + 1 ?>">
<?php if ($printLayout === 'normal'): ?>
  <div class="normal-grid">
    <?php foreach ($pageVouchers as $index => $v): $globalIndex = ($pageIndex * $perPage) + $index + 1; $sameCredentials = ($v['user_mode'] ?? 'separate') === 'same'; ?>
      <article class="compact-voucher">
        <div class="compact-voucher-head" style="background:<?= e($voucherTemplate['color']) ?>"><span><?= e($voucherTemplate['subtitle']) ?></span><strong><?= e($voucherTemplate['title']) ?></strong></div>
        <div class="compact-voucher-body"><div class="compact-voucher-package"><?= e($v['package_name']) ?> · <?= e($v['mikrotik_profile']) ?></div><div class="compact-voucher-credential"><span>USER</span><strong><?= e($v['username']) ?></strong></div><?php if ($voucherTemplate['show_password'] && !$sameCredentials): ?><div class="compact-voucher-credential"><span>PASS</span><strong><?= e($v['password']) ?></strong></div><?php endif; ?></div>
        <div class="compact-voucher-foot"><span><?= e($voucherTemplate['footer']) ?></span><b>#<?= $globalIndex ?></b></div>
      </article>
    <?php endforeach; ?>
  </div>
<?php elseif ($printLayout === 'qr'): ?>
  <div class="qr-grid">
    <?php foreach ($pageVouchers as $index => $v): $globalIndex = ($pageIndex * $perPage) + $index + 1; $sameCredentials = ($v['user_mode'] ?? 'separate') === 'same'; $payload = $sameCredentials ? (string)$v['username'] : ((string)$v['username'] . ':' . (string)$v['password']); ?>
      <article class="qr-voucher">
        <div class="qr-head" style="background:<?= e($voucherTemplate['color']) ?>"><span><?= e($voucherTemplate['subtitle']) ?></span><span><?= e($voucherTemplate['title']) ?> · #<?= $globalIndex ?></span></div>
        <div class="qr-body">
          <div class="qr-info">
            <div class="qr-title"><?= e($v['package_name']) ?></div>
            <div class="qr-package">Profile <?= e($v['mikrotik_profile']) ?></div>
            <div class="qr-credential"><span>USERNAME</span><strong><?= e($v['username']) ?></strong></div>
            <?php if ($voucherTemplate['show_password'] && !$sameCredentials): ?><div class="qr-credential"><span>PASSWORD</span><strong><?= e($v['password']) ?></strong></div><?php endif; ?>
            <div class="qr-extra">Scan QR di halaman login HotSpot untuk masuk otomatis.</div>
          </div>
          <div><div class="qr-code"><?php try { echo \RakanWiman\Services\QrCode::svg($payload, 4, 4); } catch (Throwable $qrError) { ?><code><?= e($payload) ?></code><?php } ?></div><div class="qr-code-label">QR LOGIN VOUCHER</div></div>
        </div>
        <div class="qr-foot"><span><?= e($voucherTemplate['footer']) ?></span><span><?= $sameCredentials ? 'USER = PASS' : 'KREDENSIAL LOGIN' ?></span></div>
      </article>
    <?php endforeach; ?>
  </div>
<?php else: ?>
  <div class="small-grid">
    <?php foreach ($pageVouchers as $index => $v): $globalIndex = ($pageIndex * $perPage) + $index + 1; $sameCredentials = ($v['user_mode'] ?? 'separate') === 'same'; ?>
      <article class="small-voucher">
        <div class="small-head" style="background:<?= e($voucherTemplate['color']) ?>"><span><?= e($voucherTemplate['subtitle']) ?></span><span>#<?= $globalIndex ?></span></div>
        <div class="small-body"><div class="small-title"><?= e($voucherTemplate['title']) ?></div><div class="small-package"><?= e($v['package_name']) ?> · <?= e($v['mikrotik_profile']) ?></div><div class="small-credential"><span>USER</span><strong><?= e($v['username']) ?></strong></div><?php if ($voucherTemplate['show_password'] && !$sameCredentials): ?><div class="small-credential"><span>PASS</span><strong><?= e($v['password']) ?></strong></div><?php endif; ?></div>
        <div class="small-foot"><?= e($voucherTemplate['footer']) ?></div>
      </article>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
</section>
<?php endforeach; ?>
<script>window.addEventListener('load',function(){setTimeout(function(){try{window.focus();window.print();}catch(e){}},350);});</script>
