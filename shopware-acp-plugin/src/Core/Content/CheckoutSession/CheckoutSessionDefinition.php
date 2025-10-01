<?php declare(strict_types=1);

namespace Acp\ShopwarePlugin\Core\Content\CheckoutSession;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\LongTextField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\DateTimeField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

class CheckoutSessionDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'acp_checkout_session';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return CheckoutSessionEntity::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required()),
            (new StringField('cart_token', 'cartToken'))->addFlags(new Required()),
            (new FkField('sales_channel_id', 'salesChannelId', 'sales_channel'))->addFlags(new Required()),
            (new StringField('status', 'status'))->addFlags(new Required()),
            (new LongTextField('data', 'data'))->addFlags(new Required()),
            new FkField('order_id', 'orderId', 'order'),
            new DateTimeField('created_at', 'createdAt'),
            new DateTimeField('updated_at', 'updatedAt'),
        ]);
    }
}
