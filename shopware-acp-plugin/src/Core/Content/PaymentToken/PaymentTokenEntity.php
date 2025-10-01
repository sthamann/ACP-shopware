<?php declare(strict_types=1);

namespace Acp\ShopwarePlugin\Core\Content\PaymentToken;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class PaymentTokenEntity extends Entity
{
    use EntityIdTrait;

    protected string $acpTokenId;
    protected ?string $paypalVaultTokenId = null;
    protected string $paymentMethodId;
    protected string $checkoutSessionId;
    protected int $maxAmount;
    protected string $currency;
    protected \DateTimeInterface $expiresAt;
    protected bool $used = false;
    protected ?string $orderId = null;
    protected ?string $cardLast4 = null;
    protected ?string $cardBrand = null;

    public function getAcpTokenId(): string
    {
        return $this->acpTokenId;
    }

    public function setAcpTokenId(string $acpTokenId): void
    {
        $this->acpTokenId = $acpTokenId;
    }

    public function getPaypalVaultTokenId(): ?string
    {
        return $this->paypalVaultTokenId;
    }

    public function setPaypalVaultTokenId(?string $paypalVaultTokenId): void
    {
        $this->paypalVaultTokenId = $paypalVaultTokenId;
    }

    public function getPaymentMethodId(): string
    {
        return $this->paymentMethodId;
    }

    public function setPaymentMethodId(string $paymentMethodId): void
    {
        $this->paymentMethodId = $paymentMethodId;
    }

    public function getCheckoutSessionId(): string
    {
        return $this->checkoutSessionId;
    }

    public function setCheckoutSessionId(string $checkoutSessionId): void
    {
        $this->checkoutSessionId = $checkoutSessionId;
    }

    public function getMaxAmount(): int
    {
        return $this->maxAmount;
    }

    public function setMaxAmount(int $maxAmount): void
    {
        $this->maxAmount = $maxAmount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): void
    {
        $this->currency = $currency;
    }

    public function getExpiresAt(): \DateTimeInterface
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(\DateTimeInterface $expiresAt): void
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

    public function getCardLast4(): ?string
    {
        return $this->cardLast4;
    }

    public function setCardLast4(?string $cardLast4): void
    {
        $this->cardLast4 = $cardLast4;
    }

    public function getCardBrand(): ?string
    {
        return $this->cardBrand;
    }

    public function setCardBrand(?string $cardBrand): void
    {
        $this->cardBrand = $cardBrand;
    }
}

