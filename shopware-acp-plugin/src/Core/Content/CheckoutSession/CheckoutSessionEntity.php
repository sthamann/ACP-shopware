<?php declare(strict_types=1);

namespace Acp\ShopwarePlugin\Core\Content\CheckoutSession;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class CheckoutSessionEntity extends Entity
{
    use EntityIdTrait;

    protected string $cartToken;
    protected string $salesChannelId;
    protected ?string $customerId = null;
    protected string $status;
    protected string $data;
    protected ?string $orderId = null;
    protected ?string $paymentStatus = null;

    public function getCartToken(): string
    {
        return $this->cartToken;
    }

    public function setCartToken(string $cartToken): void
    {
        $this->cartToken = $cartToken;
    }

    public function getSalesChannelId(): string
    {
        return $this->salesChannelId;
    }

    public function setSalesChannelId(string $salesChannelId): void
    {
        $this->salesChannelId = $salesChannelId;
    }

    public function getCustomerId(): ?string
    {
        return $this->customerId;
    }

    public function setCustomerId(?string $customerId): void
    {
        $this->customerId = $customerId;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function getData(): string
    {
        return $this->data;
    }

    public function setData(string $data): void
    {
        $this->data = $data;
    }

    public function getOrderId(): ?string
    {
        return $this->orderId;
    }

    public function setOrderId(?string $orderId): void
    {
        $this->orderId = $orderId;
    }

    public function getPaymentStatus(): ?string
    {
        return $this->paymentStatus;
    }

    public function setPaymentStatus(?string $paymentStatus): void
    {
        $this->paymentStatus = $paymentStatus;
    }
}
