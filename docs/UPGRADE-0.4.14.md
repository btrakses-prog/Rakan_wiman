# Upgrade RAKAN WIMAN v0.4.14

## Perubahan

- Daftar **Profil Hanya di MikroTik** kini menampilkan tombol **Buat Master + Sync** di samping status **Belum ada di DB**. Tombol membuka formulir master dengan nama profil sudah terisi. Parameter RouterOS tidak diimpor. Atur parameter master lalu simpan; aplikasi membuat antrean sinkronisasi satu arah Database → MikroTik untuk router aktif.
- Pada mode voucher **Username = Password**, hasil batch, daftar pengguna, dan semua format cetak hanya menampilkan username. Password tidak ditampilkan sebagai kolom/baris terpisah.
- Tombol **Print 52 / A4**, **QR + Auto Login**, dan **Small** kini membuka halaman cetak di tab baru menggunakan URL batch lengkap. Tab cetak menyediakan tombol **Kembali ke Batch**, bukan `window.close()` yang dapat diblokir browser pada tab yang dibuka lewat tautan. Ini menghindari hilangnya halaman batch dan kembali ke dashboard secara tidak sengaja.
- Cetak QR menghasilkan 10 kartu per halaman A4 dengan QR berukuran besar. Untuk mode `Username = Password`, QR berisi username saja; scanner mengisi username ke kedua kolom. Untuk mode username/password terpisah, QR berisi `username:password`.
- Template HotSpot mandiri memiliki tombol **Scan QR Voucher** yang membuka scanner HTTPS pada `APP_URL/hotspot-scan`. Hasil scan dikirim ke tab login yang membukanya; form login normal tetap digunakan termasuk perhitungan CHAP saat aktif.

## Cara update

1. Backup folder aplikasi dan database.
2. Upload `RAKAN-WIMAN-v0.4.14-UPDATE-PATCH.zip` ke folder utama proyek (folder yang berisi `app`, `config`, `public`, `resources`).
3. Ekstrak dan izinkan overwrite.
4. Jangan menimpa `.env` atau folder `storage`.
5. Tidak ada migration database baru.
6. Buka **Pengaturan → Unduh Template Login Page** dan upload ulang seluruh isi ZIP template terbaru ke direktori HotSpot MikroTik.

## Catatan master profil

Tombol **Buat Master + Sync** bukan impor parameter MikroTik. Nama profil diisi sebagai identitas, sementara nilai Shared Users, Rate Limit, timeout, MAC Cookie, Address Pool dan parameter lain perlu ditetapkan di form RAKAN WIMAN. Setelah disimpan, database menjadi sumber konfigurasi dan nilai dikirim ke router.

## Catatan QR scanner

- `APP_URL` harus HTTPS dengan sertifikat valid.
- Walled Garden harus mengizinkan hostname `APP_URL` yang sama. Endpoint `/hotspot-scan` memakai hostname yang sama dengan portal pembelian.
- Kamera membutuhkan izin browser. Scanner menggunakan `BarcodeDetector`; gunakan Chrome/Edge versi terbaru pada Android yang menyediakan fitur tersebut.
- QR berisi kredensial login; siapa pun yang bisa memindai QR dapat menggunakan voucher. Simpan kartu dan foto QR dengan aman.
- Kredensial diproses di browser, tidak dikirim ke server aplikasi. Handoff otomatis hanya diterima dari scanner yang dibuka melalui halaman login, dengan pemeriksaan origin dan referrer.
