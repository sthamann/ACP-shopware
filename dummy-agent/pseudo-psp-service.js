#!/usr/bin/env node

/**
 * Pseudo Payment Service Provider (PSP) Service
 *
 * Simulates PayPal/Stripe behavior for ACP testing:
 * - Creates vault tokens from card data (like PayPal ACDC or Stripe tokenization)
 * - Returns ACP-compliant vault token IDs
 * - Used by dummy agent to test correct ACP flow
 */

import express from 'express';
import cors from 'cors';

const app = express();
const PORT = 3001;

// In-memory storage for demo purposes
const vaultTokens = new Map();

app.use(cors());
app.use(express.json());

// Generate a unique vault token ID
function generateVaultTokenId(provider) {
    const prefix = provider === 'paypal' ? 'vt_paypal_' : 'pm_';
    const suffix = Math.random().toString(36).substring(2, 15) + Math.random().toString(36).substring(2, 15);
    return prefix + suffix;
}

// Simulate PayPal ACDC vault token creation
app.post('/api/psp/paypal/vault', (req, res) => {
    const { payment_method, allowance } = req.body;

    console.log('🎯 Pseudo PayPal: Creating vault token for card ending in', payment_method.number.slice(-4));

    // Simulate PayPal vault creation
    const vaultTokenId = generateVaultTokenId('paypal');

    // Store token data
    vaultTokens.set(vaultTokenId, {
        id: vaultTokenId,
        provider: 'paypal',
        payment_method: payment_method,
        allowance: allowance,
        created_at: new Date().toISOString(),
        status: 'active'
    });

    console.log('✅ Pseudo PayPal: Vault token created:', vaultTokenId);

    // Return ACP-compliant response
    res.json({
        id: vaultTokenId,
        created: new Date().toISOString(),
        metadata: {
            source: 'pseudo_paypal',
            provider: 'paypal',
            card_last4: payment_method.number.slice(-4),
            card_brand: payment_method.display_brand,
            merchant_id: allowance.merchant_id,
            checkout_session_id: allowance.checkout_session_id
        }
    });
});

// Simulate Stripe payment method creation
app.post('/api/psp/stripe/payment-methods', (req, res) => {
    const { payment_method, allowance } = req.body;

    console.log('💳 Pseudo Stripe: Creating payment method for card ending in', payment_method.number.slice(-4));

    // Simulate Stripe payment method creation
    const paymentMethodId = generateVaultTokenId('stripe');

    // Store token data
    vaultTokens.set(paymentMethodId, {
        id: paymentMethodId,
        provider: 'stripe',
        payment_method: payment_method,
        allowance: allowance,
        created_at: new Date().toISOString(),
        status: 'active'
    });

    console.log('✅ Pseudo Stripe: Payment method created:', paymentMethodId);

    // Return Stripe-style response (but ACP-compliant structure)
    res.json({
        id: paymentMethodId,
        created: new Date().toISOString(),
        metadata: {
            source: 'pseudo_stripe',
            provider: 'stripe',
            card_last4: payment_method.number.slice(-4),
            card_brand: payment_method.display_brand,
            merchant_id: allowance.merchant_id,
            checkout_session_id: allowance.checkout_session_id
        }
    });
});

// Simulate Adyen token creation
app.post('/api/psp/adyen/tokens', (req, res) => {
    const { payment_method, allowance } = req.body;

    console.log('🌐 Pseudo Adyen: Creating token for card ending in', payment_method.number.slice(-4));

    // Simulate Adyen token creation
    const tokenId = generateVaultTokenId('adyen');

    // Store token data
    vaultTokens.set(tokenId, {
        id: tokenId,
        provider: 'adyen',
        payment_method: payment_method,
        allowance: allowance,
        created_at: new Date().toISOString(),
        status: 'active'
    });

    console.log('✅ Pseudo Adyen: Token created:', tokenId);

    // Return Adyen-style response (but ACP-compliant structure)
    res.json({
        id: tokenId,
        created: new Date().toISOString(),
        metadata: {
            source: 'pseudo_adyen',
            provider: 'adyen',
            card_last4: payment_method.number.slice(-4),
            card_brand: payment_method.display_brand,
            merchant_id: allowance.merchant_id,
            checkout_session_id: allowance.checkout_session_id
        }
    });
});

// Get vault token details (for debugging)
app.get('/api/psp/tokens/:tokenId', (req, res) => {
    const token = vaultTokens.get(req.params.tokenId);

    if (!token) {
        return res.status(404).json({ error: 'Token not found' });
    }

    res.json(token);
});

// List all vault tokens (for debugging)
app.get('/api/psp/tokens', (req, res) => {
    const tokens = Array.from(vaultTokens.values()).map(token => ({
        id: token.id,
        provider: token.provider,
        created_at: token.created_at,
        card_last4: token.payment_method?.number?.slice(-4) || 'unknown'
    }));

    res.json({ tokens });
});

// Health check
app.get('/health', (req, res) => {
    res.json({
        status: 'ok',
        service: 'Pseudo PSP Service',
        providers: ['paypal', 'stripe', 'adyen'],
        tokens_count: vaultTokens.size,
        timestamp: new Date().toISOString()
    });
});

app.listen(PORT, () => {
    console.log(`
╔══════════════════════════════════════╗
║      🤖 Pseudo PSP Service          ║
║     (Simulates PayPal/Stripe)        ║
╚══════════════════════════════════════╝
`);
    console.log(`🚀 Server running on: http://localhost:${PORT}`);
    console.log(`📋 Health check:      http://localhost:${PORT}/health`);
    console.log(`🎯 PayPal vault:      POST /api/psp/paypal/vault`);
    console.log(`💳 Stripe PM:         POST /api/psp/stripe/payment-methods`);
    console.log(`🌐 Adyen tokens:      POST /api/psp/adyen/tokens`);
    console.log(`🔍 List tokens:       GET  /api/psp/tokens`);
    console.log('');
    console.log('✅ Ready to simulate PSP tokenization for ACP testing');
    console.log('');
});
