# Upgrade RAKAN WIMAN v0.4.17

Pembaruan ini memperbaiki pengenalan pembayaran ViaQRIS dinamis dan pencatatan kode unik.

## Sebelum update

1. Backup folder aplikasi dan database MySQL/MariaDB.
2. Pastikan lokasi proyek adalah folder yang berisi `app`, `bin`, `config`, `database`, `public`, `resources`, dan `storage`.

## Pemasangan PATCH

1. Upload `RAKAN-WIMAN-v0.4.17-UPDATE-PATCH.zip` ke folder utama proyek.
2. Ekstrak dan izinkan overwrite file aplikasi.
3. Jangan menimpa `.env`, `storage`, atau data database.
4. Tidak perlu menjalankan installer dan tidak perlu mengimpor SQL secara manual.
5. Buka dashboard sekali. Aplikasi menjalankan penambahan kolom secara otomatis. Cron juga memanggil schema upgrader.

Migrasi `008_viaqris_dynamic_amounts.sql` menambahkan:

- `payments.unique_code`
- `payments.payable_amount`
- `payments.paid_amount`

Kolom ditambahkan secara idempotent oleh `SchemaUpgrade::ensureViaQrisDynamicAmounts()`.

## Cara memeriksa transaksi yang sudah dibayar

1. Buka menu **QRIS → Audit Transaksi**.
2. Pada invoice yang masih berstatus **Menunggu**, tekan **Cek status**.
3. RAKAN WIMAN meminta detail transaksi berdasarkan `gateway_transaction_id` yang sama persis dengan ID saat transaksi dibuat.
4. Jika field status pembayaran pada record ViaQRIS menunjukkan `Berhasil`, RAKAN WIMAN menandai transaksi lunas, membuat voucher satu kali, menambahkan antrean sinkronisasi router, dan antrean WhatsApp.
5. Tekan kembali cek status bila server ViaQRIS belum memperbarui status pada saat pemeriksaan pertama.

Pembayaran tidak lagi ditolak hanya karena nominal QRIS (harga paket + kode unik) berbeda dari harga paket dasar. Callback tetap diverifikasi ulang ke API ViaQRIS untuk ID transaksi yang sama. Nominal callback yang diterima dicatat sebagai `paid_amount` setelah status gateway terkonfirmasi.

## Pencatatan kode unik

- Jika respons API/callback menyediakan nominal akhir, aplikasi menghitung kode unik dari nominal akhir dikurangi harga paket.
- Jika browser pelanggan mendukung `BarcodeDetector`, halaman pembayaran membaca QR EMV tag `54`, memeriksa CRC16 QR, lalu mengirim payload QR ke endpoint petunjuk nominal. Endpoint ini hanya memperbarui `payable_amount`/`unique_code`; tidak dapat menandai pembayaran lunas.
- Browser tanpa `BarcodeDetector` tetap dapat menampilkan QR dan menerima pembayaran. Pelanggan harus mengikuti nominal akhir yang ditampilkan aplikasi bank/e-wallet. Kode unik akan muncul di audit bila kemudian diterima dari ViaQRIS atau callback.

## Callback ViaQRIS

Pastikan URL callback yang didaftarkan pada layanan ViaQRIS adalah:

```text
APP_URL/callback/viaqris
```

Callback harus mengirim JSON yang berisi `transactionId`, `status`, dan `amount`. Jika sebelumnya URL notifikasi masih mengarah ke PHPNuxBill, ubah tujuan callback ke RAKAN WIMAN (atau tambahkan endpoint RAKAN jika layanan mendukung beberapa tujuan). Jika callback tidak masuk ke RAKAN, aplikasi mungkin tetap dapat mengetahui status lunas melalui polling GET, tetapi tidak menerima nilai `amount` dari webhook untuk mencatat kode unik dinamis.

Jika instalasi berada pada subfolder seperti contoh ini, URL callback adalah:

```text
https://rakanwiman.rakanakses.my.id/RAKAN-WIMAN/callback/viaqris
```

Gunakan URL yang tampil pada menu **QRIS → Konfigurasi ViaQRIS** sebagai nilai final; jangan menyalin contoh jika `APP_URL` Anda berbeda. Jika callback belum dikonfigurasi atau tidak dikirim oleh layanan, tombol **Cek status** tetap menggunakan polling GET ke ViaQRIS.

## Catatan keamanan dan idempotensi

- Pembacaan QR tidak dianggap bukti pembayaran.
- Pembayaran dikonfirmasi hanya berdasarkan status gateway untuk `gateway_transaction_id` yang cocok atau callback yang diverifikasi ulang ke API.
- Satu invoice hanya boleh menerbitkan satu voucher. Pemeriksaan ulang status dan callback berulang tidak membuat voucher duplikat.
- Jangan mengganti `APP_KEY` setelah instalasi.

## Setelah update

Uji satu invoice yang masih pending. Catat harga paket, nominal QRIS di aplikasi bank/e-wallet, status audit, dan nomor voucher yang diterbitkan. Jangan melakukan pembayaran ulang pada invoice yang sebelumnya sudah dibayar; cukup tekan **Cek status**.
