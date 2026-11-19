# Arsitektur RAKAN WIMAN

## Modul utama
1. Admin & RBAC: akun administrator, role, session, audit trail.
2. Router Manager: multi-router, credential terenkripsi, status koneksi, konfigurasi hotspot.
3. HotSpot Profile Manager: master profil di MySQL, status per router, sinkronisasi satu arah DB → MikroTik, dan antrean retry.
4. Package Manager: harga, referensi master profil HotSpot, limit-uptime, masa berlaku kalender.
5. Voucher Engine: generator on-demand dan bulk; unique username/public ID; transaction lock; idempotency.
6. Payment: adapter ViaQRIS, invoice, callback verification, amount matching, event deduplication, expiry 60 menit.
7. Router Sync: queue/retry; rekonsiliasi voucher pada router pengganti tanpa membuat voucher baru.
8. WhatsApp: sender JWT, `/auth`, `/send/text`, queue, retry, template, log.
9. Portal: captive portal mandiri RAKAN WIMAN untuk MikroTik HotSpot dan customer checkout portal.
10. Expiry Worker: cek accumulated uptime dari RouterOS dan expiry kalender dari database.

## Model status voucher
Status penjualan, sinkronisasi, dan pemakaian dipisah menjadi tiga dimensi:
- `sale_status`: available, reserved, sold, cancelled.
- `sync_status`: pending, synced, failed, not_required.
- `usage_status`: unused, active, expired, disabled.

Pemisahan ini menghindari satu kolom status yang ambigu. Voucher bulk yang belum terjual dapat berstatus available + synced + unused. Voucher pembelian dapat berstatus sold + pending + unused sampai router mengonfirmasi sinkronisasi.

## Aturan anti-duplikasi
- `vouchers.username` UNIQUE dan `vouchers.public_id` UNIQUE.
- `payments.gateway_transaction_id` UNIQUE.
- `webhook_events(gateway,event_key)` UNIQUE.
- Callback memproses transaksi dalam DB transaction dengan row lock.
- Pembuatan voucher dan update status pembayaran dilakukan satu kali secara atomik.
- Retry sinkronisasi hanya memakai voucher_id yang sama.
- Penghapusan dari router tidak menghapus riwayat atau membebaskan username.

## Master profil HotSpot

- `hotspot_profiles` adalah sumber kebenaran konfigurasi profil yang dikelola RAKAN WIMAN.
- `hotspot_profile_router_sync` menyimpan status dan hash konfigurasi per router.
- `hotspot_profile_sync_queue` membuat pekerjaan create/update yang dapat dicoba ulang oleh cron.
- Profil hanya di MikroTik ditampilkan sebagai informasi dan tidak diimpor. Profil lama yang direferensikan paket dimigrasikan sebagai `is_managed=0` agar tidak tertimpa sebelum administrator meninjau dan mengadopsinya.
- Sinkronisasi hanya mengirim master database ke router; tidak ada proses reverse sync.
