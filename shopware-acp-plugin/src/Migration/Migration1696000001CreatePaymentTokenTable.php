<?php declare(strict_types=1);

namespace Acp\ShopwarePlugin\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1696000001CreatePaymentTokenTable extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1696000001;
    }

    public function update(Connection $connection): void
    {
        // Drop old table if exists
        $connection->executeStatement('DROP TABLE IF EXISTS `acp_payment_token`');
        
        // Create new external token reference table
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS `acp_external_token` (
    `id` BINARY(16) NOT NULL,
    `external_token` VARCHAR(255) NOT NULL,
    `provider` VARCHAR(50) NOT NULL,
    `checkout_session_id` VARCHAR(255) NULL,
    `customer_id` BINARY(16) NULL,
    `max_amount` INT NULL,
    `currency` VARCHAR(3) NULL,
    `expires_at` DATETIME(3) NULL,
    `used` TINYINT(1) DEFAULT 0,
    `order_id` BINARY(16) NULL,
    `payment_method_id` BINARY(16) NULL,
    `metadata` JSON NULL,
    `created_at` DATETIME(3) NOT NULL,
    `updated_at` DATETIME(3) NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq.external_token` (`external_token`, `provider`),
    KEY `idx.checkout_session` (`checkout_session_id`),
    KEY `idx.customer` (`customer_id`),
    KEY `idx.order` (`order_id`),
    KEY `idx.provider` (`provider`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;
        $connection->executeStatement($sql);
    }

    public function updateDestructive(Connection $connection): void
    {
        // No destructive changes
    }
}

