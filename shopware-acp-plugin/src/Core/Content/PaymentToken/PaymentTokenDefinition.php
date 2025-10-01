<?php declare(strict_types=1);

namespace Acp\ShopwarePlugin\Core\Content\PaymentToken;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\BoolField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\DateTimeField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

class PaymentTokenDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'acp_payment_token';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return PaymentTokenEntity::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required()),
            (new StringField('acp_token_id', 'acpTokenId'))->addFlags(new Required()),
            new FkField('paypal_vault_token_id', 'paypalVaultTokenId', 'swag_paypal_vault_token'),
            (new FkField('payment_method_id', 'paymentMethodId', 'payment_method'))->addFlags(new Required()),
            (new StringField('checkout_session_id', 'checkoutSessionId'))->addFlags(new Required()),
            (new IntField('max_amount', 'maxAmount'))->addFlags(new Required()),
            (new StringField('currency', 'currency'))->addFlags(new Required()),
            (new DateTimeField('expires_at', 'expiresAt'))->addFlags(new Required()),
            new BoolField('used', 'used'),
            new FkField('order_id', 'orderId', 'order'),
            new StringField('card_last4', 'cardLast4'),
            new StringField('card_brand', 'cardBrand'),
            new DateTimeField('created_at', 'createdAt'),
            new DateTimeField('updated_at', 'updatedAt'),
        ]);
    }
}

