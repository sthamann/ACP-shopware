<?php declare(strict_types=1);

namespace Acp\ShopwarePlugin\Controller;

use Acp\ShopwarePlugin\Service\CheckoutSessionService;
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

    public function __construct(
        CheckoutSessionService $checkoutSessionService,
        EntityRepository $checkoutSessionRepository
    ) {
        $this->checkoutSessionService = $checkoutSessionService;
        $this->checkoutSessionRepository = $checkoutSessionRepository;
    }

    #[Route(path: '/api/checkout_sessions', name: 'api.checkout_sessions.create', methods: ['POST'])]
    public function create(Request $request, Context $context): JsonResponse
    {
        // Validate API version
        $apiVersion = $request->headers->get('API-Version');
        if ($apiVersion !== '2025-09-29') {
            return new JsonResponse([
                'type' => 'invalid_request',
                'code' => 'unsupported_version',
                'message' => 'API-Version header must be 2025-09-29'
            ], 400);
        }

        $data = json_decode($request->getContent(), true);

        // Validate data
        if (!isset($data['items']) || !is_array($data['items']) || empty($data['items'])) {
            return new JsonResponse([
                'type' => 'invalid_request',
                'code' => 'invalid',
                'message' => 'Items required',
                'param' => '$.items'
            ], 400);
        }

        try {
            $response = $this->checkoutSessionService->createSession($data, $context);

            $salesChannelId = $this->checkoutSessionService->resolveSalesChannelId($context);

            $sessionId = Uuid::randomHex();
            $this->checkoutSessionRepository->create([
                [
                    'id' => $sessionId,
                    'cartToken' => $response['id'],
                    'salesChannelId' => $salesChannelId,
                    'status' => $response['status'],
                    'data' => json_encode($response)
                ]
            ], $context);

            return new JsonResponse($response, 201, [
                'Idempotency-Key' => $request->headers->get('Idempotency-Key', ''),
                'Request-Id' => $request->headers->get('Request-Id', '')
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'type' => 'processing_error',
                'code' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    #[Route(path: '/api/checkout_sessions/{id}', name: 'api.checkout_sessions.retrieve', methods: ['GET'])]
    public function retrieve(string $id, Context $context): JsonResponse
    {
        $criteria = new Criteria();
        $criteria->addFilter(new \Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter('cartToken', $id));
        
        $session = $this->checkoutSessionRepository->search($criteria, $context)->first();

        if (!$session) {
            return new JsonResponse([
                'type' => 'invalid_request',
                'code' => 'not_found',
                'message' => 'Session not found'
            ], 404);
        }

        $sessionData = json_decode($session->getData(), true);
        
        return new JsonResponse($sessionData);
    }

    #[Route(path: '/api/checkout_sessions/{id}', name: 'api.checkout_sessions.update', methods: ['POST'])]
    public function update(string $id, Request $request, Context $context): JsonResponse
    {
        $criteria = new Criteria();
        $criteria->addFilter(new \Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter('cartToken', $id));
        
        $session = $this->checkoutSessionRepository->search($criteria, $context)->first();

        if (!$session) {
            return new JsonResponse([
                'type' => 'invalid_request',
                'code' => 'not_found',
                'message' => 'Session not found'
            ], 404);
        }

        $data = json_decode($request->getContent(), true);
        $currentData = json_decode($session->getData(), true);

        try {
            // Get cart and sales channel context (simplified - would need proper reconstruction)
            $cart = new \Shopware\Core\Checkout\Cart\Cart($id, $id);
            $salesChannelContext = $this->checkoutSessionService->getDefaultSalesChannelContext($context);
            
            $response = $this->checkoutSessionService->updateSession($id, $data, $cart, $salesChannelContext);
            
            // Update persisted session
            $this->checkoutSessionRepository->update([
                [
                    'id' => $session->getId(),
                    'status' => $response['status'],
                    'data' => json_encode($response)
                ]
            ], $context);

            return new JsonResponse($response);
        } catch (\Exception $e) {
            return new JsonResponse([
                'type' => 'processing_error',
                'code' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    #[Route(path: '/api/checkout_sessions/{id}/complete', name: 'api.checkout_sessions.complete', methods: ['POST'])]
    public function complete(string $id, Request $request, Context $context): JsonResponse
    {
        $criteria = new Criteria();
        $criteria->addFilter(new \Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter('cartToken', $id));
        
        $session = $this->checkoutSessionRepository->search($criteria, $context)->first();

        if (!$session) {
            return new JsonResponse([
                'type' => 'invalid_request',
                'code' => 'not_found',
                'message' => 'Session not found'
            ], 404);
        }

        $data = json_decode($request->getContent(), true);

        if (!isset($data['payment_data'])) {
            return new JsonResponse([
                'type' => 'invalid_request',
                'code' => 'invalid',
                'message' => 'payment_data required',
                'param' => '$.payment_data'
            ], 400);
        }

        try {
            // Load the actual cart from session data
            $sessionData = json_decode($session->getData(), true);
            $cartToken = $sessionData['id'] ?? $id;
            
            // Get sales channel context
            $salesChannelContext = $this->checkoutSessionService->getDefaultSalesChannelContext($context);
            
            // Load the cart
            $cart = $this->checkoutSessionService->getCart($cartToken, $salesChannelContext);
            
            // Handle payment token if provided
            $paymentToken = $data['payment_data']['token'] ?? null;
            
            // Complete the session and create order
            $response = $this->checkoutSessionService->completeSession($cart, $data, $salesChannelContext, $paymentToken);
            
            // Update session with order ID
            $this->checkoutSessionRepository->update([
                [
                    'id' => $session->getId(),
                    'status' => 'completed',
                    'orderId' => $response['order']['id'],
                    'data' => json_encode($response)
                ]
            ], $context);

            return new JsonResponse($response);
        } catch (\Exception $e) {
            error_log('Checkout completion error: ' . $e->getMessage());
            return new JsonResponse([
                'type' => 'processing_error',
                'code' => 'payment_declined',
                'message' => $e->getMessage()
            ], 400);
        }
    }

    #[Route(path: '/api/checkout_sessions/{id}/cancel', name: 'api.checkout_sessions.cancel', methods: ['POST'])]
    public function cancel(string $id, Context $context): JsonResponse
    {
        $criteria = new Criteria();
        $criteria->addFilter(new \Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter('cartToken', $id));
        
        $session = $this->checkoutSessionRepository->search($criteria, $context)->first();

        if (!$session) {
            return new JsonResponse([
                'type' => 'invalid_request',
                'code' => 'not_found',
                'message' => 'Session not found'
            ], 404);
        }

        $sessionData = json_decode($session->getData(), true);
        
        if (in_array($sessionData['status'] ?? '', ['completed', 'canceled'])) {
            return new JsonResponse([
                'type' => 'invalid_request',
                'code' => 'invalid_state',
                'message' => 'Cannot cancel completed or canceled session'
            ], 405);
        }

        $sessionData['status'] = 'canceled';
        $sessionData['messages'] = [
            [
                'type' => 'info',
                'content_type' => 'plain',
                'content' => 'Checkout session has been canceled.'
            ]
        ];

        $this->checkoutSessionRepository->update([
            [
                'id' => $session->getId(),
                'status' => 'canceled',
                'data' => json_encode($sessionData)
            ]
        ], $context);

        return new JsonResponse($sessionData);
    }
}
