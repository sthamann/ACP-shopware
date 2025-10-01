# ACP Dummy Agent - Chat Interface Demo

A fully functional chat interface that demonstrates the Agentic Commerce Protocol (ACP) integration with Shopware 6.

## Overview

This application simulates a ChatGPT-style interface that:
- Shows products in a conversational way
- Handles checkout embedded in the chat
- Uses the **real** Shopware ACP API running in Docker
- Demonstrates the complete purchase flow

## Features

- 🤖 ChatGPT-style conversational interface
- 🛍️ Product browsing with images and prices
- 💳 Embedded checkout experience
- 🔒 Real ACP payment token delegation
- ✅ Complete order flow with Shopware integration

## Installation

```bash
cd dummy-agent
npm install
```

## Running

### Prerequisites
1. Shopware Docker container must be running (`shopware-acp`)
2. ACP plugin must be installed
3. OAuth integration credentials configured

### Start the Server

**Demo Mode (default):**
```bash
npm start
# or use convenience script:
./start-demo.sh
```

**Production Mode (PayPal Sandbox):**
```bash
PRODUCTIVE_MODE=true npm start
# or use convenience script:
./start-productive.sh
```

Or for development with auto-reload:

```bash
npm run dev
```

**Mode Differences:**
- **Demo Mode**: Uses `vt_card_*` tokens for testing without real PayPal integration
- **Production Mode**: Uses `vt_paypal_*` tokens with real PayPal vault token storage (requires SwagPayPal plugin and sandbox credentials configured)

### Access the Interface

Open your browser to:
```
http://localhost:3000
```

## How It Works

### Architecture

```
User Browser (http://localhost:3000)
    ↓
Dummy Agent Server (Express.js)
    ↓ ACP API Calls
Shopware Docker (http://localhost:80)
    ↓
ACP Plugin Endpoints
```

### Flow Diagram

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
   ← Returns payment token
   ↓
6. Agent calls POST /api/checkout_sessions
   ← Returns session with totals
   ↓
7. User clicks "Pay Etsy"
   ↓
8. Agent calls POST /api/checkout_sessions/{id}/complete
   ← Returns order confirmation
   ↓
9. Shows "Purchase complete" screen
```

### API Endpoints (Dummy Agent Server)

- `GET /api/agent/products` - Get product list (mock data)
- `POST /api/agent/create-payment-token` - Delegates to Shopware ACP
- `POST /api/agent/checkout` - Creates checkout session via ACP
- `POST /api/agent/complete-checkout` - Completes order via ACP

### ACP Integration Points

The dummy agent makes **real** calls to Shopware:

1. **OAuth Authentication**
   ```javascript
   POST http://localhost:80/api/oauth/token
   → Access token for API calls
   ```

2. **Payment Token Delegation**
   ```javascript
   POST http://localhost:80/api/agentic_commerce/delegate_payment
   → vt_card_xxx token with allowance
   ```

3. **Checkout Session**
   ```javascript
   POST http://localhost:80/api/checkout_sessions
   → Session with calculated totals
   ```

4. **Complete Order**
   ```javascript
   POST http://localhost:80/api/checkout_sessions/{id}/complete
   → Order created in Shopware
   ```

## Configuration

Edit `server.js` to configure:

```javascript
const SHOPWARE_URL = 'http://localhost:80';
const CLIENT_ID = 'YOUR_CLIENT_ID';
const CLIENT_SECRET = 'YOUR_CLIENT_SECRET';
const API_VERSION = '2025-09-29';
```

## UI/UX Features

### ChatGPT-Style Interface
- Clean, minimal design
- Message bubbles with avatars
- Typing indicators
- Smooth animations

### Product Display
- Grid layout with images
- Price and vendor information
- "Instant checkout" badge
- Hover effects

### Checkout Experience
- Slide-up modals (native app feel)
- Embedded address form
- Payment method display
- Order summary with totals
- Quantity selector

### Completion Screen
- Success animation
- Order confirmation
- Delivery estimate
- View details option

## Testing

### Manual Testing

1. Start Shopware Docker: `cd ../tests && ./docker-start.sh start`
2. Install plugin: `./install-plugin.sh`
3. Start dummy agent: `cd ../dummy-agent && npm start`
4. Open browser: `http://localhost:3000`
5. Type: "I'm looking for a ceramic dinnerware set"
6. Click a product → Click "Buy" → Fill form → Click "Pay Etsy"
7. See completion screen

### What to Verify

- [ ] Chat interface loads
- [ ] Products display correctly
- [ ] Product modal opens
- [ ] Checkout modal works
- [ ] Payment token created (check console)
- [ ] Checkout session created (check console)
- [ ] Completion screen appears
- [ ] Order details shown in chat

## Troubleshooting

### "Failed to get OAuth token"
- Check Shopware container is running
- Verify CLIENT_ID and CLIENT_SECRET in server.js
- Test: `curl http://localhost:80/api/_info/version`

### "Product not found" errors
- This is expected (using demo product IDs)
- The flow still demonstrates token creation
- Real products can be configured in Shopware Admin

### Port 3000 already in use
- Change PORT in server.js
- Or stop conflicting service: `lsof -ti:3000 | xargs kill`

## Development

### File Structure

```
dummy-agent/
├── package.json          # Dependencies
├── server.js             # Express backend
├── public/
│   ├── index.html        # Chat interface
│   ├── styles.css        # ChatGPT-style UI
│   └── app.js            # Frontend logic
└── README.md             # This file
```

### Adding Features

**Add new products:**
Edit `server.js`, function `app.get('/api/agent/products')`

**Customize styling:**
Edit `public/styles.css`

**Add chat capabilities:**
Edit `public/app.js`, function `handleUserMessage()`

## Demo Screenshots

The interface mimics the ChatGPT + Etsy integration shown in the reference screenshot:
- Conversational product discovery
- Embedded checkout
- Native-feeling modals
- Purchase completion flow

## Production Considerations

This is a **demo/prototype**. For production:

1. Add real product catalog integration
2. Implement proper error handling
3. Add loading states
4. Handle all edge cases
5. Add analytics
6. Implement proper authentication
7. Add rate limiting
8. Use environment variables for config

## License

MIT - Demo purposes only

