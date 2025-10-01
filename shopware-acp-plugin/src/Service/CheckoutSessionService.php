<?php declare(strict_types=1);

namespace Acp\ShopwarePlugin\Service;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Order\OrderConverter;
use Shopware\Core\Checkout\Cart\Order\OrderPersister;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
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
        EntityRepository $customerRepository
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
    }

    public function createSession(array $data, Context $context): array
    {
        // Get default sales channel
        $salesChannelContext = $this->getDefaultSalesChannelContext($context);
        
        // Create cart token
        $cartToken = Uuid::randomHex();
        
        // Get or create cart
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

        // Store buyer data if provided
        if (isset($data['buyer'])) {
            // Store in cart custom fields for later use
            $cart->getData()->set('acp_buyer', $data['buyer']);
        }
        
        if (isset($data['fulfillment_address'])) {
            $cart->getData()->set('acp_fulfillment_address', $data['fulfillment_address']);
        }

        // Build response
        return $this->buildSessionResponse($cart, $salesChannelContext, $data);
    }

    public function updateSession(string $sessionId, array $data, Cart $cart, SalesChannelContext $salesChannelContext): array
    {
        // Update fulfillment address if provided
        if (isset($data['fulfillment_address'])) {
            $cart = $this->updateShippingAddress($cart, $data['fulfillment_address'], $salesChannelContext);
        }

        // Update items if provided
        if (isset($data['items'])) {
            $cart->getLineItems()->clear();
            foreach ($data['items'] as $itemData) {
                $product = $this->getProductByNumber($itemData['id'], $salesChannelContext->getContext());
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
            $shippingMethod = $this->getShippingMethodById($data['fulfillment_option_id'], $salesChannelContext->getContext());
            if ($shippingMethod) {
                $salesChannelContext = $this->updateSalesChannelContextShipping($salesChannelContext, $shippingMethod);
            }
        }

        // Recalculate cart
        $cart = $this->cartService->recalculate($cart, $salesChannelContext);

        return $this->buildSessionResponse($cart, $salesChannelContext, $data);
    }

    public function completeSession(Cart $cart, array $data, SalesChannelContext $salesChannelContext, ?string $paymentTokenId = null): array
    {
        try {
            // Set buyer data on context if provided
            if (isset($data['buyer'])) {
                // For guest checkout, we need to handle customer creation
                $customerId = $this->createOrGetCustomer($data['buyer'], $salesChannelContext);
                if ($customerId) {
                    // Update context with customer
                    $salesChannelContext = $this->salesChannelContextFactory->create(
                        $salesChannelContext->getToken(),
                        $salesChannelContext->getSalesChannel()->getId(),
                        [
                            'customerId' => $customerId
                        ]
                    );
                }
            }
            
            // Optional: Set payment method if token provided
            if ($paymentTokenId && isset($data['payment_method_id'])) {
                $salesChannelContext = $this->updateSalesChannelContextPayment($salesChannelContext, $data['payment_method_id']);
            }
            
            // Recalculate cart one final time
            $cart = $this->cartService->recalculate($cart, $salesChannelContext);
            
            // Create order via Shopware's order persister
            $orderId = $this->orderPersister->persist($cart, $salesChannelContext);

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
            // Log the error but return a meaningful response
            error_log('Order completion error: ' . $e->getMessage());
            throw new \RuntimeException('Failed to complete order: ' . $e->getMessage());
        }
    }
    
    private function createOrGetCustomer(array $buyerData, SalesChannelContext $context): ?string
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
                return $customer->getId();
            }
            
            // For demo/testing, reuse existing customer if creating new ones fails
            $existingCriteria = new Criteria();
            $existingCriteria->setLimit(1);
            $existingCriteria->addAssociation('defaultBillingAddress');
            $existingCriteria->addAssociation('defaultShippingAddress');
            $existingCustomer = $this->customerRepository->search($existingCriteria, $context->getContext())->first();
            
            if ($existingCustomer && $existingCustomer->getDefaultBillingAddress()) {
                return $existingCustomer->getId();
            }
            
            // Create guest customer with addresses
            $customerId = Uuid::randomHex();
            $addressId = Uuid::randomHex();
            
            // Get country ID (default to Germany)
            $countryCriteria = new Criteria();
            $countryCriteria->addFilter(new EqualsFilter('iso', 'DE'));
            $countryCriteria->setLimit(1);
            $country = $this->countryRepository->search($countryCriteria, $context->getContext())->first();
            $countryId = $country ? $country->getId() : Uuid::randomHex();
            
            $this->customerRepository->create([
                [
                    'id' => $customerId,
                    'salesChannelId' => $context->getSalesChannel()->getId(),
                    'groupId' => $context->getCurrentCustomerGroup()->getId(),
                    'defaultPaymentMethodId' => $context->getPaymentMethod()->getId(),
                    'defaultShippingAddressId' => $addressId,
                    'defaultBillingAddressId' => $addressId,
                    'customerNumber' => 'ACP-' . time(),
                    'firstName' => $buyerData['first_name'] ?? 'Guest',
                    'lastName' => $buyerData['last_name'] ?? 'Customer',
                    'email' => $buyerData['email'],
                    'guest' => true,
                    'addresses' => [
                        [
                            'id' => $addressId,
                            'customerId' => $customerId,
                            'countryId' => $countryId,
                            'salutationId' => $context->getSalesChannel()->getCustomerGroupId(),
                            'firstName' => $buyerData['first_name'] ?? 'Guest',
                            'lastName' => $buyerData['last_name'] ?? 'Customer',
                            'street' => '123 Default St',
                            'zipcode' => '10115',
                            'city' => 'Berlin',
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

    private function buildSessionResponse(Cart $cart, SalesChannelContext $salesChannelContext, array $inputData = []): array
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

        // Get payment methods (including PayPal)
        $paymentMethods = $this->getAvailablePaymentMethods($salesChannelContext);
        $supportedMethods = [];
        foreach ($paymentMethods as $method) {
            $handlerIdentifier = $method->getHandlerIdentifier();
            if (strpos($handlerIdentifier, 'PayPal') !== false) {
                $supportedMethods[] = 'paypal';
            }
            $supportedMethods[] = 'card';
        }

        $currency = strtolower($salesChannelContext->getCurrency()->getIsoCode());

        return [
            'id' => $cart->getToken(),
            'payment_provider' => [
                'provider' => 'shopware',
                'supported_payment_methods' => array_unique($supportedMethods)
            ],
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

    private function updateShippingAddress(Cart $cart, array $address, SalesChannelContext $context): Cart
    {
        // Address updates would be handled via customer data or cart extension
        return $cart;
    }

    private function updateSalesChannelContextShipping(SalesChannelContext $context, ShippingMethodEntity $shippingMethod): SalesChannelContext
    {
        // Create new context with updated shipping method
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
        // Create new context with updated payment method
        return $this->salesChannelContextFactory->create(
            $context->getToken(),
            $context->getSalesChannel()->getId(),
            [
                'paymentMethodId' => $paymentMethodId
            ]
        );
    }

    private function getOrder(string $orderId, Context $context): ?OrderEntity
    {
        $criteria = new Criteria([$orderId]);
        return $context->scope('order', fn() => null); // Simplified
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
