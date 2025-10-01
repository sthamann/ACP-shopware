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
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS `acp_payment_token` (
    `id` BINARY(16) NOT NULL,
    `acp_token_id` VARCHAR(255) NOT NULL,
    `paypal_vault_token_id` BINARY(16) NULL,
    `payment_method_id` BINARY(16) NOT NULL,
    `checkout_session_id` VARCHAR(255) NOT NULL,
    `max_amount` INT NOT NULL,
    `currency` VARCHAR(3) NOT NULL,
    `expires_at` DATETIME(3) NOT NULL,
    `used` TINYINT(1) DEFAULT 0,
    `order_id` BINARY(16) NULL,
    `card_last4` VARCHAR(4) NULL,
    `card_brand` VARCHAR(50) NULL,
    `created_at` DATETIME(3) NOT NULL,
    `updated_at` DATETIME(3) NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq.acp_token` (`acp_token_id`),
    KEY `idx.paypal_vault` (`paypal_vault_token_id`),
    KEY `idx.checkout_session` (`checkout_session_id`),
    KEY `idx.payment_method` (`payment_method_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;
        $connection->executeStatement($sql);
    }

    public function updateDestructive(Connection $connection): void
    {
        // No destructive changes
    }
}

