# Upgrade RAKAN WIMAN ke v0.4.4

Update ini menyederhanakan generator voucher, integrasi captive portal N-STORE, dan panel dashboard. Database, akun, `.env`, data transaksi, voucher, router, serta pengaturan lama tetap digunakan.

## Cara update

1. Backup folder proyek dan database MySQL/MariaDB.
2. Upload `RAKAN-WIMAN-v0.4.4-UPDATE-PATCH.zip` ke folder utama proyek (folder yang berisi `app`, `config`, `database`, `public`, dan `resources`).
3. Ekstrak di folder tersebut dan izinkan overwrite file aplikasi.
4. Jangan menimpa `.env`, `storage`, atau database.
5. Login kembali dan buka Dashboard, Voucher, serta Pengaturan.
6. Tidak ada migration database baru. Tidak perlu menjalankan installer atau mengimpor SQL.

## Perubahan Generator Voucher

- Kolom Time Limit dihapus dari form Generate User.
- Limit uptime selalu mengikuti `limit_uptime_seconds` pada paket/profil yang dipilih di menu Paket & Profil.
- Data Limit tetap tersedia untuk batas kuota `limit-bytes-total` per user.
- Data voucher lama tidak diubah. Voucher yang telah dibuat sebelum update tetap mempertahankan opsi limit yang tersimpan.

## Integrasi N-STORE Login Page

- Metode login utama disederhanakan menjadi login voucher. Mode member dan QR scanner eksternal dinonaktifkan; fitur tambahan seperti menu Trial/Chat/Bantuan dan galeri tidak ditampilkan di halaman login.
- Tombol **BELI VOUCHER WIFI** hanya ditampilkan satu kali dan berada langsung di bawah form login.
- Tombol diarahkan ke `APP_URL/buy` dari file `.env` secara otomatis saat template diunduh melalui menu Pengaturan.
- Download template menggunakan ZIP dinamis yang dibuat di server, sehingga tidak memerlukan ekstensi PHP ZipArchive. Isi archive berada di folder `NStore/`.
- Upload isi folder `NStore` ke direktori HotSpot MikroTik.
- Tambahkan Walled Garden untuk hostname aplikasi (domain saja, tanpa scheme atau path):

```routeros
/ip hotspot walled-garden
add action=allow dst-host=domain-aplikasi-anda.id comment="RAKAN WIMAN Purchase Portal"
```

- Tambahkan host lain hanya jika QR image/checkout memang dimuat langsung oleh browser pelanggan dari host eksternal.
- **Lisensi N-STORE:** validasi lisensi dan script asli tetap dipertahankan. Masukkan license key resmi yang diberikan pembuat template pada properti `license` di `NStore/js/config.js` sebelum mengunggah file. Jangan menghapus atau mengakali validasi lisensi.
- URL portal harus HTTPS dan sertifikatnya valid agar pelanggan dapat membuka checkout dengan aman sebelum login hotspot.

## Dashboard Logs

Panel Tahapan Pengembangan diganti dengan Logs. Dashboard menampilkan maksimal 15 aktivitas audit terbaru dari tabel `audit_logs`, termasuk waktu, nama administrator/system, deskripsi, dan alamat IP bila tersedia. Tidak ada tabel baru.

## Pengujian

Jalankan `php tests-basic.php` dari folder proyek. Tes tersebut memeriksa helper voucher, parsing data, enkripsi, ULID, dan pembuatan QR dasar. ZIP template juga dapat diunduh dari Pengaturan dan diperiksa dengan `unzip -t` sebelum diunggah ke MikroTik.
