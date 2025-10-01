#!/bin/bash

# Plugin Verification Script
# Checks if the plugin is correctly installed and configured

CONTAINER_NAME="shopware-acp"
PLUGIN_NAME="AcpShopwarePlugin"

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}ACP Plugin Verification${NC}"
echo -e "${GREEN}========================================${NC}"
echo ""

# Check if container is running
if ! docker ps --format '{{.Names}}' | grep -q "^${CONTAINER_NAME}$"; then
    echo -e "${RED}✗ Container '${CONTAINER_NAME}' is not running${NC}"
    exit 1
fi

echo -e "${GREEN}✓ Container is running${NC}"
echo ""

CHECKS_PASSED=0
CHECKS_FAILED=0

# Function to run a check
check() {
    local NAME="$1"
    local COMMAND="$2"
    
    echo -e "${BLUE}Checking: ${NAME}${NC}"
    
    if eval "$COMMAND" > /dev/null 2>&1; then
        echo -e "  ${GREEN}✓ PASSED${NC}"
        CHECKS_PASSED=$((CHECKS_PASSED + 1))
        return 0
    else
        echo -e "  ${RED}✗ FAILED${NC}"
        CHECKS_FAILED=$((CHECKS_FAILED + 1))
        return 1
    fi
}

# 1. Check plugin files exist
echo -e "${YELLOW}[1] Plugin Files${NC}"
check "Plugin directory exists" \
    "docker exec ${CONTAINER_NAME} test -d /var/www/html/custom/plugins/${PLUGIN_NAME}"

check "composer.json exists" \
    "docker exec ${CONTAINER_NAME} test -f /var/www/html/custom/plugins/${PLUGIN_NAME}/composer.json"

check "Plugin class exists" \
    "docker exec ${CONTAINER_NAME} test -f /var/www/html/custom/plugins/${PLUGIN_NAME}/src/AcpShopwarePlugin.php"

echo ""

# 2. Check plugin is installed and active
echo -e "${YELLOW}[2] Plugin Installation${NC}"

PLUGIN_LIST=$(docker exec -u www-data ${CONTAINER_NAME} php /var/www/html/bin/console plugin:list 2>/dev/null | grep ${PLUGIN_NAME})

if echo "$PLUGIN_LIST" | grep -q "Yes"; then
    echo -e "  ${GREEN}✓ Plugin is installed${NC}"
    CHECKS_PASSED=$((CHECKS_PASSED + 1))
else
    echo -e "  ${RED}✗ Plugin is not installed${NC}"
    CHECKS_FAILED=$((CHECKS_FAILED + 1))
fi

if echo "$PLUGIN_LIST" | grep -q "Yes.*Yes"; then
    echo -e "  ${GREEN}✓ Plugin is active${NC}"
    CHECKS_PASSED=$((CHECKS_PASSED + 1))
else
    echo -e "  ${RED}✗ Plugin is not active${NC}"
    CHECKS_FAILED=$((CHECKS_FAILED + 1))
fi

echo ""

# 3. Check database tables
echo -e "${YELLOW}[3] Database Tables${NC}"

check "acp_checkout_session table exists" \
    "docker exec ${CONTAINER_NAME} mysql -u root -proot shopware -e 'DESCRIBE acp_checkout_session' 2>/dev/null"

check "acp_payment_token table exists" \
    "docker exec ${CONTAINER_NAME} mysql -u root -proot shopware -e 'DESCRIBE acp_payment_token' 2>/dev/null"

echo ""

# 4. Check service definitions
echo -e "${YELLOW}[4] Service Configuration${NC}"

check "Services XML exists" \
    "docker exec ${CONTAINER_NAME} test -f /var/www/html/custom/plugins/${PLUGIN_NAME}/src/Resources/config/services.xml"

check "CheckoutSessionController defined" \
    "docker exec ${CONTAINER_NAME} grep -q 'CheckoutSessionController' /var/www/html/custom/plugins/${PLUGIN_NAME}/src/Resources/config/services.xml"

check "PaymentTokenService defined" \
    "docker exec ${CONTAINER_NAME} grep -q 'PaymentTokenService' /var/www/html/custom/plugins/${PLUGIN_NAME}/src/Resources/config/services.xml"

echo ""

# 5. Check controllers exist
echo -e "${YELLOW}[5] Controllers${NC}"

check "CheckoutSessionController exists" \
    "docker exec ${CONTAINER_NAME} test -f /var/www/html/custom/plugins/${PLUGIN_NAME}/src/Controller/CheckoutSessionController.php"

check "DelegatePaymentController exists" \
    "docker exec ${CONTAINER_NAME} test -f /var/www/html/custom/plugins/${PLUGIN_NAME}/src/Controller/DelegatePaymentController.php"

echo ""

# 6. Check services exist
echo -e "${YELLOW}[6] Services${NC}"

check "CheckoutSessionService exists" \
    "docker exec ${CONTAINER_NAME} test -f /var/www/html/custom/plugins/${PLUGIN_NAME}/src/Service/CheckoutSessionService.php"

check "PaymentTokenService exists" \
    "docker exec ${CONTAINER_NAME} test -f /var/www/html/custom/plugins/${PLUGIN_NAME}/src/Service/PaymentTokenService.php"

echo ""

# 7. Check migrations
echo -e "${YELLOW}[7] Migrations${NC}"

check "Checkout session migration exists" \
    "docker exec ${CONTAINER_NAME} test -f /var/www/html/custom/plugins/${PLUGIN_NAME}/src/Migration/Migration1696000000CreateCheckoutSessionTable.php"

check "Payment token migration exists" \
    "docker exec ${CONTAINER_NAME} test -f /var/www/html/custom/plugins/${PLUGIN_NAME}/src/Migration/Migration1696000001CreatePaymentTokenTable.php"

echo ""

# 8. Check API endpoint accessibility
echo -e "${YELLOW}[8] API Endpoints${NC}"

# Test if endpoints are accessible (even if they return errors, they should be routed)
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" http://localhost:80/api/_info/version 2>/dev/null || echo "000")

if [ "$HTTP_CODE" = "200" ] || [ "$HTTP_CODE" = "401" ]; then
    echo -e "  ${GREEN}✓ Shopware API is accessible${NC}"
    CHECKS_PASSED=$((CHECKS_PASSED + 1))
else
    echo -e "  ${RED}✗ Shopware API not accessible (HTTP $HTTP_CODE)${NC}"
    CHECKS_FAILED=$((CHECKS_FAILED + 1))
fi

echo ""

# 9. Check SwagPayPal integration
echo -e "${YELLOW}[9] SwagPayPal Integration${NC}"

if docker exec ${CONTAINER_NAME} test -d /var/www/html/custom/plugins/SwagPayPal 2>/dev/null; then
    echo -e "  ${GREEN}✓ SwagPayPal plugin found${NC}"
    CHECKS_PASSED=$((CHECKS_PASSED + 1))
    
    # Check if swag_paypal_vault_token table exists
    if docker exec ${CONTAINER_NAME} mysql -u root -proot shopware -e 'DESCRIBE swag_paypal_vault_token' 2>/dev/null > /dev/null; then
        echo -e "  ${GREEN}✓ PayPal vault token table exists${NC}"
        CHECKS_PASSED=$((CHECKS_PASSED + 1))
    else
        echo -e "  ${YELLOW}⚠ PayPal vault token table not found${NC}"
    fi
else
    echo -e "  ${YELLOW}⚠ SwagPayPal plugin not installed${NC}"
    echo -e "    (Optional but recommended for full functionality)"
fi

echo ""

# Summary
echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}Verification Results${NC}"
echo -e "${GREEN}========================================${NC}"
echo ""
echo "Checks Passed: ${GREEN}${CHECKS_PASSED}${NC}"
echo "Checks Failed: ${RED}${CHECKS_FAILED}${NC}"
echo ""

if [ $CHECKS_FAILED -eq 0 ]; then
    echo -e "${GREEN}✓✓✓ All checks passed! Plugin is working correctly.${NC}"
    echo ""
    echo -e "${BLUE}Next steps:${NC}"
    echo "• Run API tests: ./run-tests.sh"
    echo "• Access admin: http://localhost:80/admin"
    echo "• Test endpoints manually with curl"
    exit 0
else
    echo -e "${RED}✗✗✗ Some checks failed. Please review the errors above.${NC}"
    echo ""
    echo -e "${BLUE}Troubleshooting:${NC}"
    echo "• Reinstall plugin: ./install-plugin.sh"
    echo "• Check logs: docker logs ${CONTAINER_NAME}"
    echo "• Check cache: docker exec ${CONTAINER_NAME} php /var/www/html/bin/console cache:clear"
    exit 1
fi

