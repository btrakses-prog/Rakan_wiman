# Update path Apache — RAKAN WIMAN 0.4.1

Dokumen ini khusus untuk instalasi 0.4.0 yang folder proyeknya berada di dalam Document Root (contohnya URL file CSS berhasil dibuka pada `/RAKAN-WIMAN-v0.4.0/public/assets/app.css`, sedangkan `/assets/app.css` menghasilkan 404).

## Sebelum memperbarui

1. Backup folder aplikasi dan database.
2. Jangan jalankan `/install` lagi.
3. Jangan hapus atau timpa file `.env`, folder `storage`, atau `storage/installed.lock`.
4. Jangan import ulang migration SQL.

## Terapkan file update

1. Ekstrak paket 0.4.1 di komputer.
2. Upload isi folder `RAKAN-WIMAN-v0.4.1` ke dalam folder proyek yang sudah ada di hosting, misalnya tetap `public_html/RAKAN-WIMAN-v0.4.0/`.
3. Izinkan overwrite file aplikasi.
4. Pastikan file `.htaccess` pada folder proyek utama dan `public/.htaccess` ikut diperbarui.
5. Pertahankan folder `storage` beserta log dan `installed.lock`. Jika pengelola file menanyakan apakah menimpa `.env`, pilih **jangan timpa**.

## Perbarui APP_URL untuk subfolder

Karena domain masih mengarah ke folder induk, buka `.env` di folder utama proyek. Ubah hanya baris `APP_URL` menjadi:

```ini
APP_URL="https://rakanwiman.rakanakses.my.id/RAKAN-WIMAN-v0.4.0"
```

Nama folder pada URL harus sama persis dengan nama folder proyek yang terlihat di File Manager. Simpan, lalu buka:

```text
https://rakanwiman.rakanakses.my.id/RAKAN-WIMAN-v0.4.0/
```

atau langsung:

```text
https://rakanwiman.rakanakses.my.id/RAKAN-WIMAN-v0.4.0/login
```

Update ini memakai `.htaccess` folder proyek untuk mengarahkan `/login` ke `public/index.php` dan `/assets/...` ke `public/assets/...`.

## Untuk URL tanpa prefiks folder

Agar URL tetap `https://rakanwiman.rakanakses.my.id/login`, ubah Document Root subdomain melalui panel hosting supaya menunjuk langsung ke folder `RAKAN-WIMAN-v0.4.0/public`. Dalam mode itu, ubah `.env` menjadi:

```ini
APP_URL="https://rakanwiman.rakanakses.my.id"
```

Jangan mencoba mengarahkan Document Root ke folder utama proyek, karena folder privat aplikasi harus tetap terlindungi.

## Jika route tetap 404

Apache harus mengaktifkan `mod_rewrite` dan mengizinkan penggunaan `.htaccess` (`AllowOverride FileInfo` atau `All`). Minta penyedia hosting mengaktifkan fitur tersebut jika aturan rewrite tidak dijalankan.
