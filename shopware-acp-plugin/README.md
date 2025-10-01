# Shopware 6 ACP Integration Plugin

Complete implementation of the [Agentic Commerce Protocol (ACP)](https://github.com/agentic-commerce-protocol/agentic-commerce-protocol) for Shopware 6, enabling AI agents like ChatGPT to create checkout sessions, process payments, and complete orders directly through standardized REST APIs.

## What This Plugin Does

This plugin transforms your Shopware store into an **AI-agent-ready commerce platform**. AI assistants can:

- Browse your product catalog
- Create shopping carts with real-time pricing
- Process secure payments via PayPal (no customer login required)
- Complete orders that appear in your Shopware admin
- Handle shipping options and tax calculations automatically

**For Shopware Merchants:** Enable AI-powered shopping experiences for your customers without changing your existing infrastructure.

## Features

✅ **Full ACP Compliance** - API Version: `2025-09-29` specification  
✅ **Real Shopware Integration** - Native cart, products, shipping, tax, and order systems  
✅ **PayPal ACDC Support** - Secure card payments without customer login or redirects  
✅ **Dual Mode Operation** - Demo mode for testing, production mode for real payments  
✅ **OAuth2 Secured** - Industry-standard Bearer token authentication  
✅ **Database Persistence** - Sessions and tokens stored with full audit trail  
✅ **Complete Test Suite** - Automated testing with detailed logs  
✅ **Interactive Demo** - ChatGPT-style UI for live demonstrations  
✅ **Sales Channel Aware** - Works with admin API context and storefront contexts  

## Installation

### Using Test Scripts (Recommended)

```bash
# Navigate to repository root, then to tests directory
cd ../tests
./docker-start.sh start      # Start Shopware container
./install-plugin.sh          # Install and configure plugin
./verify-plugin.sh           # Verify installation
./run-tests.sh               # Run tests
```

### Manual Installation

```bash
# From the shopware-acp-plugin directory:
# Copy plugin to Shopware
docker cp src/. shopware-acp:/var/www/html/custom/plugins/AcpShopwarePlugin/src
docker cp composer.json shopware-acp:/var/www/html/custom/plugins/AcpShopwarePlugin/

# Install plugin
docker exec -u www-data shopware-acp php /var/www/html/bin/console plugin:refresh
docker exec -u www-data shopware-acp php /var/www/html/bin/console plugin:install --activate AcpShopwarePlugin

# Run migrations
docker exec -u www-data shopware-acp php /var/www/html/bin/console database:migrate --all
docker exec -u www-data shopware-acp php /var/www/html/bin/console cache:clear
```

## API Endpoints

All endpoints require `Authorization: Bearer <token>` and `API-Version: 2025-09-29` headers.

### Payment Token Delegation

```http
POST /api/agentic_commerce/delegate_payment
Content-Type: application/json

{
  "payment_method": {
    "type": "card",
    "number": "4111111111111111",
    "exp_month": "12",
    "exp_year": "2026",
    "cvc": "123",
    "display_brand": "visa",
    "display_last4": "1111"
  },
  "allowance": {
    "reason": "one_time",
    "max_amount": 10000,
    "currency": "eur",
    "checkout_session_id": "checkout_abc",
    "merchant_id": "shop_123",
    "expires_at": "2025-12-31T23:59:59Z"
  },
  "risk_signals": [
    {"type": "card_testing", "score": 5, "action": "authorized"}
  ]
}
```

**Response:**
```json
{
  "id": "vt_paypal_abc123",
  "created": "2025-10-01T12:00:00Z",
  "metadata": {
    "payment_method_id": "...",
    "checkout_session_id": "checkout_abc",
    "max_amount": "10000",
    "currency": "eur",
    "expires_at": "2025-12-31T23:59:59Z",
    "paypal_vault_id": "CARD-8VN37..."
  }
}
```

### Checkout Session Management

```http
POST /api/checkout_sessions
{
  "items": [{"id": "PRODUCT-123", "quantity": 2}],
  "fulfillment_address": {
    "name": "John Doe",
    "line_one": "123 Main St",
    "city": "Berlin",
    "country": "DE",
    "postal_code": "10115"
  }
}
```

```http
GET /api/checkout_sessions/{id}
POST /api/checkout_sessions/{id}              # Update
POST /api/checkout_sessions/{id}/complete     # Complete order
POST /api/checkout_sessions/{id}/cancel       # Cancel
```

## How It Works

### Architecture Overview

```
AI Agent Request
    ↓
API Controller (validates, authenticates)
    ↓
Service Layer (business logic)
    ↓
Shopware Core Services (cart, order, product)
    ↓
SwagPayPal (if configured) - Payment processing
    ↓
Database Persistence
```

### Payment Token Flow

**Demo Mode:**
```
1. POST /delegate_payment
   → Creates vt_card_* token
   → Stores in acp_payment_token table
   → No real payment processing

2. POST /checkout_sessions/{id}/complete
   → Validates token and allowance
   → Creates order in Shopware
   → Order status: Open
```

**Production Mode (PayPal):**
```
1. POST /delegate_payment
   → Detects SwagPayPal availability
   → Creates vt_paypal_* token
   → Stores in acp_payment_token table
   → Also stores in swag_paypal_vault_token table
   → Links vault token via foreign key

2. POST /checkout_sessions/{id}/complete
   → Validates token and allowance
   → Loads PayPal vault token
   → Creates order with PayPal payment method
   → Shopware triggers SwagPayPal ACDCHandler
   → ACDCHandler charges vaulted card via PayPal API
   → Order status: Paid ✅
```

### Database Tables

**`acp_checkout_session`** - Checkout sessions
- `id` - Session UUID
- `cart_token` - Shopware cart token
- `sales_channel_id` - Sales channel reference
- `status` - Session status (ready_for_payment, completed, canceled)
- `data` - Full session JSON
- `order_id` - Created order reference (when completed)

**`acp_payment_token`** - Payment tokens with allowances
- `id` - Token UUID
- `acp_token_id` - ACP token identifier (vt_card_* or vt_paypal_*)
- `paypal_vault_token_id` - Link to SwagPayPal vault token (if PayPal mode)
- `payment_method_id` - Shopware payment method
- `checkout_session_id` - Associated checkout session
- `max_amount` - Allowance limit (cents)
- `currency` - Currency code
- `expires_at` - Token expiry timestamp
- `used` - Whether token has been consumed
- `order_id` - Order created with this token
- `card_last4` - Card last 4 digits
- `card_brand` - Card brand (visa, mastercard, etc.)

## PayPal Integration

### Why PayPal ACDC?

**PayPal ACDC (Advanced Credit and Debit Card)** enables card payments **without customer login**:

| Feature | PayPal Express | PayPal ACDC (This Plugin) |
|---------|----------------|---------------------------|
| Customer login required | ✅ Yes | ❌ No |
| Redirect to PayPal.com | ✅ Yes | ❌ No |
| PayPal account needed | ✅ Yes | ❌ No |
| Direct card processing | ❌ No | ✅ Yes |
| AI agent suitable | ❌ No | ✅ **Perfect** |

### Setup PayPal (10 minutes)

**1. Get PayPal Sandbox Credentials:**
- Visit https://developer.paypal.com
- Create a Sandbox App under Apps & Credentials
- Copy Client ID and Client Secret

**2. Configure in Shopware Admin:**
- Open http://localhost:80/admin (admin/shopware)
- Settings → Payment → PayPal
- Environment: **Sandbox**
- Enter Client ID and Secret
- Enable **Advanced Credit and Debit Card (ACDC)**
- Save

**3. Activate Payment Method:**
- Settings → Payment methods
- Find "Credit or debit card"
- Set Active: ✅
- Assign to sales channels
- Save

**4. Clear Cache:**
```bash
docker exec -u www-data shopware-acp php /var/www/html/bin/console cache:clear
```

**5. Test:**
```bash
cd tests
./run-tests.sh --productive
```

Should display: `vt_paypal_*` tokens instead of `vt_card_*` 🎉

## Demo Interface

### Start the Demo

```bash
# Navigate to repository root, then to dummy-agent directory
cd ../dummy-agent

# Demo mode (simulated payments)
npm install
./start-demo.sh

# Production mode (PayPal sandbox)
./start-productive.sh
```

### What You'll See

1. **Auto-starting conversation** - ChatGPT-style interface loads with product discovery
2. **Real Shopware products** - Live data from your Shopware catalog
3. **Product cards** - Click to view details in fullscreen modal
4. **Embedded checkout** - Click "Buy" to open checkout modal
5. **Payment processing** - Click "Pay Demo Merchant" to complete purchase
6. **Order confirmation** - Success screen with order details

**Browser Console (F12)** shows real API calls:
```
✅ Payment token created: vt_paypal_* (or vt_card_*)
✅ Checkout session created: [session-id]
✅ Order completed: [order-id]
```

## Operating Modes

### Demo Mode (Default)

**Best for:** UI/UX demonstrations, development, testing

- ✅ No configuration needed
- ✅ Works immediately after installation
- ✅ All ACP endpoints functional
- ✅ Real Shopware cart and products
- ✅ Orders created in database
- ⚠️ No real payment processing
- Token format: `vt_card_*`

### Production Mode (PayPal Sandbox)

**Best for:** Full integration testing, staging environments

- ✅ Real PayPal vault token creation
- ✅ PayPal API integration
- ✅ Orders marked as "Paid"
- ✅ Full payment processing flow
- ✅ Still sandbox mode (no real money)
- Requires: SwagPayPal configured
- Token format: `vt_paypal_*`

## Troubleshooting

### Plugin not showing in admin
```bash
docker exec -u www-data shopware-acp php /var/www/html/bin/console plugin:refresh
```

### Database tables missing
```bash
docker exec -u www-data shopware-acp php /var/www/html/bin/console database:migrate --all
```

### PayPal tokens not created (getting vt_card_* instead)

Check these in order:

1. **SwagPayPal installed?**
   ```bash
   docker exec shopware-acp php /var/www/html/bin/console plugin:list | grep PayPal
   ```
   Should show: `SwagPayPal ... Active: Yes`

2. **PayPal credentials configured?**
   - Admin → Settings → Payment → PayPal
   - Should show Client ID filled in

3. **Payment method active?**
   - Settings → Payment methods → "Credit or debit card"
   - Active: ✅
   - Sales channels: At least one selected

4. **Cache cleared?**
   ```bash
   docker exec -u www-data shopware-acp php /var/www/html/bin/console cache:clear
   ```

### Port 3000 already in use
```bash
lsof -ti:3000 | xargs kill
cd dummy-agent
npm start
```

### Orders not appearing in admin

- Check customer creation succeeded (look for "ACP-" customer number)
- Verify sales channel is active
- Check Shopware logs: `docker exec shopware-acp tail /var/www/html/var/log/dev.log`

## Testing Checklist

After installation, verify:

- [ ] Container running: `./docker-start.sh status`
- [ ] Plugin active: `./verify-plugin.sh`
- [ ] Tests pass: `./run-tests.sh`
- [ ] Demo works: http://localhost:3000
- [ ] PayPal mode (optional): `./run-tests.sh --productive` shows `vt_paypal_*`

## URLs

- **Shopware Frontend**: http://localhost:80
- **Shopware Admin**: http://localhost:80/admin (admin/shopware)
- **Demo Interface**: http://localhost:3000
- **PayPal Developer**: https://developer.paypal.com

## Architecture

### Service Layer

**CheckoutSessionService**
- Creates and manages checkout sessions
- Integrates with Shopware `CartService` for real cart calculations
- Handles product lookups by SKU/product number
- Manages shipping methods and costs
- Converts carts to orders
- Resolves sales channels for admin API contexts

**PaymentTokenService**
- Creates delegated payment tokens
- Detects available payment methods (PayPal ACDC, generic cards)
- Integrates with SwagPayPal vault system
- Validates allowance constraints (max_amount, expiry)
- Stores token metadata and card information
- Reuses existing customers to satisfy foreign key constraints

### Controllers

**CheckoutSessionController** - 5 endpoints
- Create, retrieve, update, complete, and cancel checkout sessions
- Validates API version headers
- Returns ACP-compliant error responses
- Supports idempotency keys

**DelegatePaymentController** - 1 endpoint
- Creates payment tokens with allowance constraints
- Validates card data and risk signals
- Returns tokenized payment credentials

### Database Schema

**`acp_checkout_session`**
```sql
id                  BINARY(16)    -- Session UUID
cart_token          VARCHAR(255)  -- Shopware cart token
sales_channel_id    BINARY(16)    -- FK to sales_channel
status              VARCHAR(50)   -- ready_for_payment, completed, canceled
data                LONGTEXT      -- Full session JSON
order_id            BINARY(16)    -- FK to order (when completed)
created_at          DATETIME(3)
updated_at          DATETIME(3)
```

**`acp_payment_token`**
```sql
id                      BINARY(16)     -- Token UUID
acp_token_id            VARCHAR(255)   -- ACP token (vt_card_* or vt_paypal_*)
paypal_vault_token_id   BINARY(16)     -- FK to swag_paypal_vault_token
payment_method_id       BINARY(16)     -- FK to payment_method
checkout_session_id     VARCHAR(255)   -- Associated checkout session
max_amount              INT            -- Allowance limit in cents
currency                VARCHAR(3)     -- Currency code
expires_at              DATETIME(3)    -- Token expiration
used                    TINYINT(1)     -- Whether token consumed
order_id                BINARY(16)     -- FK to order (when used)
card_last4              VARCHAR(4)     -- Card last 4 digits
card_brand              VARCHAR(50)    -- Card brand (visa, mastercard, etc.)
created_at              DATETIME(3)
updated_at              DATETIME(3)
```

## PayPal ACDC Integration Details

### What is PayPal ACDC?

**Advanced Credit and Debit Card (ACDC)** is PayPal's direct card processing solution that enables payments **without customer login or redirect**. This makes it perfect for AI agent commerce.

### ACDC vs PayPal Express

| Feature | PayPal Express | PayPal ACDC (This Plugin) |
|---------|----------------|---------------------------|
| Customer login | Required | **Not required** ✅ |
| Redirect to PayPal.com | Yes | **No** ✅ |
| PayPal account needed | Yes | **No** ✅ |
| Card processing | Indirect via account | **Direct** ✅ |
| AI agent suitable | No ❌ | **Yes** ✅ |

### Integration Flow

**Token Creation:**
```
AI Agent provides card data
    ↓
POST /api/agentic_commerce/delegate_payment
    ↓
PaymentTokenService detects SwagPayPal availability
    ↓
If available:
    ├→ Resolves existing customer for FK constraint
    ├→ Creates entry in swag_paypal_vault_token
    ├→ Creates entry in acp_payment_token (with link)
    └→ Returns vt_paypal_* token
Else:
    ├→ Creates entry in acp_payment_token only
    └→ Returns vt_card_* token (demo mode)
```

**Order Completion:**
```
POST /checkout_sessions/{id}/complete
    ↓
CheckoutSessionService loads payment token
    ↓
Validates allowance (max_amount, expiry, not used)
    ↓
If PayPal token:
    ├→ Loads PayPal vault token from swag_paypal_vault_token
    ├→ Sets PayPal ACDC as payment method
    ├→ Shopware OrderPersister creates order
    ├→ Shopware automatically triggers SwagPayPal ACDCHandler
    ├→ ACDCHandler builds PayPal order with vault_id
    ├→ PayPal API charges the vaulted card
    └→ Order status set to "Paid" ✅
Else (demo mode):
    ├→ Creates order with generic payment method
    └→ Order status remains "Open"
```

### Key Advantage: No Login Flow

Unlike PayPal Express Checkout, ACDC processes cards **directly in the backend**:
- AI agent sends card data
- PayPal tokenizes card (vault)
- Future charges use vault token
- **Customer never sees PayPal.com**
- **No authentication prompts**

This is essential for AI-driven commerce where the user delegates payment authority to the AI agent.

## Technical Requirements

- **Shopware**: 6.5+ (tested with 6.7.2.2)
- **PHP**: 8.1+ (tested with 8.3)
- **MySQL**: 5.7+ or MariaDB 10.3+
- **SwagPayPal**: 10.1+ (optional, for production payments)
- **Node.js**: 16+ (for demo interface only)

## Configuration

### Plugin Configuration

Access via Shopware Admin → Extensions → My extensions → ACP Integration:

- **Demo Mode**: Enable to use simulated tokens (no PayPal needed)
- **Payment Mode**: Choose "Demo" or "PayPal ACDC"

### PayPal Configuration

Access via Shopware Admin → Settings → Payment → PayPal:

- **Environment**: Sandbox (for testing) or Live (for production)
- **Client ID**: From PayPal Developer Dashboard
- **Client Secret**: From PayPal Developer Dashboard
- **Enable ACDC**: Must be checked ✅
- **Activate Vaulting**: Must be checked ✅

**Payment Method Activation:**
- Settings → Payment methods → "Credit or debit card"
- Active: ✅
- Sales Channels: Assign to your channels

## Error Handling

All errors follow ACP specification format:

```json
{
  "type": "invalid_request|processing_error|service_unavailable",
  "code": "invalid|missing|not_found|invalid_state|payment_declined",
  "message": "Human-readable error description",
  "param": "$.path.to.field"
}
```

## Production Considerations

### Security
- Implement proper JWT validation for Bearer tokens
- Use HTTPS for all API requests (TLS 1.2+)
- Never log full card numbers or CVCs
- Implement rate limiting on API endpoints
- Validate request signatures for added security

### Performance
- Cache product data, shipping methods, and payment methods
- Add database indexes on `cart_token` and `acp_token_id` columns
- Implement session cleanup cron job for expired sessions
- Monitor API response times

### Monitoring
- Log all API requests and responses (redact sensitive data)
- Track session creation, completion, and cancellation rates
- Monitor error rates by type
- Set up alerts for payment failures

### Webhooks
- Implement ACP webhook endpoints for order status updates
- Handle payment status changes from PayPal
- Notify AI agents of fulfillment status changes

## Support

- **Quick Start**: See `QUICK_START.md` in this directory
- **ACP Protocol**: https://github.com/agentic-commerce-protocol/agentic-commerce-protocol
- **ACP Website**: https://agenticcommerce.dev
- **PayPal ACDC Docs**: https://developer.paypal.com/docs/checkout/advanced/customize/cards/

## License

Apache 2.0 - See LICENSE file in repository root

---

**Ready to enable AI-powered shopping for your Shopware store!** See `QUICK_START.md` to get started in 5 minutes. 🚀
