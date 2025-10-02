<?php declare(strict_types=1);

namespace Acp\ShopwarePlugin\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1696000002CreateIdempotencyKeyTable extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1696000002;
    }

    public function update(Connection $connection): void
    {
        // Drop old table if exists to ensure clean state
        $connection->executeStatement('DROP TABLE IF EXISTS `acp_idempotency_key`');
        
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS `acp_idempotency_key` (
    `id` BINARY(16) NOT NULL,
    `key` VARCHAR(255) NOT NULL,
    `request_hash` VARCHAR(64) NOT NULL,
    `response` LONGTEXT NOT NULL,
    `status_code` INT NOT NULL,
    `expires_at` DATETIME(3) NOT NULL,
    `created_at` DATETIME(3) NOT NULL,
    `updated_at` DATETIME(3) NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq.idempotency_key` (`key`),
    KEY `idx.expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;
        $connection->executeStatement($sql);
    }

    public function updateDestructive(Connection $connection): void
    {
        // No destructive changes
    }
}
