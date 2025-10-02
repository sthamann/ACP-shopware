<?php declare(strict_types=1);

namespace Acp\ShopwarePlugin\Core\Content\IdempotencyKey;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\LongTextField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\DateTimeField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;

class IdempotencyKeyDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'acp_idempotency_key';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return IdempotencyKeyEntity::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new Required(), new PrimaryKey()),
            (new StringField('key', 'key'))->addFlags(new Required()),
            (new StringField('request_hash', 'requestHash'))->addFlags(new Required()),
            (new LongTextField('response', 'response'))->addFlags(new Required()),
            (new IntField('status_code', 'statusCode'))->addFlags(new Required()),
            (new DateTimeField('expires_at', 'expiresAt'))->addFlags(new Required()),
            new DateTimeField('created_at', 'createdAt'),
            new DateTimeField('updated_at', 'updatedAt'),
        ]);
    }
}
