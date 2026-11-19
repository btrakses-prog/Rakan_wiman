<?php
declare(strict_types=1);

/*
 * RAKAN WIMAN Web Installer
 * Creates the database (when permitted), runs all SQL migrations, creates
 * the first Superadmin, writes the protected .env file and removes itself.
 */

$root = dirname(__DIR__);
$lockFile = $root . '/storage/installed.lock';
$envFile = $root . '/.env';

function rw_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function rw_env_value(string $value): string
{
    if (str_contains($value, "\0") || str_contains($value, "\r") || str_contains($value, "\n")) {
        throw new RuntimeException('Nilai konfigurasi tidak boleh berisi karakter baris baru.');
    }
    $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($encoded === false) {
        throw new RuntimeException('Gagal membuat konfigurasi .env.');
    }
    return $encoded;
}

/** Split SQL at semicolons while respecting strings and SQL comments. */
function rw_split_sql(string $sql): array
{
    $statements = [];
    $buffer = '';
    $quote = null;
    $lineComment = false;
    $blockComment = false;
    $length = strlen($sql);

    for ($i = 0; $i < $length; $i++) {
        $ch = $sql[$i];
        $next = $i + 1 < $length ? $sql[$i + 1] : '';

        if ($lineComment) {
            if ($ch === "\n") {
                $lineComment = false;
                $buffer .= "\n";
            }
            continue;
        }
        if ($blockComment) {
            if ($ch === '*' && $next === '/') {
                $blockComment = false;
                $i++;
            }
            continue;
        }
        if ($quote !== null) {
            $buffer .= $ch;
            if ($ch === '\\' && $quote !== '`' && $i + 1 < $length) {
                $buffer .= $sql[++$i];
                continue;
            }
            if ($ch === $quote) {
                if ($next === $quote && $quote !== '`') {
                    $buffer .= $sql[++$i];
                } else {
                    $quote = null;
                }
            }
            continue;
        }

        if ($ch === '/' && $next === '*') {
            $blockComment = true;
            $i++;
            continue;
        }
        if ($ch === '#' || ($ch === '-' && $next === '-' && ($i + 2 >= $length || ctype_space($sql[$i + 2])))) {
            $lineComment = true;
            if ($ch === '-') $i++;
            continue;
        }
        if ($ch === "'" || $ch === '"' || $ch === '`') {
            $quote = $ch;
            $buffer .= $ch;
            continue;
        }
        if ($ch === ';') {
            $statement = trim($buffer);
            if ($statement !== '') $statements[] = $statement;
            $buffer = '';
            continue;
        }
        $buffer .= $ch;
    }

    $statement = trim($buffer);
    if ($statement !== '') $statements[] = $statement;
    return $statements;
}

function rw_remove_installer(): bool
{
    $file = __FILE__;
    return !is_file($file) || @unlink($file);
}

// Installer is permanently disabled once .env or the lock marker exists.
if (is_file($envFile) || is_file($lockFile)) {
    $removed = rw_remove_installer();
    $httpsForLink = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    $schemeForLink = $httpsForLink ? 'https' : 'http';
    $hostForLink = preg_replace('/[^a-zA-Z0-9.\-:\[\]]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost')) ?: 'localhost';
    $scriptPathForLink = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/install.php'));
    $scriptDirForLink = str_replace('\\', '/', dirname($scriptPathForLink));
    $basePathForLink = basename($scriptDirForLink) === 'public' ? dirname($scriptDirForLink) : $scriptDirForLink;
    if ($basePathForLink === '.' || $basePathForLink === DIRECTORY_SEPARATOR) $basePathForLink = '';
    $returnUrl = $schemeForLink . '://' . $hostForLink . '/' . trim(str_replace('\\', '/', $basePathForLink), '/');
    $returnUrl = rtrim($returnUrl, '/');
    http_response_code(410);
    ?><!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Installer dinonaktifkan · RAKAN WIMAN</title><style>body{font:16px system-ui;background:#f4f6fa;color:#172033;display:grid;place-items:center;min-height:100vh;margin:0}.card{background:#fff;padding:32px;border-radius:18px;max-width:560px;box-shadow:0 12px 40px #18233a18}a{color:#175cd3}</style></head><body><div class="card"><h1>Installer sudah dinonaktifkan</h1><p>RAKAN WIMAN sudah memiliki konfigurasi instalasi. Demi keamanan, installer tidak dapat dijalankan kembali.</p><?php if (!$removed): ?><p>Hapus file <code>public/install.php</code> secara manual dari File Manager.</p><?php endif; ?><p><a href="<?= rw_h($returnUrl ?: ($schemeForLink . '://' . $hostForLink)) ?>">Kembali ke aplikasi</a></p></div></body></html><?php
    exit;
}

$https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
    || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
$defaultScheme = $https ? 'https' : 'http';
$hostHeader = preg_replace('/[^a-zA-Z0-9.\-:\[\]]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost')) ?: 'localhost';
$scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/install.php'));
$scriptDirectory = str_replace('\\', '/', dirname($scriptName));
$appPath = $scriptDirectory;
// When reached through /PROJECT/public/install.php, the clean application base is /PROJECT.
if (basename($scriptDirectory) === 'public') {
    $appPath = dirname($scriptDirectory);
}
if ($appPath === '.' || $appPath === DIRECTORY_SEPARATOR) $appPath = '';
$appPath = '/' . trim(str_replace('\\', '/', $appPath), '/');
if ($appPath === '/') $appPath = '';
$defaultUrl = $defaultScheme . '://' . $hostHeader . $appPath;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('rakan_wiman_installer');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}
if (empty($_SESSION['rw_install_csrf'])) {
    $_SESSION['rw_install_csrf'] = bin2hex(random_bytes(32));
}

$values = [
    'app_url' => $defaultUrl,
    'db_host' => 'localhost',
    'db_port' => '3306',
    'db_name' => 'rakan_wiman',
    'db_user' => '',
    'admin_name' => 'Administrator',
    'admin_email' => '',
    'timezone' => 'Asia/Jakarta',
];
$errors = [];
$environmentChecks = [
    'PHP 8.2 atau lebih baru' => PHP_VERSION_ID >= 80200,
    'Ekstensi PDO MySQL (pdo_mysql)' => extension_loaded('pdo_mysql'),
    'Ekstensi OpenSSL' => extension_loaded('openssl'),
    'Ekstensi JSON' => extension_loaded('json'),
    'Ekstensi Mbstring' => extension_loaded('mbstring'),
    'Folder proyek dapat ditulis' => is_writable($root),
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    foreach (array_keys($values) as $key) {
        $values[$key] = trim((string)($_POST[$key] ?? $values[$key]));
    }

    $token = (string)($_POST['_token'] ?? '');
    if (!hash_equals((string)$_SESSION['rw_install_csrf'], $token)) {
        $errors[] = 'Sesi formulir tidak valid. Muat ulang halaman installer.';
    }
    foreach ($environmentChecks as $requirement => $passed) {
        if (!$passed) $errors[] = 'Persyaratan server belum terpenuhi: ' . $requirement . '.';
    }
    if (!is_dir($root) || !is_writable($root)) {
        $errors[] = 'Folder utama aplikasi tidak bisa ditulis. Periksa permission folder proyek.';
    }
    if (!filter_var($values['app_url'], FILTER_VALIDATE_URL) || !in_array((string)parse_url($values['app_url'], PHP_URL_SCHEME), ['http', 'https'], true) || parse_url($values['app_url'], PHP_URL_HOST) === null || parse_url($values['app_url'], PHP_URL_QUERY) !== null || parse_url($values['app_url'], PHP_URL_FRAGMENT) !== null) {
        $errors[] = 'URL aplikasi harus berupa URL lengkap, misalnya https://wifi.domainanda.com.';
    }
    $appHost = strtolower((string)parse_url($values['app_url'], PHP_URL_HOST));
    if (parse_url($values['app_url'], PHP_URL_USER) !== null || parse_url($values['app_url'], PHP_URL_PASS) !== null) {
        $errors[] = 'URL aplikasi tidak boleh berisi username atau password.';
    }
    if (parse_url($values['app_url'], PHP_URL_SCHEME) !== 'https' && !in_array($appHost, ['localhost', '127.0.0.1', '::1'], true)) {
        $errors[] = 'Untuk instalasi server, gunakan URL HTTPS yang sudah memiliki SSL.';
    }
    if ($values['db_host'] === '' || !preg_match('/^[a-zA-Z0-9.\-:\[\]]+$/', $values['db_host'])) {
        $errors[] = 'Host database tidak valid.';
    }
    if (!ctype_digit($values['db_port']) || (int)$values['db_port'] < 1 || (int)$values['db_port'] > 65535) {
        $errors[] = 'Port database harus berupa angka 1–65535.';
    }
    if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $values['db_name'])) {
        $errors[] = 'Nama database hanya boleh berisi huruf, angka, dan garis bawah (maksimal 64 karakter).';
    }
    if ($values['db_user'] === '' || strlen($values['db_user']) > 128) {
        $errors[] = 'Username database wajib diisi (maksimal 128 karakter).';
    }
    if ($values['admin_name'] === '' || strlen($values['admin_name']) > 120) {
        $errors[] = 'Nama administrator wajib diisi (maksimal 120 karakter).';
    }
    if (!filter_var($values['admin_email'], FILTER_VALIDATE_EMAIL) || strlen($values['admin_email']) > 190) {
        $errors[] = 'Alamat email administrator tidak valid.';
    }
    $adminPassword = (string)($_POST['admin_password'] ?? '');
    $adminPasswordConfirm = (string)($_POST['admin_password_confirm'] ?? '');
    if (strlen($adminPassword) < 12) $errors[] = 'Kata sandi Superadmin minimal 12 karakter.';
    if (!hash_equals($adminPassword, $adminPasswordConfirm)) $errors[] = 'Konfirmasi kata sandi tidak sama.';
    if (str_contains($adminPassword, "\0") || str_contains((string)($_POST['db_password'] ?? ''), "\0")) $errors[] = 'Kata sandi mengandung karakter yang tidak didukung.';
    $timezone = $values['timezone'];
    if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) $errors[] = 'Zona waktu tidak dikenal.';

    if (!$errors) {
        $pdo = null;
        $installLockHandle = null;
        try {
            $storageDir = $root . '/storage';
            if (!is_dir($storageDir) && !@mkdir($storageDir, 0775, true) && !is_dir($storageDir)) {
                throw new RuntimeException('Folder storage tidak dapat dibuat. Periksa permission folder proyek.');
            }
            $installLockHandle = @fopen($storageDir . '/installing.lock', 'c');
            if (!$installLockHandle || !flock($installLockHandle, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Proses instalasi lain sedang berjalan. Tunggu sampai selesai, lalu muat ulang halaman.');
            }
            $dbPassword = (string)($_POST['db_password'] ?? '');
            $port = (int)$values['db_port'];
            $host = $values['db_host'];
            $dbName = $values['db_name'];

            // Connect without selecting a database so the installer can create it.
            $serverDsn = "mysql:host={$host};port={$port};charset=utf8mb4";
            $serverPdo = new PDO($serverDsn, $values['db_user'], $dbPassword, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);

            if (!empty($_POST['create_database'])) {
                $serverPdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            }

            $dsn = "mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4";
            $pdo = new PDO($dsn, $values['db_user'], $dbPassword, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("SET time_zone = '+07:00'");

            $existingTables = (int)$pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn();
            $hasMigrationTable = (bool)$pdo->query("SHOW TABLES LIKE 'rw_schema_migrations'")->fetchColumn();
            if ($existingTables > 0 && !$hasMigrationTable) {
                throw new RuntimeException('Database sudah berisi tabel, tetapi bukan database instalasi RAKAN WIMAN. Gunakan database kosong agar data lain tidak tertimpa.');
            }

            $pdo->exec("CREATE TABLE IF NOT EXISTS rw_schema_migrations (migration VARCHAR(190) NOT NULL PRIMARY KEY, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $migrationFiles = glob($root . '/database/migrations/*.sql') ?: [];
            sort($migrationFiles, SORT_STRING);
            if (count($migrationFiles) < 7) {
                throw new RuntimeException('File migration tidak lengkap. Pastikan folder database/migrations berisi migration 001 sampai 007.');
            }
            foreach ($migrationFiles as $migrationFile) {
                $migrationName = basename($migrationFile);
                $check = $pdo->prepare('SELECT COUNT(*) FROM rw_schema_migrations WHERE migration = ?');
                $check->execute([$migrationName]);
                if ((int)$check->fetchColumn() > 0) continue;
                $sql = file_get_contents($migrationFile);
                if ($sql === false) throw new RuntimeException('Tidak bisa membaca file ' . $migrationName . '.');
                foreach (rw_split_sql($sql) as $statement) {
                    $pdo->exec($statement);
                }
                $mark = $pdo->prepare('INSERT INTO rw_schema_migrations (migration) VALUES (?)');
                $mark->execute([$migrationName]);
            }

            // Create the first admin only once; this also makes installer retries safe
            // after a partial completion where the .env write may have failed.
            $adminCount = (int)$pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn();
            if ($adminCount === 0) {
                $adminInsert = $pdo->prepare('INSERT INTO admins (name,email,password_hash,role,is_active) VALUES (?,?,?,?,1)');
                $adminInsert->execute([
                    $values['admin_name'],
                    mb_strtolower($values['admin_email']),
                    password_hash($adminPassword, PASSWORD_DEFAULT),
                    'superadmin',
                ]);
            }

            $appKey = bin2hex(random_bytes(32));
            $isSecure = parse_url($values['app_url'], PHP_URL_SCHEME) === 'https';
            $env = [
                'APP_NAME' => 'RAKAN WIMAN',
                'APP_ENV' => 'production',
                'APP_DEBUG' => 'false',
                'APP_URL' => rtrim($values['app_url'], '/'),
                'APP_TIMEZONE' => $timezone,
                'APP_KEY' => $appKey,
                'DB_HOST' => $host,
                'DB_PORT' => (string)$port,
                'DB_DATABASE' => $dbName,
                'DB_USERNAME' => $values['db_user'],
                'DB_PASSWORD' => $dbPassword,
                'DB_CHARSET' => 'utf8mb4',
                'SESSION_NAME' => 'rakan_wiman_session',
                'SESSION_SECURE' => $isSecure ? 'true' : 'false',
            ];
            $envContent = "# RAKAN WIMAN configuration. Keep this file outside the public web root.\n";
            foreach ($env as $key => $value) $envContent .= $key . '=' . rw_env_value($value) . "\n";

            $tmpEnv = $root . '/.env.install-' . bin2hex(random_bytes(6));
            if (file_put_contents($tmpEnv, $envContent, LOCK_EX) === false) {
                throw new RuntimeException('Database berhasil disiapkan, tetapi file .env tidak dapat ditulis. Periksa permission folder utama aplikasi, lalu jalankan installer kembali.');
            }
            @chmod($tmpEnv, 0640);
            if (!@rename($tmpEnv, $envFile)) {
                @unlink($tmpEnv);
                throw new RuntimeException('Gagal menyimpan file .env. Periksa permission folder utama aplikasi.');
            }
            @chmod($envFile, 0640);

            foreach (['logs', 'cache'] as $folder) {
                $dir = $root . '/storage/' . $folder;
                if (!is_dir($dir)) @mkdir($dir, 0775, true);
                if (is_dir($dir)) @chmod($dir, 0775);
            }
            @file_put_contents($lockFile, 'Installed at ' . date(DATE_ATOM) . "\n", LOCK_EX);
            @chmod($lockFile, 0640);

            rw_remove_installer();
            session_regenerate_id(true);
            $_SESSION = [];
            session_destroy();
            header('Location: ' . rtrim($env['APP_URL'], '/') . '/login?installed=1', true, 303);
            exit;
        } catch (Throwable $e) {
            $message = $e->getMessage();
            if ($e instanceof PDOException) {
                $message = 'Koneksi atau pembuatan database gagal. Periksa host, port, nama database, username, password, dan hak akses user database. Detail: ' . $e->getMessage();
            }
            $errors[] = $message;
        }
    }
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Instalasi RAKAN WIMAN</title>
<style>
:root{color-scheme:light;--bg:#f3f6fb;--ink:#172033;--muted:#687386;--line:#e3e8f0;--blue:#245bd6;--blue2:#1747b2;--green:#0b8a62;--red:#b42318}
*{box-sizing:border-box}body{margin:0;background:linear-gradient(135deg,#edf3ff,#f8fafc 55%,#eaf7f2);font:15px/1.55 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:var(--ink)}.wrap{max-width:980px;margin:36px auto;padding:0 18px}.hero{display:flex;align-items:center;gap:15px;margin-bottom:22px}.mark{width:52px;height:52px;border-radius:16px;background:linear-gradient(145deg,#2864e8,#153d9e);display:grid;place-items:center;color:#fff;font-weight:800;font-size:19px;box-shadow:0 8px 20px #245bd633}.hero h1{margin:0;font-size:26px;letter-spacing:-.6px}.hero p{margin:2px 0 0;color:var(--muted)}.panel{background:#fff;border:1px solid #e8edf5;border-radius:20px;box-shadow:0 18px 55px #23395d12;overflow:hidden}.intro{padding:22px 26px;background:#fbfcff;border-bottom:1px solid var(--line)}.intro strong{font-size:16px}.intro p{margin:5px 0 0;color:var(--muted)}form{padding:24px 26px}.grid{display:grid;grid-template-columns:1fr 1fr;gap:22px}.section{border:1px solid var(--line);border-radius:15px;padding:18px}.section h2{font-size:16px;margin:0 0 15px}.field{margin-bottom:13px}.field:last-child{margin-bottom:0}label{display:block;font-weight:650;font-size:13px;margin-bottom:6px}.hint{display:block;color:var(--muted);font-size:12px;margin-top:5px}input,select{font:inherit;width:100%;height:43px;border:1px solid #cfd7e4;border-radius:9px;padding:0 11px;background:white;color:var(--ink);outline:none}input:focus,select:focus{border-color:#5b85ed;box-shadow:0 0 0 3px #245bd619}.check{display:flex;align-items:flex-start;gap:9px;margin-top:12px;font-size:13px;color:#354052}.check input{width:17px;height:17px;margin:2px 0 0;flex:none}.check label{font-weight:500;margin:0}.alerts{margin:20px 26px 0}.alert{border-radius:10px;padding:12px 14px;margin-bottom:8px;font-size:13px}.danger{background:#fff1f0;color:#8d1c13;border:1px solid #ffd0cc}.note{background:#eff6ff;color:#194185;border:1px solid #d7e6ff}.actions{margin-top:22px;display:flex;align-items:center;justify-content:space-between;gap:15px}.actions p{margin:0;color:var(--muted);font-size:12px}.btn{border:0;border-radius:10px;background:var(--blue);color:#fff;padding:13px 22px;font-weight:750;font-size:15px;cursor:pointer}.btn:hover{background:var(--blue2)}.foot{text-align:center;color:var(--muted);font-size:12px;margin:18px 0 0}.req{color:var(--red)}@media(max-width:720px){.wrap{margin:20px auto}.grid{grid-template-columns:1fr}.hero h1{font-size:22px}form,.intro{padding:18px}.alerts{margin:16px 18px 0}.actions{align-items:stretch;flex-direction:column}.btn{width:100%}}
</style>
</head>
<body>
<div class="wrap">
  <header class="hero"><div class="mark">RW</div><div><h1>RAKAN WIMAN</h1><p>Hotspot Billing & Network Management · Web Installer</p></div></header>
  <main class="panel">
    <div class="intro"><strong>Instalasi awal aplikasi</strong><p>Isi informasi website, database MySQL/MariaDB, dan akun Superadmin. Installer akan membuat tabel serta menjalankan semua migration secara otomatis.</p></div>
    <div class="alerts"><div class="alert note"><strong>Pemeriksaan server</strong><div style="display:grid;grid-template-columns:1fr 1fr;gap:5px 14px;margin-top:8px"><?php foreach ($environmentChecks as $requirement => $passed): ?><div><?= $passed ? '✓' : '✕' ?> <?= rw_h($requirement) ?></div><?php endforeach; ?></div></div></div>
    <?php if ($errors): ?><div class="alerts"><?php foreach ($errors as $error): ?><div class="alert danger"><?= rw_h($error) ?></div><?php endforeach; ?></div><?php endif; ?>
    <div class="alerts"><div class="alert note"><strong>Keamanan:</strong> gunakan database kosong khusus RAKAN WIMAN. Setelah instalasi berhasil, file installer ini akan menghapus dirinya sendiri dan aplikasi akan menyimpan APP_KEY otomatis.</div></div>
    <form method="post" autocomplete="off">
      <input type="hidden" name="_token" value="<?= rw_h((string)$_SESSION['rw_install_csrf']) ?>">
      <div class="grid">
        <section class="section">
          <h2>1. Informasi aplikasi</h2>
          <div class="field"><label for="app_url">URL aplikasi <span class="req">*</span></label><input id="app_url" name="app_url" type="url" required value="<?= rw_h($values['app_url']) ?>" placeholder="https://wifi.domainanda.com"><span class="hint">Alamat awal dideteksi otomatis. Jika proyek diekstrak sebagai subfolder, awalan folder akan disertakan; jika document root diarahkan ke public, gunakan domain saja.</span></div>
          <div class="field"><label for="timezone">Zona waktu</label><select id="timezone" name="timezone"><?php foreach (['Asia/Jakarta','Asia/Pontianak','Asia/Makassar','Asia/Jayapura','UTC'] as $tz): ?><option value="<?= rw_h($tz) ?>" <?= $values['timezone'] === $tz ? 'selected' : '' ?>><?= rw_h($tz) ?></option><?php endforeach; ?></select></div>
          <h2 style="margin-top:21px">2. Akun Superadmin</h2>
          <div class="field"><label for="admin_name">Nama administrator <span class="req">*</span></label><input id="admin_name" name="admin_name" required maxlength="120" value="<?= rw_h($values['admin_name']) ?>"></div>
          <div class="field"><label for="admin_email">Email administrator <span class="req">*</span></label><input id="admin_email" name="admin_email" type="email" required maxlength="190" value="<?= rw_h($values['admin_email']) ?>"></div>
          <div class="field"><label for="admin_password">Kata sandi <span class="req">*</span></label><input id="admin_password" name="admin_password" type="password" required minlength="12" autocomplete="new-password"><span class="hint">Minimal 12 karakter. Gunakan kombinasi yang kuat.</span></div>
          <div class="field"><label for="admin_password_confirm">Ulangi kata sandi <span class="req">*</span></label><input id="admin_password_confirm" name="admin_password_confirm" type="password" required minlength="12" autocomplete="new-password"></div>
        </section>
        <section class="section">
          <h2>3. Koneksi database MySQL / MariaDB</h2>
          <div class="field"><label for="db_host">Database host <span class="req">*</span></label><input id="db_host" name="db_host" required value="<?= rw_h($values['db_host']) ?>" placeholder="localhost"></div>
          <div class="field"><label for="db_port">Port database <span class="req">*</span></label><input id="db_port" name="db_port" type="number" min="1" max="65535" required value="<?= rw_h($values['db_port']) ?>"></div>
          <div class="field"><label for="db_name">Nama database <span class="req">*</span></label><input id="db_name" name="db_name" required maxlength="64" pattern="[A-Za-z0-9_]+" value="<?= rw_h($values['db_name']) ?>"><span class="hint">Hanya huruf, angka, dan garis bawah. Pada cPanel gunakan nama lengkap dengan prefix.</span></div>
          <div class="field"><label for="db_user">Username database <span class="req">*</span></label><input id="db_user" name="db_user" required maxlength="128" value="<?= rw_h($values['db_user']) ?>"></div>
          <div class="field"><label for="db_password">Password database</label><input id="db_password" name="db_password" type="password" autocomplete="new-password"><span class="hint">Boleh kosong hanya jika server database memang mengizinkannya.</span></div>
          <div class="check"><input id="create_database" name="create_database" type="checkbox" value="1" checked><label for="create_database">Buat database otomatis (user database harus memiliki hak <code>CREATE DATABASE</code>).<br>Hilangkan centang jika database sudah dibuat lewat cPanel/DirectAdmin.</label></div>
        </section>
      </div>
      <div class="actions"><p>Installer akan membuat schema 001–007, membuat akun pertama, menulis .env, lalu menghapus file installer.</p><button class="btn" type="submit">Instal RAKAN WIMAN</button></div>
    </form>
  </main>
  <div class="foot">RAKAN WIMAN · Installer satu kali pakai · PHP 8.2+ · MySQL / MariaDB</div>
</div>
</body></html>
