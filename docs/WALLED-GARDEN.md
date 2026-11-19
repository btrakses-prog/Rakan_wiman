# RAKAN WIMAN HotSpot Portal dan MikroTik Walled Garden

Template login HotSpot mandiri diunduh melalui menu **Pengaturan → Unduh Template Login Page**. ZIP template terbaru menyertakan tombol pembelian dan **Scan QR Voucher**.

Contoh:

- `APP_URL`: `https://rakanwiman.example.id/rakan-wiman`
- Portal pembelian: `https://rakanwiman.example.id/rakan-wiman/buy`
- Pemindai QR aman: `https://rakanwiman.example.id/rakan-wiman/hotspot-scan`
- Walled Garden host: `rakanwiman.example.id` (hostname saja, tanpa path)

Upload seluruh isi folder `RAKAN-WIMAN-Hotspot` ke direktori HTML HotSpot MikroTik.

## RouterOS v7

Ganti hostname sesuai `APP_URL`:

```routeros
/ip hotspot walled-garden
add action=allow dst-host=rakanwiman.example.id comment="RAKAN WIMAN Purchase and QR Scanner"
```

Satu hostname yang sama mengizinkan katalog, halaman pembayaran, polling status, dan halaman scanner QR. Pastikan `APP_URL` HTTPS dengan sertifikat yang valid. API ViaQRIS yang dipanggil dari server tidak perlu dimasukkan ke Walled Garden.

## Login dengan QR

Halaman HotSpot MikroTik biasanya dibuka melalui HTTP sehingga izin kamera browser bisa tidak tersedia. Karena itu tombol **Scan QR Voucher** membuka halaman scanner RAKAN WIMAN melalui HTTPS. Setelah QR terbaca, scanner mengirim kredensial kembali ke halaman login asal menggunakan `postMessage` dengan pemeriksaan origin/referrer. Halaman login mengisi username/password dan mengirim form normal, termasuk perhitungan CHAP bila `chap-id` tersedia.

Gunakan Chrome/Edge terbaru pada Android dan izinkan akses kamera untuk domain RAKAN WIMAN. Jika browser tidak menyediakan `BarcodeDetector`, scanner akan menampilkan pemberitahuan kompatibilitas.

QR voucher berisi kredensial login. Jaga agar foto atau salinan QR tidak tersebar ke pihak lain.
