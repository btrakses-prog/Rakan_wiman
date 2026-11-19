<?php
declare(strict_types=1);
namespace RakanWiman\Services;

use PDO;
use Throwable;

final class HotspotProfileSyncWorker
{
    public function __construct(private PDO $pdo, private HotspotProfileService $profiles) {}

    public function run(int $limit = 50): array
    {
        $this->pdo->exec("UPDATE hotspot_profile_sync_queue SET status='pending',locked_at=NULL WHERE status='processing' AND locked_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
        $jobs = $this->pdo->query("SELECT q.id,q.profile_id,q.router_id,q.attempts FROM hotspot_profile_sync_queue q JOIN routers r ON r.id=q.router_id JOIN hotspot_profiles p ON p.id=q.profile_id WHERE r.is_active=1 AND p.is_active=1 AND p.is_managed=1 AND q.status IN ('pending','failed') AND q.available_at<=NOW() ORDER BY q.id LIMIT " . max(1,min(250,$limit)))->fetchAll();
        $done = 0; $failed = 0;
        foreach ($jobs as $job) {
            $lock = $this->pdo->prepare("UPDATE hotspot_profile_sync_queue SET status='processing',locked_at=NOW(),attempts=attempts+1 WHERE id=? AND status IN ('pending','failed')");
            $lock->execute([(int)$job['id']]);
            if (!$lock->rowCount()) continue;
            try {
                $this->profiles->syncOne((int)$job['router_id'], (int)$job['profile_id']);
                $this->pdo->prepare("UPDATE hotspot_profile_sync_queue SET status='done',locked_at=NULL,last_error=NULL WHERE id=?")->execute([(int)$job['id']]);
                $done++;
            } catch (Throwable $e) {
                $attempt = (int)$job['attempts'] + 1;
                $delay = min(3600, 30 * (2 ** min(6,$attempt)));
                $message = mb_substr($e->getMessage(),0,1500);
                $this->pdo->prepare("UPDATE hotspot_profile_sync_queue SET status='failed',available_at=DATE_ADD(NOW(),INTERVAL ? SECOND),locked_at=NULL,last_error=? WHERE id=?")->execute([$delay,$message,(int)$job['id']]);
                $failed++;
            }
        }
        return compact('done','failed');
    }
}
