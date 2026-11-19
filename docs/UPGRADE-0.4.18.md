# Upgrade RAKAN WIMAN v0.4.18

## Fitur

- Menambahkan pengaturan khusus generator voucher pembelian QRIS pada menu **QRIS → Pengaturan Voucher Otomatis QRIS**.
- Setiap invoice QRIS yang terkonfirmasi lunas menerbitkan tepat satu voucher secara idempoten.
- Mode `Username & Password` atau `Username = Password` mengikuti pengaturan QRIS.
- Prefix, panjang karakter 3–8, pola karakter, HotSpot Server, Data Limit, dan komentar digunakan ketika akun HotSpot dibuat.
- Profil HotSpot, router tujuan, Limit Uptime, dan masa berlaku kalender tetap mengikuti paket serta snapshot invoice saat pembelian.
- Halaman berhasil bayar menampilkan kredensial lebih besar dan QR `RWV1` yang kompatibel dengan fitur **Scan QR Voucher** pada login page RAKAN WIMAN.
- Tombol **Download Voucher PDF** menghasilkan PDF A6 dengan QR vektor.
- PDF mengikuti menu **Template Voucher**: judul, subjudul, warna aksen, footer, dan pengaturan tampil/sembunyi password.

## Cara update

1. Backup folder aplikasi dan database.
2. Upload `RAKAN-WIMAN-v0.4.18-UPDATE-PATCH.zip` ke folder utama proyek (sejajar dengan `app`, `config`, `public`, dan `resources`).
3. Ekstrak dan izinkan overwrite.
4. Jangan menimpa `.env` atau folder `storage`.
5. Tidak ada migration database baru dan tidak perlu menjalankan installer.
6. Buka **QRIS → Pengaturan Voucher Otomatis QRIS**, lalu simpan pengaturan generator sesuai kebutuhan.
7. Pembelian QRIS berikutnya memakai pengaturan yang disimpan. Invoice lama dan voucher yang sudah diterbitkan tidak diubah.

## Catatan konfigurasi

- Satu pembayaran QRIS lunas menghasilkan **1 voucher**.
- Profil HotSpot, router, Limit Uptime, dan masa berlaku diambil dari paket serta router yang tersimpan pada invoice.
- `HotSpot Server` dapat diisi `all` atau nama HotSpot Server di MikroTik.
- Data Limit kosong berarti tanpa batas data; jika diisi, total upload + download dipasang sebagai `limit-bytes-total` pada akun HotSpot.
- Jika `Username = Password`, halaman hasil pembayaran dan PDF hanya menampilkan satu kredensial. QR tetap menggunakan payload `RWV1|username|password`, yang dibaca scanner RAKAN WIMAN.
- Pengaturan **Tampilkan password pada hasil cetak** berlaku pada PDF. QR tetap memuat kredensial login agar dapat dipindai.
- URL PDF memakai nomor invoice acak dan hanya melayani transaksi berstatus `paid` yang sudah memiliki voucher.

## Pengujian dan batasan

Pengujian lokal meliputi lint seluruh file PHP, pembuatan kredensial untuk dua mode, render halaman pembayaran, pembuatan PDF A6, pembacaan teks PDF, serta decode QR PDF untuk memastikan payload kompatibel dengan scanner. Pengujian end-to-end MySQL, ViaQRIS, WhatsApp, dan MikroTik harus dilanjutkan pada hosting aktif.
