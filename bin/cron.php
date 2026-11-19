<?php
declare(strict_types=1);
require dirname(__DIR__).'/config/bootstrap.php';
use RakanWiman\Core\Database;
use RakanWiman\Core\SchemaUpgrade;
use RakanWiman\Services\RouterRepository;
use RakanWiman\Services\ExpiryService;
use RakanWiman\Services\RouterSyncWorker;
use RakanWiman\Services\WhatsAppGateway;
use RakanWiman\Services\HotspotProfileService;
use RakanWiman\Services\HotspotProfileSyncWorker;
$pdo=Database::connection();
SchemaUpgrade::ensurePackageSnapshots($pdo);
SchemaUpgrade::ensureViaQrisDynamicAmounts($pdo);
$optionsTable=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
$optionsTable->execute(['voucher_generation_options']);
if((int)$optionsTable->fetchColumn()===0){
    $migration=file_get_contents(dirname(__DIR__).'/database/migrations/005_voucher_generation_options.sql');
    if($migration===false) throw new RuntimeException('File migration 005 tidak ditemukan.');
    $pdo->exec($migration);
    try{$pdo->exec("INSERT IGNORE INTO rw_schema_migrations (migration) VALUES ('005_voucher_generation_options.sql')");}catch(Throwable $ignored){}
}
// Ensure HotSpot Profile Manager tables exist on upgraded installations, even if web UI has not been opened yet.
$profileTables = ['hotspot_profiles','hotspot_profile_router_sync','hotspot_profile_sync_queue'];
$missingProfileTable = false;
foreach ($profileTables as $tableName) {
    $checkTable = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
    $checkTable->execute([$tableName]);
    if ((int)$checkTable->fetchColumn() === 0) { $missingProfileTable = true; break; }
}
if ($missingProfileTable) {
    $migrationSql = file_get_contents(dirname(__DIR__).'/database/migrations/006_hotspot_profile_manager.sql');
    if ($migrationSql === false) throw new RuntimeException('File migration 006 HotSpot Profile Manager tidak ditemukan.');
    $migrationSql = preg_replace('/^\s*--.*$/m', '', $migrationSql) ?? $migrationSql;
    foreach (array_filter(array_map('trim', explode(';', $migrationSql))) as $statement) $pdo->exec($statement);
    try { $pdo->exec("INSERT IGNORE INTO rw_schema_migrations (migration) VALUES ('006_hotspot_profile_manager.sql')"); } catch (Throwable $ignored) {}
}
$repo=new RouterRepository($pdo);
$expiry=(new ExpiryService($pdo,$repo))->run();
$sync=(new RouterSyncWorker($pdo,$repo))->run(150);
$profileSync=(new HotspotProfileSyncWorker($pdo,new HotspotProfileService($pdo,$repo)))->run(80);
$whatsapp=(new WhatsAppGateway($pdo))->processQueue(30);
$line='['.date('Y-m-d H:i:s').'] '.json_encode(['expiry'=>$expiry,'sync'=>$sync,'profile_sync'=>$profileSync,'whatsapp'=>$whatsapp],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).PHP_EOL;
file_put_contents(dirname(__DIR__).'/storage/logs/cron.log',$line,FILE_APPEND|LOCK_EX);
echo $line;
