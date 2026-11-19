# Upgrade RAKAN WIMAN v0.4.21

## Langkah
1. Backup folder aplikasi dan database.
2. Upload isi `RAKAN-WIMAN-v0.4.21-UPDATE-PATCH.zip` ke folder utama aplikasi.
3. Ekstrak dan izinkan overwrite.
4. Jangan menimpa `.env` atau `storage`.
5. Tidak ada migration database pada update ini.
6. Buka Dashboard dan tekan **Perbarui** pada panel User HotSpot Aktif.

## Hak akses MikroTik
User API memerlukan kebijakan `read` agar dapat membaca `/ip/hotspot/active/print`. Tidak perlu memberi izin `write` khusus untuk fitur pemantauan ini.

## Perilaku monitor
- Membaca sesi live pada setiap router dengan `is_active=1`.
- Menggunakan timeout monitoring yang lebih singkat (default 3 detik per operasi koneksi/baca).
- Menampilkan jumlah dan rincian sesi per router.
- Data router yang gagal dibaca tidak dihitung. Dashboard akan menandai data sebagai sebagian atau tidak tersedia.
- Tidak membuat, menghapus, atau mengubah akun HotSpot.
- Tidak membuat tabel baru dan tidak membutuhkan import SQL.
