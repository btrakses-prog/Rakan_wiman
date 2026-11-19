# Upgrade RAKAN WIMAN v0.4.16

Update ini memperbaiki komunikasi ViaQRIS, pemeriksaan status pembayaran, redirect ke voucher, dan menambahkan kontak WhatsApp Admin pada halaman pembelian/pembayaran.

## Sebelum update
1. Backup folder aplikasi dan database.
2. Pastikan Anda mengetahui lokasi folder utama proyek (folder yang berisi `app`, `config`, `database`, `public`, dan `resources`).

## Pasang patch
1. Upload `RAKAN-WIMAN-v0.4.16-UPDATE-PATCH.zip` ke folder utama proyek.
2. Ekstrak dan izinkan overwrite.
3. Jangan menimpa `.env`, `storage`, atau database.
4. Tidak ada migration database baru dan tidak perlu import SQL.
5. Login admin, buka menu QRIS, lalu isi **WhatsApp Admin / Bantuan Pelanggan** dengan format internasional, misalnya `62812xxxxxxxx` (tanpa tanda `+`). Simpan konfigurasi.
6. Pastikan `API URL` tetap memakai endpoint ViaQRIS yang benar, misalnya `https://via-qris-cloud.vercel.app/api/v2`, serta API Token yang valid.

## Uji status pembayaran
1. Buka Audit Transaksi QRIS.
2. Pada invoice yang masih Menunggu, tekan **Cek status**.
3. Jika koneksi gagal, pesan baru akan menjelaskan apakah masalah berasal dari cURL/DNS/SSL/koneksi, HTTP error dari ViaQRIS, atau format JSON.
4. Buka halaman pembayaran pelanggan. Tombol **Periksa Status Pembayaran** dapat ditekan manual. Halaman juga memeriksa otomatis dengan jeda sekitar 30 detik dan jeda lebih panjang setelah error.
5. Setelah ViaQRIS mengonfirmasi `Berhasil` dan nominal cocok, RAKAN WIMAN menerbitkan voucher secara idempoten, menjalankan antrean sinkronisasi, lalu mengarahkan ulang halaman pembayaran untuk menampilkan kode voucher.

## Kontak bantuan pelanggan
Nomor pada menu QRIS digunakan untuk membuat tautan `wa.me` di halaman pembelian dan pembayaran. Jika kolom dikosongkan, tombol Chat Admin tidak ditampilkan.

## Kebutuhan server
- cURL direkomendasikan dan akan digunakan lebih dahulu jika tersedia.
- Jika PHP tidak memiliki ekstensi cURL, fallback PHP stream membutuhkan `allow_url_fopen=On` serta sertifikat CA SSL yang valid.
- Jangan mematikan verifikasi TLS untuk mengatasi error sertifikat. Perbarui CA bundle atau minta penyedia hosting memperbaiki konfigurasi SSL.

## Catatan keamanan
- Voucher hanya diterbitkan setelah status berhasil dikonfirmasi melalui API ViaQRIS dan nominal pada respons cocok dengan nilai invoice.
- Redirect kembali ke halaman tidak dianggap sebagai bukti pembayaran; status server tetap menjadi sumber kebenaran.
- Masa berlaku transaksi tetap 60 menit.
