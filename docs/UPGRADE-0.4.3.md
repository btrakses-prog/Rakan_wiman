# Upgrade RAKAN WIMAN ke v0.4.3

Update ini menambahkan generator voucher yang lebih fleksibel dan pilihan cetak. Database dan konfigurasi instalasi lama tetap digunakan.

## Cara update

1. Backup folder proyek dan database MySQL/MariaDB.
2. Upload dan ekstrak `RAKAN-WIMAN-v0.4.3-UPDATE-PATCH.zip` di folder utama proyek (folder yang berisi `app`, `config`, `database`, `public`, dan `resources`).
3. Izinkan overwrite file aplikasi yang diminta. Jangan menimpa `.env`, `storage`, atau database.
4. Login kembali ke dashboard. Pada request pertama, aplikasi membuat tabel tambahan `voucher_generation_options` secara otomatis. Cron juga memiliki pemeriksaan migration yang sama.
5. Buka menu Voucher dan coba generate 1 voucher dengan router yang dapat diuji. Periksa status antrean sinkronisasi.

Tidak perlu menjalankan installer dan tidak perlu impor SQL manual. Jika akun database tidak memiliki hak `CREATE TABLE`, aplikasi akan menampilkan pesan error migration. Berikan hak CREATE dan REFERENCES untuk database aplikasi, kemudian muat ulang halaman.

## Fitur generator

- Qty: 1–500 voucher per batch.
- Router tujuan: pilih salah satu router aktif yang sudah didaftarkan.
- Hotspot Server: `all` atau nama server tertentu.
- User Mode: username dan password berbeda, atau username sama dengan password.
- Name Length: 3–8 karakter.
- Prefix: opsional, maksimal 24 karakter.
- Character: lowercase, uppercase, mixed-case, dan tiga variasi alphanumeric.
- Profile: dipilih dari paket aktif; nilai profil MikroTik mengikuti `mikrotik_profile` pada paket.
- Time Limit: opsional dalam format `HH:MM:SS` atau RouterOS duration seperti `1d2h`. Kosong berarti mengikuti limit uptime paket.
- Data Limit: opsional dalam KB, MB, atau GB; disimpan sebagai byte dan diterapkan sebagai `limit-bytes-total` per user.
- Comment: catatan opsional, ikut disertakan dalam comment user MikroTik setelah ID internal RAKAN WIMAN.

## Pilihan cetak

Setelah batch dibuat, halaman detail batch menyediakan:

- **Print**: kartu voucher dua kolom untuk kertas A4 atau Save as PDF.
- **QR**: kartu voucher dengan QR code yang dibuat secara lokal oleh PHP. Kredensial tidak dikirim ke layanan QR pihak ketiga. QR berisi `username:password` jika password ditampilkan pada template; jika password disembunyikan pada template, QR hanya berisi username.
- **Small**: kartu kecil tiga kolom untuk pemotongan/cetak label.
- **User List**: menampilkan daftar username, password (mengikuti pengaturan template), status sinkronisasi, dan status penggunaan.

Template judul, subjudul, warna, footer, dan opsi tampilkan password mengikuti menu Template Voucher.

## Catatan limit

`limit-uptime` dan `limit-bytes-total` dipasang pada user HotSpot MikroTik. Cron RAKAN WIMAN juga memeriksa akumulasi uptime dan byte counters pada pembacaan router berikutnya, kemudian memasukkan proses penghapusan ke antrean saat batas tercapai. Untuk kuota data, angka `bytes-in + bytes-out` bergantung pada counter yang dikembalikan router.

## Keamanan dan konsistensi

- Password voucher tetap dienkripsi menggunakan APP_KEY.
- Username tetap dijamin unik oleh constraint database dan tidak didaur ulang.
- `request_key` membuat submit ulang pada request yang sama mengembalikan batch sebelumnya.
- Password batch yang tersimpan hanya didekripsi untuk ditampilkan kepada admin yang sudah login ketika membuka detail batch.
- QR dibuat di server aplikasi, bukan melalui API QR eksternal.

## Pengujian

Jalankan `php tests-basic.php` dari folder proyek untuk uji parser limit, enkripsi, ULID, dan pembuatan SVG QR. Tes ini tidak menggantikan uji integrasi MySQL, RouterOS API, atau pencetakan di printer fisik.
