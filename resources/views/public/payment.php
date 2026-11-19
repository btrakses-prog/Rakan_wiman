<?php
use function RakanWiman\Core\e;
$baseAmount = (int)$status['amount'];
$displayAmount = (int)($status['paid_amount'] ?? $status['payable_amount'] ?? $baseAmount);
$uniqueCode = $status['unique_code'] !== null ? (int)$status['unique_code'] : ($displayAmount > $baseAmount ? $displayAmount - $baseAmount : null);
$amountKnown = $status['paid_amount'] !== null || $status['payable_amount'] !== null;
$chatUrl = null;
if (!empty($supportWhatsapp)) {
    $chatText = "Halo Admin RAKAN WIMAN, saya membutuhkan bantuan terkait pembayaran QRIS.\nInvoice: {$invoice}\nPaket: {$status['package_name']}\nHarga paket: Rp " . number_format($baseAmount, 0, ',', '.') . "\nNominal QRIS: Rp " . number_format($displayAmount, 0, ',', '.') . "\nKode unik: " . ($uniqueCode === null ? 'belum diketahui' : 'Rp ' . number_format($uniqueCode, 0, ',', '.')) . "\nStatus: {$status['status']}";
    $chatUrl = 'https://wa.me/' . rawurlencode($supportWhatsapp) . '?text=' . rawurlencode($chatText);
}
?>
<div class="card">
    <div style="display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap">
        <div>
            <h1 style="margin:0">Pembayaran QRIS</h1>
            <div class="muted">Invoice <?= e($invoice) ?></div>
        </div>
        <span class="state" id="payState"><?= e(strtoupper($status['status'])) ?></span>
    </div>

    <div class="row" style="margin-top:22px">
        <div class="col" style="text-align:center">
            <?php if ($status['status'] === 'pending' && !empty($payment['qr_image_url'])): ?>
                <img class="qr" id="qrisPaymentImage" src="<?= e($payment['qr_image_url']) ?>" alt="QRIS Pembayaran" referrerpolicy="no-referrer">
                <p class="muted">Scan QRIS menggunakan aplikasi mobile banking atau e-wallet.</p>
                <?php if (!empty($payment['payment_url']) && $payment['payment_url'] !== $payment['qr_image_url']): ?>
                    <a class="btn btn-outline" href="<?= e($payment['payment_url']) ?>" target="_blank" rel="noopener noreferrer">Buka Halaman Pembayaran</a>
                <?php endif; ?>
            <?php elseif ($status['status'] === 'pending' && !empty($payment['payment_url'])): ?>
                <a class="btn" href="<?= e($payment['payment_url']) ?>" target="_blank" rel="noopener noreferrer">Buka Halaman Pembayaran</a>
            <?php elseif ($status['status'] === 'paid'): ?>
                <div style="font-size:52px;color:#16a34a">✓</div>
                <h2>Pembayaran berhasil</h2>
            <?php else: ?>
                <div class="state warning">Transaksi tidak aktif</div>
            <?php endif; ?>
        </div>

        <div class="col">
            <p><strong>Paket:</strong> <?= e($status['package_name']) ?></p>
            <p><strong>Harga paket:</strong> Rp <?= number_format($baseAmount, 0, ',', '.') ?></p>
            <p><strong>Total QRIS:</strong> <strong id="qrisAmountDisplay">Rp <?= number_format($displayAmount, 0, ',', '.') ?></strong></p>
            <p id="qrisUniqueCodeDisplay" class="muted" style="margin-top:-8px;<?= ($uniqueCode !== null && $uniqueCode > 0) ? '' : 'display:none;' ?>"><?= ($uniqueCode !== null && $uniqueCode > 0) ? 'Kode unik: +Rp ' . number_format($uniqueCode, 0, ',', '.') : '' ?></p>
            <?php if ($status['status'] === 'pending' && !$amountKnown): ?>
                <p id="qrisAmountHint" class="muted" style="font-size:13px">ViaQRIS dapat menambahkan kode unik otomatis. Ikuti nominal akhir yang tertera setelah QR dipindai di aplikasi pembayaran.</p>
            <?php else: ?>
                <p id="qrisAmountHint" class="muted" style="font-size:13px;display:none"></p>
            <?php endif; ?>
            <p>
                <strong>Batas pembayaran:</strong> <?= e($status['expires_at'] ?? '-') ?>
                <?php if ($status['status'] === 'pending'): ?>
                    <span class="state warning" id="countdown">60:00</span>
                <?php endif; ?>
            </p>
            <div id="payMessage" class="muted" role="status" aria-live="polite">
                <?php if ($status['status'] === 'pending'): ?>Menunggu pembayaran. Tekan tombol periksa status setelah Anda menyelesaikan pembayaran.<?php elseif ($status['status'] === 'paid' && !$voucher): ?>Pembayaran sudah terkonfirmasi. Voucher sedang diproses. Simpan nomor invoice ini bila perlu menghubungi admin.<?php elseif ($status['status'] === 'paid'): ?>Pembayaran berhasil. Voucher Anda tersedia di bawah.<?php elseif ($status['status'] === 'expired'): ?>Transaksi sudah kedaluwarsa atau dibatalkan. Silakan buat transaksi baru.<?php elseif ($status['status'] === 'failed'): ?>Transaksi gagal dibuat. Silakan hubungi admin dengan nomor invoice ini.<?php endif; ?>
            </div>

            <?php if ($status['status'] === 'pending'): ?>
                <style>
                    .rw-payment-actions{display:grid;grid-template-columns:1fr;gap:12px;margin-top:20px}
                    .rw-payment-action{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;padding:15px 16px;border:1px solid #dbe5f1;border-radius:13px;background:#fbfdff}
                    .rw-payment-action-copy{flex:1;min-width:210px}
                    .rw-payment-action-copy strong{display:block;color:#10213b;font-size:15px;margin-bottom:3px}
                    .rw-payment-action-copy small{display:block;color:#64748b;line-height:1.45}
                    .rw-payment-action .btn{white-space:nowrap}
                    .rw-payment-action .btn-whatsapp{background:#16a34a;color:#fff;border:1px solid #16a34a}
                    .rw-payment-action .btn-whatsapp:hover{background:#15803d}
                    .rw-payment-action .btn-disabled{background:#e2e8f0;color:#64748b;border:1px solid #cbd5e1;cursor:not-allowed}
                    @media(max-width:560px){.rw-payment-action{align-items:stretch}.rw-payment-action .btn{width:100%;text-align:center}}
                </style>
                <div class="rw-payment-actions" aria-label="Bantuan pembayaran">
                    <section class="rw-payment-action">
                        <div class="rw-payment-action-copy">
                            <strong>Periksa Status Pembayaran</strong>
                            <small>Tekan setelah Anda menyelesaikan pembayaran QRIS untuk memeriksa konfirmasi dari ViaQRIS.</small>
                        </div>
                        <button type="button" class="btn" id="checkPaymentButton" data-idle-label="Periksa Status Pembayaran">Periksa Status Pembayaran</button>
                    </section>
                    <section class="rw-payment-action">
                        <div class="rw-payment-action-copy">
                            <strong>Butuh bantuan pembayaran?</strong>
                            <small>Hubungi administrator melalui WhatsApp jika QR tidak bisa dibayar, pembayaran belum terdeteksi, atau ada kendala transaksi.</small>
                        </div>
                        <?php if ($chatUrl): ?>
                            <a class="btn btn-whatsapp" href="<?= e($chatUrl) ?>" target="_blank" rel="noopener noreferrer" aria-label="Hubungi admin melalui WhatsApp">Hubungi Admin via WhatsApp</a>
                        <?php else: ?>
                            <button type="button" class="btn btn-disabled" disabled aria-disabled="true" title="Nomor WhatsApp admin belum diatur">Hubungi Admin via WhatsApp</button>
                            <small class="muted" style="flex-basis:100%;text-align:right">Nomor bantuan belum diatur. Pengelola dapat mengisinya di menu QRIS → WhatsApp Admin.</small>
                        <?php endif; ?>
                    </section>
                </div>
                <div id="paymentCheckFeedback" class="muted" style="margin-top:10px" role="status" aria-live="polite"></div>
            <?php elseif (($status['status'] !== 'paid' || !$voucher || ($status['sync_status'] ?? '') !== 'synced')): ?>
                <style>
                    .rw-payment-help{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-top:18px;padding:15px 16px;border:1px solid #dbe5f1;border-radius:13px;background:#fbfdff}
                    .rw-payment-help strong{display:block;color:#10213b;margin-bottom:3px}
                    .rw-payment-help small{display:block;color:#64748b}
                    .rw-payment-help .btn-whatsapp{background:#16a34a;color:#fff;border:1px solid #16a34a;white-space:nowrap}
                    .rw-payment-help .btn-disabled{background:#e2e8f0;color:#64748b;border:1px solid #cbd5e1;cursor:not-allowed;white-space:nowrap}
                </style>
                <div class="rw-payment-help">
                    <div><strong>Butuh bantuan pembayaran?</strong><small>Hubungi administrator melalui WhatsApp dan sertakan nomor invoice ini.</small></div>
                    <?php if ($chatUrl): ?>
                        <a class="btn btn-whatsapp" href="<?= e($chatUrl) ?>" target="_blank" rel="noopener noreferrer">Hubungi Admin via WhatsApp</a>
                    <?php else: ?>
                        <button type="button" class="btn btn-disabled" disabled aria-disabled="true">Hubungi Admin via WhatsApp</button>
                        <small style="flex-basis:100%;text-align:right">Nomor bantuan belum diatur pada menu QRIS.</small>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($status['status'] === 'paid' && !$voucher): ?>
        <div class="alert" style="margin-top:18px">Pembayaran sudah terkonfirmasi, tetapi voucher belum tersedia. Jangan melakukan pembayaran ulang. Hubungi administrator dan sertakan nomor invoice.</div>
    <?php endif; ?>

    <?php if ($voucher): ?>
        <style>
            .rw-voucher-result{margin-top:26px!important;padding:28px!important;background:linear-gradient(135deg,#f8fbff,#eef5ff)!important;border:1px solid #cfe0ff!important}
            .rw-voucher-head{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:18px}
            .rw-voucher-grid{display:grid;grid-template-columns:minmax(0,1.15fr) minmax(230px,.85fr);gap:22px;align-items:center}
            .rw-credential{background:#fff;border:1px solid #c8dcff;border-radius:14px;padding:18px 20px;margin:10px 0}
            .rw-credential-label{font-size:12px;letter-spacing:.08em;color:#64748b;font-weight:800;margin:0 0 6px}
            .rw-credential-value{font:800 clamp(22px,3vw,30px)/1.25 ui-monospace,SFMono-Regular,Consolas,monospace;letter-spacing:.02em;word-break:break-all;color:#10213b}
            .rw-voucher-qr{background:#fff;border:1px solid #d9e4f3;border-radius:16px;padding:16px;text-align:center;max-width:290px;margin:0 auto}
            .rw-voucher-qr svg{width:100%!important;height:auto!important;max-width:240px;display:block;margin:0 auto}
            .rw-voucher-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:20px}
            .rw-voucher-download{background:#166534;color:#fff}
            @media(max-width:650px){.rw-voucher-grid{grid-template-columns:1fr}.rw-voucher-result{padding:20px!important}.rw-voucher-qr{max-width:260px}}
        </style>
        <div class="card rw-voucher-result">
            <div class="rw-voucher-head">
                <div><div class="muted" style="font-weight:800;letter-spacing:.12em;font-size:11px">RAKAN WIMAN · INTERNET ACCESS</div><h2 style="margin:4px 0 0;font-size:30px">Voucher Anda</h2><p class="muted" style="margin:4px 0 0">Paket <?= e($voucher['package_name'] ?? $status['package_name']) ?><?php if (!empty($voucher['validity_days'])): ?> · <?= (int)$voucher['validity_days'] ?> hari<?php endif; ?></p></div>
                <span class="state <?= ($status['sync_status'] ?? '') === 'synced' ? 'success' : 'warning' ?>" id="syncState"><?= ($status['sync_status'] ?? '') === 'synced' ? 'SIAP DIGUNAKAN' : 'MENUNGGU SINKRON' ?></span>
            </div>
            <div class="rw-voucher-grid">
                <div>
                    <?php if (($voucher['user_mode'] ?? 'separate') === 'same'): ?>
                        <div class="rw-credential"><div class="rw-credential-label">USERNAME = PASSWORD</div><div class="rw-credential-value"><?= e($voucher['username']) ?></div></div>
                        <p class="muted" style="margin:8px 0 0">Gunakan username yang sama pada kolom username dan password saat login.</p>
                    <?php else: ?>
                        <div class="rw-credential"><div class="rw-credential-label">USERNAME</div><div class="rw-credential-value"><?= e($voucher['username']) ?></div></div>
                        <div class="rw-credential"><div class="rw-credential-label">PASSWORD</div><div class="rw-credential-value"><?= e($voucher['password']) ?></div></div>
                    <?php endif; ?>
                    <p class="muted" id="activationNote" style="margin-top:14px"><?= ($status['sync_status'] ?? '') === 'synced' ? 'Voucher sudah tersinkron ke router dan dapat digunakan.' : 'Pembayaran diterima. Voucher sedang menunggu aktivasi/sinkronisasi router. QR dan data voucher sudah dapat disimpan.' ?></p>
                </div>
                <div class="rw-voucher-qr">
                    <?= $voucher['qr_svg'] ?>
                    <strong style="display:block;margin-top:10px;color:#10213b">SCAN UNTUK LOGIN WIFI</strong>
                    <span class="muted" style="font-size:12px">Buka halaman login WiFi, pilih Scan QR Voucher, lalu arahkan kamera ke kode ini.</span>
                </div>
            </div>
            <div class="rw-voucher-actions">
                <a class="btn rw-voucher-download" href="<?= e($voucherPdfUrl) ?>"><i aria-hidden="true">↓</i> Download Voucher PDF</a>
            </div>
        </div>
    <?php endif; ?>

    <p style="margin-top:20px"><a class="btn btn-outline" href="<?= e($buyUrl) ?>">Kembali ke katalog paket</a></p>
</div>

<?php if ($status['status'] === 'pending' || $voucher): ?>
<script>
(() => {
    const invoice = <?= json_encode($invoice, JSON_UNESCAPED_SLASHES) ?>;
    const statusUrl = <?= json_encode($paymentStatusUrl, JSON_UNESCAPED_SLASHES) ?>;
    const qrHintUrl = <?= json_encode($paymentQrHintUrl ?? '', JSON_UNESCAPED_SLASHES) ?>;
    const paymentPageUrl = <?= json_encode($paymentPageUrl, JSON_UNESCAPED_SLASHES) ?>;
    const expiryMs = <?= json_encode(!empty($status['expires_at']) ? strtotime($status['expires_at']) * 1000 : null) ?>;
    const hasVoucher = <?= $voucher ? 'true' : 'false' ?>;
    const button = document.getElementById('checkPaymentButton');
    const feedback = document.getElementById('paymentCheckFeedback');
    let checking = false;
    let pollDelay = 30000;

    const baseAmount = <?= json_encode($baseAmount) ?>;
    const amountKnownFromServer = <?= $amountKnown ? 'true' : 'false' ?>;
    const amountDisplay = document.getElementById('qrisAmountDisplay');
    const uniqueDisplay = document.getElementById('qrisUniqueCodeDisplay');
    const amountHint = document.getElementById('qrisAmountHint');

    function idr(value) {
        return 'Rp ' + new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(value);
    }

    function emvAmount(raw) {
        if (typeof raw !== 'string' || !raw.startsWith('000201')) return null;
        let offset = 0;
        while (offset + 4 <= raw.length) {
            const tag = raw.slice(offset, offset + 2);
            const lengthText = raw.slice(offset + 2, offset + 4);
            if (!/^\d{2}$/.test(lengthText)) return null;
            const size = Number(lengthText);
            offset += 4;
            if (offset + size > raw.length) return null;
            const value = raw.slice(offset, offset + size);
            if (tag === '54') {
                const amount = Number(value);
                return Number.isFinite(amount) && amount > 0 ? Math.round(amount) : null;
            }
            offset += size;
        }
        return null;
    }

    async function saveQrAmountHint(raw) {
        if (!qrHintUrl || typeof raw !== 'string' || !raw.startsWith('000201')) return;
        try {
            const response = await fetch(qrHintUrl, {
                method: 'POST',
                cache: 'no-store',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ qr_payload: raw })
            });
            const result = await response.json().catch(() => null);
            if (response.ok && result && result.ok && result.payment) {
                updateAmountFromServer(result.payment);
                if (amountHint) amountHint.textContent = 'Nominal dan kode unik dibaca dari QRIS. Status pembayaran tetap diverifikasi oleh ViaQRIS.';
            }
        } catch (error) {
            // The QR remains usable; the QR amount is still shown locally below.
        }
    }

    async function detectDynamicNominalFromQr() {
        const image = document.getElementById('qrisPaymentImage');
        if (!image) return;
        if (!('BarcodeDetector' in window) || !window.BarcodeDetector) {
            if (amountHint && !amountKnownFromServer) amountHint.textContent = 'Browser ini tidak mendukung pembacaan nominal QR otomatis. Scan QR dengan aplikasi bank/e-wallet dan ikuti nominal akhir yang muncul.';
            return;
        }
        try {
            if (!image.complete) await new Promise((resolve, reject) => {
                image.addEventListener('load', resolve, { once: true });
                image.addEventListener('error', reject, { once: true });
            });
            const detector = new BarcodeDetector({ formats: ['qr_code'] });
            const codes = await detector.detect(image);
            for (const item of codes) {
                const raw = item.rawValue || '';
                const amount = emvAmount(raw);
                if (amount !== null && amount >= baseAmount) {
                    if (amountDisplay) amountDisplay.textContent = idr(amount);
                    const unique = amount - baseAmount;
                    if (uniqueDisplay) {
                        uniqueDisplay.textContent = unique > 0 ? 'Kode unik: +' + idr(unique) : 'Tidak ada kode unik tambahan';
                        uniqueDisplay.style.display = 'block';
                    }
                    if (amountHint) amountHint.textContent = 'Nominal dibaca dari QRIS dinamis. Status pembayaran tetap diverifikasi oleh ViaQRIS.';
                    await saveQrAmountHint(raw);
                    break;
                }
            }
        } catch (error) {
            if (amountHint && !amountKnownFromServer) amountHint.textContent = 'QRIS dapat dipindai seperti biasa. Ikuti nominal akhir yang ditampilkan aplikasi bank/e-wallet.';
        }
    }

    function updateAmountFromServer(payment) {
        if (!payment) return;
        const amount = Number(payment.paid_amount || payment.payable_amount || 0);
        if (amount > 0 && amountDisplay) amountDisplay.textContent = idr(amount);
        const unique = payment.unique_code === null || payment.unique_code === undefined ? null : Number(payment.unique_code);
        if (uniqueDisplay && unique !== null) {
            uniqueDisplay.textContent = unique > 0 ? 'Kode unik: +' + idr(unique) : 'Tidak ada kode unik tambahan';
            uniqueDisplay.style.display = 'block';
        }
        if (amountHint && amount > 0) amountHint.textContent = payment.status === 'paid' ? 'Nominal pembayaran terkonfirmasi oleh ViaQRIS.' : 'Nominal akhir dikembalikan oleh ViaQRIS.';
    }

    function updateCountdown() {
        const element = document.getElementById('countdown');
        if (!element || !expiryMs) return;
        const remaining = Math.max(0, Math.floor((expiryMs - Date.now()) / 1000));
        element.textContent = remaining <= 0
            ? 'KEDALUWARSA'
            : String(Math.floor(remaining / 60)).padStart(2, '0') + ':' + String(remaining % 60).padStart(2, '0');
        if (remaining <= 0) element.classList.add('warning');
    }

    async function checkPayment(manual = false) {
        if (checking) return;
        checking = true;
        if (button) {
            button.disabled = true;
            button.textContent = 'Memeriksa...';
        }
        if (feedback && manual) feedback.textContent = 'Menghubungi server pembayaran ViaQRIS...';

        try {
            const response = await fetch(statusUrl, {
                method: 'GET',
                cache: 'no-store',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            });
            const payload = await response.json().catch(() => null);
            if (!response.ok || !payload || payload.ok !== true) {
                const message = payload && payload.message ? payload.message : 'Server pembayaran tidak memberikan respons yang valid.';
                throw new Error(message);
            }

            const payment = payload.payment || {};
            updateAmountFromServer(payment);
            const state = document.getElementById('payState');
            if (state && payment.status) state.textContent = payment.status.toUpperCase();

            if (payment.status === 'paid') {
                if (feedback) feedback.textContent = 'Pembayaran berhasil dikonfirmasi. Menyiapkan voucher Anda...';
                const message = document.getElementById('payMessage');
                if (message) message.textContent = 'Pembayaran berhasil. Halaman voucher sedang dibuka.';
                if (!hasVoucher && payment.voucher_id) {
                    window.location.replace(paymentPageUrl + '?confirmed=1');
                    return;
                }
                if (!payment.voucher_id) {
                    if (feedback) feedback.textContent = 'Pembayaran sudah terkonfirmasi, tetapi voucher belum tersedia. Silakan Chat Admin dan sertakan invoice ' + invoice + '.';
                    pollDelay = 30000;
                }
                if (payment.sync_status && document.getElementById('syncState')) {
                    document.getElementById('syncState').textContent = 'Sinkronisasi: ' + payment.sync_status;
                    if (payment.sync_status === 'synced' && document.getElementById('activationNote')) {
                        document.getElementById('activationNote').textContent = 'Voucher sudah tersinkron ke router dan dapat digunakan.';
                    }
                }
            } else if (payment.status === 'expired' || payment.status === 'cancelled' || payment.status === 'failed') {
                if (feedback) feedback.textContent = 'Transaksi berstatus ' + payment.status + '. Silakan kembali ke katalog atau Chat Admin jika sudah melakukan pembayaran.';
                if (document.getElementById('payMessage')) document.getElementById('payMessage').textContent = 'Transaksi tidak lagi berstatus menunggu pembayaran.';
                pollDelay = 60000;
            } else {
                if (feedback) feedback.textContent = manual ? 'Pembayaran belum terdeteksi. Jika Anda baru saja membayar, tunggu sebentar lalu periksa kembali.' : 'Belum ada konfirmasi pembayaran. Anda dapat menekan tombol periksa status kapan saja.';
                pollDelay = 30000;
            }
        } catch (error) {
            if (feedback) feedback.textContent = 'Tidak dapat memeriksa status pembayaran: ' + error.message + '. Anda dapat mencoba lagi atau Chat Admin.';
            pollDelay = Math.min(Math.max(pollDelay * 1.5, 30000), 90000);
        } finally {
            checking = false;
            if (button) {
                button.disabled = false;
                button.textContent = button.dataset.idleLabel || 'Periksa Status Pembayaran';
            }
            if (!hasVoucher && document.getElementById('paymentCheckFeedback')) {
                window.clearTimeout(window.__rwQrisPollTimer);
                window.__rwQrisPollTimer = window.setTimeout(() => checkPayment(false), pollDelay);
            }
        }
    }

    updateCountdown();
    detectDynamicNominalFromQr();
    window.setInterval(updateCountdown, 1000);
    if (button) button.addEventListener('click', () => checkPayment(true));
    if (!hasVoucher && <?= $status['status'] === 'pending' ? 'true' : 'false' ?>) {
        window.__rwQrisPollTimer = window.setTimeout(() => checkPayment(false), 15000);
    } else if (hasVoucher) {
        window.__rwQrisPollTimer = window.setTimeout(() => checkPayment(false), 30000);
    }
})();
</script>
<?php endif; ?>
