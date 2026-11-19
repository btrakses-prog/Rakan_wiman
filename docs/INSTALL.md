# Instalasi RAKAN WIMAN 0.4.7 — Web Installer

## Persyaratan

- PHP 8.2+ dengan ekstensi `pdo_mysql`, `openssl`, `json`, dan `mbstring`.
- MySQL 8+ atau MariaDB 10.6+.
- Apache dengan `mod_rewrite` dan `.htaccess` aktif, atau konfigurasi Nginx yang diarahkan ke `public/index.php` serta `public/install.php`.
- HTTPS/SSL aktif pada domain aplikasi.
- Folder proyek dapat ditulis oleh PHP saat instalasi agar `.env` dapat dibuat dan file installer dapat menghapus dirinya sendiri.

## Cara instalasi otomatis

1. Upload dan ekstrak folder proyek, misalnya `public_html/RAKAN-WIMAN-v0.4.1`.
2. **Cara yang direkomendasikan:** arahkan Document Root subdomain ke `RAKAN-WIMAN-v0.4.1/public`. Dengan cara ini alamat aplikasi cukup `https://wifi.domainanda.com`.
3. **Alternatif jika Document Root tidak bisa diubah:** biarkan folder proyek berada di dalam Document Root. File `.htaccess` di folder proyek akan melayani aplikasi melalui prefiks folder, misalnya `https://wifi.domainanda.com/RAKAN-WIMAN-v0.4.1/`. Installer mendeteksi prefiks ini otomatis. Jangan membuka `/login` di akar domain pada mode ini; gunakan URL dengan prefiks folder yang ditampilkan installer.
4. Aktifkan SSL/HTTPS.
5. Buka `https://wifi.domainanda.com/RAKAN-WIMAN-v0.4.1/install` untuk mode subfolder, atau `https://wifi.domainanda.com/install` jika Document Root sudah menunjuk ke folder `public`.
5. Isi URL aplikasi, zona waktu, koneksi database, dan akun Superadmin.
6. Pilih cara database:
   - **Buat database otomatis**: gunakan jika user database memiliki hak `CREATE DATABASE`.
   - **Database sudah dibuat**: hilangkan centang jika database dan user sudah dibuat melalui cPanel/DirectAdmin. Database harus kosong dan user harus sudah diberi seluruh hak akses pada database tersebut.
7. Klik **Instal RAKAN WIMAN**. Installer akan menguji koneksi, membuat database bila dipilih, menjalankan migration `001` sampai `005`, membuat akun Superadmin, menghasilkan `APP_KEY`, menulis `.env`, dan membuat penanda instalasi.
8. Setelah berhasil, installer mencoba menghapus `public/install.php` dan mengarahkan browser ke halaman login. Bila hosting menolak penghapusan file, hapus `public/install.php` secara manual dari File Manager. Penanda instalasi tetap mengunci installer.
9. Login dengan email dan kata sandi Superadmin yang dibuat pada formulir.

## Catatan hosting bersama (cPanel / DirectAdmin)

Pada sebagian besar hosting bersama, user MySQL tidak memiliki hak membuat database baru. Dalam kondisi tersebut, buat database dan user melalui panel hosting terlebih dahulu, tambahkan user ke database dengan seluruh hak akses, lalu buka installer dan hilangkan centang **Buat database otomatis**. Installer tetap membuat semua tabel dan menjalankan seluruh migration secara otomatis; tidak perlu membuka phpMyAdmin atau mengimpor file SQL.

## Setelah masuk ke aplikasi

1. Tambahkan router MikroTik dan lakukan uji koneksi.
2. Pastikan profil Hotspot yang digunakan sudah ada di MikroTik.
3. Buat paket dengan nama profil Hotspot yang sama persis.
4. Atur integrasi ViaQRIS dan WhatsApp pada menu pengaturan.
5. Pasang cron setiap menit sesuai `docs/CRON.md`.
6. Dari menu Pengaturan, unduh template login HotSpot RAKAN WIMAN. Ekstrak arsip dan upload seluruh isi folder `RAKAN-WIMAN-Hotspot` ke direktori HTML HotSpot MikroTik. Terapkan Walled Garden sesuai `docs/WALLED-GARDEN.md`.

## Mengatasi 404 pada Apache

Jika file `.../public/assets/app.css` dapat dibuka melalui URL yang mengandung nama folder proyek, tetapi `/assets/app.css` dan `/login` di akar domain menghasilkan 404, berarti domain masih menunjuk ke folder induk. Pada kondisi ini gunakan mode subfolder: akses `/NAMA-FOLDER-PROYEK/` dan pastikan `APP_URL` memuat prefiks `/NAMA-FOLDER-PROYEK`. Update 0.4.1 mendeteksi prefiks tersebut saat instalasi baru dan menulis aturan `.htaccess` untuk mengarahkan route serta aset ke folder `public`. Untuk instalasi yang sudah terlanjur dibuat pada 0.4.0, ubah nilai `APP_URL` di `.env` secara manual sebelum membuka ulang aplikasi.

## Keamanan

- Jangan membagikan file `.env`. File ini berisi kredensial database dan APP_KEY.
- Arahkan Document Root ke folder `public`; jangan menjadikan folder proyek utama sebagai root publik jika tidak diperlukan.
- Jika Document Root terpaksa diarahkan ke folder proyek utama, pastikan aturan `.htaccess` root berjalan dan file tersembunyi diblokir oleh server.
- Pertahankan file `storage/installed.lock` setelah instalasi selesai.
- Jangan mengganti APP_KEY setelah aplikasi mulai menyimpan password router atau voucher.
- Batasi akses RouterOS API hanya dari alamat IP server aplikasi.

## Upgrade dari instalasi versi sebelumnya

Jangan menjalankan Web Installer untuk upgrade database yang sudah berisi data. Installer hanya untuk instalasi baru pada database kosong atau instalasi RAKAN WIMAN parsial yang memiliki tabel penanda `rw_schema_migrations`. Backup database dan gunakan prosedur upgrade khusus sebelum memperbarui aplikasi yang sudah berjalan.
