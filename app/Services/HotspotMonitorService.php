<?php
declare(strict_types=1);
namespace RakanWiman\Services;

use PDO;
use Throwable;

/** Reads live HotSpot sessions from every active router. This data is never
 * persisted as voucher data; it is a live operational snapshot only.
 */
final class HotspotMonitorService
{
    public function __construct(
        private PDO $pdo,
        private RouterRepository $routers
    ) {}

    /**
     * @return array{
     *   total:int,router_total:int,router_ok:int,router_failed:int,complete:bool,
     *   checked_at:string,routers:array<int,array<string,mixed>>,
     *   sessions:array<int,array<string,mixed>>
     * }
     */
    public function snapshot(int $timeoutSeconds = 3): array
    {
        $timeoutSeconds = max(1, min(8, $timeoutSeconds));
        $routerRows = $this->pdo->query(
            'SELECT * FROM routers WHERE is_active=1 ORDER BY name,id'
        )->fetchAll(PDO::FETCH_ASSOC);

        $routers = [];
        $sessions = [];
        $total = 0;
        $routerOk = 0;
        $routerFailed = 0;

        $statusUpdate = $this->pdo->prepare(
            "UPDATE routers SET connection_status=?,last_seen_at=IF(?='online',NOW(),last_seen_at),last_error=? WHERE id=?"
        );

        foreach ($routerRows as $router) {
            $api = null;
            $connected = false;
            $routerItem = [
                'id' => (int)$router['id'],
                'name' => (string)$router['name'],
                'host' => (string)$router['host'],
                'count' => null,
                'ok' => false,
                'status' => 'offline',
                'error' => null,
            ];

            try {
                // Dashboard monitoring must not stall indefinitely if one remote
                // router is unreachable. The timeout is intentionally shorter
                // than the normal interactive router-management timeout.
                $api = $this->routers->connect($router, $timeoutSeconds);
                $connected = true;
                $rows = $api->command('/ip/hotspot/active/print', [
                    '.proplist' => '.id,user,address,mac-address,server,uptime,login-by,bytes-in,bytes-out',
                ]);
                $api->close();
                $api = null;

                $routerItem['count'] = count($rows);
                $routerItem['ok'] = true;
                $routerItem['status'] = 'online';
                $total += count($rows);
                $routerOk++;

                foreach ($rows as $row) {
                    $sessions[] = [
                        'router_id' => (int)$router['id'],
                        'router_name' => (string)$router['name'],
                        'user' => (string)($row['user'] ?? '-'),
                        'address' => (string)($row['address'] ?? '-'),
                        'mac_address' => (string)($row['mac-address'] ?? '-'),
                        'server' => (string)($row['server'] ?? '-'),
                        'uptime' => (string)($row['uptime'] ?? '-'),
                        'login_by' => (string)($row['login-by'] ?? '-'),
                        'bytes_in' => max(0, (int)($row['bytes-in'] ?? 0)),
                        'bytes_out' => max(0, (int)($row['bytes-out'] ?? 0)),
                    ];
                }
                try { $statusUpdate->execute(['online', 'online', null, (int)$router['id']]); } catch (Throwable $ignored) { /* Live count remains useful even if telemetry update fails. */ }
            } catch (Throwable $e) {
                if ($api instanceof RouterOS) $api->close();
                $routerFailed++;
                $routerItem['status'] = $connected ? 'error' : 'offline';
                $routerItem['error'] = mb_substr($e->getMessage(), 0, 350);
                try {
                    $statusUpdate->execute([
                        $routerItem['status'],
                        $routerItem['status'],
                        $routerItem['error'],
                        (int)$router['id'],
                    ]);
                } catch (Throwable $ignored) { /* Monitoring result is still returned to the dashboard. */ }
            }

            $routers[] = $routerItem;
        }

        return [
            'total' => $total,
            'router_total' => count($routerRows),
            'router_ok' => $routerOk,
            'router_failed' => $routerFailed,
            'complete' => $routerFailed === 0,
            'checked_at' => date('Y-m-d H:i:s'),
            'routers' => $routers,
            'sessions' => $sessions,
        ];
    }
}
