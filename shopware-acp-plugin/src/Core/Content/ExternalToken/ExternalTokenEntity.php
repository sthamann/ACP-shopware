<?php declare(strict_types=1);

namespace Acp\ShopwarePlugin\Core\Content\ExternalToken;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class ExternalTokenEntity extends Entity
{
    use EntityIdTrait;

    protected string $externalToken;
    protected string $provider;
    protected ?string $checkoutSessionId = null;
    protected ?string $customerId = null;
    protected ?int $maxAmount = null;
    protected ?string $currency = null;
    protected ?\DateTimeInterface $expiresAt = null;
    protected bool $used = false;
    protected ?string $orderId = null;
    protected ?string $paymentMethodId = null;
    protected ?array $metadata = null;

    public function getExternalToken(): string
    {
        return $this->externalToken;
    }

    public function setExternalToken(string $externalToken): void
    {
        $this->externalToken = $externalToken;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function setProvider(string $provider): void
    {
        $this->provider = $provider;
    }

    public function getCheckoutSessionId(): ?string
    {
        return $this->checkoutSessionId;
    }

    public function setCheckoutSessionId(?string $checkoutSessionId): void
    {
        $this->checkoutSessionId = $checkoutSessionId;
    }

    public function getCustomerId(): ?string
    {
        return $this->customerId;
    }

    public function setCustomerId(?string $customerId): void
    {
        $this->customerId = $customerId;
    }

    public function getMaxAmount(): ?int
    {
        return $this->maxAmount;
    }

    public function setMaxAmount(?int $maxAmount): void
    {
        $this->maxAmount = $maxAmount;
    }

    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    public function setCurrency(?string $currency): void
    {
        $this->currency = $currency;
    }

    public function getExpiresAt(): ?\DateTimeInterface
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeInterface $expiresAt): void
    {
        $this->expiresAt = $expiresAt;
    }

    public function isUsed(): bool
    {
        return $this->used;
    }

    public function setUsed(bool $used): void
    {
        $this->used = $used;
    }

    public function getOrderId(): ?string
    {
        return $this->orderId;
    }

    public function setOrderId(?string $orderId): void
    {
        $this->orderId = $orderId;
    }

    public function getPaymentMethodId(): ?string
    {
        return $this->paymentMethodId;
    }

    public function setPaymentMethodId(?string $paymentMethodId): void
    {
        $this->paymentMethodId = $paymentMethodId;
    }

    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    public function setMetadata(?array $metadata): void
    {
        $this->metadata = $metadata;
    }
}
