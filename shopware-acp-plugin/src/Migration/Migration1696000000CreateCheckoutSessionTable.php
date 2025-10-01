<?php declare(strict_types=1);

namespace Acp\ShopwarePlugin\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1696000000CreateCheckoutSessionTable extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1696000000;
    }

    public function update(Connection $connection): void
    {
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS `acp_checkout_session` (
    `id` BINARY(16) NOT NULL,
    `cart_token` VARCHAR(255) NOT NULL,
    `sales_channel_id` BINARY(16) NOT NULL,
    `status` VARCHAR(50) NOT NULL,
    `data` LONGTEXT NOT NULL,
    `order_id` BINARY(16) NULL,
    `created_at` DATETIME(3) NOT NULL,
    `updated_at` DATETIME(3) NULL,
    PRIMARY KEY (`id`),
    KEY `idx.cart_token` (`cart_token`),
    KEY `idx.order_id` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;
        $connection->executeStatement($sql);
    }

    public function updateDestructive(Connection $connection): void
    {
        // No destructive changes
    }
}
