<?php
declare(strict_types=1);

namespace RakanWiman\Services;

use RuntimeException;

/** Dependency-free A6 voucher PDF with an embedded vector QR code. */
final class VoucherPdfService
{
    public static function create(array $voucher): string
    {
        $username = (string)($voucher['username'] ?? '');
        $password = (string)($voucher['password'] ?? '');
        $invoice = (string)($voucher['invoice_no'] ?? '');
        if ($username === '' || $password === '' || $invoice === '') {
            throw new RuntimeException('Data voucher untuk PDF tidak lengkap.');
        }

        $same = (string)($voucher['user_mode'] ?? 'separate') === 'same';
        $showPassword = !array_key_exists('show_password', $voucher) || (bool)$voucher['show_password'];
        $voucherTitle = trim((string)($voucher['voucher_title'] ?? 'VOUCHER INTERNET')) ?: 'VOUCHER INTERNET';
        $voucherSubtitle = trim((string)($voucher['voucher_subtitle'] ?? 'RAKAN WIMAN')) ?: 'RAKAN WIMAN';
        $voucherFooter = trim((string)($voucher['voucher_footer'] ?? 'Terima kasih telah menggunakan layanan kami.'));
        if (function_exists('mb_substr')) $voucherFooter = mb_substr($voucherFooter, 0, 68, 'UTF-8');
        else $voucherFooter = substr($voucherFooter, 0, 68);
        $accent = self::hexToRgb((string)($voucher['accent_color'] ?? '#2563eb'));
        $payload = 'RWV1|' . $username . '|' . $password;
        $svg = QrCode::svg($payload, 1, 4);
        if (!preg_match('/viewBox="0 0 (\d+) (\d+)"/', $svg, $viewBox)) {
            throw new RuntimeException('Tidak dapat menyiapkan QR voucher untuk PDF.');
        }
        $qrViewSize = (int)$viewBox[1];
        preg_match_all('/M(\d+) (\d+)h1v1h-1z/', $svg, $modules, PREG_SET_ORDER);
        if (!$modules || $qrViewSize < 1) {
            throw new RuntimeException('QR voucher kosong atau tidak valid.');
        }

        // A6 portrait in PDF points.
        $pageW = 297.6378;
        $pageH = 419.5276;
        $commands = [];
        $commands[] = 'q 0.96 0.98 1 rg 0 0 ' . self::n($pageW) . ' ' . self::n($pageH) . ' re f Q';
        $commands[] = 'q 1 1 1 rg 14 14 ' . self::n($pageW - 28) . ' ' . self::n($pageH - 28) . ' re f Q';
        $commands[] = 'q 0.08 0.25 0.55 RG 1.2 w 14 14 ' . self::n($pageW - 28) . ' ' . self::n($pageH - 28) . ' re S Q';
        $commands[] = 'q ' . implode(' ', array_map([self::class, 'n'], $accent)) . ' rg 14 373 ' . self::n($pageW - 28) . ' 46 re f Q';
        $brandSize = max(9.0, min(17.0, 250 / max(14, strlen($voucherSubtitle) * 0.62)));
        $titleSize = max(6.5, min(8.5, 250 / max(18, strlen($voucherTitle) * 0.62)));
        self::text($commands, 28, 395, $brandSize, $voucherSubtitle, true, [1, 1, 1]);
        self::text($commands, 28, 381, $titleSize, $voucherTitle, true, [1, 1, 1]);

        self::text($commands, 28, 349, 8, 'PAKET INTERNET', false, [0.36, 0.42, 0.52]);
        self::text($commands, 28, 330, 13, (string)($voucher['package_name'] ?? 'Voucher WiFi'), true);
        if (!empty($voucher['validity_days'])) {
            self::text($commands, 28, 313, 8, 'Masa berlaku: ' . (int)$voucher['validity_days'] . ' hari', false, [0.36, 0.42, 0.52]);
        }

        $qrSize = 145.0;
        $cell = $qrSize / $qrViewSize;
        $qrX = ($pageW - $qrSize) / 2;
        $qrY = 153.0;
        $commands[] = 'q 1 1 1 rg ' . self::n($qrX - 7) . ' ' . self::n($qrY - 7) . ' ' . self::n($qrSize + 14) . ' ' . self::n($qrSize + 14) . ' re f Q';
        $commands[] = 'q 0.83 0.87 0.93 RG 0.7 w ' . self::n($qrX - 7) . ' ' . self::n($qrY - 7) . ' ' . self::n($qrSize + 14) . ' ' . self::n($qrSize + 14) . ' re S Q';
        $commands[] = 'q 0 0 0 rg';
        foreach ($modules as $module) {
            $mx = (int)$module[1];
            $my = (int)$module[2];
            $x = $qrX + ($mx * $cell);
            $y = $qrY + (($qrViewSize - 1 - $my) * $cell);
            $commands[] = self::n($x) . ' ' . self::n($y) . ' ' . self::n($cell + 0.02) . ' ' . self::n($cell + 0.02) . ' re f';
        }
        $commands[] = 'Q';

        if ($same) {
            self::text($commands, 28, 128, 7.5, 'USERNAME / PASSWORD', false, [0.36, 0.42, 0.52]);
            self::text($commands, 28, 110, 14, $username, true);
        } else {
            self::text($commands, 28, 135, 7.5, 'USERNAME', false, [0.36, 0.42, 0.52]);
            self::text($commands, 28, 119, 12.5, $username, true);
            if ($showPassword) {
                self::text($commands, 28, 99, 7.5, 'PASSWORD', false, [0.36, 0.42, 0.52]);
                self::text($commands, 28, 83, 12.5, $password, true);
            } else {
                self::text($commands, 28, 91, 8, 'Password disembunyikan. Gunakan QR untuk login.', false, [0.36, 0.42, 0.52]);
            }
        }

        $sync = (string)($voucher['sync_status'] ?? 'pending');
        $syncLabel = $sync === 'synced' ? 'Status router: siap digunakan' : 'Status router: menunggu sinkronisasi';
        self::text($commands, 28, 57, 8, $syncLabel, false, $sync === 'synced' ? [0.08, 0.48, 0.27] : [0.65, 0.42, 0.05]);
        self::text($commands, 28, 40, 6.5, 'Invoice: ' . $invoice, false, [0.42, 0.46, 0.53]);
        self::text($commands, 28, 27, 6.5, $voucherFooter !== '' ? $voucherFooter : 'Terima kasih telah menggunakan layanan kami.', false, [0.42, 0.46, 0.53]);

        $stream = implode("\n", $commands) . "\n";
        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
        $objects[3] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . self::n($pageW) . ' ' . self::n($pageH) . '] /Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents 6 0 R >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[5] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $objects[6] = '<< /Length ' . strlen($stream) . ">>\nstream\n" . $stream . "endstream";

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];
        foreach ($objects as $number => $object) {
            $offsets[$number] = strlen($pdf);
            $pdf .= $number . " 0 obj\n" . $object . "\nendobj\n";
        }
        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 7\n0000000000 65535 f \n";
        for ($i = 1; $i <= 6; $i++) {
            $pdf .= sprintf('%010d 00000 n ', $offsets[$i]) . "\n";
        }
        $pdf .= "trailer\n<< /Size 7 /Root 1 0 R >>\nstartxref\n" . $xrefOffset . "\n%%EOF";
        return $pdf;
    }

    private static function text(array &$commands, float $x, float $y, float $size, string $value, bool $bold = false, array $color = [0.08, 0.13, 0.22]): void
    {
        $encoded = function_exists('iconv') ? @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $value) : false;
        if (!is_string($encoded)) $encoded = preg_replace('/[^\x20-\x7E]/', '?', $value) ?? '';
        $encoded = str_replace(["\\", '(', ')', "\r", "\n"], ["\\\\", '\\(', '\\)', ' ', ' '], $encoded);
        $font = $bold ? 'F2' : 'F1';
        $commands[] = 'q ' . implode(' ', array_map([self::class, 'n'], $color)) . ' rg BT /' . $font . ' ' . self::n($size) . ' Tf ' . self::n($x) . ' ' . self::n($y) . ' Td (' . $encoded . ') Tj ET Q';
    }

    private static function hexToRgb(string $hex): array
    {
        if (!preg_match('/^#?([0-9A-Fa-f]{6})$/', trim($hex), $match)) return [0.13, 0.36, 0.91];
        $value = $match[1];
        return [
            hexdec(substr($value, 0, 2)) / 255,
            hexdec(substr($value, 2, 2)) / 255,
            hexdec(substr($value, 4, 2)) / 255,
        ];
    }

    private static function n(float $number): string
    {
        return rtrim(rtrim(number_format($number, 3, '.', ''), '0'), '.');
    }
}
