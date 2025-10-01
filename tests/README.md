# ACP Plugin Test Suite

This directory contains scripts for managing the Docker container, installing the plugin, and running tests.

## Prerequisites

- Docker installed and running
- A Shopware 6 Docker container named `shopware-acp`
- Container accessible on `localhost:80`

## Scripts

### 1. `docker-start.sh` - Container Management

Start, stop, restart, and monitor the Shopware Docker container.

```bash
# Start container
./docker-start.sh start

# Stop container
./docker-start.sh stop

# Restart container
./docker-start.sh restart

# Check status
./docker-start.sh status

# View logs
./docker-start.sh logs

# Access shell
./docker-start.sh shell
```

### 2. `install-plugin.sh` - Plugin Installation

Installs the ACP plugin into the Shopware container and runs all necessary setup steps.

```bash
./install-plugin.sh
```

**What it does:**
1. Copies plugin files to container
2. Sets correct permissions
3. Checks/installs SwagPayPal dependency
4. Refreshes plugin list
5. Installs and activates plugin
6. Runs database migrations
7. Clears cache
8. Verifies installation

### 3. `verify-plugin.sh` - Plugin Verification

Comprehensive check to verify the plugin is correctly installed and configured.

```bash
./verify-plugin.sh
```

**Checks:**
- Plugin files exist
- Plugin is installed and active
- Database tables created
- Service definitions
- Controllers exist
- Services exist
- Migrations executed
- API endpoints accessible
- SwagPayPal integration

### 4. `run-tests.sh` - API Test Suite

Runs automated tests against all ACP API endpoints.

```bash
./run-tests.sh
```

**Tests:**
- Health check
- Create checkout session
- Retrieve checkout session
- Update checkout session
- Cancel checkout session
- Create payment token
- API version validation

## Quick Start

### First Time Setup

```bash
# 1. Make scripts executable
chmod +x *.sh

# 2. Start the Docker container
./docker-start.sh start

# 3. Install the plugin
./install-plugin.sh

# 4. Verify installation
./verify-plugin.sh

# 5. Run API tests
./run-tests.sh
```

### After Code Changes

```bash
# Reinstall plugin with latest changes
./install-plugin.sh

# Verify everything works
./verify-plugin.sh

# Run tests
./run-tests.sh
```

## Manual Testing

### Create Checkout Session

```bash
curl -X POST http://localhost:80/api/checkout_sessions \
  -H "Content-Type: application/json" \
  -H "API-Version: 2025-09-29" \
  -H "Authorization: Bearer test-token" \
  -d '{
    "items": [
      {"id": "PRODUCT-NUMBER", "quantity": 1}
    ],
    "fulfillment_address": {
      "name": "Test User",
      "line_one": "123 Test St",
      "city": "Berlin",
      "state": "BE",
      "country": "DE",
      "postal_code": "10115"
    }
  }'
```

### Create Payment Token

```bash
curl -X POST http://localhost:80/api/agentic_commerce/delegate_payment \
  -H "Content-Type: application/json" \
  -H "API-Version: 2025-09-29" \
  -H "Authorization: Bearer test-token" \
  -d '{
    "payment_method": {
      "type": "card",
      "card_number_type": "fpan",
      "virtual": false,
      "number": "4111111111111111",
      "exp_month": "12",
      "exp_year": "2025",
      "cvc": "123",
      "display_card_funding_type": "credit",
      "display_brand": "visa",
      "display_last4": "1111",
      "metadata": {}
    },
    "allowance": {
      "reason": "one_time",
      "max_amount": 10000,
      "currency": "eur",
      "checkout_session_id": "test_123",
      "merchant_id": "shop_123",
      "expires_at": "2025-12-31T23:59:59Z"
    },
    "risk_signals": [
      {"type": "card_testing", "score": 5, "action": "authorized"}
    ],
    "metadata": {"source": "manual_test"}
  }'
```

## Troubleshooting

### Container not accessible

```bash
# Check container status
./docker-start.sh status

# Check logs
./docker-start.sh logs

# Restart container
./docker-start.sh restart
```

### Plugin installation fails

```bash
# Access container shell
./docker-start.sh shell

# Inside container, check Shopware logs
tail -f /var/www/html/var/log/*.log

# Check plugin status
php bin/console plugin:list | grep Acp

# Clear cache manually
php bin/console cache:clear
```

### Database issues

```bash
# Check database
docker exec shopware-acp mysql -u root -proot shopware -e "SHOW TABLES LIKE 'acp_%';"

# Re-run migrations
docker exec -u www-data shopware-acp php /var/www/html/bin/console database:migrate --all AcpShopwarePlugin
```

### API tests fail

```bash
# Check if Shopware is accessible
curl http://localhost:80/api/_info/version

# Check plugin is active
./verify-plugin.sh

# Ensure PayPal vault table has data
docker exec shopware-acp mysql -u root -proot -h 127.0.0.1 shopware -e "SELECT COUNT(*) FROM swag_paypal_vault_token;"

# Check Shopware logs
docker logs shopware-acp | tail -50
```

### PayPal tokens stay in demo mode
- Verify SwagPayPal plugin is active and sandbox credentials are configured in the Shopware admin
- Run `./run-tests.sh --productive` and confirm the log shows `vt_paypal_*` token ids
- If the vault insert fails, ensure at least one customer exists (Shopware demo data creates one by default)

## Container Setup (If Not Exists)

If you need to create the container from scratch:

```bash
# Using official Shopware Docker image
docker run -d \
  --name shopware-acp \
  -p 80:80 \
  -e APP_ENV=dev \
  -e DATABASE_URL=mysql://root:root@localhost:3306/shopware \
  shopware/docker-base:latest

# Wait for Shopware to initialize
sleep 30

# Then run installation
./install-plugin.sh
```

## CI/CD Integration

These scripts can be integrated into CI/CD pipelines:

```bash
#!/bin/bash
set -e

# Start container
./tests/docker-start.sh start

# Install plugin
./tests/install-plugin.sh

# Verify installation
./tests/verify-plugin.sh

# Run tests
./tests/run-tests.sh

# Stop container
./tests/docker-start.sh stop
```

## Notes

- All scripts use color-coded output (green=success, red=error, yellow=warning, blue=info)
- Scripts exit with appropriate exit codes for CI/CD integration
- Logs are available via `docker logs shopware-acp`
- Container data persists between restarts
- Plugin changes require reinstallation (`./install-plugin.sh`)

