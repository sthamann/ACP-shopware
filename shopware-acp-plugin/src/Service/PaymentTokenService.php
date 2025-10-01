<?php declare(strict_types=1);

namespace Acp\ShopwarePlugin\Service;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Swag\PayPal\Checkout\Payment\Service\VaultTokenService as SwagVaultTokenService;
use Swag\PayPal\DataAbstractionLayer\VaultToken\VaultTokenEntity;

class PaymentTokenService
{
    private EntityRepository $paymentMethodRepository;
    private SystemConfigService $systemConfigService;
    private ?EntityRepository $vaultTokenRepository;
    private EntityRepository $acpPaymentTokenRepository;
    private ?EntityRepository $customerRepository;
    private ?SwagVaultTokenService $swagVaultTokenService;
    private $paypalRequestService;

    public function __construct(
        EntityRepository $paymentMethodRepository,
        SystemConfigService $systemConfigService,
        ?EntityRepository $vaultTokenRepository,
        EntityRepository $acpPaymentTokenRepository,
        ?EntityRepository $customerRepository,
        ?SwagVaultTokenService $swagVaultTokenService = null,
        $paypalRequestService = null
    ) {
        $this->paymentMethodRepository = $paymentMethodRepository;
        $this->systemConfigService = $systemConfigService;
        $this->vaultTokenRepository = $vaultTokenRepository;
        $this->acpPaymentTokenRepository = $acpPaymentTokenRepository;
        $this->customerRepository = $customerRepository;
        $this->swagVaultTokenService = $swagVaultTokenService;
        $this->paypalRequestService = $paypalRequestService;
    }

    public function createDelegatedPaymentToken(array $data, Context $context): array
    {
        // Validate payment method
        $paymentMethod = $data['payment_method'];

        if ($paymentMethod['type'] !== 'card') {
            throw new \InvalidArgumentException('Only card payment method is supported');
        }

        // Validate allowance
        $allowance = $data['allowance'];
        if ($allowance['reason'] !== 'one_time') {
            throw new \InvalidArgumentException('Only one_time allowance is supported');
        }

        // Check if PayPal is available (check this FIRST)
        $paypalMethod = $this->getPayPalPaymentMethod($context);

        // Check mode: Demo or Production (default to false/production if not set)
        $demoMode = $this->systemConfigService->get('AcpShopwarePlugin.config.demoMode');

        // Debug logging with more details
        error_log('ACP Payment Token - Demo Mode: ' . ($demoMode ? 'true' : 'false') . ' (type: ' . gettype($demoMode) . ')');
        error_log('ACP Payment Token - PayPal Method found: ' . ($paypalMethod ? 'YES (' . $paypalMethod->getId() . ' - ' . $paypalMethod->getName() . ')' : 'NO'));
        error_log('ACP Payment Token - PayPal Method active: ' . ($paypalMethod && $paypalMethod->getActive() ? 'YES' : 'NO'));
        error_log('ACP Payment Token - Vault Service available: ' . ($this->swagVaultTokenService ? 'YES' : 'NO'));
        error_log('ACP Payment Token - Vault Repository available: ' . ($this->vaultTokenRepository ? 'YES' : 'NO'));

        // Check system config in different ways
        $demoModeNull = $this->systemConfigService->get('AcpShopwarePlugin.config.demoMode');
        $demoModeFalse = $this->systemConfigService->get('AcpShopwarePlugin.config.demoMode') ?? false;
        error_log('ACP Payment Token - Demo Mode (null coalesced): ' . ($demoModeFalse ? 'true' : 'false'));

        // Evaluate conditions step by step
        $hasPayPalMethod = $paypalMethod !== null;
        $hasVaultRepo = $this->vaultTokenRepository !== null;
        $isNotDemoMode = !$demoMode;
        $isNotDemoModeNull = !$demoModeNull;
        $isNotDemoModeFalse = !$demoModeFalse;

        error_log('ACP Payment Token - Condition check:');
        error_log('  - hasPayPalMethod: ' . ($hasPayPalMethod ? 'true' : 'false'));
        error_log('  - hasVaultRepo: ' . ($hasVaultRepo ? 'true' : 'false'));
        error_log('  - isNotDemoMode (!demoMode): ' . ($isNotDemoMode ? 'true' : 'false'));
        error_log('  - isNotDemoModeNull (!$demoModeNull): ' . ($isNotDemoModeNull ? 'true' : 'false'));
        error_log('  - isNotDemoModeFalse (!$demoModeFalse): ' . ($isNotDemoModeFalse ? 'true' : 'false'));

        $usePayPalMode = $hasPayPalMethod && $hasVaultRepo && $isNotDemoMode;
        error_log('ACP Payment Token - Use PayPal mode: ' . ($usePayPalMode ? 'YES' : 'NO'));

        // FORCE PayPal mode if PayPal is available (ignore demo mode for testing)
        $forcePayPalMode = $hasPayPalMethod && $hasVaultRepo;
        if ($forcePayPalMode) {
            // PRODUCTION MODE: Use real PayPal
            error_log('ACP Payment Token - FORCED: Using PayPal mode for testing!');
            return $this->createPayPalVaultToken($data, $paypalMethod->getId(), $context);
        }

        // If PayPal is available and we have vault repository, use PayPal
        // (swagVaultTokenService might be null even if PayPal is installed)
        if ($usePayPalMode) {
            // PRODUCTION MODE: Use real PayPal
            error_log('ACP Payment Token - Using PayPal mode!');
            return $this->createPayPalVaultToken($data, $paypalMethod->getId(), $context);
        }

        // DEMO MODE or PayPal not available: Generic card tokenization
        error_log('ACP Payment Token - Using demo mode fallback');

        // Add debug info to response
        $result = $this->createGenericCardToken($data, $context);
        $result['debug_info'] = 'DM:' . ($demoMode ? '1' : '0') . ',PM:' . ($paypalMethod ? '1' : '0') . ',VR:' . ($this->vaultTokenRepository ? '1' : '0') . ',VS:' . ($this->swagVaultTokenService ? '1' : '0');
        return $result;
    }

    private function createPayPalVaultToken(array $data, string $paymentMethodId, Context $context): array
    {
        $paymentMethod = $data['payment_method'];
        $allowance = $data['allowance'];

        try {
            // Try to use real PayPal integration if available
            if ($this->swagVaultTokenService) {
                error_log('ACP Payment Token - Using REAL PayPal VaultTokenService!');

                // Create a temporary customer for tokenization
                $tempCustomerId = Uuid::randomHex();
                $tempCustomer = [
                    'id' => $tempCustomerId,
                    'firstName' => 'ACP',
                    'lastName' => 'Customer',
                    'email' => 'acp-' . time() . '@example.com',
                    'guest' => true
                ];

                // TODO: Implement real PayPal vault token creation using SwagPayPal service
                // This would require:
                // 1. Creating a customer
                // 2. Using the VaultTokenService to create a real PayPal vault token
                // 3. Returning the real token ID

                // For now, fall back to simulation
                error_log('ACP Payment Token - SwagVaultTokenService available but not implemented yet');
            }
        } catch (\Exception $e) {
            error_log('ACP Payment Token - Error using PayPal service: ' . $e->getMessage());
        }

        // Fallback: Simuliere PayPal Vault Token Creation (for testing)
        error_log('ACP Payment Token - Using SIMULATED PayPal tokens');

        $paypalVaultTokenId = null;
        $paypalToken = 'CARD-' . strtoupper(substr(Uuid::randomHex(), 0, 13));

        // Speichere in SwagPayPal Vault Token Tabelle (nur wenn Repository verfügbar)
        $customerIdForVault = $this->resolveVaultCustomerId($context);

        if ($this->vaultTokenRepository && $customerIdForVault) {
            $paypalVaultTokenId = Uuid::randomHex();
            $this->vaultTokenRepository->create([
                [
                    'id' => $paypalVaultTokenId,
                    'customerId' => $customerIdForVault,
                    'paymentMethodId' => $paymentMethodId,
                    'token' => $paypalToken,
                    'identifier' => $paymentMethod['display_last4'] ?? 'XXXX',
                ]
            ], $context);
        }

        // Generiere ACP Token ID
        $acpTokenId = 'vt_paypal_' . Uuid::randomHex();

        // Speichere Mapping in unserer Tabelle
        $this->acpPaymentTokenRepository->create([
            [
                'id' => Uuid::randomHex(),
                'acpTokenId' => $acpTokenId,
                'paypalVaultTokenId' => $paypalVaultTokenId,
                'paymentMethodId' => $paymentMethodId,
                'checkoutSessionId' => $allowance['checkout_session_id'],
                'maxAmount' => $allowance['max_amount'],
                'currency' => $allowance['currency'],
                'expiresAt' => new \DateTime($allowance['expires_at']),
                'used' => false,
                'cardLast4' => $paymentMethod['display_last4'] ?? null,
                'cardBrand' => $paymentMethod['display_brand'] ?? null,
            ]
        ], $context);

        return [
            'id' => $acpTokenId,
            'created' => date('c'),
            'metadata' => array_merge(
                $data['metadata'] ?? [],
                [
                    'payment_method_id' => $paymentMethodId,
                    'checkout_session_id' => $allowance['checkout_session_id'],
                    'max_amount' => (string) $allowance['max_amount'],
                    'currency' => $allowance['currency'],
                    'expires_at' => $allowance['expires_at'],
                    'paypal_vault_id' => $paypalToken,
                    'vault_customer_id' => $customerIdForVault,
                ]
            )
        ];
    }

    private function resolveVaultCustomerId(Context $context): ?string
    {
        if (!$this->customerRepository) {
            return null;
        }

        $criteria = new Criteria();
        $criteria->setLimit(1);

        $customer = $this->customerRepository->search($criteria, $context)->first();

        return $customer ? $customer->getId() : null;
    }

    private function createGenericCardToken(array $data, Context $context): array
    {
        $paymentMethod = $data['payment_method'];
        $allowance = $data['allowance'];

        $acpTokenId = 'vt_card_' . Uuid::randomHex();

        // Finde eine generische Kartenbasierte Zahlungsmethode
        $cardPaymentMethod = $this->getCardPaymentMethod($context);

        $this->acpPaymentTokenRepository->create([
            [
                'id' => Uuid::randomHex(),
                'acpTokenId' => $acpTokenId,
                'paymentMethodId' => $cardPaymentMethod?->getId() ?? Uuid::randomHex(),
                'checkoutSessionId' => $allowance['checkout_session_id'],
                'maxAmount' => $allowance['max_amount'],
                'currency' => $allowance['currency'],
                'expiresAt' => new \DateTime($allowance['expires_at']),
                'used' => false,
                'cardLast4' => $paymentMethod['display_last4'] ?? null,
                'cardBrand' => $paymentMethod['display_brand'] ?? null,
            ]
        ], $context);

        return [
            'id' => $acpTokenId,
            'created' => date('c'),
            'metadata' => array_merge(
                $data['metadata'] ?? [],
                [
                    'checkout_session_id' => $allowance['checkout_session_id'],
                    'max_amount' => (string) $allowance['max_amount'],
                    'currency' => $allowance['currency'],
                    'expires_at' => $allowance['expires_at'],
                    'card_last4' => $paymentMethod['display_last4'] ?? 'XXXX',
                    'card_brand' => $paymentMethod['display_brand'] ?? 'unknown'
                ]
            )
        ];
    }

    public function getPaymentToken(string $acpTokenId, Context $context): ?array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('acpTokenId', $acpTokenId));
        $criteria->setLimit(1);
        
        $token = $this->acpPaymentTokenRepository->search($criteria, $context)->first();
        
        if (!$token) {
            return null;
        }
        
        return [
            'id' => $token->getId(),
            'acp_token_id' => $token->getAcpTokenId(),
            'paypal_vault_token_id' => $token->getPaypalVaultTokenId(),
            'payment_method_id' => $token->getPaymentMethodId(),
            'checkout_session_id' => $token->getCheckoutSessionId(),
            'max_amount' => $token->getMaxAmount(),
            'currency' => $token->getCurrency(),
            'expires_at' => $token->getExpiresAt()->format('c'),
            'used' => $token->isUsed(),
            'order_id' => $token->getOrderId(),
            'card_last4' => $token->getCardLast4(),
            'card_brand' => $token->getCardBrand(),
        ];
    }

    public function getPayPalVaultToken(string $paypalVaultTokenId, Context $context): ?VaultTokenEntity
    {
        if (!$this->vaultTokenRepository) {
            return null;
        }
        return $this->vaultTokenRepository->search(new Criteria([$paypalVaultTokenId]), $context)->first();
    }

    public function markTokenAsUsed(string $acpTokenId, string $orderId, Context $context): void
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('acpTokenId', $acpTokenId));
        
        $token = $this->acpPaymentTokenRepository->search($criteria, $context)->first();
        
        if ($token) {
            $this->acpPaymentTokenRepository->update([
                [
                    'id' => $token->getId(),
                    'used' => true,
                    'orderId' => $orderId,
                ]
            ], $context);
        }
    }

    private function getCardPaymentMethod(Context $context)
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('active', true));
        $criteria->addFilter(new ContainsFilter('handlerIdentifier', 'Card'));
        $criteria->setLimit(1);

        return $this->paymentMethodRepository->search($criteria, $context)->first();
    }

    private function getPayPalPaymentMethod(Context $context)
    {
        // First try to find ACDC Handler (best for ACP)
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('active', true));
        $criteria->addFilter(new ContainsFilter('handlerIdentifier', 'ACDCHandler'));
        $criteria->setLimit(1);

        $acdcMethod = $this->paymentMethodRepository->search($criteria, $context)->first();
        if ($acdcMethod) {
            return $acdcMethod;
        }

        // Fallback: Any PayPal handler
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('active', true));
        $criteria->addFilter(new ContainsFilter('handlerIdentifier', 'PayPal'));
        $criteria->setLimit(1);

        return $this->paymentMethodRepository->search($criteria, $context)->first();
    }
}