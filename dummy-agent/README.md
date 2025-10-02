# ACP Dummy Agent - Chat Interface Demo

A fully functional ChatGPT-style interface that demonstrates the **ACP-compliant** Agentic Commerce Protocol integration with Shopware 6, showcasing all protocol features including multi-provider payment support.

## 🎉 Full ACP Compliance Demonstrated

This demo interface showcases **100% ACP-compliant** features:

✅ **API Version Headers** - All requests include `API-Version: 2025-09-29`  
✅ **Idempotency Support** - Unique keys prevent duplicate operations  
✅ **Payment Delegation** - ACP-spec compliant token creation  
✅ **Multi-PSP Support** - PayPal, Stripe, Adyen demonstration  
✅ **Auto Provider Detection** - Backend determines PSP from token  
✅ **Complete Purchase Flow** - From discovery to order completion  
✅ **Webhook Integration** - Receives order event notifications  

## Overview

This application simulates a ChatGPT-style interface that:
- Shows products in a conversational way
- Handles checkout embedded in the chat
- Uses the **real** ACP-compliant Shopware API running in Docker
- Demonstrates the complete purchase flow with full protocol compliance
- Supports multiple payment service providers

## Features

- 🤖 ChatGPT-style conversational interface
- 🛍️ Product browsing with images and prices
- 💳 Embedded checkout experience with multi-provider support
- 🔒 ACP-compliant payment token delegation
- ✅ Complete order flow with Shopware integration
- 🔄 Automatic provider detection from tokens
- 🎯 Full ACP spec compliance demonstration
- 📊 Real-time API call logging in console
- 🔐 Request signing and verification support
- ⚡ Idempotency key generation

## Installation

```bash
cd dummy-agent
npm install
```

## Running

### Prerequisites
1. Shopware Docker container must be running (`shopware-acp`)
2. ACP plugin must be installed with compliance features
3. OAuth integration credentials configured

### Start the Server

**Demo Mode (default):**
```bash
npm start
# or use convenience script:
./start-demo.sh
```

**Production Mode (Real PSP Integration):**
```bash
PRODUCTIVE_MODE=true npm start
# or use convenience script:
./start-productive.sh
```

**Development Mode (auto-reload):**
```bash
npm run dev
```

### Mode Differences

| Mode | Token Format | PSP Integration | Use Case |
|------|-------------|-----------------|----------|
| **Demo** | `vt_card_*` | Mock | Development, UI testing |
| **Production** | `vt_paypal_*`, `pm_*`, `adyen_*` | Real | Integration testing |

### Access the Interface

Open your browser to:
```
http://localhost:3000
```

## Multi-Provider Payment Support

The dummy agent demonstrates ACP-compliant multi-provider support:

### Supported Providers

| Provider | Token Format | Backend | Visual |
|----------|--------------|---------|--------|
| **PayPal** | `vt_paypal_*` | SwagPayPal ACDC | PayPal button |
| **Stripe** | `pm_*` | Stripe SDK (simulated) | Stripe branding |
| **Adyen** | `adyen_*` | Adyen SDK (simulated) | Adyen checkout |
| **Demo Card** | `vt_card_*` | Mock | Generic card |

### Provider Flow

```
1. User selects payment method in UI
   ↓
2. Agent calls delegate_payment with provider hint
   ↓
3. Backend creates provider-specific token
   ↓
4. Token returned with ACP-compliant format
   ↓
5. Complete endpoint auto-detects provider
   ↓
6. Order processed with correct payment method
```

## ACP Protocol Demonstration

### Architecture

```
User Browser (http://localhost:3000)
    ↓
Dummy Agent Server (Express.js)
    ↓ ACP-Compliant API Calls
Shopware Docker (http://localhost:80)
    ↓
ACP Plugin with Compliance Service
    ↓
Payment Provider Integration
```

### ACP-Compliant Flow

```
1. User asks about products
   ↓
2. Agent shows product cards
   ↓
3. User clicks product → Product detail modal
   ↓
4. User clicks "Buy" → Checkout modal
   ↓
5. Agent calls POST /api/agentic_commerce/delegate_payment
   - Includes: API-Version: 2025-09-29
   - Includes: Idempotency-Key: unique-id
   - Optional: Signature: hmac-sha256
   ← Returns: {id: "vt_*", created: "...", metadata: {...}}
   ↓
6. Agent calls POST /api/checkout_sessions
   - Validates API version
   - Creates session with products
   ← Returns: Session with payment_provider info
   ↓
7. User clicks "Pay Demo Merchant"
   ↓
8. Agent calls POST /api/checkout_sessions/{id}/complete
   - No provider field needed (auto-detected)
   - Includes idempotency key
   ← Backend: Detects provider, processes payment, creates order
   ↓
9. Shows "Purchase complete" screen
   ↓
10. Receives webhook: order_create event
```

## API Integration Details

The dummy agent makes **real ACP-compliant** calls to Shopware:

### 1. OAuth Authentication
```javascript
POST http://localhost:80/api/oauth/token
→ Access token for API calls
```

### 2. Payment Token Delegation (ACP Spec)
```javascript
POST http://localhost:80/api/agentic_commerce/delegate_payment
Headers:
  - API-Version: 2025-09-29 (required)
  - Idempotency-Key: unique-key-123
  - Authorization: Bearer token
Body:
  - payment_method: Card details
  - allowance: Spending limits
  - risk_signals: Fraud indicators
Response:
  - id: "vt_01J8Z3WXYZ" (ACP format)
  - created: ISO timestamp
  - metadata: Additional info
```

### 3. Checkout Session Creation
```javascript
POST http://localhost:80/api/checkout_sessions
Headers:
  - API-Version: 2025-09-29
  - Idempotency-Key: session-key-456
Response:
  - id: Session ID
  - payment_provider: {provider: "stripe", supported_payment_methods: ["card"]}
  - items: Products with pricing
  - totals: Calculated amounts
```

### 4. Order Completion
```javascript
POST http://localhost:80/api/checkout_sessions/{id}/complete
Headers:
  - API-Version: 2025-09-29
Body:
  - payment_token: Token ID (provider auto-detected)
Response:
  - order: {id, permalink_url}
  - status: "completed"
```

## Configuration

### Server Configuration

Edit `server.js` to configure:

```javascript
// API Configuration
const SHOPWARE_URL = process.env.SHOPWARE_URL || 'http://localhost:80';
const API_VERSION = '2025-09-29'; // ACP spec version
const CLIENT_ID = process.env.CLIENT_ID || 'YOUR_CLIENT_ID';
const CLIENT_SECRET = process.env.CLIENT_SECRET || 'YOUR_CLIENT_SECRET';

// ACP Compliance
const SIGNING_SECRET = process.env.ACP_SIGNING_SECRET || 'your-secret';
const USE_IDEMPOTENCY = true;
const USE_SIGNING = false; // Enable for production
```

### PSP Service Configuration

The `pseudo-psp-service.js` simulates multiple payment providers:

```javascript
// Provider simulation
const PROVIDERS = {
  paypal: { prefix: 'vt_paypal_', vault: true },
  stripe: { prefix: 'pm_', method: 'payment_method' },
  adyen: { prefix: 'adyen_', tokenized: true },
  demo: { prefix: 'vt_card_', mock: true }
};
```

## UI/UX Features

### ChatGPT-Style Interface
- Clean, minimal design matching ChatGPT
- Message bubbles with avatars
- Typing indicators
- Smooth animations
- Dark mode support

### Product Display
- Grid layout with images
- Real-time pricing from Shopware
- "Instant checkout" badges
- Provider logos (PayPal, Stripe, etc.)
- Hover effects and interactions

### Checkout Experience
- Slide-up modals (native app feel)
- Embedded address form
- Payment method selector (multi-PSP)
- Provider-specific branding
- Order summary with totals
- Quantity selector
- Shipping options

### Completion Screen
- Success animation
- Order confirmation with ID
- Delivery estimate
- View order link (permalink_url)
- Webhook status indicator

## Developer Features

### Console Logging

Open browser console (F12) to see:

```
=== ACP API CALLS ===
✅ OAuth Token obtained
✅ API Version: 2025-09-29
✅ Idempotency Key: idem_abc123
✅ Payment token created: vt_paypal_01J8Z3
✅ Provider detected: paypal
✅ Checkout session: cs_xyz789
✅ Order completed: SW-123456
✅ Webhook received: order_create
```

### Request Inspection

All requests show:
- Headers (including ACP compliance headers)
- Request body
- Response data
- Timing information
- Error details (if any)

## Testing Features

### Manual Testing Checklist

1. **Start Services:**
   ```bash
   cd ../tests && ./docker-start.sh start
   ./install-plugin.sh
   cd ../dummy-agent && npm start
   ```

2. **Test Conversation:**
   - Type: "I'm looking for a ceramic dinnerware set"
   - Verify products display

3. **Test Product Details:**
   - Click product card
   - Verify modal opens with details

4. **Test Checkout:**
   - Click "Buy" button
   - Fill address form
   - Select payment method

5. **Test Payment Providers:**
   - Try each provider option
   - Verify different token formats in console

6. **Test Completion:**
   - Click "Pay Demo Merchant"
   - Verify order creation
   - Check webhook notification

### API Compliance Verification

Check console for:
- [ ] API-Version header sent: `2025-09-29`
- [ ] Idempotency keys generated
- [ ] Token format matches provider
- [ ] Provider auto-detection works
- [ ] Order has permalink_url
- [ ] Webhook events received

## Troubleshooting

### "Failed to get OAuth token"
- Check Shopware container is running
- Verify CLIENT_ID and CLIENT_SECRET in server.js
- Test: `curl http://localhost:80/api/_info/version`

### "API Version not supported"
- Ensure plugin is updated with ACP compliance features
- Run: `cd ../tests && ./install-plugin.sh`
- Verify: `./verify-plugin.sh`

### "Product not found" errors
- This is expected with demo product IDs
- The flow still demonstrates token creation
- Add real products in Shopware Admin

### Port 3000 already in use
- Change PORT in server.js
- Or stop conflicting service: `lsof -ti:3000 | xargs kill`

### Payment token shows wrong format
- Demo mode: Should show `vt_card_*`
- Production mode: Should show `vt_paypal_*`, `pm_*`, etc.
- Check PRODUCTIVE_MODE environment variable

## Development

### File Structure

```
dummy-agent/
├── package.json              # Dependencies
├── server.js                 # Express backend with ACP compliance
├── pseudo-psp-service.js     # Multi-PSP simulation
├── public/
│   ├── index.html           # Chat interface
│   ├── styles.css           # ChatGPT-style UI
│   └── app.js               # Frontend with ACP support
├── start-demo.sh            # Demo mode launcher
├── start-productive.sh      # Production mode launcher
└── README.md                # This file
```

### Adding Features

**Add new products:**
```javascript
// In server.js
app.get('/api/agent/products', (req, res) => {
  // Add products here
});
```

**Add payment providers:**
```javascript
// In pseudo-psp-service.js
const PROVIDERS = {
  newprovider: { prefix: 'new_', ... }
};
```

**Customize ACP headers:**
```javascript
// In server.js
const acpHeaders = {
  'API-Version': API_VERSION,
  'Idempotency-Key': generateIdempotencyKey(),
  'Signature': generateSignature(body) // If enabled
};
```

## Production Considerations

This is a **demo/prototype** showcasing ACP compliance. For production:

### Security
1. Enable request signing (`USE_SIGNING = true`)
2. Use environment variables for all secrets
3. Implement proper OAuth token refresh
4. Add rate limiting
5. Use HTTPS everywhere

### Compliance
1. Always include API-Version header
2. Generate unique idempotency keys
3. Handle 409 conflicts properly
4. Verify webhook signatures
5. Log all ACP interactions

### Performance
1. Cache OAuth tokens
2. Implement retry logic with backoff
3. Add request timeout handling
4. Use connection pooling
5. Monitor API response times

### Error Handling
1. Handle all ACP error types
2. Display user-friendly messages
3. Log errors with context
4. Implement fallback flows
5. Add monitoring alerts

## Environment Variables

```bash
# API Configuration
SHOPWARE_URL=http://localhost:80
CLIENT_ID=your-client-id
CLIENT_SECRET=your-client-secret

# ACP Compliance
ACP_API_VERSION=2025-09-29
ACP_SIGNING_SECRET=your-signing-secret
USE_IDEMPOTENCY=true
USE_SIGNING=false  # Enable in production

# PSP Configuration
PRODUCTIVE_MODE=false  # true for real PSPs
PAYPAL_CLIENT_ID=your-paypal-id
STRIPE_SECRET_KEY=sk_test_...
ADYEN_API_KEY=your-adyen-key

# Server
PORT=3000
NODE_ENV=development
```

## Demo Screenshots

The interface demonstrates:
- ChatGPT-style conversation with product discovery
- Multi-PSP payment options with provider branding
- Embedded checkout with ACP compliance
- Real-time API call visualization
- Complete purchase flow with webhooks

## License

MIT - Demo purposes only

---

**🚀 Ready to see ACP in action?** Start the demo with `./start-demo.sh` and experience the future of AI-powered commerce!