<?php
declare(strict_types=1);

namespace RakanWiman\Services;

use RuntimeException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;

/** Builds the original RAKAN WIMAN MikroTik HotSpot ZIP without ext-zip. */
final class HotspotTemplateExporter
{
    public static function build(string $sourceDirectory, string $purchaseUrl, string $scannerUrl): string
    {
        $root = realpath($sourceDirectory);
        if ($root === false || !is_dir($root)) {
            throw new RuntimeException('Folder template HotSpot RAKAN WIMAN tidak ditemukan.');
        }
        foreach (['portal pembelian' => $purchaseUrl, 'pemindai QR' => $scannerUrl] as $label => $candidateUrl) {
            if (!filter_var($candidateUrl, FILTER_VALIDATE_URL) || strtolower((string)parse_url($candidateUrl, PHP_URL_SCHEME)) !== 'https') {
                throw new RuntimeException('URL ' . $label . ' harus berupa alamat HTTPS yang valid.');
            }
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $item) {
            if (!$item->isFile() || $item->isLink()) continue;
            $absolute = $item->getPathname();
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($absolute, strlen($root) + 1));
            if ($relative === '') continue;
            $contents = file_get_contents($absolute);
            if ($contents === false) throw new RuntimeException('Gagal membaca file template: ' . $relative);
            if (in_array($relative, ['login.html', 'flogin.html'], true)) {
                $contents = str_replace('__RW_PURCHASE_URL__', htmlspecialchars($purchaseUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $contents, $purchaseCount);
                $contents = str_replace('__RW_SCANNER_URL__', htmlspecialchars($scannerUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $contents, $scannerCount);
                if ($purchaseCount !== 1 || $scannerCount !== 1) {
                    throw new RuntimeException('Titik tautan portal pembelian atau pemindai QR pada ' . $relative . ' tidak lengkap.');
                }
            }
            $files['RAKAN-WIMAN-Hotspot/' . $relative] = $contents;
        }
        if (!$files) throw new RuntimeException('Tidak ada file template yang dapat dikemas.');
        ksort($files);
        return self::createZip($files);
    }

    /** @param array<string,string> $files */
    private static function createZip(array $files): string
    {
        $local = '';
        $central = '';
        $offset = 0;
        $count = 0;
        $dosTime = (int)(date('H') << 11) | (int)(date('i') << 5) | (int)(date('s') / 2);
        $dosDate = ((int)date('Y') - 1980) << 9 | ((int)date('n')) << 5 | (int)date('j');

        foreach ($files as $name => $contents) {
            $name = str_replace('\\', '/', $name);
            $nameLength = strlen($name);
            $data = (string)$contents;
            $crc = (int)hexdec(hash('crc32b', $data));
            $compressed = function_exists('gzdeflate') ? gzdeflate($data, 6) : false;
            $method = $compressed === false ? 0 : 8;
            if ($compressed === false) $compressed = $data;
            $compressedSize = strlen($compressed);
            $originalSize = strlen($data);
            if ($compressedSize > 0xffffffff || $originalSize > 0xffffffff || $offset > 0xffffffff) {
                throw new RuntimeException('Ukuran template melebihi batas ZIP klasik.');
            }
            $flags = 0x0800; // UTF-8 filenames
            $localHeader = pack('VvvvvvVVVvv', 0x04034b50, 20, $flags, $method, $dosTime, $dosDate, $crc, $compressedSize, $originalSize, $nameLength, 0) . $name;
            $local .= $localHeader . $compressed;

            $central .= pack(
                'VvvvvvvVVVvvvvvVV',
                0x02014b50, 20, 20, $flags, $method, $dosTime, $dosDate,
                $crc, $compressedSize, $originalSize, $nameLength, 0, 0, 0, 0, 0, $offset
            ) . $name;
            $offset += strlen($localHeader) + $compressedSize;
            $count++;
        }

        $centralOffset = strlen($local);
        $centralSize = strlen($central);
        $end = pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, $centralSize, $centralOffset, 0);
        return $local . $central . $end;
    }
}
