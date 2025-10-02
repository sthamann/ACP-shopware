<?php declare(strict_types=1);

namespace Acp\ShopwarePlugin\Service;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Service to ensure ACP specification compliance
 * Handles API versioning, idempotency, error formatting, and request signing
 */
class AcpComplianceService
{
    private const SUPPORTED_API_VERSION = '2025-09-29';
    private const IDEMPOTENCY_TTL = 86400; // 24 hours in seconds
    private const HMAC_ALGORITHM = 'sha256';
    
    private EntityRepository $idempotencyRepository;
    private string $signingSecret;

    public function __construct(
        EntityRepository $idempotencyRepository,
        ?string $signingSecret = null
    ) {
        $this->idempotencyRepository = $idempotencyRepository;
        $this->signingSecret = $signingSecret ?? getenv('ACP_SIGNING_SECRET') ?? 'default-secret';
    }

    /**
     * Validate API version header
     */
    public function validateApiVersion(Request $request): ?JsonResponse
    {
        $apiVersion = $request->headers->get('API-Version');
        
        if (!$apiVersion) {
            return $this->errorResponse(
                'invalid_request',
                'missing_header',
                'API-Version header is required',
                null,
                400
            );
        }
        
        if ($apiVersion !== self::SUPPORTED_API_VERSION) {
            return $this->errorResponse(
                'invalid_request',
                'unsupported_version',
                sprintf('API-Version %s is required, got %s', self::SUPPORTED_API_VERSION, $apiVersion),
                null,
                400
            );
        }
        
        return null; // Valid
    }

    /**
     * Handle idempotency key processing
     */
    public function handleIdempotency(Request $request, Context $context): ?array
    {
        $idempotencyKey = $request->headers->get('Idempotency-Key');
        
        if (!$idempotencyKey || !in_array($request->getMethod(), ['POST', 'PUT', 'PATCH'])) {
            return null; // No idempotency key or not a mutating request
        }
        
        // Clean up old idempotency records
        $this->cleanupOldIdempotencyKeys($context);
        
        // Check for existing idempotency record
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('key', $idempotencyKey));
        $criteria->setLimit(1);
        
        $existing = $this->idempotencyRepository->search($criteria, $context)->first();
        
        if ($existing) {
            $requestHash = $this->hashRequest($request);
            
            if ($existing->get('requestHash') !== $requestHash) {
                // Same key with different parameters - conflict
                return [
                    'conflict' => true,
                    'response' => $this->errorResponse(
                        'invalid_request',
                        'idempotency_conflict',
                        'Same Idempotency-Key used with different parameters',
                        null,
                        409
                    )
                ];
            }
            
            // Return cached response
            return [
                'cached' => true,
                'response' => json_decode($existing->get('response'), true),
                'statusCode' => $existing->get('statusCode')
            ];
        }
        
        return null; // New request, proceed
    }

    /**
     * Store idempotency response
     */
    public function storeIdempotencyResponse(
        Request $request,
        array $response,
        int $statusCode,
        Context $context
    ): void {
        $idempotencyKey = $request->headers->get('Idempotency-Key');
        
        if (!$idempotencyKey || !in_array($request->getMethod(), ['POST', 'PUT', 'PATCH'])) {
            return; // No idempotency key to store
        }
        
        $this->idempotencyRepository->create([
            [
                'id' => Uuid::randomHex(),
                'key' => $idempotencyKey,
                'requestHash' => $this->hashRequest($request),
                'response' => json_encode($response),
                'statusCode' => $statusCode,
                'expiresAt' => new \DateTime('+24 hours')
            ]
        ], $context);
    }

    /**
     * Verify request signature
     */
    public function verifySignature(Request $request): ?JsonResponse
    {
        $signature = $request->headers->get('Signature');
        $timestamp = $request->headers->get('Timestamp');
        
        if (!$signature || !$timestamp) {
            return null; // Signature not provided, skip verification (optional per spec)
        }
        
        // Check timestamp freshness (5 minutes)
        try {
            $requestTime = new \DateTime($timestamp);
            $now = new \DateTime();
            $diff = $now->getTimestamp() - $requestTime->getTimestamp();
            
            if (abs($diff) > 300) {
                return $this->errorResponse(
                    'invalid_request',
                    'timestamp_expired',
                    'Request timestamp is too old or in the future',
                    null,
                    400
                );
            }
        } catch (\Exception $e) {
            return $this->errorResponse(
                'invalid_request',
                'invalid_timestamp',
                'Invalid timestamp format',
                null,
                400
            );
        }
        
        // Verify signature
        $canonicalRequest = $this->canonicalizeRequest($request);
        $expectedSignature = base64_encode(
            hash_hmac(self::HMAC_ALGORITHM, $canonicalRequest, $this->signingSecret, true)
        );
        
        if (!hash_equals($expectedSignature, $signature)) {
            return $this->errorResponse(
                'invalid_request',
                'invalid_signature',
                'Request signature verification failed',
                null,
                401
            );
        }
        
        return null; // Valid
    }

    /**
     * Format error response according to ACP spec
     */
    public function errorResponse(
        string $type,
        string $code,
        string $message,
        ?string $param = null,
        int $statusCode = 400
    ): JsonResponse {
        $error = [
            'type' => $type,
            'code' => $code,
            'message' => $message
        ];
        
        if ($param !== null) {
            $error['param'] = $param;
        }
        
        return new JsonResponse($error, $statusCode);
    }

    /**
     * Add payment provider information to response
     */
    public function addPaymentProviderInfo(array $response, string $provider = 'stripe'): array
    {
        if (!isset($response['payment_provider'])) {
            $response['payment_provider'] = [
                'provider' => $provider,
                'supported_payment_methods' => ['card']
            ];
        }
        
        return $response;
    }

    /**
     * Format order object according to ACP spec
     */
    public function formatOrderObject(string $orderId, string $checkoutSessionId, string $baseUrl = ''): array
    {
        if (empty($baseUrl)) {
            $baseUrl = getenv('APP_URL') ?: 'https://shop.example.com';
        }
        
        return [
            'id' => $orderId,
            'checkout_session_id' => $checkoutSessionId,
            'permalink_url' => sprintf('%s/account/order/%s', rtrim($baseUrl, '/'), $orderId)
        ];
    }

    /**
     * Clean up expired idempotency keys
     */
    private function cleanupOldIdempotencyKeys(Context $context): void
    {
        $criteria = new Criteria();
        $criteria->addFilter(new RangeFilter('expiresAt', [
            RangeFilter::LTE => (new \DateTime())->format('Y-m-d H:i:s')
        ]));
        
        $expiredKeys = $this->idempotencyRepository->search($criteria, $context);
        
        if ($expiredKeys->count() > 0) {
            $ids = array_map(fn($key) => ['id' => $key->getId()], $expiredKeys->getElements());
            $this->idempotencyRepository->delete($ids, $context);
        }
    }

    /**
     * Hash request for idempotency comparison
     */
    private function hashRequest(Request $request): string
    {
        $data = [
            'method' => $request->getMethod(),
            'path' => $request->getPathInfo(),
            'query' => $request->query->all(),
            'body' => $request->getContent()
        ];
        
        return hash('sha256', json_encode($data));
    }

    /**
     * Create canonical request string for signature verification
     */
    private function canonicalizeRequest(Request $request): string
    {
        $parts = [
            $request->getMethod(),
            $request->getPathInfo(),
            $request->headers->get('Timestamp'),
            $request->getContent()
        ];
        
        return implode("\n", $parts);
    }

    /**
     * Apply all ACP compliance checks to a request
     */
    public function validateRequest(Request $request, Context $context): ?JsonResponse
    {
        // Check API version
        if ($error = $this->validateApiVersion($request)) {
            return $error;
        }
        
        // Check idempotency
        $idempotencyResult = $this->handleIdempotency($request, $context);
        if ($idempotencyResult) {
            if (isset($idempotencyResult['conflict'])) {
                return $idempotencyResult['response'];
            }
            if (isset($idempotencyResult['cached'])) {
                return new JsonResponse(
                    $idempotencyResult['response'],
                    $idempotencyResult['statusCode']
                );
            }
        }
        
        // Verify signature (optional)
        if ($error = $this->verifySignature($request)) {
            return $error;
        }
        
        return null; // All validations passed
    }
}
