<?php declare(strict_types=1);

namespace Acp\ShopwarePlugin\Controller;

use Acp\ShopwarePlugin\Service\PaymentTokenService;
use Shopware\Core\Framework\Context;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Shopware\Core\Framework\Routing\ApiRouteScope;
use Shopware\Core\PlatformRequest;

#[Route(defaults: ['_routeScope' => ['api']])]
class DelegatePaymentController extends AbstractController
{
    private PaymentTokenService $paymentTokenService;

    public function __construct(PaymentTokenService $paymentTokenService)
    {
        $this->paymentTokenService = $paymentTokenService;
    }

    #[Route(path: '/api/agentic_commerce/delegate_payment', name: 'api.delegate_payment.create', methods: ['POST'])]
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

        // Validate required fields
        if (!isset($data['payment_method']['type']) || $data['payment_method']['type'] !== 'card') {
            return new JsonResponse([
                'type' => 'invalid_request',
                'code' => 'invalid_card',
                'message' => 'Invalid payment method type',
                'param' => '$.payment_method.type'
            ], 400);
        }

        if (!isset($data['allowance'])) {
            return new JsonResponse([
                'type' => 'invalid_request',
                'code' => 'missing',
                'message' => 'allowance is required',
                'param' => '$.allowance'
            ], 400);
        }

        if (!isset($data['risk_signals']) || !is_array($data['risk_signals']) || empty($data['risk_signals'])) {
            return new JsonResponse([
                'type' => 'invalid_request',
                'code' => 'missing',
                'message' => 'risk_signals is required',
                'param' => '$.risk_signals'
            ], 400);
        }

        try {
            $response = $this->paymentTokenService->createDelegatedPaymentToken($data, $context);

            return new JsonResponse($response, 201, [
                'Idempotency-Key' => $request->headers->get('Idempotency-Key', ''),
                'Request-Id' => $request->headers->get('Request-Id', '')
            ]);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse([
                'type' => 'invalid_request',
                'code' => 'invalid_card',
                'message' => $e->getMessage()
            ], 400);
        } catch (\Exception $e) {
            return new JsonResponse([
                'type' => 'processing_error',
                'code' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
