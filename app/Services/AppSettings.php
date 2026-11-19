<?php
declare(strict_types=1);
namespace RakanWiman\Services;
use PDO;
final class AppSettings {
 public function __construct(private PDO $pdo){}
 public function get(string $key, ?string $default=null): ?string { $s=$this->pdo->prepare('SELECT setting_value FROM settings WHERE setting_key=?');$s->execute([$key]);$v=$s->fetchColumn();return $v===false?$default:(string)$v; }
 public function set(string $key, ?string $value): void { $s=$this->pdo->prepare('INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');$s->execute([$key,$value]); }
 public function setSecret(string $key,string $value): void { $this->set($key,$value===''?'':VoucherService::encryptSecret($value)); }
 public function getSecret(string $key): string { $v=$this->get($key,'')??'';return $v===''?'':VoucherService::decryptSecret($v); }
}
