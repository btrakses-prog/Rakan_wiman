<?php
declare(strict_types=1);
namespace RakanWiman\Services;

use PDO;
use RuntimeException;
use Throwable;

final class HotspotProfileService
{
    private const ROUTER_PROPLIST = '.id,name,shared-users,rate-limit,idle-timeout,keepalive-timeout,status-autorefresh,add-mac-cookie,mac-cookie-timeout,address-pool,address-list,transparent-proxy';

    private const FIELD_MAP = [
        'shared_users' => 'shared-users',
        'rate_limit' => 'rate-limit',
        'idle_timeout' => 'idle-timeout',
        'keepalive_timeout' => 'keepalive-timeout',
        'status_autorefresh' => 'status-autorefresh',
        'add_mac_cookie' => 'add-mac-cookie',
        'mac_cookie_timeout' => 'mac-cookie-timeout',
        'address_pool' => 'address-pool',
        'address_list' => 'address-list',
        'transparent_proxy' => 'transparent-proxy',
    ];

    public function __construct(private PDO $pdo, private RouterRepository $routers) {}

    /** Minimal property list for HotSpot profile reads; avoids expensive default fields. */
    public static function routerPrintArgs(?string $profileName = null): array
    {
        $args = ['.proplist' => self::ROUTER_PROPLIST];
        if ($profileName !== null) $args['?name'] = $profileName;
        return $args;
    }

    public static function validateInput(array $input): array
    {
        $name = trim((string)($input['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120 || preg_match('/[\x00-\x1F\x7F]/', $name)) {
            throw new RuntimeException('Nama profil wajib diisi, maksimal 120 karakter, dan tidak boleh berisi karakter kontrol.');
        }

        $shared = filter_var($input['shared_users'] ?? 1, FILTER_VALIDATE_INT);
        if ($shared === false || $shared < 1 || $shared > 1000) {
            throw new RuntimeException('Shared Users harus berupa angka antara 1 sampai 1000.');
        }

        $rateLimit = trim((string)($input['rate_limit'] ?? ''));
        if (mb_strlen($rateLimit) > 120 || preg_match('/[\x00-\x1F\x7F]/', $rateLimit)) {
            throw new RuntimeException('Rate Limit tidak valid atau terlalu panjang.');
        }
        if ($rateLimit !== '' && !preg_match('/^[0-9A-Za-z.\/,: _-]+$/', $rateLimit)) {
            throw new RuntimeException('Format Rate Limit tidak valid. Contoh: 5M/2M atau 512k/1M.');
        }

        $durationFields = [
            'idle_timeout' => 'Idle Timeout',
            'keepalive_timeout' => 'Keepalive Timeout',
            'status_autorefresh' => 'Status Auto Refresh',
            'mac_cookie_timeout' => 'MAC Cookie Timeout',
        ];
        $durations = [];
        foreach ($durationFields as $field => $label) {
            $value = strtolower(trim((string)($input[$field] ?? 'none')));
            if ($value === '') $value = 'none';
            if ($value !== 'none' && !preg_match('/^(?=\d)(?:\d+w)?(?:\d+d)?(?:\d+h)?(?:\d+m)?(?:\d+s)?$/', $value)) {
                throw new RuntimeException($label . ' tidak valid. Gunakan contoh 5m, 1h, 1d atau none.');
            }
            $durations[$field] = $value;
        }

        $textFields = ['address_pool' => 'Address Pool', 'address_list' => 'Address List'];
        $textValues = [];
        foreach ($textFields as $field => $label) {
            $value = trim((string)($input[$field] ?? ''));
            if (mb_strlen($value) > 120 || preg_match('/[\x00-\x1F\x7F]/', $value)) {
                throw new RuntimeException($label . ' maksimal 120 karakter dan tidak boleh berisi karakter kontrol.');
            }
            $textValues[$field] = $field === 'address_pool' && $value === '' ? 'none' : $value;
        }

        return [
            'name' => $name,
            'shared_users' => (int)$shared,
            'rate_limit' => $rateLimit,
            'idle_timeout' => $durations['idle_timeout'],
            'keepalive_timeout' => $durations['keepalive_timeout'],
            'status_autorefresh' => $durations['status_autorefresh'],
            'add_mac_cookie' => isset($input['add_mac_cookie']) ? 1 : 0,
            'mac_cookie_timeout' => $durations['mac_cookie_timeout'],
            'address_pool' => $textValues['address_pool'],
            'address_list' => $textValues['address_list'],
            'transparent_proxy' => isset($input['transparent_proxy']) ? 1 : 0,
        ];
    }

    public static function routerFields(array $profile): array
    {
        $values = [];
        foreach (self::FIELD_MAP as $db => $router) {
            $value = $profile[$db] ?? null;
            if ($db === 'shared_users') $value = (string)max(1, (int)$value);
            elseif (in_array($db, ['add_mac_cookie','transparent_proxy'], true)) $value = (int)$value === 1 ? 'yes' : 'no';
            elseif ($value === null) $value = '';
            $values[$router] = (string)$value;
        }
        return $values;
    }

    public static function profileHash(array $profile): string
    {
        $data = ['name' => (string)$profile['name']] + self::routerFields($profile);
        ksort($data);
        return hash('sha256', json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
    }

    public function refreshRouter(int $routerId): array
    {
        $router = $this->routers->get($routerId);
        $api = $this->routers->connect($router);
        try {
            $remote = $api->command('/ip/hotspot/user/profile/print', self::routerPrintArgs());
            $api->close();
        } catch (Throwable $e) {
            $api->close();
            $this->markRouterError($routerId, $e->getMessage());
            throw $e;
        }

        $identityName = trim((string)($router['identity'] ?? ''));
        return $this->recordRouterProfiles($routerId, $remote, $identityName !== '' ? $identityName : null);
    }

    public function recordRouterProfiles(int $routerId, array $remote, ?string $identityName = null): array
    {
        $remoteByName = [];
        foreach ($remote as $row) {
            if (isset($row['name'])) $remoteByName[(string)$row['name']] = $row;
        }
        $profiles = $this->pdo->query('SELECT * FROM hotspot_profiles WHERE is_active=1 ORDER BY name')->fetchAll();
        $dbNames = [];
        $counts = ['synced'=>0,'missing'=>0,'different'=>0,'legacy_present'=>0,'legacy_missing'=>0];
        foreach ($profiles as $profile) {
            $name = (string)$profile['name'];
            $dbNames[$name] = true;
            $remoteRow = $remoteByName[$name] ?? null;
            $remoteHash = $remoteRow ? $this->remoteHash($remoteRow) : null;
            if ((int)$profile['is_managed'] !== 1) {
                $status = $remoteRow ? 'legacy_present' : 'legacy_missing';
                $lastError = 'Profil lama belum dikelola oleh master data RAKAN WIMAN.';
            } elseif (!$remoteRow) {
                $status = 'missing';
                $lastError = 'Profil belum ditemukan di MikroTik.';
            } else {
                $status = $this->matches($profile, $remoteRow) ? 'synced' : 'different';
                $lastError = $status === 'different' ? 'Konfigurasi MikroTik berbeda dari master data RAKAN WIMAN.' : null;
            }
            $this->saveStatus((int)$profile['id'], $routerId, $status, $remoteHash, $lastError, false);
            $counts[$status]++;
        }

        $routerOnly = [];
        foreach ($remoteByName as $name => $row) {
            if (!isset($dbNames[$name])) $routerOnly[] = ['name'=>$name,'shared_users'=>$row['shared-users'] ?? '','rate_limit'=>$row['rate-limit'] ?? ''];
        }

        $identityName = (string)($identityName ?? '');
        if ($identityName !== '') {
            $this->pdo->prepare("UPDATE routers SET identity=?,connection_status='online',last_seen_at=NOW(),last_error=NULL WHERE id=?")->execute([$identityName,$routerId]);
        } else {
            $this->pdo->prepare("UPDATE routers SET connection_status='online',last_seen_at=NOW(),last_error=NULL WHERE id=?")->execute([$routerId]);
        }
        return ['counts'=>$counts,'router_only'=>$routerOnly,'remote_count'=>count($remoteByName),'identity'=>$identityName];
    }

    public function syncOne(int $routerId, int $profileId): void
    {
        $q = $this->pdo->prepare('SELECT * FROM hotspot_profiles WHERE id=? AND is_active=1 AND is_managed=1');
        $q->execute([$profileId]);
        $profile = $q->fetch();
        if (!$profile) throw new RuntimeException('Profil tidak ditemukan, nonaktif, atau belum dikelola master data.');
        $router = $this->routers->get($routerId);
        $api = $this->routers->connect($router);
        try {
            // Query only the target profile and only fields managed by RAKAN WIMAN.
            // RouterOS may return !empty when the profile does not exist; RouterOS client
            // treats that response as a completed empty result, not a socket timeout.
            $remoteProfiles = $api->command('/ip/hotspot/user/profile/print', self::routerPrintArgs((string)$profile['name']));
            $remoteRow = $remoteProfiles[0] ?? null;

            $desired = self::routerFields($profile);
            if ($remoteRow === null) {
                $api->command('/ip/hotspot/user/profile/add', ['name'=>(string)$profile['name']] + $desired);
            } else {
                $changed = [];
                foreach ($desired as $field => $value) {
                    if ($this->normalise($field, $remoteRow[$field] ?? null) !== $this->normalise($field, $value)) $changed[$field] = $value;
                }
                if ($changed) {
                    if (empty($remoteRow['.id'])) throw new RuntimeException('Profil ditemukan, tetapi RouterOS tidak mengembalikan .id untuk pembaruan.');
                    $api->command('/ip/hotspot/user/profile/set', ['.id'=>(string)$remoteRow['.id']] + $changed);
                }
            }

            $verifyRows = $api->command('/ip/hotspot/user/profile/print', self::routerPrintArgs((string)$profile['name']));
            $verify = $verifyRows[0] ?? null;
            if (!$verify || !$this->matches($profile, $verify)) {
                throw new RuntimeException('Sinkronisasi dikirim, tetapi verifikasi konfigurasi profil di MikroTik belum cocok.');
            }
            $api->close();
            $this->saveStatus($profileId, $routerId, 'synced', $this->remoteHash($verify), null, true);
            $this->pdo->prepare("UPDATE routers SET connection_status='online',last_seen_at=NOW(),last_error=NULL WHERE id=?")->execute([$routerId]);
        } catch (Throwable $e) {
            $api->close();
            $this->saveStatus($profileId, $routerId, 'failed', null, mb_substr($e->getMessage(),0,1500), false);
            $this->markRouterError($routerId, $e->getMessage());
            throw $e;
        }
    }

    public function enqueueForRouters(int $profileId, array $routerIds): int
    {
        $count = 0;
        $stmt = $this->pdo->prepare("INSERT INTO hotspot_profile_sync_queue (profile_id,router_id,status,attempts,available_at,last_error,locked_at) VALUES (?,?,'pending',0,NOW(),NULL,NULL) ON DUPLICATE KEY UPDATE status='pending',attempts=0,available_at=NOW(),last_error=NULL,locked_at=NULL");
        foreach (array_unique(array_map('intval',$routerIds)) as $routerId) {
            if ($routerId < 1) continue;
            $stmt->execute([$profileId,$routerId]);
            $count++;
        }
        return $count;
    }

    private function matches(array $profile, array $remote): bool
    {
        foreach (self::routerFields($profile) as $field => $desired) {
            if ($this->normalise($field, $remote[$field] ?? null) !== $this->normalise($field, $desired)) return false;
        }
        return true;
    }

    private function normalise(string $field, mixed $value): string
    {
        if ($value === null) {
            return match ($field) {
                'shared-users' => '1',
                'address-pool' => 'none',
                'add-mac-cookie' => 'yes',
                'transparent-proxy' => 'yes',
                'mac-cookie-timeout' => '3d',
                'idle-timeout','keepalive-timeout','status-autorefresh' => 'none',
                'rate-limit','address-list' => '',
                default => '',
            };
        }
        $value = trim((string)$value);
        if (in_array($field, ['add-mac-cookie','transparent-proxy'], true)) {
            return in_array(strtolower($value), ['yes','true','1','on'], true) ? 'yes' : 'no';
        }
        if ($field === 'shared-users') return (string)(int)$value;
        if ($field === 'rate-limit') return strtolower(preg_replace('/\s+/', ' ', $value) ?? $value);
        if ($field === 'address-pool' && $value === '') return 'none';
        return strtolower($value);
    }

    private function remoteHash(array $remote): string
    {
        $keys = array_values(self::FIELD_MAP);
        $data = ['name'=>(string)($remote['name'] ?? '')];
        foreach ($keys as $key) $data[$key] = $this->normalise($key, $remote[$key] ?? null);
        ksort($data);
        return hash('sha256', json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
    }

    private function saveStatus(int $profileId, int $routerId, string $status, ?string $remoteHash, ?string $error, bool $synced): void
    {
        $profileQ = $this->pdo->prepare('SELECT * FROM hotspot_profiles WHERE id=?');
        $profileQ->execute([$profileId]);
        $profile = $profileQ->fetch();
        $desiredHash = $profile ? self::profileHash($profile) : null;
        $sql = "INSERT INTO hotspot_profile_router_sync (profile_id,router_id,status,config_hash,remote_hash,last_checked_at,last_synced_at,last_error) VALUES (?,?,?,?,?,NOW()," . ($synced ? 'NOW()' : 'NULL') . ",?) ON DUPLICATE KEY UPDATE status=VALUES(status),config_hash=VALUES(config_hash),remote_hash=VALUES(remote_hash),last_checked_at=NOW(),last_synced_at=" . ($synced ? 'NOW()' : 'last_synced_at') . ",last_error=VALUES(last_error)";
        $this->pdo->prepare($sql)->execute([$profileId,$routerId,$status,$desiredHash,$remoteHash,$error]);
    }

    private function markRouterError(int $routerId, string $error): void
    {
        $this->pdo->prepare("UPDATE routers SET connection_status='offline',last_error=? WHERE id=?")->execute([mb_substr($error,0,450),$routerId]);
    }
}
