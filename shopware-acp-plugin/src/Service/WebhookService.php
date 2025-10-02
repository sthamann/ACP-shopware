<?php declare(strict_types=1);

namespace Acp\ShopwarePlugin\Service;

use Shopware\Core\Framework\Context;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Service for sending ACP-compliant webhooks to agents
 */
class WebhookService
{
    private SystemConfigService $systemConfigService;

    public function __construct(SystemConfigService $systemConfigService)
    {
        $this->systemConfigService = $systemConfigService;
    }

    /**
     * Send order create webhook
     */
    public function sendOrderCreateWebhook(string $checkoutSessionId, string $orderId, string $permalinkUrl, Context $context): void
    {
        $webhookUrl = $this->getWebhookUrl($context);
        if (!$webhookUrl) {
            return; // No webhook configured
        }

        $payload = [
            'type' => 'order_create',
            'data' => [
                'type' => 'order',
                'checkout_session_id' => $checkoutSessionId,
                'permalink_url' => $permalinkUrl,
                'status' => 'created',
                'refunds' => []
            ]
        ];

        $this->sendWebhook($webhookUrl, $payload, $checkoutSessionId);
    }

    /**
     * Send order update webhook
     */
    public function sendOrderUpdateWebhook(string $checkoutSessionId, string $orderId, string $permalinkUrl, string $status, array $refunds = [], Context $context): void
    {
        $webhookUrl = $this->getWebhookUrl($context);
        if (!$webhookUrl) {
            return; // No webhook configured
        }

        $payload = [
            'type' => 'order_update',
            'data' => [
                'type' => 'order',
                'checkout_session_id' => $checkoutSessionId,
                'permalink_url' => $permalinkUrl,
                'status' => $status,
                'refunds' => $refunds
            ]
        ];

        $this->sendWebhook($webhookUrl, $payload, $checkoutSessionId);
    }

    /**
     * Get webhook URL from system config
     */
    private function getWebhookUrl(Context $context): ?string
    {
        // In production, this should be configurable per sales channel or globally
        // For demo purposes, we'll use a placeholder
        return $this->systemConfigService->get('AcpShopwarePlugin.config.webhookUrl');
    }

    /**
     * Send HTTP webhook request
     */
    private function sendWebhook(string $url, array $payload, string $checkoutSessionId): void
    {
        try {
            $jsonPayload = json_encode($payload);

            // Create signature (simplified for demo - in production use proper HMAC)
            $signature = hash_hmac('sha256', $jsonPayload, 'acp_webhook_secret');

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Merchant-Signature: ' . $signature,
                'Request-Id: ' . $checkoutSessionId,
                'Timestamp: ' . date('c'),
                'Content-Length: ' . strlen($jsonPayload)
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            curl_close($ch);

            if ($httpCode >= 200 && $httpCode < 300) {
                error_log("Webhook sent successfully to {$url} for session {$checkoutSessionId}");
            } else {
                error_log("Webhook failed with HTTP {$httpCode} to {$url} for session {$checkoutSessionId}");
            }
        } catch (\Exception $e) {
            error_log("Webhook error for session {$checkoutSessionId}: " . $e->getMessage());
        }
    }
}
