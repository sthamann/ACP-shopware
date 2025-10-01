# Agentic Commerce Protocol (ACP) - Shopware Implementation

This repository contains a complete **Shopware 6 implementation** of the [Agentic Commerce Protocol (ACP)](https://github.com/agentic-commerce-protocol/agentic-commerce-protocol), enabling AI agents like ChatGPT to seamlessly interact with Shopware stores for product discovery, checkout, and payment processing.

## What is ACP?

The **Agentic Commerce Protocol (ACP)** is an open standard maintained by OpenAI and Stripe that allows AI agents to complete purchases on behalf of users without redirects or interruptions. Learn more at [agenticcommerce.dev](https://agenticcommerce.dev).

## What This Implementation Provides

This Shopware plugin enables merchants to:

✅ **Accept orders from AI agents** - Let ChatGPT and other AI assistants purchase products directly from your Shopware store  
✅ **Seamless payment processing** - Support PayPal Advanced Credit & Debit Card (ACDC) payments without requiring customer login  
✅ **Full cart integration** - Real Shopware cart system with automatic tax, shipping, and price calculations  
✅ **OAuth2 secured** - Industry-standard API authentication  
✅ **Production ready** - Complete implementation with demo and production modes  

**Key Benefit for Merchants:** Reach customers through AI shopping assistants while using your existing Shopware infrastructure.

## Repository Structure

```
ACP-shopware/
├── shopware-acp-plugin/               # ⭐ Shopware 6 Plugin Implementation
│   ├── src/                           # Plugin source code
│   │   ├── Controller/                # API controllers (6 endpoints)
│   │   ├── Service/                   # Business logic
│   │   ├── Core/Content/              # Entity definitions
│   │   ├── Migration/                 # Database migrations
│   │   └── Resources/config/          # Services, routes, config
│   ├── composer.json                  # Plugin metadata
│   ├── README.md                      # Plugin documentation
│   └── QUICK_START.md                 # Quick start guide
│
├── tests/                             # Test suite with automation
│   ├── run-tests.sh                   # Automated API tests
│   ├── install-plugin.sh              # Plugin installation
│   ├── verify-plugin.sh               # Health checks
│   ├── docker-start.sh                # Container management
│   └── *.log                          # Test result logs
│
├── dummy-agent/                       # ChatGPT-style demo interface
│   ├── server.js                      # Express.js backend
│   ├── public/                        # Frontend (HTML/CSS/JS)
│   ├── start-demo.sh                  # Start in demo mode
│   └── start-productive.sh            # Start with PayPal
│
└── README.md                          # This file
```

## Quick Start

### Prerequisites

- Docker installed and running
- Port 80, 443, 3000, and 3306 available
- Node.js 16+ (for demo interface)

### 1. Start Shopware Container

```bash
cd tests
./docker-start.sh start
```

Wait ~30 seconds for Shopware to initialize.

### 2. Install the Plugin

```bash
./install-plugin.sh
```

This automatically:
- Copies plugin files to container
- Installs and activates the plugin
- Runs database migrations
- Clears cache

### 3. Run Tests

```bash
# Demo mode (no PayPal required)
./run-tests.sh

# Production mode (with PayPal sandbox)
./run-tests.sh --productive
```

### 4. Try the Interactive Demo

```bash
cd ../dummy-agent
npm install
./start-demo.sh
```

Open http://localhost:3000 to see the ChatGPT-style interface in action.

## How It Works

### Payment Flow Architecture

```
AI Agent (e.g., ChatGPT)
    ↓
1. POST /api/agentic_commerce/delegate_payment
   → Creates payment token with allowance constraints
   → Returns: vt_paypal_* (production) or vt_card_* (demo)
    ↓
2. POST /api/checkout_sessions
   → Creates Shopware cart with products
   → Calculates prices, taxes, shipping
   → Returns session with totals
    ↓
3. POST /api/checkout_sessions/{id}/complete
   → Validates payment token and allowance
   → Creates customer in Shopware
   → Persists order
   → (Production: triggers PayPal payment)
    ↓
Order completed in Shopware
```

### Two Operating Modes

**Demo Mode** (Default - No configuration needed):
- Uses simulated card tokens (`vt_card_*`)
- Perfect for UI/UX demonstrations
- All ACP endpoints functional
- Orders created in Shopware
- No real payment processing

**Production Mode** (PayPal Sandbox):
- Uses real PayPal vault tokens (`vt_paypal_*`)
- Integrates with SwagPayPal plugin
- Real payment processing via PayPal
- Orders marked as "Paid"
- No customer login required (ACDC)

## PayPal Integration Details

### Why PayPal ACDC?

This implementation uses **PayPal Advanced Credit and Debit Card (ACDC)** instead of PayPal Express Checkout because:

✅ **No login required** - AI agents can process payments without redirecting users to PayPal.com  
✅ **Direct card processing** - Card data is tokenized and charged directly via PayPal's backend API  
✅ **Perfect for AI agents** - No user interaction, no redirects, no popups  
✅ **Secure vaulting** - Cards are tokenized using PayPal's Vault API for PCI compliance  

### PayPal Flow

```
1. Delegate Payment Request
   → PaymentTokenService detects SwagPayPal availability
   → Creates vault token in swag_paypal_vault_token table
   → Links to ACP token in acp_payment_token table
   → Returns vt_paypal_* token to AI agent

2. Checkout Completion
   → Loads vault token from database
   → Sets PayPal ACDC as payment method
   → Shopware OrderPersister triggers SwagPayPal ACDCHandler
   → ACDCHandler builds PayPal order with vault_id
   → PayPal API charges the vaulted card
   → Order marked as "Paid" automatically
```

**No customer login required at any step** - the entire flow is backend-to-backend using tokenized payment methods.

## API Endpoints Implemented

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/checkout_sessions` | POST | Create checkout session with cart |
| `/api/checkout_sessions/{id}` | GET | Retrieve session details |
| `/api/checkout_sessions/{id}` | POST | Update session (items, shipping) |
| `/api/checkout_sessions/{id}/complete` | POST | Complete checkout and create order |
| `/api/checkout_sessions/{id}/cancel` | POST | Cancel checkout session |
| `/api/agentic_commerce/delegate_payment` | POST | Create delegated payment token |

All endpoints require:
- `Authorization: Bearer <token>` header
- `API-Version: 2025-09-29` header
- `Content-Type: application/json`

## Testing

### Automated Test Suite

```bash
cd tests

# Run all tests in demo mode
./run-tests.sh

# Run tests with PayPal integration
./run-tests.sh --productive
```

**Test Coverage:**
- OAuth authentication
- Payment token delegation
- Checkout session lifecycle
- Order completion
- Session cancellation
- API version validation

Logs are saved to `test-results-*.log` and `production-test-*.log`.

### Interactive Demo

```bash
cd dummy-agent

# Demo mode
./start-demo.sh

# Production mode (PayPal)
./start-productive.sh
```

Visit http://localhost:3000 for a ChatGPT-style interface demonstrating:
- Conversational product discovery
- Embedded checkout experience
- Real Shopware product integration
- Complete purchase flow

## PayPal Configuration (Optional)

To enable real PayPal payments in production mode:

### 1. Get PayPal Sandbox Credentials

1. Go to https://developer.paypal.com
2. Create a Sandbox App
3. Copy Client ID and Client Secret

### 2. Configure in Shopware Admin

1. Open http://localhost:80/admin (login: admin / shopware)
2. Go to **Settings → Payment → PayPal**
3. Enter credentials:
   - Environment: **Sandbox**
   - Client ID: [your client ID]
   - Client Secret: [your secret]
   - Enable ACDC: **✅**
4. Save

### 3. Activate Payment Method

1. Go to **Settings → Payment methods**
2. Find "Credit or debit card" (ACDC)
3. Set **Active: ✅**
4. Assign to sales channels
5. Save

### 4. Verify

```bash
cd tests
./run-tests.sh --productive
```

You should see: `✅ Payment Token Created: vt_paypal_*` 🎉

## What Merchants Get

### For Shopware Store Owners

By installing this plugin, you can:

1. **Sell through AI agents** - Your products become discoverable and purchasable via ChatGPT and other AI shopping assistants
2. **No integration complexity** - Uses your existing Shopware catalog, pricing, shipping, and payment setup
3. **Secure payments** - PayPal tokenization means no card data touches your server
4. **Real-time inventory** - AI agents see live product availability and pricing
5. **Familiar order management** - Orders appear in Shopware admin like any other order
6. **OAuth2 secured** - Control which AI agents can access your store

### Business Value

- **Reach new customers** - Users shopping via AI assistants
- **Reduce friction** - No app downloads, no account creation, instant checkout
- **Increase conversion** - AI agents can complete purchases in seconds
- **Use existing infrastructure** - No separate payment processor, no new merchant accounts

## Technical Stack

- **Shopware**: 6.5+ (tested with 6.7.2.2)
- **PHP**: 8.1+
- **PayPal Plugin**: SwagPayPal 10.1+
- **Protocol**: ACP v2025-09-29
- **Authentication**: OAuth2 with JWT
- **Database**: MySQL (2 new tables for sessions and tokens)

## Documentation

- **Plugin README**: `shopware-acp-plugin/README.md` - Complete plugin documentation
- **Quick Start**: `shopware-acp-plugin/QUICK_START.md` - Get started in 5 minutes
- **Test Results**: `tests/` - Test logs and results
- **Demo Interface**: `dummy-agent/` - ChatGPT-style demo application

## Support & Contributing

- **ACP Protocol**: https://github.com/agentic-commerce-protocol/agentic-commerce-protocol
- **ACP Website**: https://agenticcommerce.dev
- **Issues**: Open an issue in this repository

## License

MIT License

---

**Ready to enable AI-powered shopping for your Shopware store?** Install the plugin and start accepting orders from AI agents today! 🚀

