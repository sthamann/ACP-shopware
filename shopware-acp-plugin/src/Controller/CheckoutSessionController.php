<?php declare(strict_types=1);

namespace Acp\ShopwarePlugin\Controller;

use Acp\ShopwarePlugin\Service\CheckoutSessionService;
use Acp\ShopwarePlugin\Service\AcpComplianceService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Shopware\Core\Framework\Routing\ApiRouteScope;
use Shopware\Core\PlatformRequest;

#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [ApiRouteScope::ID]])]
class CheckoutSessionController extends AbstractController
{
    private CheckoutSessionService $checkoutSessionService;
    private EntityRepository $checkoutSessionRepository;
    private AcpComplianceService $acpComplianceService;

    public function __construct(
        CheckoutSessionService $checkoutSessionService,
        EntityRepository $checkoutSessionRepository,
        AcpComplianceService $acpComplianceService
    ) {
        $this->checkoutSessionService = $checkoutSessionService;
        $this->checkoutSessionRepository = $checkoutSessionRepository;
        $this->acpComplianceService = $acpComplianceService;
    }

    #[Route(path: '/api/checkout_sessions', name: 'api.checkout_sessions.create', methods: ['POST'])]
    public function create(Request $request, Context $context): JsonResponse
    {
        // Apply all ACP compliance checks
        if ($error = $this->acpComplianceService->validateRequest($request, $context)) {
            return $error;
        }

        $data = json_decode($request->getContent(), true);

        // Validate required fields
        if (!isset($data['items']) || !is_array($data['items']) || empty($data['items'])) {
            return $this->acpComplianceService->errorResponse(
                'invalid_request',
                'missing',
                'items is required',
                '$.items',
                400
            );
        }

        try {
            $response = $this->checkoutSessionService->createSession($data, $context);
            
            // Add payment provider info
            $response = $this->acpComplianceService->addPaymentProviderInfo($response);

            $salesChannelId = $this->checkoutSessionService->resolveSalesChannelId($context);

            $sessionId = Uuid::randomHex();
            $this->checkoutSessionRepository->create([
                [
                    'id' => $sessionId,
                    'cartToken' => $response['id'],
                    'salesChannelId' => $salesChannelId,
                    'customerId' => $response['customer_id'] ?? null,
                    'status' => $response['status'],
                    'data' => json_encode($response)
                ]
            ], $context);

            // Store idempotency response
            $this->acpComplianceService->storeIdempotencyResponse($request, $response, 201, $context);

            return new JsonResponse($response, 201, [
                'Idempotency-Key' => $request->headers->get('Idempotency-Key', ''),
                'Request-Id' => $request->headers->get('Request-Id', '')
            ]);
        } catch (\Exception $e) {
            return $this->acpComplianceService->errorResponse(
                'processing_error',
                'error',
                $e->getMessage(),
                null,
                500
            );
        }
    }

    #[Route(path: '/api/checkout_sessions/{id}', name: 'api.checkout_sessions.retrieve', methods: ['GET'])]
    public function retrieve(string $id, Request $request, Context $context): JsonResponse
    {
        // Validate API version
        if ($error = $this->acpComplianceService->validateApiVersion($request)) {
            return $error;
        }
        
        $criteria = new Criteria();
        $criteria->addFilter(new \Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter('cartToken', $id));
        
        $session = $this->checkoutSessionRepository->search($criteria, $context)->first();

        if (!$session) {
            return $this->acpComplianceService->errorResponse(
                'invalid_request',
                'not_found',
                'Checkout session not found',
                null,
                404
            );
        }

        $sessionData = json_decode($session->getData(), true);
        
        // Ensure payment provider info is present
        $sessionData = $this->acpComplianceService->addPaymentProviderInfo($sessionData);
        
        return new JsonResponse($sessionData, 200, [
            'Request-Id' => $request->headers->get('Request-Id', '')
        ]);
    }

    #[Route(path: '/api/checkout_sessions/{id}', name: 'api.checkout_sessions.update', methods: ['POST'])]
    public function update(string $id, Request $request, Context $context): JsonResponse
    {
        // Apply all ACP compliance checks
        if ($error = $this->acpComplianceService->validateRequest($request, $context)) {
            return $error;
        }
        
        $criteria = new Criteria();
        $criteria->addFilter(new \Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter('cartToken', $id));
        
        $session = $this->checkoutSessionRepository->search($criteria, $context)->first();

        if (!$session) {
            return $this->acpComplianceService->errorResponse(
                'invalid_request',
                'not_found',
                'Checkout session not found',
                null,
                404
            );
        }

        // Check if session can be updated
        $currentData = json_decode($session->getData(), true);
        if (in_array($currentData['status'] ?? '', ['completed', 'canceled'])) {
            return $this->acpComplianceService->errorResponse(
                'invalid_request',
                'invalid_state',
                'Cannot update completed or canceled session',
                null,
                400
            );
        }

        $data = json_decode($request->getContent(), true);

        try {
            // Get cart and sales channel context
            $cart = new \Shopware\Core\Checkout\Cart\Cart($id, $id);
            $salesChannelContext = $this->checkoutSessionService->getDefaultSalesChannelContext($context);
            
            $response = $this->checkoutSessionService->updateSession($id, $data, $cart, $salesChannelContext);
            
            // Add payment provider info
            $response = $this->acpComplianceService->addPaymentProviderInfo($response);
            
            // Update persisted session
            $this->checkoutSessionRepository->update([
                [
                    'id' => $session->getId(),
                    'status' => $response['status'],
                    'data' => json_encode($response)
                ]
            ], $context);

            // Store idempotency response
            $this->acpComplianceService->storeIdempotencyResponse($request, $response, 200, $context);

            return new JsonResponse($response, 200, [
                'Idempotency-Key' => $request->headers->get('Idempotency-Key', ''),
                'Request-Id' => $request->headers->get('Request-Id', '')
            ]);
        } catch (\Exception $e) {
            return $this->acpComplianceService->errorResponse(
                'processing_error',
                'error',
                $e->getMessage(),
                null,
                500
            );
        }
    }

    #[Route(path: '/api/checkout_sessions/{id}/complete', name: 'api.checkout_sessions.complete', methods: ['POST'])]
    public function complete(string $id, Request $request, Context $context): JsonResponse
    {
        // Apply all ACP compliance checks
        if ($error = $this->acpComplianceService->validateRequest($request, $context)) {
            return $error;
        }
        
        $criteria = new Criteria();
        $criteria->addFilter(new \Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter('cartToken', $id));
        
        $session = $this->checkoutSessionRepository->search($criteria, $context)->first();

        if (!$session) {
            return $this->acpComplianceService->errorResponse(
                'invalid_request',
                'not_found',
                'Checkout session not found',
                null,
                404
            );
        }

        // Check if session can be completed
        $currentData = json_decode($session->getData(), true);
        $sessionStatus = $currentData['status'] ?? $session->getStatus();

        if ($sessionStatus === 'completed') {
            return $this->acpComplianceService->errorResponse(
                'invalid_request',
                'already_completed',
                'Session has already been completed',
                null,
                400
            );
        }
        if ($sessionStatus === 'canceled') {
            return $this->acpComplianceService->errorResponse(
                'invalid_request',
                'session_canceled',
                'Cannot complete a canceled session',
                null,
                400
            );
        }

        $data = json_decode($request->getContent(), true);

        // Validate required payment_data
        if (!isset($data['payment_data'])) {
            return $this->acpComplianceService->errorResponse(
                'invalid_request',
                'missing',
                'payment_data is required',
                '$.payment_data',
                400
            );
        }

        if (!isset($data['payment_data']['token'])) {
            return $this->acpComplianceService->errorResponse(
                'invalid_request',
                'missing',
                'payment_data.token is required',
                '$.payment_data.token',
                400
            );
        }

        // Validate token exists and get provider from token
        $tokenId = $data['payment_data']['token'];
        $externalToken = $this->paymentTokenService->getExternalTokenById($tokenId, $context);

        if (!$externalToken) {
            return $this->acpComplianceService->errorResponse(
                'invalid_request',
                'invalid_token',
                'Payment token not found or invalid',
                '$.payment_data.token',
                400
            );
        }

        // Check if token is still valid
        if ($externalToken->isUsed() ||
            ($externalToken->getExpiresAt() && $externalToken->getExpiresAt() < new \DateTime())) {
            return $this->acpComplianceService->errorResponse(
                'invalid_request',
                'expired_token',
                'Payment token has expired or been used',
                '$.payment_data.token',
                400
            );
        }

        // Extract provider from token
        $provider = $externalToken->getProvider();

        try {
            // Load the actual cart from session data
            $sessionData = json_decode($session->getData(), true);
            $cartToken = $sessionData['id'] ?? $id;
            
            // Get sales channel context
            $salesChannelContext = $this->checkoutSessionService->getDefaultSalesChannelContext($context);
            
            // Load the cart
            $cart = $this->checkoutSessionService->getCart($cartToken, $salesChannelContext);
            
            // Complete the session and create order
            $response = $this->checkoutSessionService->completeSession($cart, $data, $salesChannelContext, $tokenId, $provider);
            
            // Add payment provider info
            $response = $this->acpComplianceService->addPaymentProviderInfo($response);
            
            // Ensure proper order object format
            if (isset($response['order']) && isset($response['order']['id'])) {
                $response['order'] = $this->acpComplianceService->formatOrderObject(
                    $response['order']['id'],
                    $id,
                    $this->getBaseUrl($salesChannelContext)
                );
            }
            
            // Update session with order ID
            $this->checkoutSessionRepository->update([
                [
                    'id' => $session->getId(),
                    'status' => 'completed',
                    'orderId' => $response['order']['id'] ?? null,
                    'paymentStatus' => $response['payment_status'] ?? 'pending',
                    'data' => json_encode($response)
                ]
            ], $context);

            // Store idempotency response
            $this->acpComplianceService->storeIdempotencyResponse($request, $response, 200, $context);

            return new JsonResponse($response, 200, [
                'Idempotency-Key' => $request->headers->get('Idempotency-Key', ''),
                'Request-Id' => $request->headers->get('Request-Id', '')
            ]);
        } catch (\Exception $e) {
            error_log('Checkout completion error: ' . $e->getMessage());
            return $this->acpComplianceService->errorResponse(
                'processing_error',
                'payment_declined',
                $e->getMessage(),
                null,
                400
            );
        }
    }

    #[Route(path: '/api/checkout_sessions/{id}/cancel', name: 'api.checkout_sessions.cancel', methods: ['POST'])]
    public function cancel(string $id, Request $request, Context $context): JsonResponse
    {
        // Apply all ACP compliance checks
        if ($error = $this->acpComplianceService->validateRequest($request, $context)) {
            return $error;
        }
        
        $criteria = new Criteria();
        $criteria->addFilter(new \Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter('cartToken', $id));
        
        $session = $this->checkoutSessionRepository->search($criteria, $context)->first();

        if (!$session) {
            return $this->acpComplianceService->errorResponse(
                'invalid_request',
                'not_found',
                'Checkout session not found',
                null,
                404
            );
        }

        $sessionData = json_decode($session->getData(), true);
        
        if (in_array($sessionData['status'] ?? '', ['completed', 'canceled'])) {
            return $this->acpComplianceService->errorResponse(
                'invalid_request',
                'invalid_state',
                'Cannot cancel completed or canceled session',
                null,
                405
            );
        }

        $sessionData['status'] = 'canceled';
        $sessionData['messages'] = [
            [
                'type' => 'info',
                'content_type' => 'plain',
                'content' => 'Checkout session has been canceled.'
            ]
        ];
        
        // Ensure payment provider info is present
        $sessionData = $this->acpComplianceService->addPaymentProviderInfo($sessionData);

        $this->checkoutSessionRepository->update([
            [
                'id' => $session->getId(),
                'status' => 'canceled',
                'data' => json_encode($sessionData)
            ]
        ], $context);

        // Store idempotency response
        $this->acpComplianceService->storeIdempotencyResponse($request, $sessionData, 200, $context);

        return new JsonResponse($sessionData, 200, [
            'Idempotency-Key' => $request->headers->get('Idempotency-Key', ''),
            'Request-Id' => $request->headers->get('Request-Id', '')
        ]);
    }
    
    /**
     * Get base URL for the sales channel
     */
    private function getBaseUrl($salesChannelContext): string
    {
        $domain = $salesChannelContext->getSalesChannel()->getDomains()->first();
        return $domain ? $domain->getUrl() : 'https://shop.example.com';
    }
}