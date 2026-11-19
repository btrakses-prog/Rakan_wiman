<?php use function RakanWiman\Core\e; use function RakanWiman\Core\csrf_field; ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4"><div><h4 class="fw-bold mb-1">Template Voucher</h4><p class="text-secondary mb-0">Atur tampilan kartu voucher saat dicetak atau disimpan sebagai PDF.</p></div><span class="badge text-bg-light border">Cetak / PDF · 52 kartu per A4</span></div>
<?php if (!empty($error)): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<div class="row g-3"><div class="col-xl-6"><div class="panel-card"><h5 class="fw-bold mb-3">Pengaturan Template Cetak</h5><form method="post" action="voucher-template"><?= csrf_field() ?><div class="mb-3"><label class="form-label">Judul voucher</label><input class="form-control" name="voucher_title" value="<?= e($template['title']) ?>" maxlength="80" required></div><div class="mb-3"><label class="form-label">Nama usaha / subjudul</label><input class="form-control" name="voucher_subtitle" value="<?= e($template['subtitle']) ?>" maxlength="120"></div><div class="mb-3"><label class="form-label">Catatan bagian bawah</label><textarea class="form-control" name="voucher_footer" rows="3" maxlength="240"><?= e($template['footer']) ?></textarea></div><div class="mb-3"><label class="form-label">Warna aksen</label><div class="d-flex align-items-center gap-2"><input class="form-control form-control-color" type="color" name="voucher_accent_color" value="<?= e($template['color']) ?>"><code><?= e($template['color']) ?></code></div></div><div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="voucher_show_password" id="showPassword" value="1" <?= $template['show_password'] ? 'checked' : '' ?>><label class="form-check-label" for="showPassword">Tampilkan password pada hasil cetak</label></div>
<div class="border-top pt-3 mt-3"><label class="form-label fw-semibold" for="test-print-qty">Jumlah voucher untuk Test Print</label><div class="input-group" style="max-width:260px"><input class="form-control" type="number" id="test-print-qty" min="1" max="500" step="1" value="52" inputmode="numeric"><span class="input-group-text">voucher</span></div><div class="form-text">Isi 1–500. Layout standar memuat maksimal 52 voucher per halaman A4 (4 kolom × 13 baris).</div></div>
<div class="d-flex flex-wrap gap-2 mt-3"><button class="btn btn-primary" type="submit"><i class="bi bi-save me-1"></i> Simpan Template</button><button class="btn btn-outline-primary" type="button" id="test-voucher-print"><i class="bi bi-printer me-1"></i> Test Print</button></div><div class="form-text mt-2">Test Print memakai data contoh, tidak membuat voucher aktif, tidak menyimpan perubahan, dan tidak menghubungi MikroTik.</div></form></div></div>
<div class="col-xl-6"><div class="panel-card"><h5 class="fw-bold mb-3">Pratinjau Voucher</h5><div class="border rounded-4 overflow-hidden" style="max-width:420px;margin:auto"><div class="p-3 text-white" style="background:<?= e($template['color']) ?>"><div class="small opacity-75"><?= e($template['subtitle']) ?></div><div class="fs-5 fw-bold"><?= e($template['title']) ?></div></div><div class="p-3"><div class="d-flex justify-content-between mb-2"><span class="small text-secondary">PAKET</span><strong>Internet 1 Jam</strong></div><hr><div class="mb-2"><div class="small text-secondary">USERNAME</div><div class="fs-5 fw-bold font-monospace">RW7K2M9QX</div></div><?php if ($template['show_password']): ?><div class="mb-2"><div class="small text-secondary">PASSWORD</div><div class="fs-5 fw-bold font-monospace">A8P4Z2</div></div><?php endif; ?><div class="border-top pt-2 mt-3 small text-secondary"><?= e($template['footer']) ?></div></div></div><p class="small text-secondary mt-3 mb-0">Tampilan cetak standar dibuat ringkas agar 52 voucher dapat dimuat pada satu lembar A4.</p></div></div></div>

<script>
(() => {
  const button = document.getElementById('test-voucher-print');
  const qtyInput = document.getElementById('test-print-qty');
  if (!button || !qtyInput) return;

  const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
  })[char]);

  button.addEventListener('click', () => {
    const form = button.closest('form');
    const field = (name) => form?.querySelector(`[name="${name}"]`);
    const title = field('voucher_title')?.value.trim() || 'VOUCHER INTERNET';
    const subtitle = field('voucher_subtitle')?.value.trim() || 'RAKAN WIMAN';
    const footer = field('voucher_footer')?.value.trim() || '';
    const colorRaw = field('voucher_accent_color')?.value || '#2563eb';
    const color = /^#[0-9a-fA-F]{6}$/.test(colorRaw) ? colorRaw : '#2563eb';
    const showPassword = Boolean(field('voucher_show_password')?.checked);
    const quantity = Number.parseInt(qtyInput.value, 10);

    if (!Number.isInteger(quantity) || quantity < 1 || quantity > 500) {
      alert('Jumlah Test Print harus antara 1 sampai 500 voucher.');
      qtyInput.focus();
      return;
    }

    const voucherCard = (index) => {
      const number = String(index + 1).padStart(4, '0');
      const username = `TEST${number}`;
      const password = `RW${number}X`;
      return `
        <article class="compact-voucher">
          <div class="compact-voucher-head" style="background:${color}">
            <span>${escapeHtml(subtitle)}</span><strong>${escapeHtml(title)}</strong>
          </div>
          <div class="compact-voucher-body">
            <div class="compact-voucher-package">PAKET CONTOH · TEST PRINT</div>
            <div class="compact-voucher-credential"><span>USER</span><strong>${username}</strong></div>
            ${showPassword ? `<div class="compact-voucher-credential"><span>PASS</span><strong>${password}</strong></div>` : ''}
          </div>
          <div class="compact-voucher-foot"><span>${escapeHtml(footer)}</span><b>TEST</b></div>
        </article>`;
    };

    const pages = [];
    for (let offset = 0; offset < quantity; offset += 52) {
      const cards = [];
      const pageEnd = Math.min(offset + 52, quantity);
      for (let index = offset; index < pageEnd; index++) cards.push(voucherCard(index));
      pages.push(`<section class="print-page" aria-label="Halaman ${pages.length + 1}"><div class="compact-52-grid">${cards.join('')}</div><div class="page-counter">TEST PRINT · HALAMAN ${pages.length + 1} · ${quantity} VOUCHER</div></section>`);
    }

    const preview = window.open('', '_blank', 'width=1100,height=820');
    if (!preview) {
      alert('Popup diblokir browser. Izinkan pop-up untuk domain RAKAN WIMAN, lalu tekan Test Print kembali.');
      return;
    }

    const doc = `<!doctype html>
      <html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
      <title>Test Print ${quantity} Voucher — ${escapeHtml(title)}</title>
      <style>
        *{box-sizing:border-box}html,body{margin:0;padding:0;font-family:Arial,Helvetica,sans-serif;color:#172033;background:#eef2f7}
        .toolbar{position:sticky;top:0;z-index:2;display:flex;align-items:center;gap:10px;padding:12px 18px;background:#fff;border-bottom:1px solid #dbe3ee;box-shadow:0 2px 8px #17203312}
        .toolbar strong{margin-right:auto;font-size:14px}.toolbar button{border:0;border-radius:7px;padding:9px 14px;font-weight:700;cursor:pointer}.print-btn{background:#2563eb;color:#fff}.close-btn{background:#e8edf4;color:#172033}
        .print-page{box-sizing:border-box;width:200mm;height:287mm;margin:18px auto;padding:0;background:#fff;box-shadow:0 5px 28px #17203318;overflow:hidden;position:relative}
        .compact-52-grid{box-sizing:border-box;width:200mm;height:287mm;display:grid;grid-template-columns:repeat(4,49.25mm);grid-template-rows:repeat(13,21mm);grid-auto-flow:row;gap:1mm;align-content:start}
        .compact-voucher{box-sizing:border-box;width:49.25mm;height:21mm;min-width:0;min-height:0;display:flex;flex-direction:column;overflow:hidden;border:.25mm solid #bfc9d6;border-radius:1mm;background:#fff;color:#172033;break-inside:avoid;page-break-inside:avoid}
        .compact-voucher-head{box-sizing:border-box;flex:0 0 4mm;min-height:4mm;padding:.45mm 1mm;display:flex;align-items:center;justify-content:space-between;gap:1mm;color:#fff;font-size:5pt;line-height:1.05;-webkit-print-color-adjust:exact;print-color-adjust:exact;overflow:hidden}
        .compact-voucher-head span{max-width:46%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;opacity:.95}.compact-voucher-head strong{max-width:54%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:5.5pt;text-align:right}
        .compact-voucher-body{box-sizing:border-box;flex:1;min-height:0;padding:.65mm 1mm;display:flex;flex-direction:column;justify-content:center;gap:.15mm;overflow:hidden}
        .compact-voucher-package{font-size:5pt;line-height:1.1;color:#64748b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:.35mm}
        .compact-voucher-credential{min-width:0;display:flex;align-items:center;justify-content:space-between;gap:1mm;border-top:.15mm dashed #cbd5e1;padding-top:.25mm;line-height:1.05}
        .compact-voucher-credential span{flex:0 0 auto;font-size:4.5pt;letter-spacing:.04em;color:#64748b}.compact-voucher-credential strong{min-width:0;max-width:78%;font-family:ui-monospace,monospace;font-size:7pt;font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:clip;letter-spacing:-.02em}
        .compact-voucher-foot{box-sizing:border-box;flex:0 0 2.4mm;min-height:2.4mm;padding:.35mm 1mm;border-top:.15mm solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;gap:1mm;font-size:4.2pt;line-height:1;color:#64748b;overflow:hidden}
        .compact-voucher-foot span{min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.compact-voucher-foot b{flex:0 0 auto;font-size:4.2pt;color:#94a3b8}
        .page-counter{display:none}
        @media(max-width:800px){.print-page{margin:12px 0;transform-origin:top left}}
        @media print{@page{size:A4 portrait;margin:5mm}html,body{margin:0;padding:0;background:#fff}.toolbar{display:none!important}.print-page{width:200mm;height:287mm;margin:0;box-shadow:none;page-break-after:always;break-after:page}.print-page:last-child{page-break-after:auto;break-after:auto}.page-counter{display:none}}
      </style></head><body>
      <div class="toolbar"><strong>Test Print · ${quantity} Voucher · ${Math.ceil(quantity / 52)} Halaman A4</strong><button class="print-btn" onclick="window.print()">Cetak / Simpan PDF</button><button class="close-btn" onclick="window.close()">Tutup</button></div>
      ${pages.join('')}
      <script>window.addEventListener('load',()=>{setTimeout(()=>{try{window.focus();window.print();}catch(e){}},350)});<\/script>
      </body></html>`;

    preview.document.open();
    preview.document.write(doc);
    preview.document.close();
  });
})();
</script>
