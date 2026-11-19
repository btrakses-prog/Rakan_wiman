# Cron RAKAN WIMAN

RAKAN WIMAN mengelola masa aktif voucher dari server aplikasi. Jangan membuat scheduler penghapusan voucher di MikroTik untuk menggantikan cron ini.

## Apa yang dikerjakan

Setiap eksekusi `bin/cron.php`:
1. Membaca user Hotspot dan sesi aktif dari setiap router yang aktif.
2. Mencatat `first_login_at` saat sesi pertama terdeteksi.
3. Membandingkan akumulasi `uptime` user Hotspot dengan `packages.limit_uptime_seconds`.
4. Mencatat `expires_at = first_login_at + validity_days` dan membandingkannya dengan waktu server (termasuk ketika router offline).
5. Jika salah satu batas tercapai, status voucher di MySQL menjadi `expired` dan pekerjaan penghapusan dimasukkan ke `router_sync_queue`.
6. Worker memutus sesi aktif lalu menghapus user Hotspot dari router. Jika router offline, pekerjaan diulang otomatis dengan jeda bertahap.
7. Menyinkronkan voucher baru ke router dan memperbarui status koneksi router.

Aplikasi **tidak mengirim properti `limit-uptime` ke user MikroTik**. Limit uptime dihitung dari akumulasi properti `uptime` yang dibaca oleh cron, sehingga penghapusan diputuskan dan dieksekusi oleh aplikasi.

## Crontab

Jalankan sebagai user sistem yang memiliki akses baca/tulis ke folder proyek:

```cron
* * * * * cd /var/www/rakan-wiman && /usr/bin/flock -n /tmp/rakan-wiman-cron.lock /usr/bin/php bin/cron.php >> storage/logs/cron-crontab.log 2>&1
```

Sesuaikan path proyek dan path PHP di server. Pastikan `.env` tersedia dan `APP_KEY` sama dengan yang digunakan aplikasi web. Folder `storage/logs` harus dapat ditulis oleh user cron.

## RouterOS API

Aktifkan service API di router (port default 8728) dan buat user khusus dengan hak minimum yang diperlukan untuk membaca Hotspot, menambah user, memutus sesi aktif, dan menghapus user. Batasi firewall service API agar hanya menerima koneksi dari IP server RAKAN WIMAN. Gunakan VPN/private network jika router tidak berada di LAN server.

## Batasan operasional penting

- Cron harus berjalan minimal setiap menit. Keterlambatan cron berarti keterlambatan pemutusan voucher yang telah habis.
- Untuk masa kalender, waktu mulai adalah login pertama yang terdeteksi oleh cron. Dengan interval satu menit, pencatatan bisa terlambat maksimal sekitar satu interval.
- Untuk limit uptime, router harus dapat dijangkau agar nilai akumulasi uptime terbaca. Saat router offline, sistem tidak dapat mengetahui tambahan pemakaian yang terjadi; tindakan expiry yang sudah diketahui tetap antre dan akan dieksekusi saat router kembali online.
- Jangan menghapus user voucher secara manual di MikroTik jika ingin histori dan status tetap konsisten. Jika router diganti, gunakan proses rekonsiliasi/sinkronisasi aplikasi pada tahap berikutnya.
