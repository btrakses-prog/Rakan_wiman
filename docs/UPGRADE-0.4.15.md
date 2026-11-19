# Upgrade RAKAN WIMAN v0.4.15

## Perubahan
- Menambahkan tombol Edit pada setiap paket internet.
- Form edit mengubah nama, kode, harga, profil master HotSpot, Limit Uptime, masa berlaku, dan deskripsi.
- Validasi kode paket unik tetap diterapkan.
- Halaman pembelian publik tidak lagi menampilkan baris `Limit pemakaian`; pelanggan melihat masa berlaku kalender paket.
- Migration 007 menyimpan snapshot nama/kode/profil/limit paket pada voucher dan transaksi agar perubahan paket hanya memengaruhi penerbitan baru.

## Update instalasi yang sudah ada
1. Backup database dan folder aplikasi.
2. Upload isi PATCH v0.4.15 ke folder utama RAKAN WIMAN (folder yang berisi `app`, `public`, `config`, dan `resources`).
3. Ekstrak dan izinkan overwrite.
4. Jangan menimpa `.env` atau `storage`.
5. Buka dashboard sekali. Aplikasi menjalankan migration 007 secara otomatis dan idempotent. Cron juga memastikan migration tersedia sebelum memproses expiry.
6. Buka Paket Internet. Tombol Edit tersedia di setiap baris paket.

Tidak perlu instalasi ulang atau import SQL manual.

## Perilaku perubahan paket
- Harga/nama/profil/limit yang diedit berlaku untuk voucher dan transaksi baru.
- Voucher yang sudah dibuat dan transaksi yang sudah dimulai mempertahankan snapshot nilai paket saat diterbitkan/dipesan.
- Edit paket tidak mengubah akun HotSpot yang sudah ada di MikroTik.
- Baris `Limit pemakaian` di halaman pembelian publik dihilangkan; masa berlaku paket tetap ditampilkan jika diatur.
