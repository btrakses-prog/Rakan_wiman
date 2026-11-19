<?php
declare(strict_types=1);
namespace RakanWiman\Services;
use PDO;
use Throwable;

final class ExpiryService
{
    public function __construct(private PDO $pdo, private RouterRepository $routers) {}
    public function run(): array
    {
        $expired=0; $firstLogins=0; $errors=0;
        // Calendar expiry is knowable from MySQL even when a router is offline.
        $calendar=$this->pdo->query("SELECT v.id,v.router_id,COALESCE(r.is_active,0) AS router_is_active FROM vouchers v JOIN packages p ON p.id=v.package_id LEFT JOIN routers r ON r.id=v.router_id WHERE v.usage_status IN ('unused','active') AND v.expires_at IS NOT NULL AND NOW() >= v.expires_at")->fetchAll();
        foreach($calendar as $v) {
            $this->pdo->beginTransaction();
            try {
                $this->pdo->prepare("UPDATE vouchers SET usage_status='expired',expired_at=NOW(),notes=CONCAT(COALESCE(notes,''), ?) WHERE id=? AND usage_status<>'expired'")->execute(["\n[".date('Y-m-d H:i:s')."] Masa berlaku kalender habis",$v['id']]);
                if($v['router_id'] && (int)$v['router_is_active'] === 1) { $q=$this->pdo->prepare("INSERT INTO router_sync_queue (router_id,voucher_id,action,status) VALUES (?,?, 'delete','pending') ON DUPLICATE KEY UPDATE status=IF(status='done','pending',status),available_at=NOW(),last_error=NULL"); $q->execute([$v['router_id'],$v['id']]); }
                $this->pdo->commit(); $expired++;
            } catch(Throwable $e) { $this->pdo->rollBack(); throw $e; }
        }
        $routers=$this->pdo->query("SELECT * FROM routers WHERE is_active=1")->fetchAll();
        foreach($routers as $router) {
            try {
                $api=$this->routers->connect($router);
                $users=$api->command('/ip/hotspot/user/print'); $active=$api->command('/ip/hotspot/active/print');
                $activeNames=[]; foreach($active as $a) if(!empty($a['user'])) $activeNames[$a['user']]=true;
                $byName=[]; foreach($users as $u) if(isset($u['name'])) $byName[$u['name']]=$u;
                $stmt=$this->pdo->prepare("SELECT v.*,COALESCE(go.limit_uptime_seconds,v.limit_uptime_seconds_snapshot,p.limit_uptime_seconds) AS effective_limit_uptime_seconds,go.limit_bytes_total,COALESCE(v.validity_days_snapshot,p.validity_days) AS validity_days FROM vouchers v JOIN packages p ON p.id=v.package_id LEFT JOIN voucher_generation_options go ON go.voucher_id=v.id WHERE v.router_id=? AND v.sync_status='synced' AND v.usage_status IN ('unused','active')");
                $stmt->execute([$router['id']]);
                foreach($stmt->fetchAll() as $v) {
                    $name=$v['username']; $user=$byName[$name]??null; $isActive=isset($activeNames[$name]);
                    $used=$isActive || ($user && self::routerTimeToSeconds((string)($user['uptime']??'0s'))>0);
                    if($used && empty($v['first_login_at'])) {
                        $firstLogin=new \DateTimeImmutable(); $expires=$v['validity_days'] ? $firstLogin->modify('+'.(int)$v['validity_days'].' days')->format('Y-m-d H:i:s') : null;
                        $this->pdo->prepare("UPDATE vouchers SET first_login_at=?,expires_at=?,usage_status='active' WHERE id=? AND first_login_at IS NULL")->execute([$firstLogin->format('Y-m-d H:i:s'),$expires,$v['id']]); $v['first_login_at']=$firstLogin->format('Y-m-d H:i:s'); $v['usage_status']='active'; $firstLogins++;
                    } elseif($used && $v['usage_status']==='unused') {
                        $this->pdo->prepare("UPDATE vouchers SET usage_status='active' WHERE id=?")->execute([$v['id']]);
                    }
                    $reason=null;
                    if($v['validity_days'] && $v['first_login_at']) {
                        $expiry=(new \DateTimeImmutable($v['first_login_at']))->modify('+'.(int)$v['validity_days'].' days');
                        if($expiry <= new \DateTimeImmutable()) $reason='Masa berlaku kalender habis';
                    }
                    if(!$reason && $v['effective_limit_uptime_seconds'] && $user && isset($user['uptime'])) {
                        if(self::routerTimeToSeconds($user['uptime']) >= (int)$v['effective_limit_uptime_seconds']) $reason='Batas akumulasi uptime habis';
                    }
                    if(!$reason && !empty($v['limit_bytes_total']) && $user) {
                        $usedBytes = (int)($user['bytes-in'] ?? 0) + (int)($user['bytes-out'] ?? 0);
                        if($usedBytes >= (int)$v['limit_bytes_total']) $reason='Batas kuota data habis';
                    }
                    if($reason) {
                        $this->pdo->beginTransaction();
                        try {
                            $this->pdo->prepare("UPDATE vouchers SET usage_status='expired',expired_at=NOW(),notes=CONCAT(COALESCE(notes,''), ?) WHERE id=? AND usage_status<>'expired'")->execute(["\n[".date('Y-m-d H:i:s')."] ".$reason,$v['id']]);
                            $q=$this->pdo->prepare("INSERT INTO router_sync_queue (router_id,voucher_id,action,status) VALUES (?,?, 'delete','pending') ON DUPLICATE KEY UPDATE status=IF(status='done','pending',status),available_at=NOW(),last_error=NULL"); $q->execute([$router['id'],$v['id']]);
                            $this->pdo->commit(); $expired++;
                        } catch(Throwable $e) { $this->pdo->rollBack(); throw $e; }
                    }
                }
                $api->close();
                $this->pdo->prepare("UPDATE routers SET connection_status='online',last_seen_at=NOW(),last_error=NULL WHERE id=?")->execute([$router['id']]);
            } catch(Throwable $e) {
                $errors++; $this->pdo->prepare("UPDATE routers SET connection_status='offline',last_error=? WHERE id=?")->execute([mb_substr($e->getMessage(),0,450),$router['id']]);
            }
        }
        return compact('expired','firstLogins','errors');
    }
    public static function routerTimeToSeconds(string $time): int
    {
        if(preg_match('/^(?:(\d+)w)?(?:(\d+)d)?(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s)?$/',$time,$m)) return ((int)($m[1]??0)*604800)+((int)($m[2]??0)*86400)+((int)($m[3]??0)*3600)+((int)($m[4]??0)*60)+(int)($m[5]??0);
        if(preg_match('/^(\d+):(\d{2}):(\d{2})$/',$time,$m)) return (int)$m[1]*3600+(int)$m[2]*60+(int)$m[3];
        return 0;
    }
}
