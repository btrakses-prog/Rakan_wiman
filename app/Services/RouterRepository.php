<?php
declare(strict_types=1);
namespace RakanWiman\Services;
use PDO;
use RuntimeException;

final class RouterRepository
{
    public function __construct(private PDO $pdo) {}
    public function credentials(array $router): array
    {
        $key = getenv('APP_KEY') ?: '';
        if (strlen($key) < 32 || str_contains($key,'CHANGE_THIS')) throw new RuntimeException('APP_KEY belum dikonfigurasi dengan aman.');
        $raw = base64_decode($router['password_encrypted'], true);
        if ($raw === false || strlen($raw) < 29) throw new RuntimeException('Password router terenkripsi tidak valid.');
        $nonce = substr($raw,0,12); $tag = substr($raw,12,16); $cipher = substr($raw,28);
        $password = openssl_decrypt($cipher,'aes-256-gcm',hash('sha256',$key,true),OPENSSL_RAW_DATA,$nonce,$tag);
        if ($password === false) throw new RuntimeException('Gagal membuka password router. Periksa APP_KEY.');
        return [$router['host'],(int)$router['api_port'],$router['username'],$password,(bool)$router['api_ssl']];
    }
    public function connect(array $router, int $timeout = 10): RouterOS
    {
        [$host,$port,$user,$pass,$ssl] = $this->credentials($router);
        $api = new RouterOS($host,$port,$user,$pass,$ssl,max(1,min(30,$timeout))); $api->connect(); return $api;
    }
    public function get(int $id): array
    {
        $s=$this->pdo->prepare('SELECT * FROM routers WHERE id=? AND is_active=1'); $s->execute([$id]); $r=$s->fetch();
        if (!$r) throw new RuntimeException('Router tidak ditemukan atau tidak aktif.'); return $r;
    }
}
