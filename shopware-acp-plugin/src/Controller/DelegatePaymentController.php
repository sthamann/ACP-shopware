<?php declare(strict_types=1);

namespace Acp\ShopwarePlugin\Controller;

use Acp\ShopwarePlugin\Service\PaymentTokenService;
use Acp\ShopwarePlugin\Service\AcpComplianceService;
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
    private AcpComplianceService $acpComplianceService;

    public function __construct(
        PaymentTokenService $paymentTokenService,
        AcpComplianceService $acpComplianceService
    ) {
        $this->paymentTokenService = $paymentTokenService;
        $this->acpComplianceService = $acpComplianceService;
    }

    /**
     * Delegate payment credential validation and storage per ACP spec
     * Validates and stores external payment tokens created by PSPs
     */
    #[Route(path: '/api/agentic_commerce/delegate_payment', name: 'api.external_token.register', methods: ['POST'])]
    public function register(Request $request, Context $context): JsonResponse
    {
        // Apply all ACP compliance checks
        if ($error = $this->acpComplianceService->validateRequest($request, $context)) {
            return $error;
        }

        $data = json_decode($request->getContent(), true);

        // Validate required fields - this endpoint now validates external tokens, doesn't create them
        if (!isset($data['payment_method'])) {
            return $this->acpComplianceService->errorResponse(
                'invalid_request',
                'missing',
                'payment_method is required',
                '$.payment_method',
                400
            );
        }

        if (!isset($data['allowance'])) {
            return $this->acpComplianceService->errorResponse(
                'invalid_request',
                'missing',
                'allowance is required',
                '$.allowance',
                400
            );
        }

        // Validate allowance structure
        $requiredAllowanceFields = ['reason', 'max_amount', 'currency', 'checkout_session_id', 'merchant_id', 'expires_at'];
        foreach ($requiredAllowanceFields as $field) {
            if (!isset($data['allowance'][$field])) {
                return $this->acpComplianceService->errorResponse(
                    'invalid_request',
                    'missing',
                    "allowance.${field} is required",
                    "$.allowance.${field}",
                    400
                );
            }
        }

        // Validate risk signals
        if (!isset($data['risk_signals']) || !is_array($data['risk_signals'])) {
            return $this->acpComplianceService->errorResponse(
                'invalid_request',
                'missing',
                'risk_signals array is required',
                '$.risk_signals',
                400
            );
        }

        // Validate payment method structure
        if (!isset($data['payment_method']['type']) || $data['payment_method']['type'] !== 'card') {
            return $this->acpComplianceService->errorResponse(
                'invalid_request',
                'invalid_card_type',
                'Only card payment methods are supported',
                '$.payment_method.type',
                400
            );
        }

        try {
            // Store the external token (created by PSP) for later use
            $response = $this->paymentTokenService->storeExternalToken([
                'payment_method' => $data['payment_method'],
                'allowance' => $data['allowance'],
                'risk_signals' => $data['risk_signals'],
                'metadata' => $data['metadata'] ?? []
            ], $context);

            // ACP spec compliant response format
            $responseData = [
                'id' => $response['id'],
                'created' => $response['created'],
                'metadata' => $response['metadata'] ?? []
            ];

            // Store idempotency response
            $this->acpComplianceService->storeIdempotencyResponse($request, $responseData, 201, $context);

            return new JsonResponse($responseData, 201, [
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

    /**
     * Validate an external token status
     */
    #[Route(path: '/api/agentic_commerce/validate_token/{token}', name: 'api.external_token.validate', methods: ['GET'])]
    public function validate(string $token, Request $request, Context $context): JsonResponse
    {
        // Validate API version
        if ($error = $this->acpComplianceService->validateApiVersion($request)) {
            return $error;
        }
        
        $provider = $request->query->get('provider');
        
        if (!$provider) {
            return $this->acpComplianceService->errorResponse(
                'invalid_request',
                'missing',
                'provider query parameter is required',
                null,
                400
            );
        }

        try {
            $tokenData = $this->paymentTokenService->getExternalToken($token, $provider, $context);
            
            if (!$tokenData) {
                return $this->acpComplianceService->errorResponse(
                    'invalid_request',
                    'token_not_found',
                    'Token not found',
                    null,
                    404
                );
            }

            $isValid = !$tokenData->isUsed() && 
                      (!$tokenData->getExpiresAt() || $tokenData->getExpiresAt() > new \DateTime());

            return new JsonResponse([
                'valid' => $isValid,
                'used' => $tokenData->isUsed(),
                'expires_at' => $tokenData->getExpiresAt() ? $tokenData->getExpiresAt()->format('c') : null,
                'provider' => $tokenData->getProvider()
            ], 200, [
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
}