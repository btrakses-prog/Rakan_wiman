<?php
declare(strict_types=1);

namespace RakanWiman\Services;

use PDO;
use RuntimeException;
use Throwable;

/** ViaQRIS dynamic invoice integration. All payment confirmation is verified server-to-server. */
final class ViaQrisService
{
    private AppSettings $settings;

    public function __construct(private PDO $pdo)
    {
        $this->settings = new AppSettings($pdo);
    }

    public function create(int $packageId, string $phone, string $customerName = ''): array
    {
        $packageQuery = $this->pdo->prepare('SELECT * FROM packages WHERE id=? AND is_active=1');
        $packageQuery->execute([$packageId]);
        $package = $packageQuery->fetch();
        if (!$package) {
            throw new RuntimeException('Paket tidak tersedia.');
        }

        $routerId = (int)$this->settings->get('default_router_id', '0');
        if (!$routerId) {
            $routerId = (int)$this->pdo->query('SELECT id FROM routers WHERE is_active=1 ORDER BY id LIMIT 1')->fetchColumn();
        }
        $routerQuery = $this->pdo->prepare('SELECT id FROM routers WHERE id=? AND is_active=1');
        $routerQuery->execute([$routerId]);
        if (!$routerQuery->fetch()) {
            throw new RuntimeException('Router tujuan belum ditentukan. Atur router default pada menu QRIS.');
        }

        $merchant = trim((string)$this->settings->get('viaqris_merchant', ''));
        $apiUrl = rtrim(trim((string)$this->settings->get('viaqris_api_url', '')), '/');
        $apiKey = $this->settings->getSecret('viaqris_api_key');
        if ($merchant === '' || $apiUrl === '' || $apiKey === '') {
            throw new RuntimeException('Konfigurasi ViaQRIS belum lengkap di menu QRIS.');
        }

        $phone = preg_replace('/\D+/', '', $phone) ?? '';
        if (str_starts_with($phone, '0')) {
            $phone = '62' . substr($phone, 1);
        }
        if (!preg_match('/^62[0-9]{8,15}$/', $phone)) {
            throw new RuntimeException('Nomor WhatsApp harus menggunakan nomor Indonesia yang valid.');
        }

        $invoice = 'RW' . VoucherService::ulid();
        $expiry = (new \DateTimeImmutable('+60 minutes'))->format(DATE_ATOM);
        $now = (new \DateTimeImmutable())->format(DATE_ATOM);
        $timestamp = (int)round(microtime(true) * 1000);
        $gatewayId = 'trx-' . $invoice . '-' . $timestamp;

        $insert = $this->pdo->prepare("INSERT INTO payments(invoice_no,package_id,router_id,customer_name,whatsapp_number,amount,gateway,gateway_transaction_id,status,expires_at,package_name_snapshot,package_code_snapshot,mikrotik_profile_snapshot,limit_uptime_seconds_snapshot,validity_days_snapshot) VALUES(?,?,?,?,?,?,'viaqris',?,'pending',DATE_ADD(NOW(),INTERVAL 60 MINUTE),?,?,?,?,?)");
        $insert->execute([
            $invoice,
            $packageId,
            $routerId,
            trim($customerName) ?: null,
            $phone,
            (int)$package['price'],
            $gatewayId,
            $package['name'],
            $package['code'],
            $package['mikrotik_profile'],
            $package['limit_uptime_seconds'],
            $package['validity_days'],
        ]);
        $paymentId = (int)$this->pdo->lastInsertId();

        $payload = [
            'kunciEntri' => $gatewayId,
            'dataBaru' => [
                'penjual' => $merchant,
                'jenis' => 'jasa',
                'tanggal' => $now,
                'nama' => trim($customerName) ?: 'Pelanggan Hotspot',
                'email' => strtolower($invoice) . '@customer.rakanwiman.local',
                'telepon' => $phone,
                'pesan' => 'Pembayaran Paket: ' . $package['name'],
                'nama_transaksi' => $package['name'],
                'harga_transaksi' => (string)(int)$package['price'],
                'metode_pembayaran_transaksi' => 'QRIS',
                'status_pembayaran_transaksi' => 'Belum Bayar',
                'kedaluwarsa' => $expiry,
            ],
        ];

        try {
            $created = $this->request('POST', $apiUrl, $apiKey, $payload);
            $createdMessage = (string)($created['message'] ?? '');
            $createdOk = !empty($created['success'])
                || ($createdMessage === "Data untuk '{$gatewayId}' berhasil diproses!")
                || (($created['data']['success'] ?? false) === true);
            if (!$createdOk) {
                throw new RuntimeException($createdMessage !== '' ? $createdMessage : 'ViaQRIS menolak pembuatan transaksi.');
            }

            $detail = $this->request('GET', $this->withQuery($apiUrl, ['id' => $gatewayId]), $apiKey);
            $qr = $this->firstValue($detail, [
                'url_pambayaran', 'url_pembayaran', 'qr_image_url', 'qr_url', 'url_qr',
                'payment_qr_url', 'qr_image', 'qrImageUrl', 'qrImage',
            ]);
            $checkout = $this->firstValue($detail, ['checkout_url', 'url_checkout', 'payment_url', 'url_payment']);

            if ($qr !== null && !is_string($qr)) {
                $qr = null;
            }
            if ($checkout !== null && !is_string($checkout)) {
                $checkout = null;
            }
            if (!$qr && !$checkout) {
                throw new RuntimeException('Transaksi berhasil dibuat, tetapi respons ViaQRIS tidak berisi URL QR atau halaman pembayaran. Hubungi admin dan sertakan nomor invoice: ' . $invoice);
            }

            // ViaQRIS dynamic QR may append an automatic unique code to the package price.
            // Prefer the amount embedded in the QRIS payload, then explicit total fields from API detail.
            $amountInfo = $this->payableAmountInfo($detail, (int)$package['price']);
            $update = $this->pdo->prepare('UPDATE payments SET payment_url=?,qr_image_url=?,payment_payload=?,payable_amount=?,unique_code=? WHERE id=?');
            $update->execute([
                $checkout ?: $qr,
                $qr,
                json_encode(['create' => $created, 'detail' => $detail], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                $amountInfo['payable_amount'],
                $amountInfo['unique_code'],
                $paymentId,
            ]);

            return [
                'invoice' => $invoice,
                'qr_url' => $qr,
                'checkout_url' => $checkout,
                'expires_at' => $expiry,
            ];
        } catch (Throwable $exception) {
            $this->pdo->prepare("UPDATE payments SET status='failed',payment_payload=? WHERE id=?")
                ->execute([json_encode(['error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE), $paymentId]);
            throw $exception;
        }
    }

    public function refresh(string $invoice): array
    {
        $query = $this->pdo->prepare('SELECT * FROM payments WHERE invoice_no=?');
        $query->execute([$invoice]);
        $payment = $query->fetch();
        if (!$payment) {
            throw new RuntimeException('Transaksi tidak ditemukan.');
        }

        if ($payment['status'] === 'pending' && !empty($payment['gateway_transaction_id'])) {
            $apiUrl = rtrim(trim((string)$this->settings->get('viaqris_api_url', '')), '/');
            $apiKey = $this->settings->getSecret('viaqris_api_key');
            if ($apiUrl === '' || $apiKey === '') {
                throw new RuntimeException('Konfigurasi URL atau API Token ViaQRIS belum lengkap.');
            }

            $detail = $this->request('GET', $this->withQuery($apiUrl, ['id' => (string)$payment['gateway_transaction_id']]), $apiKey);
            $paymentStatus = $this->paymentStatusFrom($detail);
            if ($paymentStatus === '') {
                $gatewayMessage = (string)($detail['message'] ?? $detail['data']['message'] ?? '');
                throw new RuntimeException('Respons ViaQRIS tidak memuat status pembayaran.' . ($gatewayMessage !== '' ? ' Pesan gateway: ' . $gatewayMessage : ''));
            }

            $baseAmount = (int)$payment['amount'];
            $amountInfo = $this->payableAmountInfo($detail, $baseAmount);
            if ($amountInfo['payable_amount'] !== null) {
                $this->savePayableAmountHint((int)$payment['id'], $amountInfo);
            }

            if ($paymentStatus === 'Berhasil') {
                // ViaQRIS uses the exact gateway transaction ID as the binding reference and may
                // add a unique code to harga_transaksi. The final amount is therefore not required
                // to equal the base package price stored in payments.amount.
                $confirmedAmount = $amountInfo['payable_amount'];
                if ($confirmedAmount === null) {
                    $rawAmount = $this->amountFrom($detail);
                    // A value equal to the base price may be the original harga_transaksi,
                    // not the final QRIS nominal. Do not record it as a confirmed unique code.
                    if ($rawAmount !== null && $rawAmount > $baseAmount) {
                        $confirmedAmount = $rawAmount;
                    }
                }
                $this->markPaid($payment, $detail, $confirmedAmount);
            } elseif (in_array($paymentStatus, ['Dibatalkan', 'Kedaluwarsa'], true)) {
                $update = $this->pdo->prepare("UPDATE payments SET status='expired',payment_payload=? WHERE id=? AND status='pending'");
                $update->execute([json_encode($detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $payment['id']]);
            }
        }

        return $this->getPublicStatus($invoice);
    }

    /**
     * Store the final nominal read from the displayed EMV QR. This is only a display/audit
     * hint; it never confirms payment. Payment is confirmed exclusively by ViaQRIS status/callback.
     */
    public function recordQrPayloadHint(string $invoice, string $payload): array
    {
        $payload = preg_replace('/\s+/', '', trim($payload)) ?? '';
        if (strlen($payload) < 40 || strlen($payload) > 1500 || !str_starts_with($payload, '000201')) {
            throw new RuntimeException('Payload QRIS tidak valid.');
        }
        if (!$this->validEmvCrc($payload)) {
            throw new RuntimeException('CRC QRIS tidak valid.');
        }

        $query = $this->pdo->prepare('SELECT id,amount,status FROM payments WHERE invoice_no=? AND gateway=\'viaqris\'');
        $query->execute([$invoice]);
        $payment = $query->fetch();
        if (!$payment) throw new RuntimeException('Invoice tidak ditemukan.');
        if (!in_array($payment['status'], ['pending', 'paid'], true)) {
            throw new RuntimeException('Transaksi tidak lagi menerima pembaruan nominal QR.');
        }

        $amount = $this->qrPayloadAmountFrom(['qr_string' => $payload]);
        $baseAmount = (int)$payment['amount'];
        if ($amount === null || $amount < $baseAmount || ($amount - $baseAmount) > 9999) {
            throw new RuntimeException('Nominal QRIS tidak sesuai dengan harga paket.');
        }
        $uniqueCode = $amount - $baseAmount;
        $update = $this->pdo->prepare("UPDATE payments SET payable_amount=CASE WHEN paid_amount IS NULL THEN ? ELSE payable_amount END, unique_code=CASE WHEN paid_amount IS NULL THEN ? ELSE unique_code END WHERE id=? AND status IN ('pending','paid')");
        $update->execute([$amount, $uniqueCode, $payment['id']]);
        return $this->getPublicStatus($invoice);
    }

    private function validEmvCrc(string $payload): bool
    {
        if (!preg_match('/6304([0-9A-Fa-f]{4})$/', $payload, $match)) return false;
        $content = substr($payload, 0, -4);
        $crc = 0xFFFF;
        $length = strlen($content);
        for ($i = 0; $i < $length; $i++) {
            $crc ^= ord($content[$i]) << 8;
            for ($bit = 0; $bit < 8; $bit++) {
                $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) & 0xFFFF : ($crc << 1) & 0xFFFF;
            }
        }
        return strtoupper(sprintf('%04X', $crc)) === strtoupper($match[1]);
    }

    public function callback(array $payload): array
    {
        $transactionId = (string)($payload['transactionId'] ?? '');
        $callbackStatus = (string)($payload['status'] ?? '');
        $callbackAmount = $this->parseMoney($payload['amount'] ?? null);
        if ($transactionId === '' || $callbackStatus === '' || $callbackAmount === null) {
            throw new RuntimeException('Payload callback tidak lengkap.');
        }

        $query = $this->pdo->prepare('SELECT * FROM payments WHERE gateway_transaction_id=?');
        $query->execute([$transactionId]);
        $payment = $query->fetch();
        if (!$payment) {
            throw new RuntimeException('Transaksi callback tidak ditemukan.');
        }

        $eventKey = hash('sha256', $transactionId . '|' . $callbackStatus . '|' . (string)$callbackAmount . '|' . json_encode($payload));
        $event = $this->pdo->prepare("INSERT IGNORE INTO webhook_events(gateway,event_key,payload,processing_status) VALUES('viaqris',?,?,'received')");
        $event->execute([$eventKey, json_encode($payload, JSON_UNESCAPED_UNICODE)]);

        if ($this->normalizePaymentStatus($callbackStatus) === 'Berhasil') {
            $apiUrl = rtrim(trim((string)$this->settings->get('viaqris_api_url', '')), '/');
            $apiKey = $this->settings->getSecret('viaqris_api_key');
            if ($apiUrl === '' || $apiKey === '') {
                throw new RuntimeException('Konfigurasi ViaQRIS belum tersedia untuk verifikasi callback.');
            }

            // A callback alone is not enough: confirm that the same gateway transaction is paid.
            $verified = $this->request('GET', $this->withQuery($apiUrl, ['id' => $transactionId]), $apiKey);
            if ($this->paymentStatusFrom($verified) !== 'Berhasil') {
                throw new RuntimeException('Status pembayaran belum dikonfirmasi oleh API ViaQRIS.');
            }

            $baseAmount = (int)$payment['amount'];
            // Some ViaQRIS responses expose the original harga_transaksi in updatedData while
            // the webhook amount contains the final automatic nominal (base + unique code).
            // In that case prefer the callback amount, but still require an exact paid status
            // from the API for this transaction ID.
            if ($callbackAmount < $baseAmount || ($callbackAmount - $baseAmount) > 9999) {
                throw new RuntimeException('Nominal callback tidak sesuai dengan harga paket/kode unik yang diharapkan. Pembayaran tidak diproses.');
            }
            // The callback amount is the amount received from the payment event. The API GET
            // confirms the exact transaction ID and paid status; some ViaQRIS records retain
            // the original base harga_transaksi, so do not reject a valid callback because that
            // stored field differs from the dynamic amount received by the bank.
            $finalAmount = $callbackAmount;
            $this->markPaid($payment, $verified + ['callback' => $payload], $finalAmount);
        } elseif (in_array($this->normalizePaymentStatus($callbackStatus), ['Dibatalkan', 'Kedaluwarsa'], true)) {
            $this->pdo->prepare("UPDATE payments SET status='expired' WHERE id=? AND status='pending'")->execute([$payment['id']]);
        }

        $this->pdo->prepare("UPDATE webhook_events SET processing_status='processed',processed_at=NOW() WHERE gateway='viaqris' AND event_key=?")
            ->execute([$eventKey]);

        return ['ok' => true, 'message' => 'Callback diproses.'];
    }

    private function markPaid(array $payment, array $gatewayData, ?int $paidAmount = null): void
    {
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT * FROM payments WHERE id=? FOR UPDATE');
            $lock->execute([$payment['id']]);
            $fresh = $lock->fetch();
            if (!$fresh) {
                throw new RuntimeException('Pembayaran tidak ditemukan.');
            }
            if ($fresh['status'] === 'paid' && $fresh['processed_at'] !== null) {
                // A later webhook can enrich the paid amount/unique code without issuing a second voucher.
                if ($paidAmount !== null && $paidAmount >= (int)$fresh['amount']) {
                    $uniqueCode = $paidAmount - (int)$fresh['amount'];
                    $this->pdo->prepare('UPDATE payments SET paid_amount=?,payable_amount=?,unique_code=? WHERE id=?')
                        ->execute([$paidAmount, $paidAmount, $uniqueCode, $fresh['id']]);
                }
                $this->pdo->commit();
                return;
            }
            if (!in_array($fresh['status'], ['pending', 'paid'], true)) {
                $this->pdo->commit();
                return;
            }

            if ($paidAmount !== null && $paidAmount < (int)$fresh['amount']) {
                throw new RuntimeException('Nominal terkonfirmasi lebih kecil dari harga paket. Voucher tidak diterbitkan.');
            }
            $paidUniqueCode = $paidAmount !== null ? $paidAmount - (int)$fresh['amount'] : null;
            $this->pdo->prepare("UPDATE payments SET status='paid',paid_at=COALESCE(paid_at,NOW()),payment_payload=?,paid_amount=COALESCE(?,paid_amount),payable_amount=COALESCE(?,payable_amount),unique_code=COALESCE(?,unique_code) WHERE id=?")
                ->execute([
                    json_encode($gatewayData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    $paidAmount,
                    $paidAmount,
                    $paidUniqueCode,
                    $fresh['id'],
                ]);

            $existing = $this->pdo->prepare('SELECT id FROM vouchers WHERE source_payment_id=?');
            $existing->execute([$fresh['id']]);
            $voucherId = (int)($existing->fetchColumn() ?: 0);

            if (!$voucherId) {
                $packageQuery = $this->pdo->prepare('SELECT * FROM packages WHERE id=?');
                $packageQuery->execute([$fresh['package_id']]);
                $package = $packageQuery->fetch();
                if (!$package) {
                    throw new RuntimeException('Paket transaksi tidak ditemukan. Pembayaran tercatat, penerbitan voucher perlu bantuan administrator.');
                }
                $routerQuery = $this->pdo->prepare('SELECT id FROM routers WHERE id=?');
                $routerQuery->execute([$fresh['router_id']]);
                if (!$routerQuery->fetch()) {
                    throw new RuntimeException('Router tujuan tidak ditemukan. Pembayaran tercatat, tetapi aktivasi memerlukan bantuan administrator.');
                }

                $hasPackageSnapshot = !empty($fresh['package_name_snapshot']);
                $packageName = $hasPackageSnapshot ? (string)$fresh['package_name_snapshot'] : (string)$package['name'];
                $packageCode = $hasPackageSnapshot ? (string)$fresh['package_code_snapshot'] : (string)$package['code'];
                $profileSnapshot = $hasPackageSnapshot ? (string)$fresh['mikrotik_profile_snapshot'] : (string)$package['mikrotik_profile'];
                $limitSnapshot = $hasPackageSnapshot ? $fresh['limit_uptime_seconds_snapshot'] : $package['limit_uptime_seconds'];
                $validitySnapshot = $hasPackageSnapshot ? $fresh['validity_days_snapshot'] : $package['validity_days'];

                // QRIS on-demand credentials use their own saved generator settings. Package
                // profile/uptime/validity and the selected default router remain sourced from
                // the immutable payment snapshot; only credential format/server/data cap/comment
                // are controlled by the dedicated QRIS generator configuration.
                $appSettings = new AppSettings($this->pdo);
                $userMode = (string)$appSettings->get('qris_voucher_user_mode', 'separate');
                if (!in_array($userMode, ['separate','same'], true)) $userMode = 'separate';
                $characterSet = (string)$appSettings->get('qris_voucher_character_set', 'mixed_alnum');
                if (!in_array($characterSet, ['lower','upper','mixed_alpha','lower_alnum','upper_alnum','mixed_alnum'], true)) $characterSet = 'mixed_alnum';
                $nameLength = (int)$appSettings->get('qris_voucher_name_length', '8');
                if ($nameLength < 3 || $nameLength > 8) $nameLength = 8;
                $prefix = (string)$appSettings->get('qris_voucher_prefix', 'RW');
                $hotspotServer = trim((string)$appSettings->get('qris_voucher_hotspot_server', 'all')) ?: 'all';
                if ($hotspotServer !== 'all' && !preg_match('/^[A-Za-z0-9_.-]{1,120}$/', $hotspotServer)) $hotspotServer = 'all';
                $dataLimitValue = (string)$appSettings->get('qris_voucher_data_limit', '');
                $dataLimitUnit = (string)$appSettings->get('qris_voucher_data_unit', 'MB');
                $limitBytesTotal = VoucherService::parseDataLimit($dataLimitValue, $dataLimitUnit);
                $generationComment = trim((string)$appSettings->get('qris_voucher_comment', 'Pembelian QRIS'));
                if (strlen($generationComment) > 255) $generationComment = substr($generationComment, 0, 255);
                if ($generationComment === '') $generationComment = null;

                $username = '';
                $password = '';
                $inserted = false;
                for ($attempt = 0; $attempt < 30 && !$inserted; $attempt++) {
                    $credentials = VoucherService::generateCredentialPair($prefix, $nameLength, $userMode, $characterSet);
                    $username = $credentials['username'];
                    $password = $credentials['password'];
                    try {
                        $voucherInsert = $this->pdo->prepare("INSERT INTO vouchers(public_id,router_id,package_id,package_name_snapshot,package_code_snapshot,mikrotik_profile_snapshot,limit_uptime_seconds_snapshot,validity_days_snapshot,username,password_encrypted,generation_mode,sale_status,sync_status,usage_status,source_payment_id) VALUES(?,?,?,?,?,?,?,?,?,?,'on_demand','sold','pending','unused',?)");
                        $voucherInsert->execute([
                            VoucherService::ulid(),
                            $fresh['router_id'],
                            $fresh['package_id'],
                            $packageName,
                            $packageCode,
                            $profileSnapshot,
                            $limitSnapshot,
                            $validitySnapshot,
                            $username,
                            VoucherService::encryptSecret($password),
                            $fresh['id'],
                        ]);
                        $voucherId = (int)$this->pdo->lastInsertId();
                        $optionsInsert = $this->pdo->prepare('INSERT INTO voucher_generation_options (voucher_id,hotspot_server,limit_uptime_seconds,limit_bytes_total,generation_comment,user_mode,character_set,name_length) VALUES(?,?,NULL,?,?,?,?,?)');
                        $optionsInsert->execute([$voucherId, $hotspotServer, $limitBytesTotal, $generationComment, $userMode, $characterSet, $nameLength]);
                        $inserted = true;
                    } catch (\PDOException $exception) {
                        if (($exception->errorInfo[1] ?? 0) !== 1062) throw $exception;
                    }
                }
                if (!$inserted) throw new RuntimeException('Tidak berhasil membuat username QRIS yang unik. Periksa prefix atau panjang karakter.');

                $queue = $this->pdo->prepare("INSERT INTO router_sync_queue(router_id,voucher_id,action,status) VALUES(?,?,'create','pending')");
                $queue->execute([$fresh['router_id'], $voucherId]);
                $this->pdo->prepare('UPDATE payments SET voucher_id=?,processed_at=NOW() WHERE id=?')->execute([$voucherId, $fresh['id']]);

                $template = (new AppSettings($this->pdo))->get('wa_message_template', "Terima kasih telah membeli voucher RAKAN WIMAN.\nPaket: {package}\nUsername: {username}\nPassword: {password}\nStatus aktivasi: {sync_status}.") ?? '';
                $message = strtr($template, [
                    '{package}' => $packageName,
                    '{username}' => $username,
                    '{password}' => $password,
                    '{sync_status}' => 'menunggu sinkronisasi router',
                ]);
                $this->pdo->prepare("INSERT INTO message_queue(channel,recipient,message,related_type,related_id,status) VALUES('whatsapp',?,?,'payment',?,'pending')")
                    ->execute([$fresh['whatsapp_number'], $message, $fresh['id']]);
            } else {
                $this->pdo->prepare('UPDATE payments SET voucher_id=?,processed_at=COALESCE(processed_at,NOW()) WHERE id=?')
                    ->execute([$voucherId, $fresh['id']]);
            }

            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function amountFrom(array $data): ?int
    {
        // Search by semantic priority across the whole response tree. This avoids taking
        // updatedData.harga_transaksi (the package/base price) before a root total/paid amount.
        $keys = [
            'total_bayar', 'totalBayar', 'total_pembayaran', 'totalPembayaran',
            'total_amount', 'totalAmount', 'payable_amount', 'payableAmount',
            'nominal_pembayaran', 'nominalPembayaran', 'nominal_diterima', 'nominalDiterima',
            'jumlah_pembayaran', 'jumlahPembayaran', 'paid_amount', 'paidAmount',
            'amount_paid', 'amountPaid', 'amount_received', 'amountReceived',
            'total', 'amount', 'nominal', 'harga_transaksi', 'hargaTransaksi', 'harga',
        ];
        foreach ($keys as $key) {
            $value = $this->findScalarKey($data, $key);
            if ($value !== null) {
                $parsed = $this->parseMoney($value);
                if ($parsed !== null) return $parsed;
            }
        }
        return null;
    }

    private function findScalarKey(array $data, string $key, int $depth = 0): mixed
    {
        if ($depth > 6) return null;
        if (array_key_exists($key, $data) && is_scalar($data[$key]) && !is_bool($data[$key])) {
            return $data[$key];
        }
        foreach (['updatedData', 'transaction', 'payment', 'data', 'result', 'callback', 'dataBaru', 'create', 'detail'] as $nestedKey) {
            if (isset($data[$nestedKey]) && is_array($data[$nestedKey])) {
                $value = $this->findScalarKey($data[$nestedKey], $key, $depth + 1);
                if ($value !== null) return $value;
            }
        }
        return null;
    }

    private function paymentStatusFrom(array $data): string
    {
        // Payment-specific fields take priority over a generic root `status`, because a
        // webhook/update wrapper can use status=Berhasil to mean the update operation succeeded.
        $paymentStatusKeys = [
            'status_pembayaran_transaksi', 'status_pembayaran', 'payment_status',
            'paymentStatus', 'payment_status_transaksi', 'statusPembayaran',
        ];
        foreach ($paymentStatusKeys as $key) {
            $value = $this->findScalarKey($data, $key);
            if ($value !== null) {
                $normalized = $this->normalizePaymentStatus((string)$value);
                if ($normalized !== '') return $normalized;
            }
        }

        // Compatibility fallback for a direct transaction object (the legacy ViaQRIS
        // PHPNuxBill plugin expects an `id` and `status_pembayaran_transaksi` at its root).
        // A generic status is only trusted when the response itself identifies a record.
        if (isset($data['id']) && isset($data['status']) && is_scalar($data['status'])) {
            return $this->normalizePaymentStatus((string)$data['status']);
        }
        return '';
    }

    /** Parse Indonesian Rupiah values without treating a thousands separator as decimals. */
    private function parseMoney(mixed $value): ?int
    {
        if (!is_scalar($value) || is_bool($value)) return null;
        $text = trim((string)$value);
        if ($text === '') return null;
        $text = preg_replace('/[^0-9,.-]/u', '', $text) ?? '';
        if ($text === '' || $text === '-' || $text === ',' || $text === '.') return null;
        if (preg_match('/^\d{1,3}(?:[.,]\d{3})+$/', $text)) {
            return (int)str_replace([',', '.'], '', $text);
        }
        if (preg_match('/^\d+(?:[.,]\d{1,2})?$/', $text) && (str_contains($text, '.') || str_contains($text, ','))) {
            // IDR values are integer; decimal fractions, if present, are discarded.
            $parts = preg_split('/[.,]/', $text, 2);
            return (int)($parts[0] ?? 0);
        }
        $digits = preg_replace('/\D+/', '', $text) ?? '';
        return $digits !== '' ? (int)$digits : null;
    }

    /**
     * Returns the final amount and unique code when explicit gateway data or the QRIS EMV payload
     * reveals it. A plain base harga_transaksi is not mislabelled as a unique-code total.
     */
    private function payableAmountInfo(array $data, int $baseAmount): array
    {
        $qrAmount = $this->qrPayloadAmountFrom($data);
        if ($qrAmount !== null && $qrAmount >= $baseAmount) {
            return ['payable_amount' => $qrAmount, 'unique_code' => $qrAmount - $baseAmount];
        }

        $explicitCode = $this->uniqueCodeFrom($data);
        if ($explicitCode !== null && $explicitCode >= 0) {
            return ['payable_amount' => $baseAmount + $explicitCode, 'unique_code' => $explicitCode];
        }

        $candidate = $this->amountFrom($data);
        if ($candidate !== null && $candidate > $baseAmount) {
            return ['payable_amount' => $candidate, 'unique_code' => $candidate - $baseAmount];
        }
        return ['payable_amount' => null, 'unique_code' => null];
    }

    private function uniqueCodeFrom(array $data): ?int
    {
        foreach (['updatedData', 'transaction', 'payment', 'data', 'result'] as $container) {
            if (isset($data[$container]) && is_array($data[$container])) {
                $found = $this->uniqueCodeFrom($data[$container]);
                if ($found !== null) return $found;
            }
        }
        foreach (['unique_code', 'uniqueCode', 'kode_unik', 'kodeUnik', 'nominal_unik'] as $key) {
            if (array_key_exists($key, $data)) {
                $value = $this->parseMoney($data[$key]);
                if ($value !== null) return $value;
            }
        }
        return null;
    }

    /** Extract amount tag 54 from a raw QRIS EMV payload returned by ViaQRIS. */
    private function qrPayloadAmountFrom(array $data): ?int
    {
        $payload = $this->findQrPayload($data);
        if ($payload === null) return null;
        $length = strlen($payload);
        $offset = 0;
        while ($offset + 4 <= $length) {
            $tag = substr($payload, $offset, 2);
            $sizeText = substr($payload, $offset + 2, 2);
            if (!ctype_digit($sizeText)) return null;
            $size = (int)$sizeText;
            $offset += 4;
            if ($size < 0 || $offset + $size > $length) return null;
            $value = substr($payload, $offset, $size);
            if ($tag === '54') return $this->parseMoney($value);
            $offset += $size;
        }
        return null;
    }

    private function findQrPayload(array $data, int $depth = 0): ?string
    {
        if ($depth > 5) return null;
        $payloadKeys = ['qr_string', 'qrString', 'qris_string', 'qrisString', 'qris_content', 'qrisContent', 'qr_content', 'qrContent', 'qr_payload', 'qrPayload', 'qris_payload', 'qrisPayload', 'payload_qris', 'qr_text', 'qrText', 'qr_code', 'qrCode', 'qris', 'qr_data', 'qrData', 'qr_url'];
        foreach ($payloadKeys as $key) {
            if (isset($data[$key]) && is_string($data[$key])) {
                $candidate = preg_replace('/\s+/', '', trim($data[$key])) ?? '';
                if (str_starts_with($candidate, '000201') && strlen($candidate) >= 30) return $candidate;
            }
        }
        foreach (['updatedData', 'data', 'transaction', 'payment', 'result', 'create', 'detail'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                $found = $this->findQrPayload($data[$key], $depth + 1);
                if ($found !== null) return $found;
            }
        }
        return null;
    }

    private function savePayableAmountHint(int $paymentId, array $amountInfo): void
    {
        if ($amountInfo['payable_amount'] === null) return;
        $update = $this->pdo->prepare("UPDATE payments SET payable_amount=COALESCE(payable_amount,?),unique_code=COALESCE(unique_code,?) WHERE id=? AND status='pending'");
        $update->execute([$amountInfo['payable_amount'], $amountInfo['unique_code'], $paymentId]);
    }

    private function normalizePaymentStatus(string $status): string
    {
        $value = strtolower(trim($status));
        return match ($value) {
            'berhasil', 'paid', 'success', 'settled', 'lunas' => 'Berhasil',
            'belum bayar', 'unpaid', 'pending', 'menunggu', 'menunggu pembayaran' => 'Belum Bayar',
            'dibatalkan', 'cancelled', 'canceled', 'cancel' => 'Dibatalkan',
            'kedaluwarsa', 'expired', 'expire' => 'Kedaluwarsa',
            default => '',
        };
    }

    private function firstValue(array $data, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && is_scalar($data[$key]) && trim((string)$data[$key]) !== '') {
                return (string)$data[$key];
            }
        }
        foreach (['updatedData', 'data', 'transaction', 'payment', 'result', 'dataBaru', 'create', 'detail'] as $nestedKey) {
            if (isset($data[$nestedKey]) && is_array($data[$nestedKey])) {
                $value = $this->firstValue($data[$nestedKey], $keys);
                if ($value !== null) {
                    return $value;
                }
            }
        }
        return null;
    }

    private function withQuery(string $url, array $params): string
    {
        $separator = str_contains($url, '?') ? '&' : '?';
        return $url . $separator . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    private function request(string $method, string $url, string $key, ?array $data = null): array
    {
        $method = strtoupper($method);
        $headers = [
            'Content-Type: application/json',
            'X-API-Token: ' . $key,
            'Accept: application/json',
        ];
        $body = $data === null ? null : json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            throw new RuntimeException('Data transaksi tidak dapat dikonversi ke JSON.');
        }

        if (function_exists('curl_init')) {
            $curl = curl_init($url);
            if ($curl === false) {
                throw new RuntimeException('Tidak dapat memulai koneksi cURL ke ViaQRIS.');
            }
            $options = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => false,
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_CONNECTTIMEOUT => 12,
                CURLOPT_TIMEOUT => 35,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_USERAGENT => 'RAKAN-WIMAN/0.4.21',
            ];
            if ($body !== null) {
                $options[CURLOPT_POSTFIELDS] = $body;
            }
            curl_setopt_array($curl, $options);
            $raw = curl_exec($curl);
            $curlError = curl_error($curl);
            $curlErrno = curl_errno($curl);
            $httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);

            if ($raw === false) {
                $detail = $curlError !== '' ? $curlError : 'kesalahan jaringan tidak diketahui';
                throw new RuntimeException('Koneksi ViaQRIS gagal (cURL ' . $curlErrno . '): ' . $detail . '. Periksa DNS, SSL, firewall hosting, dan alamat API.');
            }
            if ($httpCode < 200 || $httpCode >= 300) {
                $jsonError = json_decode((string)$raw, true);
                $message = is_array($jsonError) ? (string)($jsonError['message'] ?? $jsonError['error'] ?? '') : '';
                throw new RuntimeException('ViaQRIS merespons HTTP ' . $httpCode . ($message !== '' ? ': ' . substr($message, 0, 240) : '. Periksa API URL dan token.'));
            }
        } else {
            if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
                throw new RuntimeException('Ekstensi cURL tidak tersedia dan allow_url_fopen dinonaktifkan. Aktifkan ekstensi cURL pada PHP hosting untuk mengakses ViaQRIS.');
            }
            $contextOptions = [
                'http' => [
                    'method' => $method,
                    'header' => implode("\r\n", $headers) . "\r\n",
                    'timeout' => 35,
                    'ignore_errors' => true,
                    'follow_location' => 0,
                    'max_redirects' => 0,
                    'user_agent' => 'RAKAN-WIMAN/0.4.21',
                ],
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'allow_self_signed' => false,
                ],
            ];
            if ($body !== null) {
                $contextOptions['http']['content'] = $body;
            }
            $context = stream_context_create($contextOptions);
            $raw = @file_get_contents($url, false, $context);
            $responseHeaders = $http_response_header ?? [];
            if ($raw === false) {
                $lastError = error_get_last();
                $detail = isset($lastError['message']) ? preg_replace('/\s+/', ' ', (string)$lastError['message']) : 'koneksi gagal';
                throw new RuntimeException('Koneksi ViaQRIS gagal melalui PHP stream: ' . substr((string)$detail, 0, 220) . '. Aktifkan cURL pada PHP hosting bila masalah berlanjut.');
            }
            $httpCode = 0;
            foreach ($responseHeaders as $headerLine) {
                if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $headerLine, $match)) {
                    $httpCode = (int)$match[1];
                }
            }
            if ($httpCode < 200 || $httpCode >= 300) {
                $jsonError = json_decode((string)$raw, true);
                $message = is_array($jsonError) ? (string)($jsonError['message'] ?? $jsonError['error'] ?? '') : '';
                throw new RuntimeException('ViaQRIS merespons HTTP ' . $httpCode . ($message !== '' ? ': ' . substr($message, 0, 240) : '. Periksa API URL dan token.'));
            }
        }

        $json = json_decode((string)$raw, true);
        if (!is_array($json)) {
            throw new RuntimeException('Respons ViaQRIS bukan JSON yang valid. Awal respons: ' . substr(trim((string)$raw), 0, 180));
        }
        return $json;
    }

    public function getPublicStatus(string $invoice): array
    {
        $query = $this->pdo->prepare('SELECT p.invoice_no,p.amount,p.unique_code,p.payable_amount,p.paid_amount,p.status,p.expires_at,p.paid_at,p.voucher_id,v.username,v.sync_status,v.usage_status,COALESCE(p.package_name_snapshot,pk.name) AS package_name FROM payments p JOIN packages pk ON pk.id=p.package_id LEFT JOIN vouchers v ON v.id=p.voucher_id WHERE p.invoice_no=?');
        $query->execute([$invoice]);
        $row = $query->fetch();
        if (!$row) {
            throw new RuntimeException('Transaksi tidak ditemukan.');
        }
        return $row;
    }
}
