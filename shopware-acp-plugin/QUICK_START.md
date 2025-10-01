# Quick Start Guide

Get the ACP plugin running in 5 minutes.

## Prerequisites

- Docker installed and running
- Port 80, 443, 3000, and 3306 available
- Node.js 16+ (for demo interface)

## Installation

### 1. Start Shopware Container

```bash
# From repository root
cd tests
./docker-start.sh start
```

Wait ~30 seconds for Shopware to initialize.

### 2. Install Plugin

```bash
./install-plugin.sh
```

This automatically:
- Copies plugin files to container
- Installs and activates AcpShopwarePlugin
- Runs database migrations (creates `acp_checkout_session` and `acp_payment_token` tables)
- Clears cache

### 3. Verify Installation

```bash
./verify-plugin.sh
```

Should show all green checkmarks ✅

## Testing

### Run Automated Tests

```bash
# Demo mode (no PayPal required)
./run-tests.sh

# Production mode (with PayPal sandbox)
./run-tests.sh --productive
```

**Expected Results:**
- ✅ OAuth authentication succeeds
- ✅ Payment tokens created: `vt_paypal_*` (production) or `vt_card_*` (demo)
- ✅ Checkout sessions created with real Shopware products
- ✅ Orders completed and persisted

Logs saved to `test-results-*.log` and `production-test-*.log`.

### Try the Interactive Demo

```bash
# From repository root
cd dummy-agent
npm install

# Demo mode (no PayPal)
./start-demo.sh

# Production mode (PayPal sandbox)
./start-productive.sh
```

Open http://localhost:3000 to see a ChatGPT-style interface with:
- Real Shopware products
- Embedded checkout flow
- Purchase completion
- Live API integration

## PayPal Setup (Optional - For Production Mode)

### 1. Get Sandbox Credentials

1. Go to https://developer.paypal.com
2. Create a **Sandbox App**
3. Copy **Client ID** and **Client Secret**

### 2. Configure in Shopware Admin

1. Open http://localhost:80/admin (login: `admin` / `shopware`)
2. Navigate to **Settings → Payment → PayPal**
3. Enter:
   - Environment: **Sandbox**
   - Client ID: [your client ID]
   - Client Secret: [your secret]
   - Enable ACDC: **✅**
4. Save

### 3. Activate Payment Method

1. Go to **Settings → Payment methods**
2. Find **"Credit or debit card"** (PayPal ACDC)
3. Set **Active: ✅**
4. Assign to **Sales Channels**
5. Save and clear cache

### 4. Verify PayPal Integration

```bash
cd tests
./run-tests.sh --productive
```

Should show: `✅ Payment Token Created: vt_paypal_*` 🎉

## Quick Test

### Manual API Test

```bash
# Get OAuth token
TOKEN=$(curl -s -X POST http://localhost:80/api/oauth/token \
  -d "client_id=SWIANVDVQ2ZQCNHUSEFOVK95VG" \
  -d "client_secret=czVkcWRZWXdQZTF6OFZXRVU3eGJoUkV2MTRoNWJ6clFoUzlIOFg" \
  -d "grant_type=client_credentials" | jq -r '.access_token')

# Create payment token
curl -X POST http://localhost:80/api/agentic_commerce/delegate_payment \
  -H "Authorization: Bearer $TOKEN" \
  -H "API-Version: 2025-09-29" \
  -H "Content-Type: application/json" \
  -d '{
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
      "max_amount": 50000,
      "currency": "eur",
      "checkout_session_id": "test_123",
      "merchant_id": "shop_test",
      "expires_at": "2025-12-31T23:59:59Z"
    },
    "risk_signals": [
      {"type": "card_testing", "score": 5, "action": "authorized"}
    ]
  }'
```

Expected response: `{"id": "vt_paypal_*" ...}` (production) or `{"id": "vt_card_*" ...}` (demo)

## Troubleshooting

### Container not running
```bash
./docker-start.sh status
./docker-start.sh restart
```

### Plugin installation fails
```bash
# Check container logs
docker logs shopware-acp

# Reinstall
./install-plugin.sh
```

### Tests fail
```bash
# Run health checks
./verify-plugin.sh

# Check if Shopware is accessible
curl http://localhost:80/api/_info/version
```

### Demo mode instead of PayPal tokens

This means PayPal is not fully configured:
1. Verify SwagPayPal plugin is installed and active
2. Check PayPal credentials in Admin → Settings → Payment → PayPal
3. Ensure "Credit or debit card" payment method is active
4. Verify payment method is assigned to sales channels
5. Clear cache: `docker exec -u www-data shopware-acp php /var/www/html/bin/console cache:clear`

## What's Next?

- **See the demo**: http://localhost:3000
- **Check orders**: http://localhost:80/admin (admin/shopware)
- **Read full docs**: `shopware-acp-plugin/README.md`
- **Configure PayPal**: Follow PayPal Setup section above

---

**You're ready!** The plugin is installed and functional. Start the demo to see ACP in action. 🎉
