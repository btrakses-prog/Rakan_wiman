# Upgrade RAKAN WIMAN 0.4.4 ke 0.4.5

Update ini mengganti template N-STORE sebelumnya dengan captive portal mandiri RAKAN WIMAN. N-STORE tidak lagi disertakan, tidak lagi menjadi dependency, dan tidak memerlukan license key. Ia hanya pernah digunakan sebagai referensi fitur dan alur.

## Isi update

- Login page baru dengan desain responsive RAKAN WIMAN.
- Form voucher username/password dengan dukungan HotSpot CHAP/MD5 menggunakan helper lokal `md5.js`.
- Tombol **BELI VOUCHER** langsung ke `APP_URL/buy`.
- Halaman `flogin.html`, `alogin.html`, `status.html`, `logout.html`, `error.html`, dan `redirect.html`.
- ZIP template dibuat otomatis dari Pengaturan tanpa ekstensi PHP ZipArchive.
- Tidak ada migration database baru.

## Cara update

1. Backup folder aplikasi dan database.
2. Upload `RAKAN-WIMAN-v0.4.5-UPDATE-PATCH.zip` ke folder utama aplikasi yang berisi `app/`, `public/`, `config/`, dan `resources/`.
3. Ekstrak dan izinkan overwrite. Jangan menimpa `.env` atau `storage/`.
4. Login ke RAKAN WIMAN dan buka **Pengaturan → Unduh Template Login Page**.
5. Ekstrak ZIP yang diunduh. Upload seluruh isi folder `RAKAN-WIMAN-Hotspot` ke direktori HTML HotSpot MikroTik.
6. Setelah memastikan file baru aktif, hapus folder lama `hotspot-template/NStore/` dan arsip `NStore-login-template.zip` dari instalasi server jika masih ada. File tersebut tidak dipakai lagi.
7. Tambahkan hostname `APP_URL` ke Walled Garden.
8. Uji halaman captive portal dari perangkat yang belum login, lalu uji login voucher.

## Tentang template lama

Template lama yang pernah diunggah ke MikroTik tidak otomatis diganti oleh update aplikasi. Unggah file RAKAN WIMAN yang baru ke direktori HTML HotSpot agar perangkat menggunakan login page mandiri yang baru.
