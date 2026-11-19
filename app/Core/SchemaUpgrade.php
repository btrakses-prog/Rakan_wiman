<?php
declare(strict_types=1);
namespace RakanWiman\Core;

use PDO;
use Throwable;

final class SchemaUpgrade
{
    public static function ensurePackageSnapshots(PDO $pdo): void
    {
        $migration = '007_package_edit_snapshots.sql';
        $check = $pdo->prepare('SELECT COUNT(*) FROM rw_schema_migrations WHERE migration=?');
        $check->execute([$migration]);
        if ((int)$check->fetchColumn() > 0) return;

        $columns = [
            'vouchers' => [
                'package_name_snapshot' => 'VARCHAR(120) NULL',
                'package_code_snapshot' => 'VARCHAR(60) NULL',
                'mikrotik_profile_snapshot' => 'VARCHAR(120) NULL',
                'limit_uptime_seconds_snapshot' => 'BIGINT UNSIGNED NULL',
                'validity_days_snapshot' => 'INT UNSIGNED NULL',
            ],
            'payments' => [
                'package_name_snapshot' => 'VARCHAR(120) NULL',
                'package_code_snapshot' => 'VARCHAR(60) NULL',
                'mikrotik_profile_snapshot' => 'VARCHAR(120) NULL',
                'limit_uptime_seconds_snapshot' => 'BIGINT UNSIGNED NULL',
                'validity_days_snapshot' => 'INT UNSIGNED NULL',
            ],
        ];
        foreach ($columns as $table => $definitions) {
            foreach ($definitions as $column => $definition) {
                $columnCheck = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');
                $columnCheck->execute([$table, $column]);
                if ((int)$columnCheck->fetchColumn() === 0) {
                    $pdo->exec('ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $definition);
                }
            }
        }

        $pdo->beginTransaction();
        try {
            $pdo->exec("UPDATE vouchers v JOIN packages p ON p.id=v.package_id
                SET v.package_name_snapshot=p.name,
                    v.package_code_snapshot=p.code,
                    v.mikrotik_profile_snapshot=p.mikrotik_profile,
                    v.limit_uptime_seconds_snapshot=p.limit_uptime_seconds,
                    v.validity_days_snapshot=p.validity_days
                WHERE v.package_name_snapshot IS NULL");
            $pdo->exec("UPDATE payments pay JOIN packages p ON p.id=pay.package_id
                SET pay.package_name_snapshot=p.name,
                    pay.package_code_snapshot=p.code,
                    pay.mikrotik_profile_snapshot=p.mikrotik_profile,
                    pay.limit_uptime_seconds_snapshot=p.limit_uptime_seconds,
                    pay.validity_days_snapshot=p.validity_days
                WHERE pay.package_name_snapshot IS NULL");
            $mark = $pdo->prepare('INSERT IGNORE INTO rw_schema_migrations (migration) VALUES (?)');
            $mark->execute([$migration]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
    public static function ensureViaQrisDynamicAmounts(PDO $pdo): void
    {
        $columns = [
            'unique_code' => 'INT UNSIGNED NULL',
            'payable_amount' => 'BIGINT UNSIGNED NULL',
            'paid_amount' => 'BIGINT UNSIGNED NULL',
        ];
        foreach ($columns as $column => $definition) {
            $check = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');
            $check->execute(['payments', $column]);
            if ((int)$check->fetchColumn() === 0) {
                $pdo->exec('ALTER TABLE `payments` ADD COLUMN `' . $column . '` ' . $definition);
            }
        }
        $mark = $pdo->prepare('INSERT IGNORE INTO rw_schema_migrations (migration) VALUES (?)');
        $mark->execute(['008_viaqris_dynamic_amounts.sql']);
    }

}
