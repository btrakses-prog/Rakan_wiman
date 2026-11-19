# RAKAN WIMAN v0.4.21

RAKAN WIMAN adalah aplikasi mandiri untuk pengelolaan HotSpot MikroTik, voucher, profil HotSpot, pembayaran ViaQRIS dinamis, dan notifikasi WhatsApp.

## Pembaruan v0.4.21

### Dashboard — User HotSpot Aktif
- Menambahkan kartu **User Hotspot Aktif** pada ringkasan dashboard.
- Jumlah sesi dibaca langsung dari `/ip/hotspot/active/print` pada setiap router aktif yang terdaftar di RAKAN WIMAN.
- Multi-router dijumlahkan; panel rincian menampilkan jumlah sesi per router dan status keberhasilan pembacaan.
- Rincian sesi dapat dibuka untuk melihat username, IP, MAC, HotSpot Server, uptime, serta trafik upload/download.
- Jika sebagian router gagal dibaca, dashboard memberi status **Data sebagian** dan hanya menampilkan jumlah dari router yang berhasil dibaca. Jika seluruh router gagal, kartu menampilkan tanda `—`, bukan angka nol yang menyesatkan.
- Data sesi tidak disimpan sebagai data voucher dan tidak memengaruhi masa aktif atau status pelanggan.
- Pemeriksaan dilakukan saat dashboard dibuka atau saat tombol **Perbarui** ditekan. Tidak ada polling latar belakang.
- Timeout monitoring dibuat singkat agar satu router yang tidak dapat dijangkau tidak menahan dashboard terlalu lama.

## Update instalasi lama
1. Backup folder aplikasi dan database.
2. Upload `RAKAN-WIMAN-v0.4.21-UPDATE-PATCH.zip` ke folder utama proyek (sejajar dengan `app`, `config`, `public`, dan `resources`).
3. Ekstrak dan izinkan overwrite.
4. Jangan menimpa `.env` atau folder `storage`.
5. Tidak perlu import SQL, migration, atau menjalankan installer.
6. Buka dashboard. Kartu User HotSpot Aktif akan membaca sesi dari router yang terdaftar.

## Catatan
- Akun API MikroTik harus memiliki hak `read` untuk menu HotSpot Active.
- Satu baris pada `/ip/hotspot/active` dihitung sebagai satu sesi aktif. Jika satu username terhubung melalui beberapa perangkat/router, setiap sesi dihitung.
- Jika router offline, tidak dapat diautentikasi, atau tidak memiliki izin membaca HotSpot Active, router tersebut ditandai gagal dan tidak dimasukkan ke total.
- Dashboard membaca data live; hasilnya bukan jumlah voucher yang dibuat maupun jumlah pelanggan terdaftar.
