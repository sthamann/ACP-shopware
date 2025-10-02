<?php declare(strict_types=1);

namespace Acp\ShopwarePlugin\Service;

use Acp\ShopwarePlugin\Core\Content\ExternalToken\ExternalTokenEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Service for handling external payment tokens from PSPs (PayPal, Stripe, etc.)
 * This service no longer creates tokens but registers and manages external tokens
 */
class PaymentTokenService
{
    private EntityRepository $externalTokenRepository;
    private EntityRepository $paymentMethodRepository;
    private SystemConfigService $systemConfigService;

    public function __construct(
        EntityRepository $externalTokenRepository,
        EntityRepository $paymentMethodRepository,
        SystemConfigService $systemConfigService
    ) {
        $this->externalTokenRepository = $externalTokenRepository;
        $this->paymentMethodRepository = $paymentMethodRepository;
        $this->systemConfigService = $systemConfigService;
    }

    /**
     * Store an external token created by a PSP (corrected ACP flow)
     */
    public function storeExternalToken(array $data, Context $context): array
    {
        $tokenId = Uuid::randomHex();

        // Extract provider from payment method metadata or default to 'stripe' for ACP compliance
        $provider = $data['payment_method']['metadata']['provider'] ?? 'stripe';

        // Prepare data for database
        $tokenData = [
            'id' => $tokenId,
            'externalToken' => $data['payment_method']['number'], // Store the card number as external token reference
            'provider' => $provider,
            'checkoutSessionId' => $data['allowance']['checkout_session_id'] ?? null,
            'customerId' => null, // Will be set when customer is created in checkout
            'maxAmount' => $data['allowance']['max_amount'] ?? null,
            'currency' => $data['allowance']['currency'] ?? null,
            'expiresAt' => isset($data['allowance']['expires_at']) ? new \DateTime($data['allowance']['expires_at']) : null,
            'paymentMethodId' => $this->getPaymentMethodForProvider($provider, $context),
            'metadata' => array_merge($data['metadata'] ?? [], [
                'payment_method' => $data['payment_method'],
                'allowance' => $data['allowance'],
                'risk_signals' => $data['risk_signals']
            ]),
            'used' => false,
        ];

        // Create token in database
        $this->externalTokenRepository->create([$tokenData], $context);

        // Return ACP spec compliant response
        $metadata = $data['metadata'] ?? [];
        $metadata['source'] = $metadata['source'] ?? 'agent_checkout';
        $metadata['merchant_id'] = $data['allowance']['merchant_id'] ?? 'unknown';

        return [
            'id' => $tokenId,
            'created' => (new \DateTime())->format('c'),
            'metadata' => $metadata
        ];
    }

    /**
     * Get an external token by token value and provider
     */
    public function getExternalToken(string $externalToken, string $provider, Context $context): ?ExternalTokenEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new MultiFilter(MultiFilter::CONNECTION_AND, [
            new EqualsFilter('externalToken', $externalToken),
            new EqualsFilter('provider', $provider),
        ]));
        $criteria->setLimit(1);

        return $this->externalTokenRepository->search($criteria, $context)->first();
    }

    /**
     * Get an external token by ID
     */
    public function getExternalTokenById(string $id, Context $context): ?ExternalTokenEntity
    {
        return $this->externalTokenRepository->search(new Criteria([$id]), $context)->first();
    }

    /**
     * Get external tokens for a checkout session
     */
    public function getTokensForCheckoutSession(string $checkoutSessionId, Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('checkoutSessionId', $checkoutSessionId));
        
        $tokens = $this->externalTokenRepository->search($criteria, $context);
        
        return array_map(function (ExternalTokenEntity $token) {
            return [
                'id' => $token->getId(),
                'external_token' => $token->getExternalToken(),
                'provider' => $token->getProvider(),
                'used' => $token->isUsed(),
                'expires_at' => $token->getExpiresAt() ? $token->getExpiresAt()->format('c') : null,
            ];
        }, $tokens->getElements());
    }

    /**
     * Mark a token as used
     */
    public function markTokenAsUsed(string $externalToken, string $provider, string $orderId, Context $context): void
    {
        $token = $this->getExternalToken($externalToken, $provider, $context);
        
        if ($token) {
            $this->externalTokenRepository->update([
                [
                    'id' => $token->getId(),
                    'used' => true,
                    'orderId' => $orderId,
                ]
            ], $context);
        }
    }

    /**
     * Check if a token is valid (not used and not expired)
     */
    public function isTokenValid(string $externalToken, string $provider, Context $context): bool
    {
        $token = $this->getExternalToken($externalToken, $provider, $context);
        
        if (!$token) {
            return false;
        }

        if ($token->isUsed()) {
            return false;
        }

        if ($token->getExpiresAt() && $token->getExpiresAt() < new \DateTime()) {
            return false;
        }

        return true;
    }

    /**
     * Get the appropriate payment method for a provider
     */
    private function getPaymentMethodForProvider(string $provider, Context $context): ?string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('active', true));

        // Map provider to payment method handler
        switch ($provider) {
            case 'paypal':
                // Try to find PayPal ACDC handler first (best for card payments)
                $criteria->addFilter(new MultiFilter(MultiFilter::CONNECTION_OR, [
                    new EqualsFilter('handlerIdentifier', 'Swag\\PayPal\\Checkout\\Payment\\Method\\ACDCHandler'),
                    new EqualsFilter('handlerIdentifier', 'Swag\\PayPal\\Checkout\\Payment\\Method\\PayPalHandler'),
                ]));
                break;
            case 'stripe':
                $criteria->addFilter(new EqualsFilter('handlerIdentifier', 'Stripe\\ShopwarePayment\\Payment\\Method\\Card'));
                break;
            case 'adyen':
                $criteria->addFilter(new EqualsFilter('handlerIdentifier', 'Adyen\\Shopware\\Handlers\\CardPaymentMethodHandler'));
                break;
            default:
                // Generic card handler
                $criteria->addFilter(new EqualsFilter('handlerIdentifier', 'Shopware\\Core\\Checkout\\Payment\\Cart\\PaymentHandler\\DefaultPayment'));
                break;
        }

        $criteria->setLimit(1);
        $paymentMethod = $this->paymentMethodRepository->search($criteria, $context)->first();

        return $paymentMethod ? $paymentMethod->getId() : null;
    }

    /**
     * Update external token with additional data
     */
    public function updateExternalToken(string $id, array $data, Context $context): void
    {
        $updateData = ['id' => $id];
        
        if (isset($data['customer_id'])) {
            $updateData['customerId'] = $data['customer_id'];
        }
        
        if (isset($data['checkout_session_id'])) {
            $updateData['checkoutSessionId'] = $data['checkout_session_id'];
        }
        
        if (isset($data['metadata'])) {
            $updateData['metadata'] = $data['metadata'];
        }
        
        $this->externalTokenRepository->update([$updateData], $context);
    }
}