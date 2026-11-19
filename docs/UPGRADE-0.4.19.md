# Upgrade RAKAN WIMAN v0.4.19

## Perubahan

- Memisahkan tombol **Periksa Status Pembayaran** dan **Hubungi Admin via WhatsApp** pada halaman pembayaran QRIS.
- Kedua tindakan berada pada panel terpisah, masing-masing dengan keterangan fungsinya.
- Chat WhatsApp memuat invoice, nama paket, harga paket, total QRIS, kode unik, dan status pembayaran.
- Jika nomor admin belum disetting, tombol WhatsApp ditampilkan dalam keadaan nonaktif dan ada petunjuk ke menu QRIS.
- Menghindari tombol bantuan ganda pada halaman voucher yang sudah ditampilkan.

## Cara update

1. Backup aplikasi dan database.
2. Upload `RAKAN-WIMAN-v0.4.19-UPDATE-PATCH.zip` ke folder utama aplikasi.
3. Ekstrak dan pilih overwrite.
4. Jangan menimpa `.env` dan `storage`.
5. Tidak ada migration database baru.
6. Buka **QRIS → Konfigurasi ViaQRIS**, isi **WhatsApp Admin / Bantuan Pelanggan** dengan nomor format internasional tanpa `+` (contoh format: `62812xxxxxxxx`), lalu simpan.
7. Buka kembali halaman pembayaran QRIS dan pastikan dua panel tampil terpisah. Uji tombol Periksa Status dan tombol WhatsApp secara terpisah.

## Catatan

Jika nomor WhatsApp admin kosong, tombol tetap terlihat tetapi tidak dapat diklik. Hal ini disengaja agar tidak mengarahkan pelanggan ke nomor yang tidak diketahui.
