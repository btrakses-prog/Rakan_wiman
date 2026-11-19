<?php
declare(strict_types=1);
namespace RakanWiman\Services;
use PDO;
use Throwable;

final class RouterSyncWorker
{
    public function __construct(private PDO $pdo, private RouterRepository $routers) {}
    public function run(int $limit=100): array
    {
        $done=0; $failed=0;
        // Recover jobs left locked by a PHP/server crash.
        $this->pdo->exec("UPDATE router_sync_queue SET status='pending',locked_at=NULL WHERE status='processing' AND locked_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
        $sql="SELECT q.id AS queue_id,q.router_id AS queue_router_id,q.voucher_id,q.action,q.attempts,v.username,v.password_encrypted AS voucher_password_encrypted,v.public_id,v.usage_status,v.sync_status,COALESCE(v.mikrotik_profile_snapshot,p.mikrotik_profile) AS mikrotik_profile,r.host,r.api_port,r.username AS api_username,r.password_encrypted AS router_password_encrypted,r.api_ssl,COALESCE(go.hotspot_server,'all') AS hotspot_server,COALESCE(go.limit_uptime_seconds,v.limit_uptime_seconds_snapshot,p.limit_uptime_seconds) AS effective_limit_uptime_seconds,go.limit_bytes_total,go.generation_comment FROM router_sync_queue q JOIN vouchers v ON v.id=q.voucher_id JOIN packages p ON p.id=v.package_id LEFT JOIN voucher_generation_options go ON go.voucher_id=v.id JOIN routers r ON r.id=q.router_id WHERE r.is_active=1 AND q.status IN ('pending','failed') AND q.available_at<=NOW() ORDER BY q.id LIMIT ".max(1,min(500,$limit));
        $jobs=$this->pdo->query($sql)->fetchAll(); $connections=[];
        foreach($jobs as $job) {
            $lock=$this->pdo->prepare("UPDATE router_sync_queue SET status='processing',locked_at=NOW(),attempts=attempts+1 WHERE id=? AND status IN ('pending','failed')"); $lock->execute([$job['queue_id']]); if(!$lock->rowCount()) continue;
            try {
                $rid=(int)$job['queue_router_id']; if(!isset($connections[$rid])) $connections[$rid]=$this->routers->connect(['host'=>$job['host'],'api_port'=>$job['api_port'],'username'=>$job['api_username'],'password_encrypted'=>$job['router_password_encrypted'],'api_ssl'=>$job['api_ssl']]);
                $api=$connections[$rid]; $name=$job['username'];
                if($job['action']==='create') {
                    if($job['usage_status']==='expired') { $this->deleteUser($api,$name); }
                    else $this->ensureUser($api, $job);
                } elseif(in_array($job['action'],['delete','disable'],true)) $this->deleteUser($api,$name);
                elseif($job['action']==='reconcile') {
                    if($job['usage_status']==='expired') $this->deleteUser($api,$name);
                    else $this->ensureUser($api, $job);
                }
                $this->pdo->prepare("UPDATE router_sync_queue SET status='done',last_error=NULL,locked_at=NULL WHERE id=?")->execute([$job['queue_id']]);
                $this->pdo->prepare("UPDATE vouchers SET sync_status='synced' WHERE id=?")->execute([$job['voucher_id']]);
                $this->pdo->prepare("UPDATE routers SET connection_status='online',last_seen_at=NOW(),last_error=NULL WHERE id=?")->execute([$rid]); $done++;
            } catch(Throwable $e) {
                $delay=min(3600,30*(2 ** min(6,(int)$job['attempts'])));
                $this->pdo->prepare("UPDATE router_sync_queue SET status='failed',available_at=DATE_ADD(NOW(),INTERVAL ? SECOND),last_error=?,locked_at=NULL WHERE id=?")->execute([$delay,mb_substr($e->getMessage(),0,1000),$job['queue_id']]);
                $this->pdo->prepare("UPDATE vouchers SET sync_status='failed' WHERE id=? AND usage_status<>'expired'")->execute([$job['voucher_id']]);
                $this->pdo->prepare("UPDATE routers SET connection_status='offline',last_error=? WHERE id=?")->execute([mb_substr($e->getMessage(),0,450),(int)$job['queue_router_id']]); $failed++;
            }
        }
        foreach($connections as $api) $api->close();
        return compact('done','failed');
    }

    private function ensureUser(RouterOS $api, array $job): void
    {
        $name = (string)$job['username'];
        $existing = $api->command('/ip/hotspot/user/print', ['?name' => $name]);
        if ($existing) return;

        $profile = (string)$job['mikrotik_profile'];
        $profiles = $api->command('/ip/hotspot/user/profile/print', HotspotProfileService::routerPrintArgs($profile));
        if (!$profiles) throw new RuntimeException('Profil Hotspot tidak ditemukan di router: ' . $profile);

        $comment = 'RAKAN-WIMAN:' . $job['public_id'];
        if (!empty($job['generation_comment'])) $comment .= ' | ' . mb_substr((string)$job['generation_comment'], 0, 180);
        $args = [
            'name' => $name,
            'password' => VoucherService::decryptSecret((string)$job['voucher_password_encrypted']),
            'profile' => $profile,
            'comment' => $comment,
        ];
        $server = trim((string)($job['hotspot_server'] ?? 'all'));
        if ($server !== '') $args['server'] = $server;
        if (!empty($job['effective_limit_uptime_seconds'])) {
            $args['limit-uptime'] = VoucherService::toRouterDuration((int)$job['effective_limit_uptime_seconds']);
        }
        if (!empty($job['limit_bytes_total'])) $args['limit-bytes-total'] = (string)$job['limit_bytes_total'];
        $api->command('/ip/hotspot/user/add', $args);
    }

    private function deleteUser(RouterOS $api,string $name): void
    {
        $active=$api->command('/ip/hotspot/active/print',['?user'=>$name]);
        foreach($active as $session) if(isset($session['.id'])) $api->command('/ip/hotspot/active/remove',['.id'=>$session['.id']]);
        $users=$api->command('/ip/hotspot/user/print',['?name'=>$name]);
        foreach($users as $user) if(isset($user['.id'])) $api->command('/ip/hotspot/user/remove',['.id'=>$user['.id']]);
    }
}
