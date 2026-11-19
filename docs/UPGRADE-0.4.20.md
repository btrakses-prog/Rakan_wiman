# Upgrade RAKAN WIMAN v0.4.20

## Perubahan
- Menambahkan tombol **Test WhatsApp** di halaman WhatsApp Gateway.
- Modal meminta sender dengan status `connected` dan nomor tujuan.
- Sistem mengirim pesan tes langsung ke endpoint `/send/text` menggunakan JWT sender yang dipilih.
- Jika token ditolak dengan HTTP/API 401 atau 403, sistem mengambil JWT baru melalui `/auth`, memperbarui token tersimpan, lalu mencoba sekali lagi.
- Keberhasilan dan kegagalan dicatat pada `audit_logs`; pesan tes juga diberi label `Test` pada tabel Sending WhatsApp.
- Pesan tes tidak dikirim ulang oleh worker cron dan tidak dapat di-retry sebagai pesan voucher.
- Tidak ada perubahan skema database.
- Perubahan v0.4.19 yang memisahkan tindakan pemeriksaan status pembayaran QRIS dan bantuan WhatsApp tetap dipertahankan.

## Cara update instalasi lama
1. Backup folder aplikasi dan database.
2. Upload isi ZIP UPDATE PATCH ke folder utama RAKAN WIMAN (sejajar `app`, `public`, `config`, dan `resources`).
3. Ekstrak dan izinkan overwrite.
4. Jangan timpa `.env` atau `storage`.
5. Tidak ada migration database baru.
6. Buka menu WhatsApp Gateway, pastikan setidaknya satu sender berstatus **connected**, klik **Test WhatsApp**, pilih sender, isi nomor tujuan, lalu kirim.

## Catatan
- Nomor tujuan yang diawali `0` akan dikonversi menggunakan kode negara yang sudah diatur.
- Tes hanya mengirim satu pesan percobaan dan tidak membuat voucher.
- Hasil tes akan terlihat pada audit/log WhatsApp dengan label `Test`.
- Pastikan penerima memang mengizinkan pesan uji.
