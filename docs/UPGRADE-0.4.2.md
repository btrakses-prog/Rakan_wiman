# Update RAKAN WIMAN 0.4.2

Update ini memisahkan menu administrasi, QRIS, WhatsApp Gateway, dan template voucher. Tidak ada migration database baru.

## Sebelum memperbarui

1. Backup database MySQL/MariaDB.
2. Backup folder proyek, terutama `.env`, `storage/`, dan file konfigurasi yang telah disesuaikan.
3. Jangan jalankan installer lagi.

## Cara update pada instalasi yang sudah ada

1. Upload `RAKAN-WIMAN-v0.4.2-UPDATE-PATCH.zip` ke folder utama proyek saat ini.
2. Ekstrak arsip ke folder utama proyek yang sama dan izinkan overwrite untuk file aplikasi.
3. Jangan menghapus atau mengganti `.env`, `storage/installed.lock`, database, atau folder `storage`.
4. Login ulang ke panel administrasi. Tidak perlu import SQL dan tidak perlu mengulang instalasi.

Contoh: bila folder proyek saat ini `public_html/RAKAN-WIMAN-v0.4.0/`, ekstrak isi patch langsung ke folder `RAKAN-WIMAN-v0.4.0/`, bukan membuat folder bersarang baru.

## Perubahan menu

- **Pengaturan:** unduhan template login N-STORE, ubah profil/email/password akun yang sedang login, dan manajemen akun Admin/Operator untuk Superadmin.
- **Template Voucher:** atur judul, subjudul, footer, warna aksen dan apakah password tampil pada hasil cetak voucher.
- **QRIS:** atur kode merchant, API URL/token, router pembelian; audit pembayaran dapat dicari/filter tanggal/status, invoice pending dapat diperiksa statusnya secara manual, dan data dapat diekspor CSV; tombol pendaftaran QRIS statis membuka `https://via-qris-cloud.vercel.app/`.
- **WhatsApp Gateway:** atur URL/secret/kode negara/template pesan; kelola sender; lihat log pengiriman dan retry pesan gagal.

Pengaturan baru ditulis ke tabel `settings` yang telah tersedia pada instalasi sebelumnya. Tidak ada perubahan struktur tabel.

## Setelah update

Buka `APP_URL` yang tersimpan di `.env`, lalu periksa menu baru. Dari Pengaturan, unduh `RAKAN-WIMAN-NStore-Login-Template.zip` untuk memperoleh direktori `NStore` yang akan diunggah ke direktori Hotspot MikroTik.
