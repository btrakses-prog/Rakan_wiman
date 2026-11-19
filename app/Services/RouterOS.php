<?php
declare(strict_types=1);
namespace RakanWiman\Services;

use RuntimeException;

/**
 * RouterOS API client for RouterOS v6 and v7.
 * Handles modern login (6.43+) and legacy challenge-response login.
 */
final class RouterOS
{
    /** @var resource|null */
    private $socket = null;
    private string $phase = 'koneksi';
    private bool $authenticated = false;

    public function __construct(
        private string $host,
        private int $port,
        private string $username,
        private string $password,
        private bool $ssl = false,
        private int $timeout = 10
    ) {}

    public function connect(): void
    {
        if (is_resource($this->socket)) return;

        $scheme = $this->ssl ? 'tls://' : 'tcp://';
        $context = stream_context_create($this->ssl ? [
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ],
        ] : []);

        $this->phase = 'membuka koneksi TCP';
        $this->socket = @stream_socket_client(
            $scheme . $this->host . ':' . $this->port,
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if (!is_resource($this->socket)) {
            throw new RuntimeException("Koneksi router gagal: {$errstr} ({$errno})");
        }

        stream_set_blocking($this->socket, true);
        stream_set_timeout($this->socket, $this->timeout);

        try {
            $this->phase = 'autentikasi API (login awal)';
            $reply = $this->request([
                '/login',
                '=name=' . $this->username,
                '=password=' . $this->password,
            ]);

            $done = $this->findSentence($reply, '!done');
            $this->throwIfRouterError($reply, 'login awal');

            // RouterOS <= 6.42 returns a challenge in !done ret=<32 hex chars>.
            // RouterOS >= 6.43 returns !done without ret when login is complete.
            $challenge = is_array($done) ? (string)($done['ret'] ?? '') : '';
            if ($challenge !== '') {
                if (!preg_match('/^[a-fA-F0-9]{32}$/', $challenge)) {
                    throw new RuntimeException('RouterOS mengirim challenge login dengan format yang tidak dikenal.');
                }

                $this->phase = 'autentikasi API (challenge-response)';
                $response = '00' . md5("\x00" . $this->password . pack('H*', $challenge));
                $legacyReply = $this->request([
                    '/login',
                    '=name=' . $this->username,
                    '=response=' . $response,
                ]);
                $this->throwIfRouterError($legacyReply, 'challenge-response');
                if ($this->findSentence($legacyReply, '!done') === null) {
                    throw new RuntimeException('RouterOS tidak menyelesaikan autentikasi challenge-response.');
                }
            } elseif ($done === null) {
                throw new RuntimeException('RouterOS tidak mengirim balasan !done saat login.');
            }

            $this->authenticated = true;
            $this->phase = 'siap';
        } catch (\Throwable $e) {
            $this->close();
            throw $e;
        }
    }

    public function close(): void
    {
        if (is_resource($this->socket)) @fclose($this->socket);
        $this->socket = null;
        $this->authenticated = false;
    }

    public function __destruct() { $this->close(); }

    public function command(string $command, array $args = []): array
    {
        if (!is_resource($this->socket) || !$this->authenticated) {
            throw new RuntimeException('RouterOS API belum terhubung atau belum terautentikasi.');
        }

        $words = [$command];
        foreach ($args as $key => $value) {
            if ($value === null) continue;
            $key = (string)$key;
            $words[] = str_starts_with($key, '?') || str_starts_with($key, '~')
                ? $key . '=' . (string)$value
                : '=' . $key . '=' . (string)$value;
        }

        $this->phase = 'menjalankan perintah ' . $command;
        $reply = $this->request($words);
        $this->throwIfRouterError($reply, $command);

        $rows = [];
        foreach ($reply as $sentence) {
            if (($sentence['type'] ?? '') === '!re') {
                unset($sentence['type']);
                $rows[] = $sentence;
            }
        }
        $this->phase = 'siap';
        return $rows;
    }

    /** Send one API sentence and collect all response sentences through !done/!fatal. */
    private function request(array $words): array
    {
        foreach ($words as $word) $this->writeWord((string)$word);
        $this->writeWord('');

        $sentences = [];
        while (true) {
            $sentence = $this->readSentence();
            if ($sentence === null) {
                if ($sentences && (($sentences[count($sentences) - 1]['type'] ?? '') === '!trap')) break;
                throw new RuntimeException('RouterOS menutup koneksi sebelum respons selesai pada fase ' . $this->phase . '.');
            }
            $sentences[] = $sentence;
            $type = $sentence['type'] ?? '';
            // RouterOS 7.18+ may emit !empty for a no-data reply, but !done is still the
            // final sentence for the command. Continue reading until !done/!fatal.
            if ($type === '!done' || $type === '!fatal') break;
        }
        return $sentences;
    }

    /** Read a single sentence (words terminated by a zero-length word). */
    private function readSentence(): ?array
    {
        $sentence = [];
        while (true) {
            $word = $this->readWord();
            if ($word === null) {
                if (!$sentence) return null;
                throw new RuntimeException('Respons RouterOS terputus di tengah kalimat pada fase ' . $this->phase . '.');
            }
            if ($word === '') break;

            if ($word[0] === '!') {
                $sentence['type'] = $word;
                continue;
            }
            if ($word[0] === '=') {
                $separator = strpos($word, '=', 1);
                if ($separator !== false) {
                    $key = substr($word, 1, $separator - 1);
                    $sentence[$key] = substr($word, $separator + 1);
                }
                continue;
            }
            if (str_starts_with($word, '.tag=')) {
                $sentence['.tag'] = substr($word, 5);
            }
        }
        return $sentence;
    }

    private function findSentence(array $sentences, string $type): ?array
    {
        foreach ($sentences as $sentence) {
            if (($sentence['type'] ?? '') === $type) return $sentence;
        }
        return null;
    }

    private function throwIfRouterError(array $sentences, string $operation): void
    {
        foreach ($sentences as $sentence) {
            $type = $sentence['type'] ?? '';
            if ($type === '!trap' || $type === '!fatal') {
                $message = trim((string)($sentence['message'] ?? ''));
                $category = trim((string)($sentence['category'] ?? ''));
                $detail = $message !== '' ? $message : 'RouterOS mengembalikan ' . $type;
                if ($category !== '') $detail .= ' (kategori ' . $category . ')';
                throw new RuntimeException('RouterOS menolak ' . $operation . ': ' . $detail);
            }
        }
    }

    private function writeWord(string $word): void
    {
        $length = strlen($word);
        if ($length > 0x0fffffff) throw new RuntimeException('Kata API terlalu panjang.');

        if ($length < 0x80) {
            $prefix = chr($length);
        } elseif ($length < 0x4000) {
            $prefix = chr(($length >> 8) | 0x80) . chr($length & 0xff);
        } elseif ($length < 0x200000) {
            $prefix = chr(($length >> 16) | 0xc0) . chr(($length >> 8) & 0xff) . chr($length & 0xff);
        } elseif ($length < 0x10000000) {
            $prefix = chr(($length >> 24) | 0xe0) . chr(($length >> 16) & 0xff) . chr(($length >> 8) & 0xff) . chr($length & 0xff);
        } else {
            $prefix = "\xf0" . pack('N', $length);
        }

        $data = $prefix . $word;
        $offset = 0;
        $total = strlen($data);
        while ($offset < $total) {
            $written = @fwrite($this->socket, substr($data, $offset));
            if ($written === false || $written === 0) {
                $meta = stream_get_meta_data($this->socket);
                if (!empty($meta['timed_out'])) throw new RuntimeException('Timeout saat mengirim perintah ke RouterOS.');
                throw new RuntimeException('Koneksi terputus saat mengirim perintah ke RouterOS.');
            }
            $offset += $written;
        }
    }

    private function readWord(): ?string
    {
        $first = $this->readBytes(1);
        if ($first === null) return null;
        $byte = ord($first);

        if (($byte & 0x80) === 0) {
            $length = $byte;
        } elseif (($byte & 0xc0) === 0x80) {
            $rest = $this->readRequiredBytes(1);
            $length = (($byte & 0x3f) << 8) | ord($rest);
        } elseif (($byte & 0xe0) === 0xc0) {
            $rest = unpack('n', $this->readRequiredBytes(2))[1];
            $length = (($byte & 0x1f) << 16) | $rest;
        } elseif (($byte & 0xf0) === 0xe0) {
            $next = ord($this->readRequiredBytes(1));
            $rest = unpack('n', $this->readRequiredBytes(2))[1];
            $length = (($byte & 0x0f) << 24) | ($next << 16) | $rest;
        } elseif ($byte === 0xf0) {
            $length = unpack('N', $this->readRequiredBytes(4))[1];
        } else {
            throw new RuntimeException('Prefix panjang data RouterOS tidak valid: 0x' . strtoupper(bin2hex($first)) . '.');
        }

        if ($length > 16 * 1024 * 1024) throw new RuntimeException('Respons RouterOS melebihi batas ukuran 16 MB.');
        return $length === 0 ? '' : $this->readRequiredBytes($length);
    }

    private function readRequiredBytes(int $length): string
    {
        $data = $this->readBytes($length);
        if ($data === null) throw new RuntimeException('Koneksi RouterOS terputus saat membaca data pada fase ' . $this->phase . '.');
        return $data;
    }

    private function readBytes(int $length): ?string
    {
        $data = '';
        while (strlen($data) < $length) {
            $remaining = $length - strlen($data);
            $chunk = @fread($this->socket, $remaining);
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($this->socket);
                if (!empty($meta['timed_out'])) {
                    throw new RuntimeException('Timeout saat membaca respons RouterOS pada fase ' . $this->phase . '.');
                }
                if (feof($this->socket)) return $data === '' ? null : null;
                // A blocking stream may transiently return an empty read; retry until timeout/EOF.
                continue;
            }
            $data .= $chunk;
        }
        return $data;
    }
}
