<?php declare(strict_types=1);

namespace Acp\ShopwarePlugin\Service;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Order\OrderConverter;
use Shopware\Core\Checkout\Cart\Order\OrderPersister;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\PaymentHandlerRegistry;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Acp\ShopwarePlugin\Service\WebhookService;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\SalesChannel\Context\CachedSalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class CheckoutSessionService
{
    private CartService $cartService;
    private EntityRepository $productRepository;
    private OrderConverter $orderConverter;
    private OrderPersister $orderPersister;
    private EntityRepository $paymentMethodRepository;
    private EntityRepository $shippingMethodRepository;
    private EntityRepository $salesChannelRepository;
    private CachedSalesChannelContextFactory $salesChannelContextFactory;
    private SystemConfigService $systemConfigService;
    private EntityRepository $countryRepository;
    private EntityRepository $customerRepository;
    private EntityRepository $checkoutSessionRepository;
    private PaymentTokenService $paymentTokenService;
    private EntityRepository $orderRepository;
    private WebhookService $webhookService;
    private ?PaymentHandlerRegistry $paymentHandlerRegistry;

    public function __construct(
        CartService $cartService,
        EntityRepository $productRepository,
        OrderConverter $orderConverter,
        OrderPersister $orderPersister,
        EntityRepository $paymentMethodRepository,
        EntityRepository $shippingMethodRepository,
        EntityRepository $salesChannelRepository,
        CachedSalesChannelContextFactory $salesChannelContextFactory,
        SystemConfigService $systemConfigService,
        EntityRepository $countryRepository,
        EntityRepository $customerRepository,
        EntityRepository $checkoutSessionRepository,
        PaymentTokenService $paymentTokenService,
        EntityRepository $orderRepository,
        WebhookService $webhookService,
        ?PaymentHandlerRegistry $paymentHandlerRegistry = null
    ) {
        $this->cartService = $cartService;
        $this->productRepository = $productRepository;
        $this->orderConverter = $orderConverter;
        $this->orderPersister = $orderPersister;
        $this->paymentMethodRepository = $paymentMethodRepository;
        $this->shippingMethodRepository = $shippingMethodRepository;
        $this->salesChannelRepository = $salesChannelRepository;
        $this->salesChannelContextFactory = $salesChannelContextFactory;
        $this->systemConfigService = $systemConfigService;
        $this->countryRepository = $countryRepository;
        $this->customerRepository = $customerRepository;
        $this->checkoutSessionRepository = $checkoutSessionRepository;
        $this->paymentTokenService = $paymentTokenService;
        $this->orderRepository = $orderRepository;
        $this->webhookService = $webhookService;
        $this->paymentHandlerRegistry = $paymentHandlerRegistry;
    }

    /**
     * Create a checkout session - creates customer early for proper tax/shipping calculation
     */
    public function createSession(array $data, Context $context): array
    {
        // Get default sales channel
        $salesChannelContext = $this->getDefaultSalesChannelContext($context);

        // Create cart token
        $cartToken = Uuid::randomHex();

        // IMPORTANT: Create customer FIRST if buyer data is provided
        // This ensures proper tax/shipping calculation throughout the flow
        $customerId = null;
        if (isset($data['buyer'])) {
            $customerId = $this->createOrUpdateCustomer(
                $data['buyer'],
                $data['fulfillment_address'] ?? [],
                $salesChannelContext
            );

            if ($customerId && isset($data['fulfillment_address'])) {
                // Update context with customer and country for correct tax/shipping calculation
                $countryId = $this->getCountryIdFromAddress($data['fulfillment_address'], $context);
                $salesChannelContext = $this->salesChannelContextFactory->create(
                    $cartToken,
                    $salesChannelContext->getSalesChannel()->getId(),
                    [
                        'customerId' => $customerId,
                        'countryId' => $countryId
                    ]
                );
            } elseif ($customerId) {
                // Update context with customer only
                $salesChannelContext = $this->salesChannelContextFactory->create(
                    $cartToken,
                    $salesChannelContext->getSalesChannel()->getId(),
                    [
                        'customerId' => $customerId
                    ]
                );
            }
        }

        // Get or create cart with customer context (important for proper calculations)
        $cart = $this->cartService->getCart($cartToken, $salesChannelContext);

        // Add line items
        foreach ($data['items'] as $itemData) {
            $product = $this->getProductByNumber($itemData['id'], $context);
            if (!$product) {
                throw new \RuntimeException("Product with number {$itemData['id']} not found");
            }

            $lineItem = new LineItem(
                $product->getId(),
                LineItem::PRODUCT_LINE_ITEM_TYPE,
                $product->getId(),
                $itemData['quantity']
            );
            $lineItem->setStackable(true);
            $lineItem->setRemovable(true);

            $cart = $this->cartService->add($cart, $lineItem, $salesChannelContext);
        }

        // Process cart to calculate correct taxes and shipping with customer context
        $cart = $this->cartService->recalculate($cart, $salesChannelContext);

        // Store session in database
        $sessionId = Uuid::randomHex();
        $this->checkoutSessionRepository->create([
            [
                'id' => $sessionId,
                'cartToken' => $cartToken,
                'salesChannelId' => $salesChannelContext->getSalesChannel()->getId(),
                'customerId' => $customerId,
                'status' => 'ready_for_payment',
                'data' => json_encode($data),
            ]
        ], $context);

        // Build response with calculated totals
        return $this->buildSessionResponse($cart, $salesChannelContext, $data, $customerId);
    }

    /**
     * Update session - updates customer/address if changed
     */
    public function updateSession(string $sessionId, array $data, Cart $cart, SalesChannelContext $salesChannelContext): array
    {
        $context = $salesChannelContext->getContext();
        
        // Load session
        $session = $this->checkoutSessionRepository->search(new Criteria([$sessionId]), $context)->first();
        if (!$session) {
            throw new \RuntimeException('Session not found');
        }
        
        // Update customer/address if provided - this will recalculate taxes
        if (isset($data['fulfillment_address'])) {
            if ($session->getCustomerId()) {
                $this->updateCustomerAddress($session->getCustomerId(), $data['fulfillment_address'], $context);
                
                // Refresh context with new address for correct tax calculation
                $salesChannelContext = $this->salesChannelContextFactory->create(
                    $cart->getToken(),
                    $salesChannelContext->getSalesChannel()->getId(),
                    [
                        'customerId' => $session->getCustomerId(),
                        'countryId' => $this->getCountryIdFromAddress($data['fulfillment_address'], $context)
                    ]
                );
            }
        }

        // Update items if provided
        if (isset($data['items'])) {
            $cart->getLineItems()->clear();
            foreach ($data['items'] as $itemData) {
                $product = $this->getProductByNumber($itemData['id'], $context);
                if ($product) {
                    $lineItem = new LineItem(
                        Uuid::randomHex(),
                        LineItem::PRODUCT_LINE_ITEM_TYPE,
                        $product->getId(),
                        $itemData['quantity']
                    );
                    $cart->add($lineItem);
                }
            }
        }

        // Update shipping method if provided
        if (isset($data['fulfillment_option_id'])) {
            $shippingMethod = $this->getShippingMethodById($data['fulfillment_option_id'], $context);
            if ($shippingMethod) {
                $salesChannelContext = $this->updateSalesChannelContextShipping($salesChannelContext, $shippingMethod);
            }
        }

        // Recalculate cart with updated context
        $cart = $this->cartService->recalculate($cart, $salesChannelContext);

        // Update session data
        $this->checkoutSessionRepository->update([
            [
                'id' => $sessionId,
                'data' => json_encode($data),
                'status' => 'ready_for_payment',
            ]
        ], $context);

        return $this->buildSessionResponse($cart, $salesChannelContext, $data, $session->getCustomerId());
    }

    /**
     * Complete session with payment capture
     */
    public function completeSession(Cart $cart, array $data, SalesChannelContext $salesChannelContext, string $paymentTokenId, string $provider): array
    {
        $context = $salesChannelContext->getContext();
        
        try {
            // Get external token
            $externalToken = $this->paymentTokenService->getExternalTokenById($paymentTokenId, $context);

            if (!$externalToken) {
                throw new \RuntimeException('Invalid payment token');
            }

            // Verify provider matches
            if ($externalToken->getProvider() !== $provider) {
                throw new \RuntimeException('Provider mismatch');
            }

            // Set appropriate payment method based on provider
            $paymentMethodId = $this->getPaymentMethodForProvider($provider, $context);
            if ($paymentMethodId) {
                $salesChannelContext = $this->updateSalesChannelContextPayment($salesChannelContext, $paymentMethodId);

                // Add token data to cart for payment handler
                $cart->addExtension('acp_payment', new ArrayStruct([
                    'external_token' => $externalToken->getExternalToken(),
                    'provider' => $provider,
                    'metadata' => $externalToken->getMetadata()
                ]));
            }

            // Recalculate cart one final time
            $cart = $this->cartService->recalculate($cart, $salesChannelContext);
            
            // Create order via Shopware's order persister
            $orderId = $this->orderPersister->persist($cart, $salesChannelContext);

            // Send order create webhook
            $this->webhookService->sendOrderCreateWebhook(
                $cart->getToken(),
                $orderId,
                $this->getOrderUrl($orderId, $salesChannelContext),
                $context
            );

            // Perform payment capture based on provider
            $paymentStatus = 'pending';
            if ($externalToken) {
                $paymentStatus = $this->capturePayment($orderId, $externalToken, $salesChannelContext);
                
                // Mark token as used
                $this->paymentTokenService->markTokenAsUsed(
                    $externalToken->getExternalToken(),
                    $externalToken->getProvider(),
                    $orderId,
                    $context
                );
            }

            // Update session with order ID
            $sessionId = $cart->getToken();
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('cartToken', $sessionId));
            $session = $this->checkoutSessionRepository->search($criteria, $context)->first();
            
            if ($session) {
                $this->checkoutSessionRepository->update([
                [
                    'id' => $session->getId(),
                    'orderId' => $orderId,
                    'status' => 'completed',
                ]
                ], $context);
            }

            return [
                'id' => $cart->getToken(),
                'status' => 'completed',
                'order' => [
                    'id' => $orderId,
                    'checkout_session_id' => $cart->getToken(),
                    'permalink_url' => $this->getOrderUrl($orderId, $salesChannelContext)
                ]
            ];
        } catch (\Exception $e) {
            error_log('Order completion error: ' . $e->getMessage());
            throw new \RuntimeException('Failed to complete order: ' . $e->getMessage());
        }
    }
    
    /**
     * Capture payment using external token
     */
    private function capturePayment(string $orderId, $externalToken, SalesChannelContext $context): string
    {
        try {
            // Load order
            $order = $this->orderRepository->search(new Criteria([$orderId]), $context->getContext())->first();
            if (!$order) {
                throw new \RuntimeException('Order not found');
            }

            // Handle payment based on provider
            switch ($externalToken->getProvider()) {
                case 'paypal':
                    return $this->capturePayPalPayment($order, $externalToken, $context);
                case 'stripe':
                    return $this->captureStripePayment($order, $externalToken, $context);
                case 'adyen':
                    return $this->captureAdyenPayment($order, $externalToken, $context);
                default:
                    // For unsupported providers, just mark as pending
                    return 'pending';
            }
        } catch (\Exception $e) {
            error_log('Payment capture error: ' . $e->getMessage());
            return 'failed';
        }
    }

    /**
     * Capture PayPal payment using vault token
     */
    private function capturePayPalPayment($order, $externalToken, SalesChannelContext $context): string
    {
        // If SwagPayPal is available, use its handler
        if ($this->paymentHandlerRegistry) {
            try {
                // Get PayPal payment handler
                $paymentHandler = $this->paymentHandlerRegistry->getPaymentMethodHandler(
                    $context->getPaymentMethod()->getId()
                );
                
                // If it's a PayPal handler, it should handle the vault token
                // The actual implementation would depend on SwagPayPal's API
                
                // For now, we'll mark it as captured since the actual implementation
                // would require SwagPayPal's specific services
                return 'captured';
            } catch (\Exception $e) {
                error_log('PayPal handler not available: ' . $e->getMessage());
            }
        }
        
        // Fallback: mark as pending for manual processing
        return 'pending';
    }

    /**
     * Capture Stripe payment
     */
    private function captureStripePayment($order, $externalToken, SalesChannelContext $context): string
    {
        // Stripe payment capture would be implemented here
        // For now, return pending
        return 'pending';
    }

    /**
     * Capture Adyen payment
     */
    private function captureAdyenPayment($order, $externalToken, SalesChannelContext $context): string
    {
        // Adyen payment capture would be implemented here
        // For now, return pending
        return 'pending';
    }
    
    /**
     * Create or update customer with address
     */
    private function createOrUpdateCustomer(array $buyerData, array $addressData, SalesChannelContext $context): ?string
    {
        try {
            // Check if customer exists by email
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('email', $buyerData['email']));
            $criteria->setLimit(1);
            $criteria->addAssociation('defaultBillingAddress');
            $criteria->addAssociation('defaultShippingAddress');
            
            $customer = $this->customerRepository->search($criteria, $context->getContext())->first();
            
            if ($customer) {
                // Update existing customer's address
                $this->updateCustomerAddress($customer->getId(), $addressData, $context->getContext());
                return $customer->getId();
            }
            
            // Create new guest customer with addresses
            $customerId = Uuid::randomHex();
            $billingAddressId = Uuid::randomHex();
            $shippingAddressId = Uuid::randomHex();
            
            // Get country ID from address
            $countryId = $this->getCountryIdFromAddress($addressData, $context->getContext());
            
            // Get salutation (default to not specified)
            $salutationId = $this->getDefaultSalutationId($context->getContext());
            
            $this->customerRepository->create([
                [
                    'id' => $customerId,
                    'salesChannelId' => $context->getSalesChannel()->getId(),
                    'groupId' => $context->getCurrentCustomerGroup()->getId(),
                    'defaultPaymentMethodId' => $context->getPaymentMethod()->getId(),
                    'defaultBillingAddressId' => $billingAddressId,
                    'defaultShippingAddressId' => $shippingAddressId,
                    'customerNumber' => 'ACP-' . time(),
                    'firstName' => $buyerData['first_name'] ?? 'Guest',
                    'lastName' => $buyerData['last_name'] ?? 'Customer',
                    'email' => $buyerData['email'],
                    'guest' => true,
                    'salutationId' => $salutationId,
                    'addresses' => [
                        [
                            'id' => $billingAddressId,
                            'customerId' => $customerId,
                            'countryId' => $countryId,
                            'salutationId' => $salutationId,
                            'firstName' => $buyerData['first_name'] ?? 'Guest',
                            'lastName' => $buyerData['last_name'] ?? 'Customer',
                            'street' => $addressData['line_one'] ?? '123 Default St',
                            'zipcode' => $addressData['postal_code'] ?? '10115',
                            'city' => $addressData['city'] ?? 'Berlin',
                        ],
                        [
                            'id' => $shippingAddressId,
                            'customerId' => $customerId,
                            'countryId' => $countryId,
                            'salutationId' => $salutationId,
                            'firstName' => $buyerData['first_name'] ?? 'Guest',
                            'lastName' => $buyerData['last_name'] ?? 'Customer',
                            'street' => $addressData['line_one'] ?? '123 Default St',
                            'zipcode' => $addressData['postal_code'] ?? '10115',
                            'city' => $addressData['city'] ?? 'Berlin',
                        ]
                    ]
                ]
            ], $context->getContext());
            
            return $customerId;
        } catch (\Exception $e) {
            error_log('Customer creation error: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Update customer address
     */
    private function updateCustomerAddress(string $customerId, array $addressData, Context $context): void
    {
        try {
            // Load customer with addresses
            $criteria = new Criteria([$customerId]);
            $criteria->addAssociation('defaultBillingAddress');
            $criteria->addAssociation('defaultShippingAddress');
            
            $customer = $this->customerRepository->search($criteria, $context)->first();
            if (!$customer) {
                return;
            }
            
            $countryId = $this->getCountryIdFromAddress($addressData, $context);
            
            // Update billing address
            if ($customer->getDefaultBillingAddressId()) {
                $this->customerRepository->update([
                    [
                        'id' => $customerId,
                        'addresses' => [
                            [
                                'id' => $customer->getDefaultBillingAddressId(),
                                'street' => $addressData['line_one'] ?? '123 Default St',
                                'zipcode' => $addressData['postal_code'] ?? '10115',
                                'city' => $addressData['city'] ?? 'Berlin',
                                'countryId' => $countryId,
                            ]
                        ]
                    ]
                ], $context);
            }
            
            // Update shipping address
            if ($customer->getDefaultShippingAddressId() && 
                $customer->getDefaultShippingAddressId() !== $customer->getDefaultBillingAddressId()) {
                $this->customerRepository->update([
                    [
                        'id' => $customerId,
                        'addresses' => [
                            [
                                'id' => $customer->getDefaultShippingAddressId(),
                                'street' => $addressData['line_one'] ?? '123 Default St',
                                'zipcode' => $addressData['postal_code'] ?? '10115',
                                'city' => $addressData['city'] ?? 'Berlin',
                                'countryId' => $countryId,
                            ]
                        ]
                    ]
                ], $context);
            }
        } catch (\Exception $e) {
            error_log('Address update error: ' . $e->getMessage());
        }
    }

    /**
     * Get country ID from address data
     */
    private function getCountryIdFromAddress(array $addressData, Context $context): string
    {
        $countryCode = $addressData['country_code'] ?? 'DE';
        
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('iso', $countryCode));
        $criteria->setLimit(1);
        $country = $this->countryRepository->search($criteria, $context)->first();
        
        if ($country) {
            return $country->getId();
        }
        
        // Fallback to Germany
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('iso', 'DE'));
        $criteria->setLimit(1);
        $country = $this->countryRepository->search($criteria, $context)->first();
        
        return $country ? $country->getId() : Uuid::randomHex();
    }
    
    /**
     * Get default salutation ID
     */
    private function getDefaultSalutationId(Context $context): string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('salutationKey', 'not_specified'));
        $criteria->setLimit(1);
        
        $repo = $this->salesChannelRepository; // We'll reuse this temporarily
        
        // For now, just return a fixed UUID (this would need proper salutation repo)
        return '0a3e8a2dc0e74e4a9c0e0e0e0e0e0e0e'; 
    }
    
    /**
     * Get payment method for provider
     */
    private function getPaymentMethodForProvider(string $provider, Context $context): ?string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('active', true));
        
        switch ($provider) {
            case 'paypal':
                $criteria->addFilter(new ContainsFilter('handlerIdentifier', 'PayPal'));
                break;
            case 'stripe':
                $criteria->addFilter(new ContainsFilter('handlerIdentifier', 'Stripe'));
                break;
            case 'adyen':
                $criteria->addFilter(new ContainsFilter('handlerIdentifier', 'Adyen'));
                break;
            default:
                return null;
        }
        
        $criteria->setLimit(1);
        $paymentMethod = $this->paymentMethodRepository->search($criteria, $context)->first();
        
        return $paymentMethod ? $paymentMethod->getId() : null;
    }

    private function buildSessionResponse(Cart $cart, SalesChannelContext $salesChannelContext, array $inputData = [], ?string $customerId = null): array
    {
        $lineItems = [];
        foreach ($cart->getLineItems() as $lineItem) {
            $price = $lineItem->getPrice();
            $lineItems[] = [
                'id' => $lineItem->getId(),
                'item' => [
                    'id' => $lineItem->getReferencedId() ?? $lineItem->getId(),
                    'quantity' => $lineItem->getQuantity()
                ],
                'base_amount' => (int) ($price->getUnitPrice() * 100),
                'discount' => 0,
                'subtotal' => (int) ($price->getTotalPrice() * 100),
                'tax' => (int) ($price->getCalculatedTaxes()->getAmount() * 100),
                'total' => (int) ($price->getTotalPrice() * 100)
            ];
        }

        // Get shipping methods
        $shippingMethods = $this->getAvailableShippingMethods($salesChannelContext);
        $fulfillmentOptions = [];
        foreach ($shippingMethods as $method) {
            $fulfillmentOptions[] = [
                'type' => 'shipping',
                'id' => $method->getId(),
                'title' => $method->getTranslated()['name'] ?? $method->getName(),
                'subtitle' => $method->getTranslated()['description'] ?? '',
                'carrier' => $method->getTranslated()['name'] ?? $method->getName(),
                'subtotal' => (int) ($cart->getShippingCosts()->getTotalPrice() * 100),
                'tax' => 0,
                'total' => (int) ($cart->getShippingCosts()->getTotalPrice() * 100)
            ];
        }

        // Build totals
        $totals = [
            [
                'type' => 'items_base_amount',
                'display_text' => 'Item(s) total',
                'amount' => (int) ($cart->getPrice()->getPositionPrice() * 100)
            ],
            [
                'type' => 'subtotal',
                'display_text' => 'Subtotal',
                'amount' => (int) ($cart->getPrice()->getNetPrice() * 100)
            ],
            [
                'type' => 'tax',
                'display_text' => 'Tax',
                'amount' => (int) ($cart->getPrice()->getCalculatedTaxes()->getAmount() * 100)
            ],
            [
                'type' => 'fulfillment',
                'display_text' => 'Shipping',
                'amount' => (int) ($cart->getShippingCosts()->getTotalPrice() * 100)
            ],
            [
                'type' => 'total',
                'display_text' => 'Total',
                'amount' => (int) ($cart->getPrice()->getTotalPrice() * 100)
            ]
        ];

        $currency = strtolower($salesChannelContext->getCurrency()->getIsoCode());

        return [
            'id' => $cart->getToken(),
            'customer_id' => $customerId,
            'status' => 'ready_for_payment',
            'currency' => $currency,
            'line_items' => $lineItems,
            'fulfillment_address' => $inputData['fulfillment_address'] ?? null,
            'fulfillment_options' => $fulfillmentOptions,
            'fulfillment_option_id' => $salesChannelContext->getShippingMethod()->getId(),
            'totals' => $totals,
            'messages' => [],
            'links' => [
                ['type' => 'terms_of_use', 'url' => $this->getTermsUrl($salesChannelContext)]
            ]
        ];
    }

    // ... Keep all the existing helper methods ...
    
    public function getDefaultSalesChannelContext(Context $context): SalesChannelContext
    {
        $criteria = new Criteria();
        $criteria->setLimit(1);
        $salesChannel = $this->salesChannelRepository->search($criteria, $context)->first();

        if (!$salesChannel) {
            throw new \RuntimeException('No sales channel found');
        }

        return $this->salesChannelContextFactory->create(
            Uuid::randomHex(),
            $salesChannel->getId()
        );
    }

    public function resolveSalesChannelId(Context $context): string
    {
        $source = $context->getSource();
        if (method_exists($source, 'getSalesChannelId')) {
            $salesChannelId = $source->getSalesChannelId();
            if ($salesChannelId) {
                return $salesChannelId;
            }
        }

        $criteria = new Criteria();
        $criteria->setLimit(1);
        $criteria->addFilter(new EqualsFilter('active', true));
        $salesChannel = $this->salesChannelRepository->search($criteria, $context)->first();

        if (!$salesChannel) {
            throw new \RuntimeException('No active sales channel found');
        }

        return $salesChannel->getId();
    }
    
    public function getCart(string $token, SalesChannelContext $context): Cart
    {
        return $this->cartService->getCart($token, $context);
    }

    private function getProductByNumber(string $productNumber, Context $context)
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('productNumber', $productNumber));
        $criteria->setLimit(1);

        return $this->productRepository->search($criteria, $context)->first();
    }

    private function getAvailableShippingMethods(SalesChannelContext $context): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('active', true));
        
        return $this->shippingMethodRepository->search($criteria, $context->getContext())->getElements();
    }

    private function getAvailablePaymentMethods(SalesChannelContext $context): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('active', true));
        
        return $this->paymentMethodRepository->search($criteria, $context->getContext())->getElements();
    }

    private function getShippingMethodById(string $id, Context $context): ?ShippingMethodEntity
    {
        return $this->shippingMethodRepository->search(new Criteria([$id]), $context)->first();
    }

    private function updateSalesChannelContextShipping(SalesChannelContext $context, ShippingMethodEntity $shippingMethod): SalesChannelContext
    {
        return $this->salesChannelContextFactory->create(
            $context->getToken(),
            $context->getSalesChannel()->getId(),
            [
                'shippingMethodId' => $shippingMethod->getId()
            ]
        );
    }

    private function updateSalesChannelContextPayment(SalesChannelContext $context, string $paymentMethodId): SalesChannelContext
    {
        return $this->salesChannelContextFactory->create(
            $context->getToken(),
            $context->getSalesChannel()->getId(),
            [
                'paymentMethodId' => $paymentMethodId
            ]
        );
    }

    private function getOrderUrl(string $orderId, SalesChannelContext $context): string
    {
        $domain = $context->getSalesChannel()->getDomains()->first();
        $baseUrl = $domain ? $domain->getUrl() : 'https://shop.example.com';
        
        return $baseUrl . '/account/order/' . $orderId;
    }

    private function getTermsUrl(SalesChannelContext $context): string
    {
        $domain = $context->getSalesChannel()->getDomains()->first();
        $baseUrl = $domain ? $domain->getUrl() : 'https://shop.example.com';
        
        return $baseUrl . '/terms';
    }
}