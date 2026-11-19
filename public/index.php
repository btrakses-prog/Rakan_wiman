<?php
declare(strict_types=1);
require dirname(__DIR__) . '/config/bootstrap.php';

use RakanWiman\Core\Auth;
use RakanWiman\Core\Database;
use RakanWiman\Core\View;
use RakanWiman\Core\SchemaUpgrade;
use RakanWiman\Services\RouterRepository;
use RakanWiman\Services\VoucherService;
use RakanWiman\Services\RouterSyncWorker;
use RakanWiman\Services\ExpiryService;
use RakanWiman\Services\ViaQrisService;
use RakanWiman\Services\AppSettings;
use RakanWiman\Services\WhatsAppGateway;
use RakanWiman\Services\HotspotTemplateExporter;
use RakanWiman\Services\HotspotProfileService;
use RakanWiman\Services\HotspotProfileSyncWorker;
use RakanWiman\Services\HotspotMonitorService;
use function RakanWiman\Core\flash;
use function RakanWiman\Core\redirect;
use function RakanWiman\Core\verify_csrf;
use function RakanWiman\Core\url;

$path = trim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/');
$base = trim(parse_url(getenv('APP_URL') ?: '', PHP_URL_PATH) ?: '', '/');
if ($base !== '' && ($path === $base || str_starts_with($path, $base . '/'))) {
    $path = trim(substr($path, strlen($base)), '/');
}
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$projectRoot = dirname(__DIR__);
if (!is_file($projectRoot . '/.env') && !is_file($projectRoot . '/storage/installed.lock')) {
    header('Location: install', true, 302);
    exit;
}

try {
    $pdo = Database::connection();
    // Apply the small, additive voucher-options migration automatically on existing installs.
    $optionsTable = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
    $optionsTable->execute(['voucher_generation_options']);
    if ((int)$optionsTable->fetchColumn() === 0) {
        $migrationPath = $projectRoot . '/database/migrations/005_voucher_generation_options.sql';
        $migrationSql = is_file($migrationPath) ? file_get_contents($migrationPath) : false;
        if ($migrationSql === false) throw new RuntimeException('File pembaruan database 005 tidak ditemukan. Upload ulang file database/migrations/005_voucher_generation_options.sql.');
        $pdo->exec($migrationSql);
        try { $pdo->exec("INSERT IGNORE INTO rw_schema_migrations (migration) VALUES ('005_voucher_generation_options.sql')"); } catch (Throwable $ignored) {}
    }
    // Additive HotSpot Profile Manager migration. Re-run all idempotent statements if a partial update occurred.
    $profileTables = ['hotspot_profiles','hotspot_profile_router_sync','hotspot_profile_sync_queue'];
    $missingProfileTable = false;
    foreach ($profileTables as $tableName) {
        $checkTable = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
        $checkTable->execute([$tableName]);
        if ((int)$checkTable->fetchColumn() === 0) { $missingProfileTable = true; break; }
    }
    if ($missingProfileTable) {
        $migrationPath = $projectRoot . '/database/migrations/006_hotspot_profile_manager.sql';
        $migrationSql = is_file($migrationPath) ? file_get_contents($migrationPath) : false;
        if ($migrationSql === false) throw new RuntimeException('File migration 006 HotSpot Profile Manager tidak ditemukan. Upload ulang folder database/migrations.');
        $migrationSql = preg_replace('/^\s*--.*$/m', '', $migrationSql) ?? $migrationSql;
        foreach (array_filter(array_map('trim', explode(';', $migrationSql))) as $statement) $pdo->exec($statement);
        try { $pdo->exec("INSERT IGNORE INTO rw_schema_migrations (migration) VALUES ('006_hotspot_profile_manager.sql')"); } catch (Throwable $ignored) {}
    }
    SchemaUpgrade::ensurePackageSnapshots($pdo);
    SchemaUpgrade::ensureViaQrisDynamicAmounts($pdo);
    $adminCount = (int) $pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn();
} catch (Throwable $e) {
    http_response_code(503);
    echo '<h1>RAKAN WIMAN belum terhubung ke database</h1><p>Periksa kredensial database pada file .env. Jika instalasi belum dilakukan, buka <a href="' . htmlspecialchars(url('install'), ENT_QUOTES, 'UTF-8') . '">halaman instalasi</a>.</p><pre>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>';
    exit;
}

if ($adminCount === 0 && $path !== 'setup') redirect('setup');
if ($path === '' || $path === 'index.php') redirect(Auth::check() ? 'dashboard' : 'login');

if ($path === 'setup') {
    if ($adminCount > 0) redirect('login');
    $error = null;
    if ($method === 'POST') {
        verify_csrf();
        $name = trim($_POST['name'] ?? '');
        $email = mb_strtolower(trim($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12) {
            $error = 'Lengkapi nama, email valid, dan kata sandi minimal 12 karakter.';
        } else {
            $stmt = $pdo->prepare('INSERT INTO admins (name,email,password_hash,role) VALUES (?,?,?,?)');
            $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), 'superadmin']);
            Auth::login($email, $password);
            redirect('dashboard');
        }
    }
    View::render('auth/setup', ['error' => $error, 'title' => 'Setup Administrator'], 'layouts/guest');
    exit;
}

if ($path === 'login') {
    if (Auth::check()) redirect('dashboard');
    $error = null;
    if ($method === 'POST') {
        verify_csrf();
        if (Auth::login(trim($_POST['email'] ?? ''), $_POST['password'] ?? '')) redirect('dashboard');
        $error = 'Email atau kata sandi tidak sesuai.';
    }
    View::render('auth/login', ['error' => $error, 'title' => 'Login Administrator'], 'layouts/guest');
    exit;
}

if ($path === 'logout' && $method === 'POST') { verify_csrf(); Auth::logout(); redirect('login'); }

// Public captive-portal purchase flow. Invoice is a high-entropy bearer reference.
if ($path === 'buy') {
    $error = null;
    if ($method === 'POST') {
        verify_csrf();
        try {
            $result = (new ViaQrisService($pdo))->create((int)($_POST['package_id'] ?? 0), (string)($_POST['whatsapp'] ?? ''), (string)($_POST['customer_name'] ?? ''));
            redirect('pay/' . rawurlencode($result['invoice']));
        } catch (Throwable $e) { $error = $e->getMessage(); }
    }
    $packages = $pdo->query('SELECT id,name,price,description,validity_days FROM packages WHERE is_active=1 ORDER BY sort_order,id')->fetchAll();
    $settings = new AppSettings($pdo);
    $supportWhatsapp = preg_replace('/\D+/', '', (string)$settings->get('qris_support_whatsapp', '')) ?? '';
    View::render('public/buy', compact('packages','error','supportWhatsapp'), 'layouts/public'); exit;
}
// Public voucher PDF download. The random invoice number is the bearer reference;
// only a confirmed paid transaction with an issued voucher can be downloaded.
if (preg_match('#^voucher-download/([A-Z0-9]+)\.pdf$#', $path, $m)) {
    try {
        $downloadQuery = $pdo->prepare("SELECT p.invoice_no,p.status,p.validity_days_snapshot,p.voucher_id,v.username,v.password_encrypted,v.sync_status,COALESCE(go.user_mode,'separate') AS user_mode,COALESCE(v.package_name_snapshot,pk.name) AS package_name FROM payments p JOIN packages pk ON pk.id=p.package_id JOIN vouchers v ON v.id=p.voucher_id LEFT JOIN voucher_generation_options go ON go.voucher_id=v.id WHERE p.invoice_no=? LIMIT 1");
        $downloadQuery->execute([$m[1]]);
        $row = $downloadQuery->fetch();
        if (!$row || $row['status'] !== 'paid' || empty($row['voucher_id'])) {
            http_response_code(404);
            exit('Voucher belum tersedia. Pastikan pembayaran sudah berhasil.');
        }
        $voucherTemplateSettings = new AppSettings($pdo);
        $pdf = \RakanWiman\Services\VoucherPdfService::create([
            'invoice_no' => $row['invoice_no'],
            'package_name' => $row['package_name'],
            'username' => $row['username'],
            'password' => VoucherService::decryptSecret($row['password_encrypted']),
            'user_mode' => $row['user_mode'],
            'sync_status' => $row['sync_status'],
            'validity_days' => $row['validity_days_snapshot'],
            'voucher_title' => $voucherTemplateSettings->get('voucher_title', 'VOUCHER INTERNET'),
            'voucher_subtitle' => $voucherTemplateSettings->get('voucher_subtitle', 'RAKAN WIMAN'),
            'voucher_footer' => $voucherTemplateSettings->get('voucher_footer', 'Terima kasih telah menggunakan layanan kami.'),
            'accent_color' => $voucherTemplateSettings->get('voucher_accent_color', '#2563eb'),
            'show_password' => $voucherTemplateSettings->get('voucher_show_password', '1') === '1',
        ]);
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="voucher-' . preg_replace('/[^A-Z0-9-]/', '', $row['invoice_no']) . '.pdf"');
        header('Content-Length: ' . strlen($pdf));
        header('Cache-Control: private, no-store, max-age=0');
        echo $pdf;
    } catch (Throwable $e) {
        http_response_code(500);
        echo 'Tidak dapat membuat PDF voucher: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    }
    exit;
}

if (preg_match('#^pay/([A-Z0-9]+)$#', $path, $m)) {
    $invoice = $m[1];
    try {
        $service = new ViaQrisService($pdo);
        $status = $service->getPublicStatus($invoice);
        $detail = $pdo->prepare('SELECT payment_url,qr_image_url,payment_payload FROM payments WHERE invoice_no=?');
        $detail->execute([$invoice]);
        $payment = $detail->fetch();
        $voucher = null;
        if ($status['status'] === 'paid' && !empty($status['voucher_id'])) {
            $query = $pdo->prepare("SELECT v.username,v.password_encrypted,COALESCE(go.user_mode,'separate') AS user_mode,COALESCE(v.package_name_snapshot,p.name) AS package_name,COALESCE(v.validity_days_snapshot,p.validity_days) AS validity_days,v.sync_status FROM vouchers v JOIN packages p ON p.id=v.package_id LEFT JOIN voucher_generation_options go ON go.voucher_id=v.id WHERE v.id=?");
            $query->execute([$status['voucher_id']]);
            $voucherRow = $query->fetch();
            if ($voucherRow) {
                $voucherPassword = VoucherService::decryptSecret($voucherRow['password_encrypted']);
                $voucher = [
                    'username' => $voucherRow['username'],
                    'password' => $voucherPassword,
                    'user_mode' => $voucherRow['user_mode'],
                    'package_name' => $voucherRow['package_name'],
                    'validity_days' => $voucherRow['validity_days'],
                    'sync_status' => $voucherRow['sync_status'],
                ];
                $qrPayload = 'RWV1|' . $voucherRow['username'] . '|' . $voucherPassword;
                $voucher['qr_svg'] = \RakanWiman\Services\QrCode::svg($qrPayload, 5, 4);
            }
        }
        $settings = new AppSettings($pdo);
        $supportWhatsapp = preg_replace('/\D+/', '', (string)$settings->get('qris_support_whatsapp', '')) ?? '';
        $paymentStatusUrl = \RakanWiman\Core\url('payment-status/' . rawurlencode($invoice));
        $paymentPageUrl = \RakanWiman\Core\url('pay/' . rawurlencode($invoice));
        $paymentQrHintUrl = \RakanWiman\Core\url('payment-qr-hint/' . rawurlencode($invoice));
        $buyUrl = \RakanWiman\Core\url('buy');
        $voucherPdfUrl = $voucher ? \RakanWiman\Core\url('voucher-download/' . rawurlencode($invoice) . '.pdf') : '';
        View::render('public/payment', compact('invoice','status','payment','voucher','supportWhatsapp','paymentStatusUrl','paymentPageUrl','paymentQrHintUrl','buyUrl','voucherPdfUrl'), 'layouts/public');
    } catch (Throwable $e) {
        http_response_code(404);
        echo 'Transaksi tidak ditemukan.';
    }
    exit;
}
if (preg_match('#^payment-qr-hint/([A-Z0-9]+)$#', $path, $m) && $method === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    try {
        $rawBody = file_get_contents('php://input') ?: '';
        if (strlen($rawBody) > 5000) throw new RuntimeException('Payload QRIS terlalu besar.');
        $body = json_decode($rawBody, true);
        if (!is_array($body) || !isset($body['qr_payload']) || !is_string($body['qr_payload'])) {
            throw new RuntimeException('Payload nominal QR tidak valid.');
        }
        $payment = (new ViaQrisService($pdo))->recordQrPayloadHint($m[1], $body['qr_payload']);
        echo json_encode(['ok' => true, 'payment' => $payment], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if (preg_match('#^payment-status/([A-Z0-9]+)$#', $path, $m)) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    try {
        $service = new ViaQrisService($pdo);
        $service->refresh($m[1]);
        // A confirmed payment creates the voucher atomically; workers then push it to the router and send WhatsApp.
        try {
            $repository = new RouterRepository($pdo);
            (new RouterSyncWorker($pdo, $repository))->run(30);
            (new WhatsAppGateway($pdo))->processQueue(10);
        } catch (Throwable $workerError) {
            // Keep queued jobs for the scheduled worker; payment must remain confirmed.
        }
        $result = $service->getPublicStatus($m[1]);
        echo json_encode(['ok' => true, 'payment' => $result], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(502);
        echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}
if ($path === 'callback/viaqris' && $method === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    try { $payload=json_decode(file_get_contents('php://input') ?: '',true); if(!is_array($payload))throw new RuntimeException('JSON callback tidak valid.'); $result=(new ViaQrisService($pdo))->callback($payload); try{$repo=new RouterRepository($pdo);(new RouterSyncWorker($pdo,$repo))->run(30);(new WhatsAppGateway($pdo))->processQueue(10);}catch(Throwable $workerError){} echo json_encode($result); }
    catch(Throwable $e) { http_response_code(400); echo json_encode(['ok'=>false,'message'=>$e->getMessage()]); }
    exit;
}
// Public HTTPS QR scanner used by the HotSpot login page. Camera access is provided
// by the secure APP_URL origin; credentials are returned only to the opener page.
if ($path === 'hotspot-scan' && $method === 'GET') {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    View::render('public/hotspot-scan', ['title' => 'Scan Voucher Wi-Fi'], 'layouts/public');
    exit;
}

// Public-compatible direct WhatsApp API. The legacy PHPNuxBill-compatible secret is md5(AUTH_BASIC_PASSWORD).
if ($path === 'whatsapp/send' && in_array($method, ['GET', 'POST'], true)) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $settings = new AppSettings($pdo);
        $secret = $settings->getSecret('wa_gateway_secret');
        $provided = (string)($_REQUEST['secret'] ?? '');
        if ($secret === '' || $provided === '' || !hash_equals(md5($secret), $provided)) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Invalid secret']);
            exit;
        }
        $recipient = (string)($_REQUEST['to'] ?? $_REQUEST['number'] ?? '');
        $message = (string)($_REQUEST['msg'] ?? $_REQUEST['message'] ?? '');
        $queueId = (new WhatsAppGateway($pdo))->enqueueDirect($recipient, $message);
        http_response_code(202);
        echo json_encode(['status' => 'success', 'message' => 'Message queued', 'data' => ['queue_id' => $queueId]], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

Auth::requireLogin();

if ($path === 'settings/download-login-template') {
    try {
        $appUrl = rtrim((string)(getenv('APP_URL') ?: ''), '/');
        if ($appUrl === '' || !filter_var($appUrl, FILTER_VALIDATE_URL) || strtolower((string)parse_url($appUrl, PHP_URL_SCHEME)) !== 'https') {
            throw new RuntimeException('APP_URL pada .env harus berisi alamat HTTPS aplikasi sebelum template diunduh.');
        }
        $archive = HotspotTemplateExporter::build(dirname(__DIR__) . '/hotspot-template/RakanWiman', $appUrl . '/buy', $appUrl . '/hotspot-scan');
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="RAKAN-WIMAN-Hotspot-Login.zip"');
        header('Content-Length: ' . strlen($archive));
        header('X-Content-Type-Options: nosniff');
        echo $archive;
    } catch (Throwable $e) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Template login tidak dapat dibuat: ' . $e->getMessage();
    }
    exit;
}

if ($path === 'settings') {
    $error = null;
    $currentAdmin = Auth::user();
    $isSuperadmin = ($currentAdmin['role'] ?? '') === 'superadmin';
    if ($method === 'POST') {
        verify_csrf();
        try {
            $action = (string)($_POST['action'] ?? 'profile_update');
            if ($action === 'profile_update') {
                $name = trim((string)($_POST['name'] ?? ''));
                $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
                $currentPassword = (string)($_POST['current_password'] ?? '');
                $newPassword = (string)($_POST['new_password'] ?? '');
                if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Nama dan email administrator harus valid.');
                $check = $pdo->prepare('SELECT password_hash FROM admins WHERE id=? AND is_active=1');
                $check->execute([(int)$currentAdmin['id']]);
                $adminRow = $check->fetch();
                if (!$adminRow || !password_verify($currentPassword, $adminRow['password_hash'])) throw new RuntimeException('Kata sandi saat ini tidak sesuai.');
                if ($newPassword !== '' && strlen($newPassword) < 12) throw new RuntimeException('Kata sandi baru minimal 12 karakter.');
                if ($newPassword === '') {
                    $upd = $pdo->prepare('UPDATE admins SET name=?,email=? WHERE id=?');
                    $upd->execute([$name, $email, (int)$currentAdmin['id']]);
                } else {
                    $upd = $pdo->prepare('UPDATE admins SET name=?,email=?,password_hash=? WHERE id=?');
                    $upd->execute([$name, $email, password_hash($newPassword, PASSWORD_DEFAULT), (int)$currentAdmin['id']]);
                }
                $log = $pdo->prepare('INSERT INTO audit_logs(admin_id,action,entity_type,entity_id,description,ip_address) VALUES(?,?,?,?,?,?)');
                $log->execute([(int)$currentAdmin['id'], 'admin.profile_updated', 'admin', (int)$currentAdmin['id'], 'Profil administrator diperbarui', $_SERVER['REMOTE_ADDR'] ?? null]);
                flash('success', 'Data login administrator berhasil diperbarui.');
                redirect('settings');
            }
            if ($action === 'admin_create') {
                if (!$isSuperadmin) throw new RuntimeException('Hanya Superadmin yang dapat menambahkan akun administrator.');
                $name = trim((string)($_POST['name'] ?? ''));
                $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
                $password = (string)($_POST['password'] ?? '');
                $role = (string)($_POST['role'] ?? 'admin');
                if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12 || !in_array($role, ['admin','operator'], true)) {
                    throw new RuntimeException('Lengkapi nama, email valid, kata sandi minimal 12 karakter, dan role yang benar.');
                }
                $ins = $pdo->prepare('INSERT INTO admins(name,email,password_hash,role) VALUES(?,?,?,?)');
                $ins->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role]);
                $newId = (int)$pdo->lastInsertId();
                $log = $pdo->prepare('INSERT INTO audit_logs(admin_id,action,entity_type,entity_id,description,ip_address) VALUES(?,?,?,?,?,?)');
                $log->execute([(int)$currentAdmin['id'], 'admin.created', 'admin', $newId, 'Akun admin baru dibuat: ' . $email, $_SERVER['REMOTE_ADDR'] ?? null]);
                flash('success', 'Akun administrator baru berhasil dibuat.');
                redirect('settings');
            }
            if ($action === 'admin_toggle') {
                if (!$isSuperadmin) throw new RuntimeException('Hanya Superadmin yang dapat mengubah status akun.');
                $targetId = (int)($_POST['admin_id'] ?? 0);
                if ($targetId === (int)$currentAdmin['id']) throw new RuntimeException('Akun yang sedang digunakan tidak dapat dinonaktifkan.');
                $targetQ = $pdo->prepare('SELECT id,role,is_active,email FROM admins WHERE id=?');
                $targetQ->execute([$targetId]);
                $target = $targetQ->fetch();
                if (!$target) throw new RuntimeException('Akun administrator tidak ditemukan.');
                if ($target['role'] === 'superadmin' && (int)$target['is_active'] === 1) {
                    $activeSuper = (int)$pdo->query("SELECT COUNT(*) FROM admins WHERE role='superadmin' AND is_active=1")->fetchColumn();
                    if ($activeSuper <= 1) throw new RuntimeException('Tidak dapat menonaktifkan Superadmin aktif yang terakhir.');
                }
                $newStatus = (int)$target['is_active'] === 1 ? 0 : 1;
                $pdo->prepare('UPDATE admins SET is_active=? WHERE id=?')->execute([$newStatus, $targetId]);
                $log = $pdo->prepare('INSERT INTO audit_logs(admin_id,action,entity_type,entity_id,description,ip_address) VALUES(?,?,?,?,?,?)');
                $log->execute([(int)$currentAdmin['id'], 'admin.status_changed', 'admin', $targetId, 'Status akun ' . $target['email'] . ' diubah menjadi ' . ($newStatus ? 'aktif' : 'nonaktif'), $_SERVER['REMOTE_ADDR'] ?? null]);
                flash('success', 'Status akun administrator diperbarui.');
                redirect('settings');
            }
            throw new RuntimeException('Tindakan pengaturan tidak dikenal.');
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
    $currentQ = $pdo->prepare('SELECT id,name,email,role FROM admins WHERE id=?');
    $currentQ->execute([(int)$currentAdmin['id']]);
    $profile = $currentQ->fetch() ?: $currentAdmin;
    $admins = $isSuperadmin ? $pdo->query('SELECT id,name,email,role,is_active,last_login_at,created_at FROM admins ORDER BY id')->fetchAll() : [];
    $portalUrl = rtrim((string)(getenv('APP_URL') ?: ''), '/') . '/buy';
    $portalHost = (string)(parse_url(getenv('APP_URL') ?: '', PHP_URL_HOST) ?: 'domain-aplikasi-anda');
    $title = 'Pengaturan';
    View::render('settings/index', compact('profile','admins','isSuperadmin','error','title','portalUrl','portalHost'), 'layouts/main');
    exit;
}

if ($path === 'voucher-template') {
    $settings = new AppSettings($pdo);
    $error = null;
    if ($method === 'POST') {
        verify_csrf();
        try {
            $title = trim((string)($_POST['voucher_title'] ?? 'VOUCHER INTERNET'));
            $subtitle = trim((string)($_POST['voucher_subtitle'] ?? 'RAKAN WIMAN'));
            $footer = trim((string)($_POST['voucher_footer'] ?? 'Terima kasih telah menggunakan layanan kami.'));
            $color = trim((string)($_POST['voucher_accent_color'] ?? '#2563eb'));
            if ($title === '' || strlen($title) > 80 || strlen($subtitle) > 120 || strlen($footer) > 240) throw new RuntimeException('Periksa panjang judul, subjudul, dan footer template.');
            if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) throw new RuntimeException('Warna aksen harus berupa kode HEX, contoh #2563eb.');
            $settings->set('voucher_title', $title);
            $settings->set('voucher_subtitle', $subtitle);
            $settings->set('voucher_footer', $footer);
            $settings->set('voucher_accent_color', $color);
            $settings->set('voucher_show_password', isset($_POST['voucher_show_password']) ? '1' : '0');
            $log = $pdo->prepare('INSERT INTO audit_logs(admin_id,action,entity_type,description,ip_address) VALUES(?,?,?,?,?)');
            $log->execute([(int)$_SESSION['admin_id'], 'voucher.template_updated', 'voucher_template', 'Template cetak voucher diperbarui', $_SERVER['REMOTE_ADDR'] ?? null]);
            flash('success', 'Template cetak voucher berhasil disimpan.');
            redirect('voucher-template');
        } catch (Throwable $e) { $error = $e->getMessage(); }
    }
    $template = [
        'title' => $settings->get('voucher_title', 'VOUCHER INTERNET'),
        'subtitle' => $settings->get('voucher_subtitle', 'RAKAN WIMAN'),
        'footer' => $settings->get('voucher_footer', 'Terima kasih telah menggunakan layanan kami.'),
        'color' => $settings->get('voucher_accent_color', '#2563eb'),
        'show_password' => $settings->get('voucher_show_password', '1') === '1',
    ];
    $title = 'Template Voucher';
    View::render('voucher-template/index', compact('template','error','title'), 'layouts/main');
    exit;
}

if ($path === 'qris') {
    $settings = new AppSettings($pdo);
    $error = null;
    if ($method === 'POST') {
        verify_csrf();
        try {
            if (($_POST['form_type'] ?? '') === 'qris_voucher_settings') {
                $userMode = (string)($_POST['qris_voucher_user_mode'] ?? 'separate');
                $characterSet = (string)($_POST['qris_voucher_character_set'] ?? 'mixed_alnum');
                $nameLength = (int)($_POST['qris_voucher_name_length'] ?? 8);
                $prefix = preg_replace('/[^A-Za-z0-9_-]/', '', trim((string)($_POST['qris_voucher_prefix'] ?? 'RW'))) ?? '';
                $hotspotServer = trim((string)($_POST['qris_voucher_hotspot_server'] ?? 'all')) ?: 'all';
                $dataLimitValue = trim((string)($_POST['qris_voucher_data_limit'] ?? ''));
                $dataLimitUnit = strtoupper((string)($_POST['qris_voucher_data_unit'] ?? 'MB'));
                $comment = trim((string)($_POST['qris_voucher_comment'] ?? 'Pembelian QRIS'));
                if (!in_array($userMode, ['separate','same'], true)) throw new RuntimeException('Mode username/password QRIS tidak valid.');
                if (!in_array($characterSet, ['lower','upper','mixed_alpha','lower_alnum','upper_alnum','mixed_alnum'], true)) throw new RuntimeException('Pola karakter voucher QRIS tidak valid.');
                if ($nameLength < 3 || $nameLength > 8) throw new RuntimeException('Panjang username QRIS harus 3–8 karakter.');
                if (strlen($prefix) > 24) throw new RuntimeException('Prefix QRIS maksimal 24 karakter.');
                if ($hotspotServer !== 'all' && !preg_match('/^[A-Za-z0-9_.-]{1,120}$/', $hotspotServer)) throw new RuntimeException('Nama HotSpot Server QRIS tidak valid.');
                if (!in_array($dataLimitUnit, ['KB','MB','GB'], true)) throw new RuntimeException('Satuan Data Limit QRIS tidak valid.');
                VoucherService::parseDataLimit($dataLimitValue, $dataLimitUnit);
                if (strlen($comment) > 255) throw new RuntimeException('Komentar voucher QRIS maksimal 255 karakter.');
                $settings->set('qris_voucher_user_mode', $userMode);
                $settings->set('qris_voucher_name_length', (string)$nameLength);
                $settings->set('qris_voucher_prefix', $prefix);
                $settings->set('qris_voucher_character_set', $characterSet);
                $settings->set('qris_voucher_hotspot_server', $hotspotServer);
                $settings->set('qris_voucher_data_limit', $dataLimitValue);
                $settings->set('qris_voucher_data_unit', $dataLimitUnit);
                $settings->set('qris_voucher_comment', $comment);
                $log = $pdo->prepare('INSERT INTO audit_logs(admin_id,action,entity_type,description,ip_address) VALUES(?,?,?,?,?)');
                $log->execute([(int)$_SESSION['admin_id'], 'qris.voucher_settings_updated', 'qris', 'Konfigurasi otomatis voucher pembelian QRIS diperbarui', $_SERVER['REMOTE_ADDR'] ?? null]);
                flash('success', 'Konfigurasi otomatis voucher QRIS berhasil disimpan. Pengaturan berlaku untuk pembelian berikutnya.');
                redirect('qris');
            }
            $merchant = trim((string)($_POST['viaqris_merchant'] ?? ''));
            $apiUrl = trim((string)($_POST['viaqris_api_url'] ?? ''));
            $apiKey = trim((string)($_POST['viaqris_api_key'] ?? ''));
            $routerId = (int)($_POST['default_router_id'] ?? 0);
            $supportWhatsapp = preg_replace('/\D+/', '', (string)($_POST['qris_support_whatsapp'] ?? '')) ?? '';
            if (str_starts_with($supportWhatsapp, '0')) $supportWhatsapp = '62' . substr($supportWhatsapp, 1);
            elseif (str_starts_with($supportWhatsapp, '8')) $supportWhatsapp = '62' . $supportWhatsapp;
            if ($supportWhatsapp !== '' && !preg_match('/^62[0-9]{8,15}$/', $supportWhatsapp)) throw new RuntimeException('Nomor WhatsApp admin harus menggunakan format 62xxxxxxxxxx atau dikosongkan.');
            if ($merchant === '' || $apiUrl === '') throw new RuntimeException('Kode Merchant dan API URL ViaQRIS wajib diisi.');
            if (!filter_var($apiUrl, FILTER_VALIDATE_URL)) throw new RuntimeException('API URL ViaQRIS tidak valid.');
            if ($apiKey === '' && $settings->getSecret('viaqris_api_key') === '') throw new RuntimeException('API Token ViaQRIS wajib diisi.');
            if ($routerId > 0) {
                $rq = $pdo->prepare('SELECT id FROM routers WHERE id=? AND is_active=1');
                $rq->execute([$routerId]);
                if (!$rq->fetch()) throw new RuntimeException('Router default tidak ditemukan atau tidak aktif.');
            }
            $settings->set('viaqris_merchant', $merchant);
            $settings->set('viaqris_api_url', rtrim($apiUrl, '/'));
            if ($apiKey !== '') $settings->setSecret('viaqris_api_key', $apiKey);
            $settings->set('default_router_id', (string)$routerId);
            $settings->set('qris_support_whatsapp', $supportWhatsapp);
            $log = $pdo->prepare('INSERT INTO audit_logs(admin_id,action,entity_type,description,ip_address) VALUES(?,?,?,?,?)');
            $log->execute([(int)$_SESSION['admin_id'], 'qris.settings_updated', 'qris', 'Konfigurasi ViaQRIS diperbarui', $_SERVER['REMOTE_ADDR'] ?? null]);
            flash('success', 'Konfigurasi QRIS berhasil disimpan.');
            redirect('qris');
        } catch (Throwable $e) { $error = $e->getMessage(); }
    }
    $values = [
        'merchant' => $settings->get('viaqris_merchant', ''),
        'api_url' => $settings->get('viaqris_api_url', ''),
        'default_router_id' => $settings->get('default_router_id', '0'),
        'support_whatsapp' => $settings->get('qris_support_whatsapp', ''),
        'api_key_configured' => $settings->getSecret('viaqris_api_key') !== '',
    ];
    $voucherSettings = [
        'user_mode' => $settings->get('qris_voucher_user_mode', 'separate'),
        'name_length' => $settings->get('qris_voucher_name_length', '8'),
        'prefix' => $settings->get('qris_voucher_prefix', 'RW'),
        'character_set' => $settings->get('qris_voucher_character_set', 'mixed_alnum'),
        'hotspot_server' => $settings->get('qris_voucher_hotspot_server', 'all'),
        'data_limit' => $settings->get('qris_voucher_data_limit', ''),
        'data_unit' => $settings->get('qris_voucher_data_unit', 'MB'),
        'comment' => $settings->get('qris_voucher_comment', 'Pembelian QRIS'),
    ];
    $routers = $pdo->query('SELECT id,name,host FROM routers WHERE is_active=1 ORDER BY name')->fetchAll();
    $filters = [
        'q' => trim((string)($_GET['q'] ?? '')),
        'status' => (string)($_GET['status'] ?? ''),
        'from' => (string)($_GET['from'] ?? ''),
        'to' => (string)($_GET['to'] ?? ''),
    ];
    foreach (['from','to'] as $dateKey) if ($filters[$dateKey] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters[$dateKey])) $filters[$dateKey] = '';
    $where = ["p.gateway='viaqris'"];
    $params = [];
    if ($filters['status'] !== '' && in_array($filters['status'], ['pending','paid','expired','cancelled','failed','refunded'], true)) { $where[] = 'p.status=?'; $params[] = $filters['status']; }
    if ($filters['from'] !== '') { $where[] = 'p.created_at>=?'; $params[] = $filters['from'] . ' 00:00:00'; }
    if ($filters['to'] !== '') { $where[] = 'p.created_at<=?'; $params[] = $filters['to'] . ' 23:59:59'; }
    if ($filters['q'] !== '') {
        $where[] = '(p.invoice_no LIKE ? OR p.gateway_transaction_id LIKE ? OR p.customer_name LIKE ? OR p.whatsapp_number LIKE ? OR COALESCE(p.package_name_snapshot,pk.name) LIKE ? OR v.username LIKE ?)';
        $needle = '%' . $filters['q'] . '%';
        array_push($params, $needle, $needle, $needle, $needle, $needle, $needle);
    }
    $whereSql = implode(' AND ', $where);
    $paymentSelect = "SELECT p.id,p.invoice_no,p.customer_name,p.whatsapp_number,p.amount,p.unique_code,p.payable_amount,p.paid_amount,COALESCE(p.paid_amount,p.payable_amount,p.amount) AS display_amount,p.status,p.created_at,p.paid_at,p.expires_at,p.gateway_transaction_id,COALESCE(p.package_name_snapshot,pk.name) AS package_name,v.username AS voucher_username,v.sync_status AS voucher_sync_status FROM payments p JOIN packages pk ON pk.id=p.package_id LEFT JOIN vouchers v ON v.id=p.voucher_id WHERE $whereSql";
    if (isset($_GET['export']) && $_GET['export'] === 'csv') {
        $exportQ = $pdo->prepare($paymentSelect . ' ORDER BY p.id DESC');
        $exportQ->execute($params);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="rakan-wiman-audit-qris-' . date('Ymd-His') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['ID','Invoice','Nama Pelanggan','WhatsApp','Paket','Harga Paket','Kode Unik','Total QRIS','Nominal Dibayar','Status','ID Transaksi Gateway','Waktu Dibuat','Waktu Dibayar','Username Voucher','Sinkronisasi Voucher']);
        while ($row = $exportQ->fetch()) {
            $cells = [(string)$row['id'],$row['invoice_no'],$row['customer_name'],$row['whatsapp_number'],$row['package_name'],(string)$row['amount'],(string)($row['unique_code'] ?? ''),(string)($row['payable_amount'] ?? ''),(string)($row['paid_amount'] ?? ''),$row['status'],$row['gateway_transaction_id'],$row['created_at'],$row['paid_at'],$row['voucher_username'],$row['voucher_sync_status']];
            foreach ($cells as &$cell) { $cell = (string)($cell ?? ''); if ($cell !== '' && preg_match('/^[=+@\-]/', $cell)) $cell = "'" . $cell; } unset($cell);
            fputcsv($out, $cells);
        }
        fclose($out);
        exit;
    }
    $summaryQ = $pdo->prepare("SELECT COUNT(*) AS total,COALESCE(SUM(CASE WHEN p.status='paid' THEN COALESCE(p.paid_amount,p.payable_amount,p.amount) ELSE 0 END),0) AS paid_amount,COALESCE(SUM(p.status='paid'),0) AS paid_count FROM payments p JOIN packages pk ON pk.id=p.package_id LEFT JOIN vouchers v ON v.id=p.voucher_id WHERE $whereSql");
    $summaryQ->execute($params); $summary = $summaryQ->fetch();
    $page = max(1, (int)($_GET['page'] ?? 1)); $perPage = 50; $totalRows = (int)($summary['total'] ?? 0); $totalPages = max(1, (int)ceil($totalRows / $perPage)); $page = min($page, $totalPages); $offset = ($page - 1) * $perPage;
    $rowsQ = $pdo->prepare($paymentSelect . ' ORDER BY p.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset);
    $rowsQ->execute($params); $payments = $rowsQ->fetchAll();
    $title = 'QRIS';
    View::render('qris/index', compact('values','voucherSettings','routers','error','filters','summary','payments','page','totalPages','totalRows','title'), 'layouts/main');
    exit;
}

if ($path === 'qris/refresh' && $method === 'POST') {
    verify_csrf();
    $invoice = strtoupper(trim((string)($_POST['invoice_no'] ?? '')));
    try {
        $status = (new ViaQrisService($pdo))->refresh($invoice);
        try {
            $repository = new RouterRepository($pdo);
            (new RouterSyncWorker($pdo, $repository))->run(30);
            (new WhatsAppGateway($pdo))->processQueue(10);
        } catch (Throwable $workerError) { /* queued jobs remain available for cron retry */ }
        $label = ($status['status'] ?? '') === 'paid' ? 'Lunas' : (string)($status['status'] ?? 'tidak diketahui');
        $log = $pdo->prepare('INSERT INTO audit_logs(admin_id,action,entity_type,entity_id,description,ip_address) VALUES(?,?,?,?,?,?)');
        $log->execute([(int)$_SESSION['admin_id'], 'qris.status_checked', 'payment', null, 'Pemeriksaan status QRIS ' . $invoice . ': ' . $label, $_SERVER['REMOTE_ADDR'] ?? null]);
        flash('success', 'Pengecekan ViaQRIS selesai untuk ' . $invoice . '. Status: ' . $label . '.');
    } catch (Throwable $e) {
        $safeMessage = mb_substr($e->getMessage(), 0, 420);
        try {
            $log = $pdo->prepare('INSERT INTO audit_logs(admin_id,action,entity_type,description,ip_address) VALUES(?,?,?,?,?)');
            $log->execute([(int)$_SESSION['admin_id'], 'qris.status_check_failed', 'payment', 'Pemeriksaan QRIS ' . $invoice . ' gagal: ' . $safeMessage, $_SERVER['REMOTE_ADDR'] ?? null]);
        } catch (Throwable $logError) { /* payment error is more important than audit logging */ }
        flash('error', 'Gagal memeriksa transaksi ' . $invoice . ': ' . $safeMessage);
    }
    redirect('qris');
}

if ($path === 'whatsapp/config') {
    $settings = new AppSettings($pdo);
    $error = null;
    if ($method === 'POST') {
        verify_csrf();
        try {
            $baseUrl = rtrim(trim((string)($_POST['wa_gateway_url'] ?? '')), '/');
            $scheme = strtolower((string)parse_url($baseUrl, PHP_URL_SCHEME));
            if ($baseUrl === '' || !filter_var($baseUrl, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true)) {
                throw new RuntimeException('Server URL wajib diisi dengan URL HTTP/HTTPS yang valid.');
            }
            $secretInput = trim((string)($_POST['wa_gateway_secret'] ?? ''));
            if ($secretInput === '' && $settings->getSecret('wa_gateway_secret') === '') {
                throw new RuntimeException('Auth Basic Password wajib diisi. Salin nilai AUTH_BASIC_PASSWORD dari file .env Go WhatsApp Gateway.');
            }
            $countryCode = preg_replace('/\D+/', '', (string)($_POST['wa_country_code'] ?? '62')) ?: '62';
            if (strlen($countryCode) > 5) throw new RuntimeException('Kode negara tidak valid.');
            $settings->set('wa_gateway_url', $baseUrl);
            if ($secretInput !== '') $settings->setSecret('wa_gateway_secret', $secretInput);
            $settings->set('wa_country_code', $countryCode);
            $log = $pdo->prepare('INSERT INTO audit_logs(admin_id,action,entity_type,description,ip_address) VALUES(?,?,?,?,?)');
            $log->execute([(int)$_SESSION['admin_id'], 'whatsapp.settings_updated', 'whatsapp', 'Konfigurasi WhatsApp Gateway diperbarui', $_SERVER['REMOTE_ADDR'] ?? null]);
            flash('success', 'Configuration saved. Samakan Auth Basic Password dengan AUTH_BASIC_PASSWORD pada server gateway.');
            redirect('whatsapp/config');
        } catch (Throwable $e) { $error = $e->getMessage(); }
    }
    $values = [
        'gateway_url' => $settings->get('wa_gateway_url', ''),
        'gateway_secret' => $settings->getSecret('wa_gateway_secret'),
        'country_code' => $settings->get('wa_country_code', '62'),
    ];
    $title = 'WhatsApp Gateway Configuration';
    View::render('whatsapp/config', compact('values', 'error', 'title'), 'layouts/main');
    exit;
}

if ($path === 'whatsapp') {
    $settings = new AppSettings($pdo);
    $error = null;
    // Backward compatibility: accept the old combined settings POST after an upgrade.
    if ($method === 'POST' && isset($_POST['wa_gateway_url'])) {
        verify_csrf();
        try {
            $baseUrl = rtrim(trim((string)($_POST['wa_gateway_url'] ?? '')), '/');
            $scheme = strtolower((string)parse_url($baseUrl, PHP_URL_SCHEME));
            if ($baseUrl === '' || !filter_var($baseUrl, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true)) throw new RuntimeException('Server URL tidak valid.');
            $secretInput = trim((string)($_POST['wa_gateway_secret'] ?? ''));
            if ($secretInput === '' && $settings->getSecret('wa_gateway_secret') === '') throw new RuntimeException('Auth Basic Password wajib diisi.');
            $settings->set('wa_gateway_url', $baseUrl);
            if ($secretInput !== '') $settings->setSecret('wa_gateway_secret', $secretInput);
            $settings->set('wa_country_code', preg_replace('/\D+/', '', (string)($_POST['wa_country_code'] ?? '62')) ?: '62');
            if (isset($_POST['wa_message_template'])) $settings->set('wa_message_template', trim((string)$_POST['wa_message_template']));
            flash('success', 'Konfigurasi WhatsApp Gateway berhasil disimpan.');
            redirect('whatsapp');
        } catch (Throwable $e) { $error = $e->getMessage(); }
    }
    $values = [
        'configured' => $settings->get('wa_gateway_url', '') !== '' && $settings->getSecret('wa_gateway_secret') !== '',
        'message_template' => $settings->get('wa_message_template', 'Terima kasih telah membeli voucher RAKAN WIMAN. Username: {username} Password: {password} Paket: {package} Status aktivasi: {sync_status}.') ?? '',
        'country_code' => $settings->get('wa_country_code', '62') ?? '62',
    ];
    $senders = $pdo->query('SELECT id,phone_number,status,is_active,last_connected_at FROM whatsapp_senders ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
    $connectedSenders = array_values(array_filter($senders, static fn(array $sender): bool => ($sender['status'] ?? '') === 'connected' && (int)($sender['is_active'] ?? 0) === 1));
    $messages = $pdo->query("SELECT id,recipient,status,attempts,last_error,created_at,sent_at,related_type FROM message_queue WHERE channel='whatsapp' ORDER BY id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
    $secret = $settings->getSecret('wa_gateway_secret');
    $sendApiUrl = $secret === '' ? '' : url('whatsapp/send') . '?to=[number]&msg=[text]&secret=' . md5($secret);
    $title = 'WhatsApp Gateway';
    View::render('whatsapp/index', compact('values', 'senders', 'connectedSenders', 'messages', 'sendApiUrl', 'error', 'title'), 'layouts/main');
    exit;
}

if ($path === 'whatsapp/sender' && $method === 'POST') {
    verify_csrf();
    try { $result = (new WhatsAppGateway($pdo))->addSender((string)($_POST['phone_number'] ?? '')); flash('success', 'Phone ' . $result['phone'] . ' berhasil ditambahkan. JWT berhasil diperoleh; lanjutkan dengan QRCode atau Paircode.'); }
    catch (Throwable $e) { flash('error', 'Gagal menambahkan sender: ' . $e->getMessage()); }
    redirect('whatsapp');
}
if ($path === 'whatsapp/login' && $method === 'GET') {
    try {
        $result = (new WhatsAppGateway($pdo))->loginSender((int)($_GET['sender_id'] ?? 0), (string)($_GET['mode'] ?? 'qr'));
        $title = 'Login WhatsApp';
        View::render('whatsapp/login', compact('result', 'title'), 'layouts/main');
    } catch (Throwable $e) {
        flash('error', 'Gagal memulai login WhatsApp: ' . $e->getMessage());
        redirect('whatsapp');
    }
    exit;
}
if ($path === 'whatsapp/delete' && $method === 'POST') {
    verify_csrf();
    try { (new WhatsAppGateway($pdo))->deleteSender((int)($_POST['sender_id'] ?? 0)); flash('success', 'Sender WhatsApp berhasil dihapus.'); }
    catch (Throwable $e) { flash('error', 'Gagal menghapus sender: ' . $e->getMessage()); }
    redirect('whatsapp');
}
if ($path === 'whatsapp/template' && $method === 'POST') {
    verify_csrf();
    try {
        $template = trim((string)($_POST['wa_message_template'] ?? ''));
        if ($template === '' || mb_strlen($template) > 2000) throw new RuntimeException('Template pesan wajib diisi dan maksimal 2.000 karakter.');
        $settings = new AppSettings($pdo);
        $settings->set('wa_message_template', $template);
        $log = $pdo->prepare('INSERT INTO audit_logs(admin_id,action,entity_type,description,ip_address) VALUES(?,?,?,?,?)');
        $log->execute([(int)$_SESSION['admin_id'], 'whatsapp.template_updated', 'whatsapp', 'Template pesan WhatsApp diperbarui', $_SERVER['REMOTE_ADDR'] ?? null]);
        flash('success', 'Template pesan voucher berhasil disimpan.');
    } catch (Throwable $e) { flash('error', 'Gagal menyimpan template pesan: ' . $e->getMessage()); }
    redirect('whatsapp');
}
if ($path === 'whatsapp/test' && $method === 'POST') {
    verify_csrf();
    $gateway = new WhatsAppGateway($pdo);
    $senderId = (int)($_POST['sender_id'] ?? 0);
    $rawRecipient = (string)($_POST['recipient'] ?? '');
    $normalizedRecipient = null;
    try {
        $normalizedRecipient = $gateway->normalizeRecipient($rawRecipient);
        $result = $gateway->sendTestMessage($senderId, $normalizedRecipient);
    } catch (Throwable $e) {
        $safeError = mb_substr($e->getMessage(), 0, 700);
        if ($normalizedRecipient !== null) {
            try {
                $failedLog = $pdo->prepare("INSERT INTO message_queue(channel,recipient,message,related_type,status,attempts,available_at,last_error) VALUES('whatsapp',?,'Tes WhatsApp RAKAN WIMAN','test','failed',1,NOW(),?)");
                $failedLog->execute([$normalizedRecipient, $safeError]);
            } catch (Throwable $queueError) { /* keep the original send error */ }
        }
        try {
            $audit = $pdo->prepare('INSERT INTO audit_logs(admin_id,action,entity_type,description,ip_address) VALUES(?,?,?,?,?)');
            $audit->execute([(int)$_SESSION['admin_id'], 'whatsapp.test_failed', 'whatsapp', 'Tes WhatsApp gagal: ' . $safeError, $_SERVER['REMOTE_ADDR'] ?? null]);
        } catch (Throwable $logError) { /* preserve the original send error */ }
        flash('error', 'Tes WhatsApp gagal: ' . $safeError);
        redirect('whatsapp');
    }

    // Delivery is already completed. Audit/logging failures must not report it as an unsent message.
    $detail = 'Tes WhatsApp berhasil dikirim dari ' . $result['sender_phone'] . ' ke ' . $result['recipient'] . ' (HTTP ' . $result['http'] . ').';
    try {
        $queueLog = $pdo->prepare("INSERT INTO message_queue(channel,recipient,message,related_type,status,attempts,available_at,sent_at) VALUES('whatsapp',?,?,'test','sent',1,NOW(),NOW())");
        $queueLog->execute([$result['recipient'], $result['message']]);
    } catch (Throwable $queueError) { /* the actual gateway send already succeeded */ }
    try {
        $audit = $pdo->prepare('INSERT INTO audit_logs(admin_id,action,entity_type,description,ip_address) VALUES(?,?,?,?,?)');
        $audit->execute([(int)$_SESSION['admin_id'], 'whatsapp.test_sent', 'whatsapp', $detail, $_SERVER['REMOTE_ADDR'] ?? null]);
    } catch (Throwable $logError) { /* the actual gateway send already succeeded */ }
    flash('success', 'Pesan uji berhasil dikirim ke ' . $result['recipient'] . ' melalui sender ' . $result['sender_phone'] . '. Periksa WhatsApp tujuan.');
    redirect('whatsapp');
}
if ($path === 'whatsapp/retry' && $method === 'POST') {
    verify_csrf();
    $id = (int)($_POST['id'] ?? 0);
    $retry = $pdo->prepare("UPDATE message_queue SET status='pending',available_at=NOW(),last_error=NULL WHERE id=? AND channel='whatsapp' AND status='failed' AND COALESCE(related_type,'')<>'test'");
    $retry->execute([$id]);
    flash('success', $retry->rowCount() ? 'Pesan dimasukkan kembali ke antrean.' : 'Pesan tidak ditemukan atau bukan berstatus gagal.');
    redirect('whatsapp');
}

if ($path === 'dashboard') {
    $stats = [
        'routers' => (int) $pdo->query('SELECT COUNT(*) FROM routers WHERE is_active=1')->fetchColumn(),
        'packages' => (int) $pdo->query('SELECT COUNT(*) FROM packages WHERE is_active=1')->fetchColumn(),
        'vouchers' => (int) $pdo->query('SELECT COUNT(*) FROM vouchers')->fetchColumn(),
        'payments' => (int) $pdo->query("SELECT COUNT(*) FROM payments WHERE status='paid'")->fetchColumn(),
    ];

    // Live HotSpot sessions are read directly from active MikroTik routers on
    // every dashboard load. No session/voucher data is inferred from MySQL.
    $hotspotLive = [
        'total' => 0, 'router_total' => 0, 'router_ok' => 0, 'router_failed' => 0,
        'complete' => true, 'checked_at' => date('Y-m-d H:i:s'), 'routers' => [], 'sessions' => [],
    ];
    $hotspotMonitorError = null;
    try {
        $hotspotLive = (new HotspotMonitorService($pdo, new RouterRepository($pdo)))->snapshot(3);
    } catch (Throwable $monitorError) {
        $hotspotMonitorError = mb_substr($monitorError->getMessage(), 0, 300);
    }
    $stats['active_users'] = (int)$hotspotLive['total'];

    $recentRouters = $pdo->query('SELECT id,name,host,connection_status,last_seen_at FROM routers WHERE is_active=1 ORDER BY id DESC LIMIT 5')->fetchAll();
    $recentLogs = $pdo->query("SELECT l.id,l.action,l.entity_type,l.description,l.ip_address,l.created_at,COALESCE(a.name,'System') AS actor FROM audit_logs l LEFT JOIN admins a ON a.id=l.admin_id ORDER BY l.created_at DESC,l.id DESC LIMIT 15")->fetchAll();
    View::render('dashboard/index', compact('stats','recentRouters','recentLogs','hotspotLive','hotspotMonitorError'), 'layouts/main');
    exit;
}

if ($path === 'hotspot-profiles') {
    $error = null;
    $service = new HotspotProfileService($pdo, new RouterRepository($pdo));
    $routers = $pdo->query('SELECT id,name,host,connection_status FROM routers WHERE is_active=1 ORDER BY name')->fetchAll();
    $selectedRouterId = (int)($_GET['router_id'] ?? 0);
    if ($selectedRouterId < 1 && $routers) $selectedRouterId = (int)$routers[0]['id'];
    $selectedRouter = null;
    foreach ($routers as $routerRow) if ((int)$routerRow['id'] === $selectedRouterId) { $selectedRouter = $routerRow; break; }
    if (!$selectedRouter) $selectedRouterId = 0;

    if ($method === 'POST') {
        verify_csrf();
        $action = (string)($_POST['action'] ?? 'save');
        try {
            if ($action === 'refresh') {
                $routerId = (int)($_POST['router_id'] ?? 0);
                if ($routerId < 1) throw new RuntimeException('Pilih router yang akan dibaca.');
                $result = $service->refreshRouter($routerId);
                $_SESSION['hotspot_profile_remote_only'][$routerId] = $result['router_only'];
                $_SESSION['hotspot_profile_remote_checked'][$routerId] = date('Y-m-d H:i:s');
                $audit = $pdo->prepare('INSERT INTO audit_logs (admin_id,action,entity_type,entity_id,description,ip_address) VALUES (?,?,?,?,?,?)');
                $audit->execute([(int)$_SESSION['admin_id'],'hotspot_profile.router_refreshed','router',$routerId,'Profil HotSpot router diperiksa: '.$result['remote_count'].' profil, '.$result['counts']['synced'].' cocok, '.$result['counts']['missing'].' belum ada, '.$result['counts']['different'].' berbeda.',$_SERVER['REMOTE_ADDR'] ?? null]);
                flash('success', 'Profil RouterOS berhasil dibaca: ' . $result['remote_count'] . ' profil. Cocok: ' . $result['counts']['synced'] . ', belum ada: ' . $result['counts']['missing'] . ', berbeda: ' . $result['counts']['different'] . ', profil lama: ' . ($result['counts']['legacy_present'] + $result['counts']['legacy_missing']) . '. Profil hanya di MikroTik tidak diimpor.');
                redirect('hotspot-profiles?router_id=' . $routerId);
            }

            if ($action === 'sync') {
                $target = (string)($_POST['target_router'] ?? 'selected');
                $routerIds = [];
                if ($target === 'all') {
                    $routerIds = array_map(static fn($r) => (int)$r['id'], $routers);
                    if (!$routerIds) throw new RuntimeException('Belum ada router aktif untuk tujuan sinkronisasi.');
                } else {
                    $rid = (int)($_POST['router_id'] ?? 0);
                    $validRouterIds = array_map(static fn($r) => (int)$r['id'], $routers);
                    if ($rid < 1 || !in_array($rid, $validRouterIds, true)) {
                        throw new RuntimeException('Router tujuan tidak ditemukan atau tidak aktif. Pilih router yang terdaftar.');
                    }
                    $routerIds = [$rid];
                }
                $singleProfileId = (int)($_POST['profile_id'] ?? 0);
                if ($singleProfileId > 0) {
                    $managedProfiles = $pdo->prepare('SELECT id FROM hotspot_profiles WHERE id=? AND is_active=1 AND is_managed=1');
                    $managedProfiles->execute([$singleProfileId]);
                    $profileList = $managedProfiles->fetchAll();
                    if (!$profileList) throw new RuntimeException('Profil master tidak ditemukan, tidak aktif, atau belum dikelola.');
                } else {
                    $profileList = $pdo->query('SELECT id FROM hotspot_profiles WHERE is_active=1 AND is_managed=1 ORDER BY id')->fetchAll();
                }
                $jobs = 0;
                foreach ($profileList as $profileRow) $jobs += $service->enqueueForRouters((int)$profileRow['id'], $routerIds);
                $workerResult = (new HotspotProfileSyncWorker($pdo, $service))->run(10);
                $remainingQ = $pdo->query("SELECT COUNT(*) FROM hotspot_profile_sync_queue WHERE status IN ('pending','failed','processing')");
                $remaining = (int)$remainingQ->fetchColumn();
                $message = 'Sinkronisasi satu arah Database RAKAN WIMAN → MikroTik dijalankan. ' . $workerResult['done'] . ' berhasil, ' . $workerResult['failed'] . ' gagal/menunggu retry, ' . $remaining . ' pekerjaan masih antre.';
                if ($jobs === 0) $message = 'Belum ada profil master yang dikelola atau router aktif yang dipilih.';
                $syncLog = $pdo->prepare('INSERT INTO audit_logs (admin_id,action,entity_type,description,ip_address) VALUES (?,?,?,?,?)');
                $syncLog->execute([(int)$_SESSION['admin_id'],'hotspot_profile.sync_requested','hotspot_profile',$message,$_SERVER['REMOTE_ADDR'] ?? null]);
                flash($workerResult['failed'] > 0 ? 'error' : 'success', $message);
                redirect('hotspot-profiles?router_id=' . (int)($_POST['router_id'] ?? $selectedRouterId));
            }

            if ($action === 'save') {
                $input = HotspotProfileService::validateInput($_POST);
                $profileId = (int)($_POST['profile_id'] ?? 0);
                if ($profileId > 0) {
                    $currentQ = $pdo->prepare('SELECT id,name FROM hotspot_profiles WHERE id=? AND is_active=1');
                    $currentQ->execute([$profileId]);
                    $currentProfile = $currentQ->fetch();
                    if (!$currentProfile) throw new RuntimeException('Profil yang akan diperbarui tidak ditemukan.');
                    // Profile names are immutable once referenced by packages; all settings remain master-controlled.
                    $input['name'] = (string)$currentProfile['name'];
                    $update = $pdo->prepare('UPDATE hotspot_profiles SET shared_users=?,rate_limit=?,idle_timeout=?,keepalive_timeout=?,status_autorefresh=?,add_mac_cookie=?,mac_cookie_timeout=?,address_pool=?,address_list=?,transparent_proxy=?,is_managed=1 WHERE id=?');
                    $update->execute([$input['shared_users'],$input['rate_limit'],$input['idle_timeout'],$input['keepalive_timeout'],$input['status_autorefresh'],$input['add_mac_cookie'],$input['mac_cookie_timeout'],$input['address_pool'],$input['address_list'],$input['transparent_proxy'],$profileId]);
                    $savedId = $profileId;
                    $auditAction = 'hotspot_profile.updated';
                    $auditDescription = 'Master profil HotSpot diperbarui: ' . $input['name'];
                } else {
                    $existingQ = $pdo->prepare('SELECT id,is_managed FROM hotspot_profiles WHERE name=? LIMIT 1');
                    $existingQ->execute([$input['name']]);
                    $existing = $existingQ->fetch();
                    if ($existing) {
                        if ((int)$existing['is_managed'] === 1) throw new RuntimeException('Nama profil sudah terdaftar di master data.');
                        $savedId = (int)$existing['id'];
                        $update = $pdo->prepare('UPDATE hotspot_profiles SET shared_users=?,rate_limit=?,idle_timeout=?,keepalive_timeout=?,status_autorefresh=?,add_mac_cookie=?,mac_cookie_timeout=?,address_pool=?,address_list=?,transparent_proxy=?,is_managed=1,is_active=1 WHERE id=?');
                        $update->execute([$input['shared_users'],$input['rate_limit'],$input['idle_timeout'],$input['keepalive_timeout'],$input['status_autorefresh'],$input['add_mac_cookie'],$input['mac_cookie_timeout'],$input['address_pool'],$input['address_list'],$input['transparent_proxy'],$savedId]);
                    } else {
                        $insert = $pdo->prepare('INSERT INTO hotspot_profiles (name,shared_users,rate_limit,idle_timeout,keepalive_timeout,status_autorefresh,add_mac_cookie,mac_cookie_timeout,address_pool,address_list,transparent_proxy,is_managed,is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?,1,1)');
                        $insert->execute([$input['name'],$input['shared_users'],$input['rate_limit'],$input['idle_timeout'],$input['keepalive_timeout'],$input['status_autorefresh'],$input['add_mac_cookie'],$input['mac_cookie_timeout'],$input['address_pool'],$input['address_list'],$input['transparent_proxy']]);
                        $savedId = (int)$pdo->lastInsertId();
                    }
                    $auditAction = 'hotspot_profile.created';
                    $auditDescription = 'Master profil HotSpot dibuat/dikelola: ' . $input['name'];
                }

                foreach ($routers as $routerForCache) {
                    $cacheRouterId = (int)$routerForCache['id'];
                    if (!empty($_SESSION['hotspot_profile_remote_only'][$cacheRouterId])) {
                        $_SESSION['hotspot_profile_remote_only'][$cacheRouterId] = array_values(array_filter(
                            $_SESSION['hotspot_profile_remote_only'][$cacheRouterId],
                            static fn($remoteProfile) => (string)($remoteProfile['name'] ?? '') !== (string)$input['name']
                        ));
                    }
                }
                $routerIds = array_map(static fn($r) => (int)$r['id'], $routers);
                $queued = $service->enqueueForRouters($savedId, $routerIds);
                $workerResult = (new HotspotProfileSyncWorker($pdo, $service))->run(10);
                $remainingQ = $pdo->prepare("SELECT COUNT(*) FROM hotspot_profile_sync_queue WHERE profile_id=? AND status IN ('pending','failed','processing')");
                $remainingQ->execute([$savedId]);
                $remaining = (int)$remainingQ->fetchColumn();
                $log = $pdo->prepare('INSERT INTO audit_logs (admin_id,action,entity_type,entity_id,description,ip_address) VALUES (?,?,?,?,?,?)');
                $log->execute([(int)$_SESSION['admin_id'],$auditAction,'hotspot_profile',$savedId,$auditDescription,$_SERVER['REMOTE_ADDR'] ?? null]);
                $message = 'Profil tersimpan di database master.';
                if ($queued > 0) $message .= ' Sinkronisasi router: ' . $workerResult['done'] . ' berhasil, ' . $workerResult['failed'] . ' gagal/menunggu retry, ' . $remaining . ' masih antre.';
                else $message .= ' Belum ada router aktif; sinkronkan setelah router ditambahkan.';
                flash($workerResult['failed'] > 0 ? 'error' : 'success', $message);
                redirect('hotspot-profiles?router_id=' . $selectedRouterId);
            }
            throw new RuntimeException('Aksi profil HotSpot tidak dikenali.');
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }

    $editProfile = null;
    $prefillProfileName = trim((string)($_GET['new_master'] ?? ''));
    if ($prefillProfileName !== '') {
        $observedRemote = $_SESSION['hotspot_profile_remote_only'][$selectedRouterId] ?? [];
        $observed = false;
        foreach ($observedRemote as $remoteProfile) {
            if ((string)($remoteProfile['name'] ?? '') === $prefillProfileName) { $observed = true; break; }
        }
        if (!$selectedRouterId || !$observed) {
            $prefillProfileName = '';
            flash('error', 'Profil tersebut tidak ada pada daftar hasil pemeriksaan router. Tekan Baca Profil MikroTik terlebih dahulu.');
        } else {
            $known = $pdo->prepare('SELECT id FROM hotspot_profiles WHERE name=? AND is_active=1 LIMIT 1');
            $known->execute([$prefillProfileName]);
            $knownId = (int)$known->fetchColumn();
            if ($knownId > 0) {
                $prefillProfileName = '';
                redirect('hotspot-profiles?router_id=' . $selectedRouterId . '&edit=' . $knownId);
            }
        }
    }
    $editId = (int)($_GET['edit'] ?? 0);
    if ($editId > 0) {
        $editQ = $pdo->prepare('SELECT * FROM hotspot_profiles WHERE id=? AND is_active=1');
        $editQ->execute([$editId]);
        $editProfile = $editQ->fetch() ?: null;
    }
    $profileSql = 'SELECT p.*,s.status AS router_sync_status,s.last_checked_at,s.last_synced_at,s.last_error FROM hotspot_profiles p LEFT JOIN hotspot_profile_router_sync s ON s.profile_id=p.id AND s.router_id=? WHERE p.is_active=1 ORDER BY p.is_managed DESC,p.name';
    $profileQ = $pdo->prepare($profileSql);
    $profileQ->execute([$selectedRouterId ?: null]);
    $profileRows = $profileQ->fetchAll();
    $routerOnly = $_SESSION['hotspot_profile_remote_only'][$selectedRouterId] ?? [];
    $routerCheckedAt = $_SESSION['hotspot_profile_remote_checked'][$selectedRouterId] ?? null;
    $title = 'HotSpot Profile';
    View::render('hotspot-profiles/index', compact('routers','selectedRouter','selectedRouterId','profileRows','routerOnly','routerCheckedAt','editProfile','prefillProfileName','error','title'), 'layouts/main');
    exit;
}

if ($path === 'packages') {
    $error = null;
    $editId = $method === 'POST' ? (int)($_POST['package_id'] ?? 0) : (int)($_GET['edit'] ?? 0);
    $editPackage = null;
    if ($editId > 0) {
        $editQ = $pdo->prepare('SELECT * FROM packages WHERE id=? LIMIT 1');
        $editQ->execute([$editId]);
        $editPackage = $editQ->fetch() ?: null;
        if (!$editPackage) {
            flash('error', 'Paket yang akan diedit tidak ditemukan.');
            redirect('packages');
        }
    }
    $formValues = $editPackage ?: [];

    if ($method === 'POST') {
        verify_csrf();
        $formValues = array_merge($formValues, $_POST);
        $action = (string)($_POST['action'] ?? 'create');
        $packageId = (int)($_POST['package_id'] ?? 0);
        try {
            if (!in_array($action, ['create', 'update'], true)) throw new RuntimeException('Aksi paket tidak valid.');
            if ($action === 'update' && (!$editPackage || $packageId !== (int)$editPackage['id'])) throw new RuntimeException('Paket yang akan diperbarui tidak valid. Muat ulang halaman.');

            $name = trim((string)($_POST['name'] ?? ''));
            $code = strtoupper(trim((string)($_POST['code'] ?? '')));
            $price = filter_var($_POST['price'] ?? null, FILTER_VALIDATE_INT);
            $profile = trim((string)($_POST['mikrotik_profile'] ?? ''));
            $uptime = trim((string)($_POST['limit_uptime'] ?? ''));
            $daysRaw = trim((string)($_POST['validity_days'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            if ($name === '' || mb_strlen($name) > 120) throw new RuntimeException('Nama paket wajib diisi dan maksimal 120 karakter.');
            if (!preg_match('/^[A-Z0-9_-]{2,60}$/', $code)) throw new RuntimeException('Kode paket harus 2–60 karakter berupa huruf, angka, garis bawah, atau tanda hubung.');
            if ($price === false || $price < 0) throw new RuntimeException('Harga harus berupa angka nol atau lebih.');
            if (mb_strlen($description) > 2000) throw new RuntimeException('Deskripsi maksimal 2.000 karakter.');

            $seconds = VoucherService::parseTimeLimit($uptime);
            if ($daysRaw !== '' && !ctype_digit($daysRaw)) throw new RuntimeException('Masa berlaku harus berupa angka hari.');
            $validity = $daysRaw === '' ? null : (int)$daysRaw;
            if ($validity !== null && ($validity < 1 || $validity > 36500)) throw new RuntimeException('Masa berlaku harus antara 1 sampai 36.500 hari.');
            if ($seconds === null && $validity === null) throw new RuntimeException('Isi minimal satu batas: Limit Uptime atau Masa berlaku.');

            $profileCheck = $pdo->prepare('SELECT id FROM hotspot_profiles WHERE name=? AND is_active=1 AND is_managed=1 LIMIT 1');
            $profileCheck->execute([$profile]);
            if (!$profileCheck->fetchColumn()) throw new RuntimeException('Pilih profil HotSpot yang sudah menjadi master RAKAN WIMAN.');

            $duplicate = $pdo->prepare('SELECT id FROM packages WHERE code=? AND id<>? LIMIT 1');
            $duplicate->execute([$code, $action === 'update' ? $packageId : 0]);
            if ($duplicate->fetchColumn()) throw new RuntimeException('Kode paket sudah digunakan oleh paket lain.');

            if ($action === 'update') {
                $update = $pdo->prepare('UPDATE packages SET name=?,code=?,price=?,mikrotik_profile=?,limit_uptime_seconds=?,validity_days=?,description=? WHERE id=?');
                $update->execute([$name, $code, $price, $profile, $seconds, $validity, $description !== '' ? $description : null, $packageId]);
                flash('success', 'Paket berhasil diperbarui. Perubahan berlaku untuk voucher dan transaksi baru; voucher/transaksi yang sudah dibuat tetap memakai pengaturan saat diterbitkan.');
            } else {
                $insert = $pdo->prepare('INSERT INTO packages (name,code,price,mikrotik_profile,limit_uptime_seconds,validity_days,shared_users,description) VALUES (?,?,?,?,?,?,?,?)');
                $insert->execute([$name, $code, $price, $profile, $seconds, $validity, 1, $description !== '' ? $description : null]);
                flash('success', 'Paket berhasil ditambahkan menggunakan master profil HotSpot RAKAN WIMAN.');
            }
            redirect('packages');
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }

    $packages = $pdo->query('SELECT * FROM packages ORDER BY sort_order,id DESC')->fetchAll();
    $profiles = $pdo->query('SELECT id,name,is_managed FROM hotspot_profiles WHERE is_active=1 AND is_managed=1 ORDER BY name')->fetchAll();
    $title = 'Paket Internet';
    View::render('packages/index', compact('packages', 'profiles', 'error', 'editPackage', 'formValues', 'title'), 'layouts/main');
    exit;
}

if (preg_match('#^vouchers/batch/([0-9]+)$#', $path, $batchMatch)) {
    $batchId = (int)$batchMatch[1];
    $batchQ = $pdo->prepare('SELECT vb.id,vb.quantity,vb.created_at,COALESCE((SELECT v.package_name_snapshot FROM vouchers v WHERE v.batch_id=vb.id ORDER BY v.id LIMIT 1),p.name) AS package_name,COALESCE((SELECT v.mikrotik_profile_snapshot FROM vouchers v WHERE v.batch_id=vb.id ORDER BY v.id LIMIT 1),p.mikrotik_profile) AS mikrotik_profile,r.name AS router_name,r.host,a.name AS created_by FROM voucher_batches vb JOIN packages p ON p.id=vb.package_id JOIN routers r ON r.id=vb.router_id LEFT JOIN admins a ON a.id=vb.created_by WHERE vb.id=?');
    $batchQ->execute([$batchId]);
    $batch = $batchQ->fetch();
    if (!$batch) { http_response_code(404); View::render('dashboard/not-found', ['title'=>'Batch Voucher Tidak Ditemukan'], 'layouts/main'); exit; }

    $voucherQ = $pdo->prepare("SELECT v.id,v.username,v.password_encrypted,v.sale_status,v.sync_status,v.usage_status,v.created_at,COALESCE(go.hotspot_server,'all') AS hotspot_server,COALESCE(go.limit_uptime_seconds,v.limit_uptime_seconds_snapshot,p.limit_uptime_seconds) AS effective_limit_uptime_seconds,go.limit_bytes_total,go.generation_comment,COALESCE(go.user_mode,'separate') AS user_mode,COALESCE(v.package_name_snapshot,p.name) AS package_name,COALESCE(v.mikrotik_profile_snapshot,p.mikrotik_profile) AS mikrotik_profile FROM vouchers v JOIN packages p ON p.id=v.package_id LEFT JOIN voucher_generation_options go ON go.voucher_id=v.id WHERE v.batch_id=? ORDER BY v.id");
    $voucherQ->execute([$batchId]);
    $batchVouchers = [];
    foreach ($voucherQ->fetchAll() as $row) {
        $row['password'] = VoucherService::decryptSecret($row['password_encrypted']);
        unset($row['password_encrypted']);
        $batchVouchers[] = $row;
    }
    $settings = new AppSettings($pdo);
    $voucherTemplate = [
        'title' => $settings->get('voucher_title', 'VOUCHER INTERNET'),
        'subtitle' => $settings->get('voucher_subtitle', 'RAKAN WIMAN'),
        'footer' => $settings->get('voucher_footer', 'Terima kasih telah menggunakan layanan kami.'),
        'color' => $settings->get('voucher_accent_color', '#2563eb'),
        'show_password' => $settings->get('voucher_show_password', '1') === '1',
    ];
    $printLayout = (string)($_GET['layout'] ?? 'normal');
    if (!in_array($printLayout, ['normal','qr','small'], true)) $printLayout = 'normal';
    $autoPrint = (($_GET['print'] ?? '') === '1');
    if ($autoPrint) {
        $title = 'Cetak Voucher · Batch #' . $batchId;
        View::render('vouchers/batch-print', compact('batch','batchVouchers','voucherTemplate','printLayout','title'), 'layouts/print');
        exit;
    }
    View::render('vouchers/batch', compact('batch','batchVouchers','voucherTemplate','printLayout','autoPrint'), 'layouts/main');
    exit;
}

if ($path === 'vouchers') {
    $error = null;
    $formValues = $_POST ?: [];
    $batchToken = bin2hex(random_bytes(16));
    if ($method === 'POST') {
        verify_csrf();
        try {
            // Time Limit is owned by the selected package/profile; do not override it in the voucher generator.
            $dataLimit = VoucherService::parseDataLimit((string)($_POST['data_limit'] ?? ''), (string)($_POST['data_limit_unit'] ?? 'MB'));
            $serverMode = (string)($_POST['hotspot_server_mode'] ?? 'all');
            $hotspotServer = $serverMode === 'custom' ? trim((string)($_POST['hotspot_server_custom'] ?? '')) : 'all';
            $service = new VoucherService($pdo);
            $result = $service->generateBulk(
                (int)($_POST['package_id'] ?? 0),
                (int)($_POST['router_id'] ?? 0),
                (int)($_POST['quantity'] ?? 0),
                (string)($_POST['prefix'] ?? ''),
                (int)($_POST['name_length'] ?? 4),
                (string)($_POST['user_mode'] ?? 'separate'),
                (string)($_POST['character_set'] ?? 'lower_alnum'),
                $hotspotServer,
                null, // Always inherit limit-uptime from the selected HotSpot profile/package.
                $dataLimit,
                (string)($_POST['comment'] ?? ''),
                trim((string)($_POST['request_key'] ?? '')),
                (int)$_SESSION['admin_id']
            );
            // Process queued additions now; an offline router remains queued for cron retry.
            (new RouterSyncWorker($pdo, new RouterRepository($pdo)))->run(500);
            if (empty($result['reused'])) {
                $log = $pdo->prepare("INSERT INTO audit_logs (admin_id,action,entity_type,entity_id,description,ip_address) VALUES (?,?,?,?,?,?)");
                $log->execute([(int)$_SESSION['admin_id'], 'voucher.bulk_generated', 'voucher_batch', (int)$result['batch_id'], count($result['vouchers']) . ' voucher dibuat untuk batch #' . $result['batch_id'], $_SERVER['REMOTE_ADDR'] ?? null]);
                flash('success', count($result['vouchers']) . ' voucher berhasil dibuat. Periksa status sinkronisasi router.');
            } else {
                flash('success', 'Permintaan ini sudah pernah diproses. Batch lama ditampilkan agar voucher tidak terduplikasi.');
            }
            redirect('vouchers/batch/' . (int)$result['batch_id']);
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }

    $packages = $pdo->query('SELECT id,name,price,mikrotik_profile,limit_uptime_seconds FROM packages WHERE is_active=1 ORDER BY sort_order,id')->fetchAll();
    $routers = $pdo->query('SELECT id,name,host,hotspot_server FROM routers WHERE is_active=1 ORDER BY name')->fetchAll();
    $vouchers = $pdo->query("SELECT vb.id AS batch_id,vb.quantity,vb.created_at,COALESCE((SELECT v.package_name_snapshot FROM vouchers v WHERE v.batch_id=vb.id ORDER BY v.id LIMIT 1),p.name) AS package_name,r.name AS router_name,a.name AS created_by FROM voucher_batches vb JOIN packages p ON p.id=vb.package_id JOIN routers r ON r.id=vb.router_id LEFT JOIN admins a ON a.id=vb.created_by ORDER BY vb.id DESC LIMIT 50")->fetchAll();
    View::render('vouchers/index', compact('packages','routers','vouchers','error','batchToken','formValues'), 'layouts/main');
    exit;
}

if ($path === 'routers/test' && $method === 'POST') {
    verify_csrf();
    try {
        $repo=new RouterRepository($pdo); $router=$repo->get((int)($_POST['id']??0)); $api=$repo->connect($router);
        $identity=$api->command('/system/identity/print', ['.proplist'=>'name']); $resource=$api->command('/system/resource/print', ['.proplist'=>'version']); $api->close();
        $id=$identity[0]['name']??$router['host']; $ver=$resource[0]['version']??'unknown';
        $pdo->prepare("UPDATE routers SET identity=?,connection_status='online',last_seen_at=NOW(),last_error=NULL WHERE id=?")->execute([(string)$id,(int)$router['id']]);
        flash('success','Koneksi API berhasil: '.$id.' · RouterOS '.$ver.'. Untuk membaca dan membandingkan profil HotSpot, gunakan tombol Baca Profil MikroTik pada menu HotSpot Profile.');
    } catch(Throwable $e) {
        $pdo->prepare("UPDATE routers SET connection_status='offline',last_error=? WHERE id=?")->execute([mb_substr($e->getMessage(),0,450),(int)($_POST['id']??0)]);
        flash('error','Uji koneksi gagal: '.$e->getMessage());
    }
    redirect('routers');
}

// Edit an existing router without ever exposing its stored API password.
if (preg_match('#^routers/edit/(\d+)$#', $path, $editMatch) && $method === 'GET') {
    $routerId = (int)$editMatch[1];
    $editQuery = $pdo->prepare('SELECT id,name,host,api_port,username,api_ssl,hotspot_server FROM routers WHERE id=? AND is_active=1');
    $editQuery->execute([$routerId]);
    $editRouter = $editQuery->fetch();
    if (!$editRouter) {
        flash('error', 'Router yang akan diedit tidak ditemukan.');
        redirect('routers');
    }
    $error = null;
    $routers = $pdo->query('SELECT id,name,identity,host,api_port,username,api_ssl,hotspot_server,is_active,connection_status,last_seen_at,last_error,created_at FROM routers WHERE is_active=1 ORDER BY id DESC')->fetchAll();
    View::render('routers/index', compact('routers','error','editRouter'), 'layouts/main');
    exit;
}

// Save router changes. An empty password field means keep the currently encrypted password.
if ($path === 'routers/update' && $method === 'POST') {
    verify_csrf();
    $routerId = (int)($_POST['id'] ?? 0);
    $error = null;
    try {
        $currentQuery = $pdo->prepare('SELECT id FROM routers WHERE id=? AND is_active=1');
        $currentQuery->execute([$routerId]);
        if (!$currentQuery->fetch()) throw new RuntimeException('Router yang akan diperbarui tidak ditemukan.');

        $name = trim((string)($_POST['name'] ?? ''));
        $host = trim((string)($_POST['host'] ?? ''));
        $port = (int)($_POST['api_port'] ?? 8728);
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $hotspotServer = trim((string)($_POST['hotspot_server'] ?? ''));
        $apiSsl = isset($_POST['api_ssl']) ? 1 : 0;

        if ($name === '' || mb_strlen($name) > 120) throw new RuntimeException('Nama router wajib diisi dan maksimal 120 karakter.');
        if ($host === '' || mb_strlen($host) > 255 || preg_match('/\s/', $host)) throw new RuntimeException('IP atau hostname router tidak valid.');
        if ($port < 1 || $port > 65535) throw new RuntimeException('API Port harus antara 1 sampai 65535.');
        if ($username === '' || mb_strlen($username) > 120) throw new RuntimeException('Username API wajib diisi dan maksimal 120 karakter.');
        if (mb_strlen($hotspotServer) > 120) throw new RuntimeException('Nama Hotspot Server maksimal 120 karakter.');

        if ($password !== '') {
            $encrypted = VoucherService::encryptSecret($password);
            $update = $pdo->prepare("UPDATE routers SET name=?,host=?,api_port=?,username=?,password_encrypted=?,api_ssl=?,hotspot_server=?,identity=NULL,connection_status='unknown',last_seen_at=NULL,last_error=NULL WHERE id=?");
            $update->execute([$name,$host,$port,$username,$encrypted,$apiSsl,$hotspotServer !== '' ? $hotspotServer : null,$routerId]);
        } else {
            $update = $pdo->prepare("UPDATE routers SET name=?,host=?,api_port=?,username=?,api_ssl=?,hotspot_server=?,identity=NULL,connection_status='unknown',last_seen_at=NULL,last_error=NULL WHERE id=?");
            $update->execute([$name,$host,$port,$username,$apiSsl,$hotspotServer !== '' ? $hotspotServer : null,$routerId]);
        }

        $log = $pdo->prepare('INSERT INTO audit_logs (admin_id,action,entity_type,entity_id,description,ip_address) VALUES (?,?,?,?,?,?)');
        $log->execute([(int)$_SESSION['admin_id'],'router.updated','router',$routerId,'Konfigurasi router diperbarui: '.$name,$_SERVER['REMOTE_ADDR'] ?? null]);
        flash('success', 'Konfigurasi router berhasil diperbarui. Silakan uji koneksi kembali.');
        redirect('routers');
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }

    $editQuery = $pdo->prepare('SELECT id,name,host,api_port,username,api_ssl,hotspot_server FROM routers WHERE id=? AND is_active=1');
    $editQuery->execute([$routerId]);
    $editRouter = $editQuery->fetch() ?: null;
    $routers = $pdo->query('SELECT id,name,identity,host,api_port,username,api_ssl,hotspot_server,is_active,connection_status,last_seen_at,last_error,created_at FROM routers WHERE is_active=1 ORDER BY id DESC')->fetchAll();
    View::render('routers/index', compact('routers','error','editRouter'), 'layouts/main');
    exit;
}

// Remove a router from the active list by archiving it. Historical voucher/payment
// references remain intact; existing HotSpot accounts on the physical MikroTik are untouched.
if ($path === 'routers/delete' && $method === 'POST') {
    verify_csrf();
    $routerId = (int)($_POST['id'] ?? 0);
    try {
        if ($routerId < 1) throw new RuntimeException('ID router tidak valid.');

        $pdo->beginTransaction();
        $find = $pdo->prepare('SELECT id,name,host,api_port FROM routers WHERE id=? AND is_active=1 FOR UPDATE');
        $find->execute([$routerId]);
        $routerToDelete = $find->fetch();
        if (!$routerToDelete) throw new RuntimeException('Router tidak ditemukan atau sudah dihapus dari daftar aktif.');

        $pdo->prepare("UPDATE routers SET is_active=0,connection_status='unknown',last_seen_at=NULL,last_error='Router diarsipkan dari daftar manajemen.' WHERE id=?")->execute([$routerId]);
        // Stop pending synchronization jobs so the cron worker will not keep retrying this router.
        $pdo->prepare("UPDATE router_sync_queue SET status='done',locked_at=NULL,last_error='Dibatalkan karena router dihapus dari daftar aktif.' WHERE router_id=? AND status IN ('pending','failed','processing')")->execute([$routerId]);
        // Mark vouchers that were waiting to be pushed; preserve already-synced vouchers and all history.
        $pdo->prepare("UPDATE vouchers SET sync_status='failed' WHERE router_id=? AND sync_status IN ('pending','failed') AND usage_status<>'expired'")->execute([$routerId]);

        $description = 'Router diarsipkan dari daftar aktif: ' . $routerToDelete['name'] . ' (' . $routerToDelete['host'] . ':' . $routerToDelete['api_port'] . '). Riwayat dan relasi voucher/pembayaran dipertahankan; akun MikroTik tidak dihapus.';
        $log = $pdo->prepare('INSERT INTO audit_logs (admin_id,action,entity_type,entity_id,description,ip_address) VALUES (?,?,?,?,?,?)');
        $log->execute([(int)$_SESSION['admin_id'],'router.archived','router',$routerId,$description,$_SERVER['REMOTE_ADDR'] ?? null]);

        $pdo->commit();
        flash('success', 'Router berhasil dihapus dari daftar aktif. Riwayat voucher dan pembayaran tetap tersimpan; akun yang sudah ada di MikroTik tidak dihapus.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('error', 'Gagal menghapus router: ' . $e->getMessage());
    }
    redirect('routers');
}

if ($path === 'routers') {
    $error = null;
    $editRouter = null;
    if ($method === 'POST') {
        verify_csrf();
        $name = trim($_POST['name'] ?? '');
        $host = trim($_POST['host'] ?? '');
        $port = (int) ($_POST['api_port'] ?? 8728);
        $username = trim($_POST['username'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        if ($name === '' || $host === '' || $username === '' || $password === '' || $port < 1 || $port > 65535) {
            $error = 'Semua data wajib diisi. Port harus antara 1 sampai 65535.';
        } else {
            $key = getenv('APP_KEY') ?: '';
            if (strlen($key) < 32 || str_contains($key, 'CHANGE_THIS')) {
                $error = 'APP_KEY belum aman. Atur APP_KEY minimal 32 karakter acak di .env.';
            } else {
                $nonce = random_bytes(12);
                $tag = '';
                $cipher = openssl_encrypt($password, 'aes-256-gcm', hash('sha256', $key, true), OPENSSL_RAW_DATA, $nonce, $tag);
                if ($cipher === false) { throw new RuntimeException('Gagal mengenkripsi password router.'); }
                $encrypted = base64_encode($nonce . $tag . $cipher);
                $stmt = $pdo->prepare('INSERT INTO routers (name,host,api_port,username,password_encrypted,api_ssl,hotspot_server) VALUES (?,?,?,?,?,?,?)');
                $stmt->execute([$name,$host,$port,$username,$encrypted,isset($_POST['api_ssl']) ? 1 : 0,trim($_POST['hotspot_server'] ?? '') ?: null]);
                $log = $pdo->prepare('INSERT INTO audit_logs (admin_id,action,entity_type,entity_id,description,ip_address) VALUES (?,?,?,?,?,?)');
                $log->execute([$_SESSION['admin_id'],'router.created','router',(int)$pdo->lastInsertId(),'Router ditambahkan: '.$name,$_SERVER['REMOTE_ADDR'] ?? null]);
                flash('success', 'Router berhasil ditambahkan. Uji koneksi akan tersedia pada tahap integrasi RouterOS API.');
                redirect('routers');
            }
        }
    }
    $routers = $pdo->query('SELECT id,name,identity,host,api_port,username,api_ssl,hotspot_server,is_active,connection_status,last_seen_at,last_error,created_at FROM routers WHERE is_active=1 ORDER BY id DESC')->fetchAll();
    View::render('routers/index', compact('routers','error'), 'layouts/main');
    exit;
}

http_response_code(404);
View::render('dashboard/not-found', ['title' => 'Halaman tidak ditemukan'], 'layouts/main');
