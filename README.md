# Agentic Commerce Protocol (ACP) - Shopware Implementation

This repository contains a Shopware 6 implementation of the [Agentic Commerce Protocol (ACP)](https://github.com/agentic-commerce-protocol/agentic-commerce-protocol), enabling AI agents like ChatGPT to seamlessly interact with Shopware stores for product discovery, checkout, and payment processing.

## 🎉 ACP Shopware Integration


✅ **API Version Validation** - Enforced on all endpoints  
✅ **Idempotency Key Handling** - 24h TTL with 409 conflict detection  
✅ **Request Signing/Verification** - HMAC SHA256 signature validation  
✅ **Error Response Format** - Exact ACP spec compliance  
✅ **Order Object Format** - Complete with `permalink_url`  
✅ **Payment Provider Responses** - Multi-PSP support  
✅ **Webhook Support** - Order lifecycle events  
✅ **Complete Test Coverage** - Automated validation  

## What is ACP?

The **Agentic Commerce Protocol (ACP)** is an open standard maintained by OpenAI and Stripe that allows AI agents to complete purchases on behalf of users without redirects or interruptions. Learn more at [agenticcommerce.dev](https://agenticcommerce.dev).

## What This Implementation Provides

This Shopware plugin enables merchants to:

✅ **Accept orders from AI agents** - Let ChatGPT and other AI assistants purchase products directly from your Shopware store  
✅ **Seamless payment processing** - Support for multiple payment service providers (PayPal, Stripe, Adyen)  
✅ **Full cart integration** - Real Shopware cart system with automatic tax, shipping, and price calculations  
✅ **OAuth2 secured** - Industry-standard API authentication  
✅ **ACP spec compliant** - Follows official protocol specifications exactly  


## Repository Structure

```
ACP-shopware/│
├── shopware-acp-plugin/               # ⭐ Shopware 6 Plugin Implementation
│   ├── src/                           # Plugin source code
│   │   ├── Controller/                # API controllers (ACP endpoints)
│   │   ├── Service/                   # Business logic + compliance
│   │   │   ├── AcpComplianceService.php  # ACP spec enforcement
│   │   │   ├── CheckoutSessionService.php # Session management
│   │   │   ├── PaymentTokenService.php    # Token handling
│   │   │   └── WebhookService.php         # Event notifications
│   │   ├── Core/Content/              # Entity definitions
│   │   │   ├── CheckoutSession/       # Session entities
│   │   │   ├── ExternalToken/         # Token storage
│   │   │   └── IdempotencyKey/        # Idempotency support
│   │   ├── Migration/                 # Database migrations
│   │   └── Resources/config/          # Services, routes, config
│   ├── composer.json                  # Plugin metadata
│   ├── README.md                      # Plugin documentation
│   └── QUICK_START.md                 # Quick start guide
│
├── tests/                             # Test suite with automation
│   ├── run-tests.sh                   # Automated ACP-compliant tests
│   ├── install-plugin.sh              # Plugin installation
│   ├── verify-plugin.sh               # Health checks
│   ├── docker-start.sh                # Container management
│   └── *.log                          # Test result logs
│
├── dummy-agent/                       # ChatGPT-style demo interface
│   ├── server.js                      # Express.js backend
│   ├── pseudo-psp-service.js          # PSP simulation
│   ├── public/                        # Frontend (HTML/CSS/JS)
│   ├── start-demo.sh                  # Start in demo mode
│   └── start-productive.sh            # Start with real PSPs
│
└── README.md                          # This file
```

## ACP Compliance Features

### Core Protocol Support

| Feature | Status | Description |
|---------|--------|-------------|
| **API Versioning** | ✅ Complete | Validates `API-Version: 2025-09-29` header on all requests |
| **Idempotency** | ✅ Complete | Handles `Idempotency-Key` header, prevents duplicates, returns 409 on conflicts |
| **Request Signing** | ✅ Complete | Verifies `Signature` header using HMAC SHA256 |
| **Error Format** | ✅ Complete | Exact ACP error response structure with type, code, message, param |
| **Webhooks** | ✅ Complete | Emits `order_create` and `order_update` events |

### API Endpoints Implemented

| Endpoint | Method | ACP Compliant | Description |
|----------|--------|---------------|-------------|
| `/api/checkout_sessions` | POST | ✅ | Create checkout session with cart |
| `/api/checkout_sessions/{id}` | GET | ✅ | Retrieve session details |
| `/api/checkout_sessions/{id}` | POST | ✅ | Update session (items, shipping) |
| `/api/checkout_sessions/{id}/complete` | POST | ✅ | Complete checkout and create order |
| `/api/checkout_sessions/{id}/cancel` | POST | ✅ | Cancel checkout session |
| `/api/agentic_commerce/delegate_payment` | POST | ✅ | Create delegated payment token |

All endpoints require:
- `Authorization: Bearer <token>` header
- `API-Version: 2025-09-29` header
- `Content-Type: application/json`
- Optional: `Idempotency-Key` header for idempotent operations
- Optional: `Signature` header for request verification

### Payment Provider Support

The implementation supports multiple payment service providers with automatic detection:

| Provider | Token Format | Status | Features |
|----------|--------------|--------|----------|
| **PayPal** | `vt_paypal_*` | ✅ Production Ready | ACDC vaulting, no redirects |
| **Stripe** | `pm_*` | ✅ Simulated | Payment methods API ready |
| **Adyen** | `adyen_*` | ✅ Simulated | Token-based payments |
| **Generic Card** | `vt_card_*` | ✅ Demo Mode | Testing without PSP |

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
- Verifies ACP compliance

### 3. Run ACP Compliance Tests

```bash
# Demo mode (no PSP required)
./run-tests.sh

# Production mode (with PayPal sandbox)
./run-tests.sh --productive
```

Tests validate:
- API version enforcement
- Idempotency handling
- Error response formats
- Payment token delegation
- Checkout session lifecycle
- Order completion with webhooks

### 4. Try the Interactive Demo

```bash
cd ../dummy-agent
npm install
./start-demo.sh
```

Open http://localhost:3000 to see the ChatGPT-style interface in action.

## How It Works

### ACP-Compliant Payment Flow

```
AI Agent (e.g., ChatGPT)
    ↓
1. POST /api/agentic_commerce/delegate_payment
   → Creates payment token with allowance constraints
   → Returns: {id: "vt_*", created: "...", metadata: {...}}
   → Full ACP spec compliance
    ↓
2. POST /api/checkout_sessions
   → Creates Shopware cart with products
   → Calculates prices, taxes, shipping
   → Returns session with payment_provider info
    ↓
3. POST /api/checkout_sessions/{id}/complete
   → Validates payment token and allowance
   → Creates customer in Shopware
   → Persists order
   → Triggers PSP payment processing
   → Emits webhook events
    ↓
Order completed in Shopware
Webhook sent to AI agent
```

### ACP Compliance Service

The `AcpComplianceService` ensures all protocol requirements are met:

```php
class AcpComplianceService {
    // API Version validation
    validateApiVersion($request) // Enforces 2025-09-29
    
    // Idempotency support
    handleIdempotency($request, $context) // 24h TTL, 409 on conflicts
    
    // Request signing
    verifySignature($request) // HMAC SHA256 validation
    
    // Error formatting
    errorResponse($type, $code, $message, $param) // ACP-compliant errors
    
    // Response formatting
    formatOrderObject($orderId, $sessionId) // With permalink_url
    addPaymentProviderInfo($response) // Provider details
}
```

### Two Operating Modes

**Demo Mode** (Default - No configuration needed):
- Uses simulated card tokens (`vt_card_*`)
- Perfect for UI/UX demonstrations
- All ACP endpoints functional
- Orders created in Shopware
- No real payment processing

**Production Mode** (PSP Integration):
- Uses real PSP tokens (`vt_paypal_*`, `pm_*`, etc.)
- Integrates with payment service providers
- Real payment processing
- Orders marked as "Paid"
- Full webhook support

## PayPal Integration Details

### Why PayPal ACDC?

This implementation uses **PayPal Advanced Credit and Debit Card (ACDC)** because:

✅ **No login required** - AI agents can process payments without redirecting users  
✅ **Direct card processing** - Card data is tokenized and charged directly  
✅ **ACP compliant** - Supports delegated payment model  
✅ **Secure vaulting** - Cards are tokenized using PayPal's Vault API  

### PayPal Configuration (Optional)

To enable real PayPal payments:

1. Get PayPal Sandbox Credentials from https://developer.paypal.com
2. Configure in Shopware Admin (Settings → Payment → PayPal)
3. Enable ACDC payment method
4. Run tests with `--productive` flag

## Testing

### Automated ACP Compliance Tests

```bash
cd tests

# Run all ACP compliance tests
./run-tests.sh

# Test with real PSP integration
./run-tests.sh --productive
```

**Test Coverage:**
- ✅ API version validation
- ✅ Idempotency key handling
- ✅ Request signature verification
- ✅ Payment token delegation
- ✅ Checkout session lifecycle
- ✅ Order completion with webhooks
- ✅ Error response formats
- ✅ Session cancellation
- ✅ Multi-PSP support

### Interactive Demo

```bash
cd dummy-agent

# Demo mode with mock PSP
./start-demo.sh

# Production mode with real PSP
./start-productive.sh
```

Visit http://localhost:3000 for a ChatGPT-style interface demonstrating:
- Conversational product discovery
- Embedded checkout experience
- Real Shopware product integration
- Complete ACP-compliant purchase flow
- Multi-provider payment support

## Technical Stack

- **Shopware**: 6.5+ (tested with 6.7.2.2)
- **PHP**: 8.1+
- **PayPal Plugin**: SwagPayPal 10.1+ (optional)
- **Protocol**: ACP v2025-09-29
- **Authentication**: OAuth2 with JWT
- **Database**: MySQL (3 new tables for sessions, tokens, and idempotency)

## Documentation

- **Plugin README**: `shopware-acp-plugin/README.md` - Complete plugin documentation
- **Quick Start**: `shopware-acp-plugin/QUICK_START.md` - Get started in 5 minutes
- **Test Results**: `tests/` - Test logs and validation results
- **Demo Interface**: `dummy-agent/README.md` - ChatGPT-style demo documentation

## Support & Contributing

- **ACP Protocol**: https://github.com/agentic-commerce-protocol/agentic-commerce-protocol
- **ACP Website**: https://agenticcommerce.dev
- **Issues**: Open an issue in this repository

## License

MIT License

---
