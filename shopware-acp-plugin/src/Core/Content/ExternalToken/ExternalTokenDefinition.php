<?php declare(strict_types=1);

namespace Acp\ShopwarePlugin\Core\Content\ExternalToken;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\BoolField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\DateTimeField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\JsonField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;

class ExternalTokenDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'acp_external_token';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return ExternalTokenEntity::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new Required(), new PrimaryKey()),
            (new StringField('external_token', 'externalToken'))->addFlags(new Required()),
            (new StringField('provider', 'provider'))->addFlags(new Required()),
            new StringField('checkout_session_id', 'checkoutSessionId'),
            new FkField('customer_id', 'customerId', \Shopware\Core\Checkout\Customer\CustomerDefinition::class),
            new IntField('max_amount', 'maxAmount'),
            new StringField('currency', 'currency'),
            new DateTimeField('expires_at', 'expiresAt'),
            new BoolField('used', 'used'),
            new FkField('order_id', 'orderId', \Shopware\Core\Checkout\Order\OrderDefinition::class),
            new FkField('payment_method_id', 'paymentMethodId', \Shopware\Core\Checkout\Payment\PaymentMethodDefinition::class),
            new JsonField('metadata', 'metadata'),
            new DateTimeField('created_at', 'createdAt'),
            new DateTimeField('updated_at', 'updatedAt'),
        ]);
    }
}
