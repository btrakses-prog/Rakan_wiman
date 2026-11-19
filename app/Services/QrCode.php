<?php
declare(strict_types=1);
namespace RakanWiman\Services;

use RuntimeException;

/** Minimal self-contained QR encoder (Version 4 / Error Correction L).
 * Suitable for short voucher credential payloads; no third-party service receives credentials.
 */
final class QrCode
{
    public static function svg(string $text, int $scale = 4, int $quietZone = 4): string
    {
        $bytes = array_values(unpack('C*', $text) ?: []);
        if (count($bytes) > 77) throw new RuntimeException('Teks QR terlalu panjang untuk format voucher.');

        $bits = [];
        self::appendBits($bits, 0b0100, 4); // Byte mode
        self::appendBits($bits, count($bytes), 8);
        foreach ($bytes as $byte) self::appendBits($bits, $byte, 8);
        $capacityBits = 80 * 8;
        for ($i = 0; $i < 4 && count($bits) < $capacityBits; $i++) $bits[] = 0;
        while (count($bits) % 8 !== 0) $bits[] = 0;

        $data = [];
        for ($i = 0; $i < count($bits); $i += 8) {
            $value = 0;
            for ($j = 0; $j < 8; $j++) $value = ($value << 1) | $bits[$i + $j];
            $data[] = $value;
        }
        $pad = 0;
        while (count($data) < 80) $data[] = (($pad++ % 2) === 0) ? 0xEC : 0x11;
        $ecc = self::reedSolomon($data, 20);
        $codewords = array_merge($data, $ecc);

        $size = 33; $matrix = array_fill(0, $size, array_fill(0, $size, false));
        $function = array_fill(0, $size, array_fill(0, $size, false));
        $setFunction = static function (int $x, int $y, bool $dark) use (&$matrix, &$function, $size): void {
            if ($x < 0 || $y < 0 || $x >= $size || $y >= $size) return;
            $matrix[$y][$x] = $dark; $function[$y][$x] = true;
        };

        // Finder patterns, including their one-module white separators.
        foreach ([[3,3],[29,3],[3,29]] as [$cx,$cy]) {
            for ($dy = -4; $dy <= 4; $dy++) for ($dx = -4; $dx <= 4; $dx++) {
                $dist = max(abs($dx), abs($dy));
                $setFunction($cx + $dx, $cy + $dy, $dist !== 2 && $dist !== 4);
            }
        }
        // Timing patterns.
        for ($i = 8; $i < $size - 8; $i++) {
            $setFunction($i, 6, ($i % 2) === 0);
            $setFunction(6, $i, ($i % 2) === 0);
        }
        // Version 4 has one non-overlapping alignment pattern at (26,26).
        for ($dy = -2; $dy <= 2; $dy++) for ($dx = -2; $dx <= 2; $dx++) {
            $dist = max(abs($dx), abs($dy));
            $setFunction(26 + $dx, 26 + $dy, $dist !== 1);
        }

        // Format information for error correction L and fixed mask pattern 0.
        $formatData = (0b01 << 3) | 0;
        $remainder = $formatData << 10;
        for ($i = 14; $i >= 10; $i--) if (($remainder & (1 << $i)) !== 0) $remainder ^= 0x537 << ($i - 10);
        $formatBits = (($formatData << 10) | $remainder) ^ 0x5412;
        $getFormatBit = static fn(int $i): bool => (($formatBits >> $i) & 1) !== 0;
        for ($i = 0; $i <= 5; $i++) $setFunction(8, $i, $getFormatBit($i));
        $setFunction(8, 7, $getFormatBit(6));
        $setFunction(8, 8, $getFormatBit(7));
        $setFunction(7, 8, $getFormatBit(8));
        for ($i = 9; $i < 15; $i++) $setFunction(14 - $i, 8, $getFormatBit($i));
        for ($i = 0; $i < 8; $i++) $setFunction($size - 1 - $i, 8, $getFormatBit($i));
        for ($i = 8; $i < 15; $i++) $setFunction(8, $size - 15 + $i, $getFormatBit($i));
        $setFunction(8, $size - 8, true); // Fixed dark module.

        // Place data in the standard two-column zig-zag, applying mask 0.
        $allBits = [];
        foreach ($codewords as $word) self::appendBits($allBits, $word, 8);
        $bitIndex = 0; $upward = true; $right = $size - 1;
        while ($right >= 1) {
            if ($right === 6) $right--;
            for ($vert = 0; $vert < $size; $vert++) {
                $y = $upward ? ($size - 1 - $vert) : $vert;
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    if ($function[$y][$x]) continue;
                    $bit = $bitIndex < count($allBits) ? $allBits[$bitIndex] : 0;
                    $bitIndex++;
                    if ((($x + $y) % 2) === 0) $bit ^= 1; // Mask 0.
                    $matrix[$y][$x] = ($bit !== 0);
                }
            }
            $right -= 2; $upward = !$upward;
        }

        $total = $size + ($quietZone * 2);
        $path = '';
        for ($y = 0; $y < $size; $y++) for ($x = 0; $x < $size; $x++) if ($matrix[$y][$x]) {
            $path .= 'M' . ($x + $quietZone) . ' ' . ($y + $quietZone) . 'h1v1h-1z';
        }
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $total . ' ' . $total . '" role="img" aria-label="QR voucher" shape-rendering="crispEdges" style="width:' . ($total * $scale) . 'px;height:' . ($total * $scale) . 'px;max-width:100%;background:#fff"><rect width="100%" height="100%" fill="#fff"/><path d="' . $path . '" fill="#000"/></svg>';
    }

    private static function appendBits(array &$bits, int $value, int $length): void
    {
        for ($i = $length - 1; $i >= 0; $i--) $bits[] = ($value >> $i) & 1;
    }

    private static function gfMultiply(int $x, int $y): int
    {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) {
            $z = (($z << 1) ^ (($z >> 7) * 0x11D)) & 0xFF;
            if ((($y >> $i) & 1) !== 0) $z ^= $x;
        }
        return $z;
    }

    private static function reedSolomon(array $data, int $degree): array
    {
        $generator = [1]; $root = 1;
        for ($i = 0; $i < $degree; $i++) {
            $next = array_fill(0, count($generator) + 1, 0);
            foreach ($generator as $j => $coefficient) {
                $next[$j] ^= $coefficient;
                $next[$j + 1] ^= self::gfMultiply($coefficient, $root);
            }
            $generator = $next;
            $root = self::gfMultiply($root, 2);
        }
        $remainder = array_merge($data, array_fill(0, $degree, 0));
        for ($i = 0; $i < count($data); $i++) {
            $factor = $remainder[$i];
            if ($factor === 0) continue;
            foreach ($generator as $j => $coefficient) $remainder[$i + $j] ^= self::gfMultiply($coefficient, $factor);
        }
        return array_slice($remainder, count($data));
    }
}
