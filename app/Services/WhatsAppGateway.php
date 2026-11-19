<?php
declare(strict_types=1);
namespace RakanWiman\Services;

use PDO;
use RuntimeException;
use Throwable;

/** Client for dimaskiddo/go-whatsapp-multidevice-rest.
 * The configured URL is the HTTP_BASE_URL, normally ending in /api/v1/whatsapp.
 */
final class WhatsAppGateway
{
    private AppSettings $settings;

    public function __construct(private PDO $pdo)
    {
        $this->settings = new AppSettings($pdo);
    }

    public function addSender(string $phone): array
    {
        $phone = preg_replace('/\D+/', '', $phone) ?? '';
        $country = preg_replace('/\D+/', '', (string)($this->settings->get('wa_country_code', '62') ?? '62')) ?: '62';
        if (str_starts_with($phone, '0')) $phone = $country . substr($phone, 1);
        if (strlen($phone) < 9 || strlen($phone) > 16) {
            throw new RuntimeException('Nomor sender tidak valid. Gunakan format internasional, misalnya 62812xxxxxxx.');
        }
        $base = $this->baseUrl();
        $secret = $this->settings->getSecret('wa_gateway_secret');
        if ($secret === '') throw new RuntimeException('Auth Basic Password belum dikonfigurasi.');

        [$http, $raw, $json] = $this->request('GET', $base . '/auth', [
            'Authorization: Basic ' . base64_encode($phone . ':' . $secret),
            'Accept: application/json',
        ]);
        $jwt = $json['data']['token'] ?? null;
        if (!self::responseSucceeded($http, $json) || !is_string($jwt) || trim($jwt) === '') {
            throw new RuntimeException('Gagal mengambil JWT dari endpoint /auth: ' . $this->responseMessage($json, $raw, $http));
        }

        $encrypted = VoucherService::encryptSecret($jwt);
        $stmt = $this->pdo->prepare("INSERT INTO whatsapp_senders(phone_number,jwt_encrypted,status,is_active) VALUES(?,?,'disconnected',1) ON DUPLICATE KEY UPDATE jwt_encrypted=VALUES(jwt_encrypted),is_active=1,status='disconnected'");
        $stmt->execute([$phone, $encrypted]);
        return ['phone' => $phone];
    }

    /** Request QR login or WhatsApp linked-device pair code, following the PHPNuxBill plugin flow. */
    public function loginSender(int $id, string $mode = 'qr'): array
    {
        if (!in_array($mode, ['qr', 'pair'], true)) throw new RuntimeException('Mode login WhatsApp tidak valid.');
        $stmt = $this->pdo->prepare('SELECT * FROM whatsapp_senders WHERE id=? AND is_active=1');
        $stmt->execute([$id]);
        $sender = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$sender) throw new RuntimeException('Sender WhatsApp tidak ditemukan atau dinonaktifkan.');

        $jwt = (string)VoucherService::decryptSecret((string)$sender['jwt_encrypted']);
        if ($jwt === '' || str_starts_with($jwt, 'Error')) {
            $jwt = $this->refreshJwt((string)$sender['phone_number']);
            $enc = VoucherService::encryptSecret($jwt);
            $this->pdo->prepare('UPDATE whatsapp_senders SET jwt_encrypted=?,status=\'disconnected\' WHERE id=?')->execute([$enc, $id]);
        }

        $endpoint = $this->baseUrl() . ($mode === 'pair' ? '/login/pair' : '/login');
        [$http, $raw, $json] = $this->request('POST', $endpoint, [
            'Content-Type: application/x-www-form-urlencoded',
            'Authorization: Bearer ' . $jwt,
            'Accept: application/json',
        ], $mode === 'qr' ? http_build_query(['output' => 'json']) : '');
        if (!self::responseSucceeded($http, $json, true)) {
            throw new RuntimeException('Permintaan login WhatsApp gagal: ' . $this->responseMessage($json, $raw, $http));
        }

        $message = (string)($json['message'] ?? (is_string($json['data'] ?? null) ? $json['data'] : $raw));
        $connected = strcasecmp(trim($message), 'WhatsApp Client is Reconnected') === 0
            || !empty($json['data']['connected'])
            || (($json['data']['status'] ?? '') === 'connected');
        $status = $connected ? 'connected' : 'disconnected';
        $this->pdo->prepare('UPDATE whatsapp_senders SET status=?,last_connected_at=IF(?,NOW(),last_connected_at) WHERE id=?')
            ->execute([$status, $connected ? 1 : 0, $id]);

        $data = is_array($json['data'] ?? null) ? $json['data'] : [];
        $qr = $data['qrcode'] ?? $data['qr'] ?? $data['qr_code'] ?? $data['qrCode'] ?? null;
        $pairCode = $data['paircode'] ?? $data['pair_code'] ?? null;
        $timeout = $data['timeout'] ?? null;

        return [
            'sender_id' => $id,
            'phone' => (string)$sender['phone_number'],
            'mode' => $mode,
            'status' => $status,
            'connected' => $connected,
            'message' => $message,
            'qr' => is_string($qr) ? $qr : null,
            'pair_code' => is_string($pairCode) ? $pairCode : null,
            'timeout' => is_numeric($timeout) ? (int)$timeout : null,
            'raw' => $json ?: ['raw' => $raw],
        ];
    }

    public function deleteSender(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM whatsapp_senders WHERE id=?');
        $stmt->execute([$id]);
        if (!$stmt->rowCount()) throw new RuntimeException('Sender WhatsApp tidak ditemukan.');
    }

    /** Normalize a destination number using the configured country code. */
    public function normalizeRecipient(string $recipient): string
    {
        $recipient = preg_replace('/\D+/', '', $recipient) ?? '';
        $country = preg_replace('/\D+/', '', (string)($this->settings->get('wa_country_code', '62') ?? '62')) ?: '62';
        if (str_starts_with($recipient, '0')) $recipient = $country . substr($recipient, 1);
        if (strlen($recipient) < 9 || strlen($recipient) > 16) {
            throw new RuntimeException('Nomor tujuan WhatsApp tidak valid. Gunakan nomor aktif, misalnya 0812xxxxxxx atau 62812xxxxxxx.');
        }
        return $recipient;
    }

    /** Send one test message immediately, using an explicitly selected connected sender. */
    public function sendTestMessage(int $senderId, string $recipient): array
    {
        $recipient = $this->normalizeRecipient($recipient);
        $stmt = $this->pdo->prepare("SELECT id,phone_number,jwt_encrypted,status FROM whatsapp_senders WHERE id=? AND is_active=1 AND status='connected' LIMIT 1");
        $stmt->execute([$senderId]);
        $sender = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$sender) {
            throw new RuntimeException('Sender tidak ditemukan, tidak aktif, atau belum connected. Selesaikan pairing WhatsApp terlebih dahulu.');
        }

        $jwt = '';
        try {
            $jwt = (string)VoucherService::decryptSecret((string)($sender['jwt_encrypted'] ?? ''));
        } catch (Throwable $ignored) {
            $jwt = '';
        }
        if ($jwt === '' || str_starts_with($jwt, 'Error')) {
            $jwt = $this->refreshJwt((string)$sender['phone_number']);
            $this->pdo->prepare('UPDATE whatsapp_senders SET jwt_encrypted=? WHERE id=?')
                ->execute([VoucherService::encryptSecret($jwt), $senderId]);
        }

        $message = "Tes WhatsApp RAKAN WIMAN\n\nJika Anda menerima pesan ini, pengiriman melalui WhatsApp Gateway sudah berfungsi.\n\nSender: "
            . (string)$sender['phone_number'] . "\nWaktu: " . date('d-m-Y H:i:s');
        $endpoint = $this->baseUrl() . '/send/text';
        $body = http_build_query(['msisdn' => $recipient, 'message' => $message]);
        $send = function (string $token) use ($endpoint, $body): array {
            return $this->request('POST', $endpoint, [
                'Content-Type: application/x-www-form-urlencoded',
                'Authorization: Bearer ' . $token,
                'Accept: application/json',
            ], $body);
        };

        [$http, $raw, $json] = $send($jwt);
        $apiCode = is_numeric($json['code'] ?? null) ? (int)$json['code'] : $http;
        $apiStatus = $json['status'] ?? null;
        $unauthorized = in_array($http, [401, 403], true)
            || in_array($apiCode, [401, 403], true)
            || (is_numeric($apiStatus) && in_array((int)$apiStatus, [401, 403], true));
        if ($unauthorized) {
            // Refresh an expired JWT once, then retry the test message exactly once.
            $jwt = $this->refreshJwt((string)$sender['phone_number']);
            $this->pdo->prepare('UPDATE whatsapp_senders SET jwt_encrypted=? WHERE id=?')
                ->execute([VoucherService::encryptSecret($jwt), $senderId]);
            [$http, $raw, $json] = $send($jwt);
        }

        if (!self::responseSucceeded($http, $json, true)) {
            throw new RuntimeException('Pengiriman pesan uji gagal: ' . $this->responseMessage($json, $raw, $http));
        }

        return [
            'sender_id' => $senderId,
            'sender_phone' => (string)$sender['phone_number'],
            'recipient' => $recipient,
            'message' => $message,
            'http' => $http,
        ];
    }

    public function enqueueDirect(string $recipient, string $message): int
    {
        $recipient = $this->normalizeRecipient($recipient);
        $message = trim($message);
        if ($message === '' || mb_strlen($message) > 4000) throw new RuntimeException('Pesan kosong atau melebihi 4.000 karakter.');
        $stmt = $this->pdo->prepare("INSERT INTO message_queue(channel,recipient,message,related_type,status,available_at) VALUES('whatsapp',?,?, 'direct_api','pending',NOW())");
        $stmt->execute([$recipient, $message]);
        return (int)$this->pdo->lastInsertId();
    }

    public function processQueue(int $limit = 20): array
    {
        $this->pdo->exec("UPDATE message_queue SET status='pending' WHERE status='processing' AND available_at < DATE_SUB(NOW(),INTERVAL 10 MINUTE)");
        $jobs = $this->pdo->query("SELECT * FROM message_queue WHERE channel='whatsapp' AND COALESCE(related_type,'')<>'test' AND status IN('pending','failed') AND available_at<=NOW() ORDER BY id LIMIT " . max(1, min(100, $limit)))->fetchAll(PDO::FETCH_ASSOC);
        $sent = 0;
        $failed = 0;
        foreach ($jobs as $job) {
            $lock = $this->pdo->prepare("UPDATE message_queue SET status='processing',attempts=attempts+1 WHERE id=? AND status IN('pending','failed')");
            $lock->execute([$job['id']]);
            if (!$lock->rowCount()) continue;
            try {
                $sender = $this->pdo->query("SELECT * FROM whatsapp_senders WHERE is_active=1 AND status='connected' AND jwt_encrypted IS NOT NULL ORDER BY RAND() LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                if (!$sender) throw new RuntimeException('Belum ada sender WhatsApp yang berstatus connected.');
                $jwt = (string)VoucherService::decryptSecret((string)$sender['jwt_encrypted']);
                $body = http_build_query(['msisdn' => (string)$job['recipient'], 'message' => (string)$job['message']]);
                [$http, $raw, $json] = $this->request('POST', $this->baseUrl() . '/send/text', [
                    'Content-Type: application/x-www-form-urlencoded',
                    'Authorization: Bearer ' . $jwt,
                    'Accept: application/json',
                ], $body);
                if (!self::responseSucceeded($http, $json, true)) {
                    throw new RuntimeException('Pengiriman WhatsApp gagal: ' . $this->responseMessage($json, $raw, $http));
                }
                $this->pdo->prepare("UPDATE message_queue SET status='sent',sent_at=NOW(),last_error=NULL WHERE id=?")->execute([$job['id']]);
                $sent++;
            } catch (Throwable $e) {
                $attempt = (int)$job['attempts'] + 1;
                $delay = min(3600, 60 * (2 ** min(6, $attempt)));
                $this->pdo->prepare("UPDATE message_queue SET status='failed',available_at=DATE_ADD(NOW(),INTERVAL ? SECOND),last_error=? WHERE id=?")
                    ->execute([$delay, mb_substr($e->getMessage(), 0, 1000), $job['id']]);
                $failed++;
            }
        }
        return compact('sent', 'failed');
    }

    private function refreshJwt(string $phone): string
    {
        $secret = $this->settings->getSecret('wa_gateway_secret');
        if ($secret === '') throw new RuntimeException('Auth Basic Password belum dikonfigurasi.');
        [$http, $raw, $json] = $this->request('GET', $this->baseUrl() . '/auth', [
            'Authorization: Basic ' . base64_encode($phone . ':' . $secret),
            'Accept: application/json',
        ]);
        $jwt = $json['data']['token'] ?? null;
        if (!self::responseSucceeded($http, $json) || !is_string($jwt) || trim($jwt) === '') {
            throw new RuntimeException('Gagal memperbarui JWT: ' . $this->responseMessage($json, $raw, $http));
        }
        return $jwt;
    }

    /**
     * Accept both gateway response schemas:
     * - Current repository: {status: true, code: 200, message: ..., data: ...}
     * - Older integrations: {status: 200, message: ..., data: ...}
     *
     * Some legacy /login implementations return HTML rather than JSON. That is
     * accepted only when $allowNonJson is explicitly enabled by the caller.
     */
    public static function responseSucceeded(int $http, array $json, bool $allowNonJson = false): bool
    {
        if ($http < 200 || $http >= 300) {
            return false;
        }

        if ($json === []) {
            return $allowNonJson;
        }

        if (array_key_exists('code', $json) && is_numeric($json['code'])) {
            $code = (int)$json['code'];
            if ($code < 200 || $code >= 300) {
                return false;
            }
        }

        if (array_key_exists('status', $json)) {
            $status = $json['status'];
            if (is_bool($status) && $status !== true) {
                return false;
            }
            if (is_numeric($status) && ((int)$status < 200 || (int)$status >= 300)) {
                return false;
            }
            if (is_string($status) && in_array(strtolower(trim($status)), ['false', 'error', 'failed'], true)) {
                return false;
            }
        }

        return true;
    }

    private function baseUrl(): string
    {
        $base = rtrim(trim((string)($this->settings->get('wa_gateway_url', '') ?? '')), '/');
        if ($base === '' || !filter_var($base, FILTER_VALIDATE_URL) || !in_array(strtolower((string)parse_url($base, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            throw new RuntimeException('Server URL WhatsApp Gateway belum valid. Isi URL HTTP_BASE_URL, biasanya diakhiri /api/v1/whatsapp.');
        }
        return $base;
    }

    /** @return array{0:int,1:string,2:array} */
    private function request(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $headers[] = 'Connection: close';
        $options = [
            'http' => [
                'method' => strtoupper($method),
                'header' => implode("\r\n", $headers) . "\r\n",
                'timeout' => 30,
                'ignore_errors' => true,
            ],
        ];
        if ($body !== null) $options['http']['content'] = $body;
        $context = stream_context_create($options);
        $raw = @file_get_contents($url, false, $context);
        $status = 0;
        foreach (($http_response_header ?? []) as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#i', $header, $m)) $status = (int)$m[1];
        }
        $raw = $raw === false ? '' : $raw;
        $json = json_decode($raw, true);
        return [$status, $raw, is_array($json) ? $json : []];
    }

    private function responseMessage(array $json, string $raw, int $http): string
    {
        $message = (string)($json['message'] ?? $json['error'] ?? '');
        if ($message !== '') return $message . ' (HTTP ' . $http . ')';
        return ($raw !== '' ? mb_substr($raw, 0, 500) : 'Tidak ada respons dari gateway') . ' (HTTP ' . $http . ')';
    }
}
