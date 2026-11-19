<?php
declare(strict_types=1);
namespace RakanWiman\Services;

use PDO;
use PDOException;
use RuntimeException;

final class VoucherService
{
    private const CHARSETS = [
        'lower' => 'abcdefghijkmnopqrstuvwxyz',
        'upper' => 'ABCDEFGHJKLMNPQRSTUVWXYZ',
        'mixed_alpha' => 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ',
        'lower_alnum' => 'abcdefghijkmnopqrstuvwxyz23456789',
        'upper_alnum' => 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789',
        'mixed_alnum' => 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789',
    ];

    public function __construct(private PDO $pdo) {}

    public static function encryptSecret(string $plain): string
    {
        $key = getenv('APP_KEY') ?: '';
        if (strlen($key) < 32 || str_contains($key, 'CHANGE_THIS')) throw new RuntimeException('APP_KEY belum aman.');
        $nonce = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', hash('sha256', $key, true), OPENSSL_RAW_DATA, $nonce, $tag);
        if ($cipher === false) throw new RuntimeException('Gagal mengenkripsi password voucher.');
        return base64_encode($nonce . $tag . $cipher);
    }

    public static function decryptSecret(string $encrypted): string
    {
        $key = getenv('APP_KEY') ?: '';
        if (strlen($key) < 32 || str_contains($key, 'CHANGE_THIS')) throw new RuntimeException('APP_KEY belum aman.');
        $raw = base64_decode($encrypted, true);
        if ($raw === false || strlen($raw) < 29) throw new RuntimeException('Password voucher tidak valid.');
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', hash('sha256', $key, true), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        if ($plain === false) throw new RuntimeException('Gagal mendekripsi password voucher. Periksa APP_KEY.');
        return $plain;
    }

    /**
     * Generate a bulk batch. The request key makes retries idempotent: a repeated
     * submission returns the original batch instead of creating more vouchers.
     */
    public function generateBulk(
        int $packageId,
        int $routerId,
        int $quantity,
        string $prefix,
        int $nameLength,
        string $userMode,
        string $characterSet,
        string $hotspotServer,
        ?int $limitUptimeSeconds,
        ?int $limitBytesTotal,
        ?string $comment,
        string $requestKey,
        ?int $adminId = null
    ): array {
        if ($quantity < 1 || $quantity > 500) throw new RuntimeException('Jumlah voucher harus 1–500 per proses.');
        if (!preg_match('/^[a-f0-9]{32}$/', $requestKey)) throw new RuntimeException('Token permintaan voucher tidak valid. Muat ulang halaman.');
        if ($nameLength < 3 || $nameLength > 8) throw new RuntimeException('Panjang nama voucher harus 3–8 karakter.');
        if (!in_array($userMode, ['separate', 'same'], true)) throw new RuntimeException('Mode username/password tidak valid.');
        if (!isset(self::CHARSETS[$characterSet])) throw new RuntimeException('Pola karakter tidak valid.');

        $prefix = preg_replace('/[^A-Za-z0-9_-]/', '', trim($prefix)) ?? '';
        if (strlen($prefix) > 24) throw new RuntimeException('Prefix maksimal 24 karakter.');
        $hotspotServer = trim($hotspotServer) ?: 'all';
        if ($hotspotServer !== 'all' && !preg_match('/^[A-Za-z0-9_.-]{1,120}$/', $hotspotServer)) {
            throw new RuntimeException('Nama Hotspot Server tidak valid.');
        }
        if ($limitUptimeSeconds !== null && ($limitUptimeSeconds < 1 || $limitUptimeSeconds > 315360000)) {
            throw new RuntimeException('Time Limit harus lebih besar dari nol dan maksimal 10 tahun.');
        }
        if ($limitBytesTotal !== null && ($limitBytesTotal < 1 || $limitBytesTotal > PHP_INT_MAX)) {
            throw new RuntimeException('Data Limit tidak valid.');
        }
        $comment = trim((string)$comment);
        if (strlen($comment) > 255) throw new RuntimeException('Komentar maksimal 255 karakter.');
        $comment = $comment === '' ? null : $comment;

        $p = $this->pdo->prepare('SELECT * FROM packages WHERE id=? AND is_active=1');
        $p->execute([$packageId]);
        $package = $p->fetch();
        if (!$package) throw new RuntimeException('Profil/paket tidak ditemukan atau tidak aktif.');

        $r = $this->pdo->prepare('SELECT id FROM routers WHERE id=? AND is_active=1');
        $r->execute([$routerId]);
        if (!$r->fetch()) throw new RuntimeException('Router tujuan tidak ditemukan atau tidak aktif.');

        $created = [];
        $this->pdo->beginTransaction();
        try {
            $batch = $this->pdo->prepare('INSERT INTO voucher_batches (request_key,package_id,router_id,quantity,created_by) VALUES (?,?,?,?,?)');
            $batch->execute([$requestKey, $packageId, $routerId, $quantity, $adminId]);
            $batchId = (int)$this->pdo->lastInsertId();

            $voucherInsert = $this->pdo->prepare("INSERT INTO vouchers (public_id,batch_id,router_id,package_id,package_name_snapshot,package_code_snapshot,mikrotik_profile_snapshot,limit_uptime_seconds_snapshot,validity_days_snapshot,username,password_encrypted,generation_mode,sale_status,sync_status,usage_status) VALUES (?,?,?,?,?,?,?,?,?,?,?,'bulk','available','pending','unused')");
            $optionsInsert = $this->pdo->prepare('INSERT INTO voucher_generation_options (voucher_id,hotspot_server,limit_uptime_seconds,limit_bytes_total,generation_comment,user_mode,character_set,name_length) VALUES (?,?,?,?,?,?,?,?)');
            $queueInsert = $this->pdo->prepare("INSERT INTO router_sync_queue (router_id,voucher_id,action,status) VALUES (?,?, 'create','pending')");

            for ($i = 0; $i < $quantity; $i++) {
                $inserted = false;
                for ($try = 0; $try < 20 && !$inserted; $try++) {
                    $randomName = self::randomString($nameLength, self::CHARSETS[$characterSet]);
                    $username = $prefix . $randomName;
                    $password = $userMode === 'same' ? $username : self::randomString($nameLength, self::CHARSETS[$characterSet]);
                    try {
                        $publicId = self::ulid();
                        $voucherInsert->execute([$publicId, $batchId, $routerId, $packageId, $package['name'], $package['code'], $package['mikrotik_profile'], $package['limit_uptime_seconds'], $package['validity_days'], $username, self::encryptSecret($password)]);
                        $id = (int)$this->pdo->lastInsertId();
                        $optionsInsert->execute([$id, $hotspotServer, $limitUptimeSeconds, $limitBytesTotal, $comment, $userMode, $characterSet, $nameLength]);
                        $queueInsert->execute([$routerId, $id]);
                        $created[] = [
                            'id' => $id,
                            'username' => $username,
                            'password' => $password,
                            'package' => $package['name'],
                            'profile' => $package['mikrotik_profile'],
                        ];
                        $inserted = true;
                    } catch (PDOException $e) {
                        if (($e->errorInfo[1] ?? 0) !== 1062) throw $e;
                        // Username collision: create another random credential.
                    }
                }
                if (!$inserted) throw new RuntimeException('Tidak berhasil membuat username unik setelah beberapa percobaan. Coba ganti prefix atau pola karakter.');
            }

            $this->pdo->commit();
            return ['batch_id' => $batchId, 'vouchers' => $created, 'reused' => false];
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            if ($e instanceof PDOException && ($e->errorInfo[1] ?? 0) === 1062) {
                $b = $this->pdo->prepare('SELECT id FROM voucher_batches WHERE request_key=?');
                $b->execute([$requestKey]);
                $existingBatch = (int)$b->fetchColumn();
                if ($existingBatch > 0) {
                    $v = $this->pdo->prepare('SELECT v.id,v.username,v.password_encrypted,COALESCE(v.package_name_snapshot,p.name) AS package_name,COALESCE(v.mikrotik_profile_snapshot,p.mikrotik_profile) AS mikrotik_profile FROM vouchers v JOIN packages p ON p.id=v.package_id WHERE v.batch_id=? ORDER BY v.id');
                    $v->execute([$existingBatch]);
                    $rows = $v->fetchAll();
                    $existing = [];
                    foreach ($rows as $row) {
                        $existing[] = ['id' => (int)$row['id'], 'username' => $row['username'], 'password' => self::decryptSecret($row['password_encrypted']), 'package' => $row['package_name'], 'profile' => $row['mikrotik_profile']];
                    }
                    return ['batch_id' => $existingBatch, 'vouchers' => $existing, 'reused' => true];
                }
            }
            throw $e;
        }
    }

    public static function parseTimeLimit(string $value): ?int
    {
        $value = trim($value);
        if ($value === '') return null;
        if (preg_match('/^(\d+):(\d{2}):(\d{2})$/', $value, $m)) {
            $hours = (int)$m[1]; $minutes = (int)$m[2]; $seconds = (int)$m[3];
            if ($minutes > 59 || $seconds > 59) throw new RuntimeException('Format Time Limit tidak valid.');
            $total = $hours * 3600 + $minutes * 60 + $seconds;
            if ($total < 1) throw new RuntimeException('Time Limit harus lebih besar dari nol.');
            return $total;
        }
        if (preg_match('/^(?:(\d+)w)?(?:(\d+)d)?(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s)?$/', $value, $m) && preg_match('/\d/', $value)) {
            $total = (int)($m[1] ?? 0) * 604800 + (int)($m[2] ?? 0) * 86400 + (int)($m[3] ?? 0) * 3600 + (int)($m[4] ?? 0) * 60 + (int)($m[5] ?? 0);
            if ($total < 1 || $total > 315360000) throw new RuntimeException('Time Limit tidak valid atau melebihi 10 tahun.');
            return $total;
        }
        throw new RuntimeException('Format Time Limit harus HH:MM:SS atau 1d2h30m.');
    }

    public static function parseDataLimit(string $value, string $unit): ?int
    {
        $value = trim($value);
        if ($value === '') return null;
        if (!ctype_digit($value) || (int)$value < 1) throw new RuntimeException('Data Limit harus berupa angka lebih besar dari nol.');
        $multiplier = match (strtoupper($unit)) { 'MB' => 1048576, 'GB' => 1073741824, 'KB' => 1024, default => throw new RuntimeException('Satuan Data Limit tidak valid.') };
        $amount = (int)$value;
        if ($amount > intdiv(PHP_INT_MAX, $multiplier)) throw new RuntimeException('Data Limit terlalu besar.');
        return $amount * $multiplier;
    }

    public static function formatBytes(?int $bytes): string
    {
        if (!$bytes || $bytes < 1) return '-';
        foreach (['B','KB','MB','GB','TB'] as $unit) {
            if ($bytes < 1024 || $unit === 'TB') return rtrim(rtrim(number_format($bytes, 2, ',', '.'), '0'), ',') . ' ' . $unit;
            $bytes /= 1024;
        }
        return '-';
    }

    public static function formatDuration(?int $seconds): string
    {
        if (!$seconds || $seconds < 1) return '-';
        $days = intdiv($seconds, 86400); $seconds %= 86400;
        $hours = intdiv($seconds, 3600); $seconds %= 3600;
        $minutes = intdiv($seconds, 60); $seconds %= 60;
        return ($days ? $days . 'd ' : '') . sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
    }

    public static function toRouterDuration(int $seconds): string
    {
        $days = intdiv($seconds, 86400); $seconds %= 86400;
        $hours = intdiv($seconds, 3600); $seconds %= 3600;
        $minutes = intdiv($seconds, 60); $seconds %= 60;
        return ($days > 0 ? $days . 'd' : '') . sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
    }

    /** Generate one credential pair using the same rules as the bulk voucher generator. */
    public static function generateCredentialPair(string $prefix, int $nameLength, string $userMode, string $characterSet): array
    {
        if ($nameLength < 3 || $nameLength > 8) throw new RuntimeException('Panjang username voucher harus 3–8 karakter.');
        if (!in_array($userMode, ['separate', 'same'], true)) throw new RuntimeException('Mode username/password QRIS tidak valid.');
        if (!isset(self::CHARSETS[$characterSet])) throw new RuntimeException('Pola karakter voucher QRIS tidak valid.');
        $prefix = preg_replace('/[^A-Za-z0-9_-]/', '', trim($prefix)) ?? '';
        if (strlen($prefix) > 24) throw new RuntimeException('Prefix voucher QRIS maksimal 24 karakter.');
        $username = $prefix . self::randomString($nameLength, self::CHARSETS[$characterSet]);
        $password = $userMode === 'same' ? $username : self::randomString($nameLength, self::CHARSETS[$characterSet]);
        return ['username' => $username, 'password' => $password];
    }

    private static function randomString(int $length, string $alphabet): string
    {
        $out = ''; $max = strlen($alphabet) - 1;
        for ($i = 0; $i < $length; $i++) $out .= $alphabet[random_int(0, $max)];
        return $out;
    }

    public static function ulid(): string
    {
        $time = (int)(microtime(true) * 1000); $chars = '0123456789ABCDEFGHJKMNPQRSTVWXYZ'; $out = '';
        for ($i = 9; $i >= 0; $i--) { $out = $chars[$time % 32] . $out; $time = intdiv($time, 32); }
        $bits = '';
        foreach (str_split(random_bytes(10)) as $b) $bits .= str_pad(decbin(ord($b)), 8, '0', STR_PAD_LEFT);
        for ($i = 0; $i < 16; $i++) $out .= $chars[bindec(substr($bits, $i * 5, 5))];
        return $out;
    }
}
