import express from 'express';
import axios from 'axios';
import path from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const app = express();
const PORT = 3000;

// Shopware ACP Configuration
const SHOPWARE_URL = process.env.SHOPWARE_URL || 'http://localhost:80';
const CLIENT_ID = process.env.CLIENT_ID || 'SWIANVDVQ2ZQCNHUSEFOVK95VG';
const CLIENT_SECRET = process.env.CLIENT_SECRET || 'czVkcWRZWXdQZTF6OFZXRVU3eGJoUkV2MTRoNWJ6clFoUzlIOFg';
const API_VERSION = '2025-09-29';
const PRODUCTIVE_MODE = process.env.PRODUCTIVE_MODE === 'true';

let accessToken = null;
let tokenExpiry = null;

app.use(express.json());
app.use(express.static('public'));

// Get OAuth token
async function getAccessToken() {
    if (accessToken && tokenExpiry && Date.now() < tokenExpiry) {
        return accessToken;
    }

    try {
        const response = await axios.post(`${SHOPWARE_URL}/api/oauth/token`, 
            new URLSearchParams({
                client_id: CLIENT_ID,
                client_secret: CLIENT_SECRET,
                grant_type: 'client_credentials'
            }));

        accessToken = response.data.access_token;
        tokenExpiry = Date.now() + (response.data.expires_in * 1000) - 60000; // Refresh 1 min before expiry
        
        console.log('✓ OAuth token obtained');
        return accessToken;
    } catch (error) {
        console.error('✗ Failed to get OAuth token:', error.message);
        throw error;
    }
}

// API endpoints

// Get real products from Shopware
app.get('/api/agent/products', async (req, res) => {
    try {
        const token = await getAccessToken();
        
        // Use Shopware API to get real products
        const response = await axios.post(
            `${SHOPWARE_URL}/api/search/product`,
            {
                limit: 3,
                filter: [
                    { type: 'equals', field: 'active', value: true }
                ],
                associations: {
                    cover: {
                        associations: {
                            media: {}
                        }
                    }
                }
            },
            {
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': `Bearer ${token}`
                }
            }
        );

        const products = response.data.data.map(product => {
            const price = product.price ? product.price[0] : null;
            const coverUrl = product.cover?.media?.url || 'https://images.unsplash.com/photo-1578500494198-246f612d3b3d?w=400';
            
            return {
                id: product.productNumber,
                name: product.translated?.name || product.name || 'Product',
                description: product.translated?.description || product.description || 'Great product from our store',
                price: price ? Math.round(price.gross * 100) : 9900,
                currency: 'eur',
                image: coverUrl.startsWith('http') ? coverUrl : `${SHOPWARE_URL}${coverUrl}`,
                vendor: 'Demo Merchant',
                shipping: 'Free shipping, 3-5 business days'
            };
        });

        res.json({ products });
    } catch (error) {
        console.error('Error fetching products:', error.message);
        // Fallback to demo products if Shopware API fails
        res.json({ 
            products: [
                {
                    id: 'demo-product-1',
                    name: 'Demo Product',
                    description: 'A demo product from the merchant',
                    price: 9900,
                    currency: 'eur',
                    image: 'https://images.unsplash.com/photo-1578500494198-246f612d3b3d?w=400',
                    vendor: 'Demo Merchant',
                    shipping: 'Free shipping'
                }
            ] 
        });
    }
});

// Create payment token
app.post('/api/agent/create-payment-token', async (req, res) => {
    try {
        const token = await getAccessToken();
        
        const { cardNumber, expMonth, expYear, cvc, cardBrand, checkoutSessionId } = req.body;
        
        const paymentData = {
            payment_method: {
                type: 'card',
                card_number_type: 'fpan',
                virtual: false,
                number: cardNumber,
                exp_month: expMonth,
                exp_year: expYear,
                cvc: cvc,
                display_card_funding_type: 'credit',
                display_brand: cardBrand || 'visa',
                display_last4: cardNumber.slice(-4),
                metadata: {}
            },
            allowance: {
                reason: 'one_time',
                max_amount: 100000,
                currency: 'eur',
                checkout_session_id: checkoutSessionId,
                merchant_id: 'dummy_agent',
                expires_at: new Date(Date.now() + 3600000).toISOString()
            },
            risk_signals: [
                { type: 'card_testing', score: 5, action: 'authorized' }
            ],
            metadata: {
                source: 'dummy_agent_chat',
                timestamp: new Date().toISOString()
            }
        };

        const response = await axios.post(
            `${SHOPWARE_URL}/api/agentic_commerce/delegate_payment`,
            paymentData,
            {
                headers: {
                    'Content-Type': 'application/json',
                    'API-Version': API_VERSION,
                    'Authorization': `Bearer ${token}`
                }
            }
        );

        res.json(response.data);
    } catch (error) {
        console.error('Error creating payment token:', error.response?.data || error.message);
        res.status(error.response?.status || 500).json({
            error: error.response?.data || { message: error.message }
        });
    }
});

// Create checkout session
app.post('/api/agent/checkout', async (req, res) => {
    try {
        const token = await getAccessToken();
        
        const { items, fulfillmentAddress } = req.body;
        
        const sessionData = {
            items: items,
            fulfillment_address: fulfillmentAddress
        };

        const response = await axios.post(
            `${SHOPWARE_URL}/api/checkout_sessions`,
            sessionData,
            {
                headers: {
                    'Content-Type': 'application/json',
                    'API-Version': API_VERSION,
                    'Authorization': `Bearer ${token}`
                }
            }
        );

        res.json(response.data);
    } catch (error) {
        console.error('Error creating checkout:', error.response?.data || error.message);
        res.status(error.response?.status || 500).json({
            error: error.response?.data || { message: error.message }
        });
    }
});

// Complete checkout
app.post('/api/agent/complete-checkout', async (req, res) => {
    try {
        const token = await getAccessToken();
        
        const { sessionId, buyer, paymentToken } = req.body;
        
        const completeData = {
            buyer: buyer,
            payment_data: {
                token: paymentToken,
                provider: 'shopware'
            }
        };

        const response = await axios.post(
            `${SHOPWARE_URL}/api/checkout_sessions/${sessionId}/complete`,
            completeData,
            {
                headers: {
                    'Content-Type': 'application/json',
                    'API-Version': API_VERSION,
                    'Authorization': `Bearer ${token}`
                }
            }
        );

        res.json(response.data);
    } catch (error) {
        console.error('Error completing checkout:', error.response?.data || error.message);
        res.status(error.response?.status || 500).json({
            error: error.response?.data || { message: error.message }
        });
    }
});

// Health check
app.get('/api/health', (req, res) => {
    res.json({ 
        status: 'ok', 
        shopware_url: SHOPWARE_URL,
        api_version: API_VERSION
    });
});

app.listen(PORT, () => {
    console.log(`
========================================
🤖 ACP Dummy Agent Chat Interface
========================================
Server running on: http://localhost:${PORT}
Shopware ACP API: ${SHOPWARE_URL}
API Version: ${API_VERSION}
Mode: ${PRODUCTIVE_MODE ? '🔴 PRODUCTION (PayPal)' : '🔵 DEMO'}
========================================

Open http://localhost:${PORT} in your browser to start!

${PRODUCTIVE_MODE ? '⚠️  PRODUCTION MODE: Using real PayPal integration' : 'ℹ️  DEMO MODE: Using dummy tokens'}
    `);
});

