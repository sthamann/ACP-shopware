#!/bin/bash

# Complete ACP Lifecycle Test Suite with Logging
# Supports --productive flag for real PayPal testing

set -e

CONTAINER_NAME="shopware-acp"
BASE_URL="http://localhost:80"
API_VERSION="2025-09-29"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOG_FILE="$SCRIPT_DIR/test-results-$(date +%Y%m%d-%H%M%S).log"

# OAuth Credentials
CLIENT_ID="SWIANVDVQ2ZQCNHUSEFOVK95VG"
CLIENT_SECRET="czVkcWRZWXdQZTF6OFZXRVU3eGJoUkV2MTRoNWJ6clFoUzlIOFg"

# Check for --productive flag
PRODUCTIVE_MODE=false
if [[ "$1" == "--productive" ]] || [[ "$1" == "-p" ]]; then
    PRODUCTIVE_MODE=true
    LOG_FILE="$SCRIPT_DIR/production-test-$(date +%Y%m%d-%H%M%S).log"
fi

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
MAGENTA='\033[0;35m'
NC='\033[0m'

# Initialize log file
echo "========================================" | tee "$LOG_FILE"
if [ "$PRODUCTIVE_MODE" = true ]; then
    echo "ACP Plugin PRODUCTION MODE Test (with PayPal)" | tee -a "$LOG_FILE"
else
    echo "ACP Plugin DEMO MODE Test" | tee -a "$LOG_FILE"
fi
echo "Test Date: $(date)" | tee -a "$LOG_FILE"
echo "========================================" | tee -a "$LOG_FILE"
echo "" | tee -a "$LOG_FILE"

# Log function
log() {
    echo -e "$1" | tee -a "$LOG_FILE"
}

# Check if container is running
if ! docker ps --format '{{.Names}}' | grep -q "^${CONTAINER_NAME}$"; then
    log "${RED}✗ Container '${CONTAINER_NAME}' is not running${NC}"
    exit 1
fi

log "${GREEN}✓ Container is running${NC}"

# Check mode
if [ "$PRODUCTIVE_MODE" = true ]; then
    log "${MAGENTA}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    log "${MAGENTA}🔴 PRODUCTION MODE - Testing with PayPal${NC}"
    log "${MAGENTA}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    
    # Set demo mode OFF in database
    log "Setting ACP plugin to production mode..."
    docker exec shopware-acp mysql -u root -proot shopware -e "UPDATE system_config SET configuration_value = '{\"_value\": false}' WHERE configuration_key = 'AcpShopwarePlugin.config.demoMode';" 2>/dev/null || true
    docker exec -u www-data shopware-acp php /var/www/html/bin/console cache:clear 2>&1 | grep -q "OK" && log "${GREEN}✓ Cache cleared${NC}"
    
    # Check if PayPal is configured
    PAYPAL_CLIENT_ID=$(docker exec shopware-acp mysql -u root -proot shopware -e "SELECT configuration_value FROM system_config WHERE configuration_key = 'SwagPayPal.settings.clientId';" 2>/dev/null | tail -n1 || echo "")
    
    if [ -z "$PAYPAL_CLIENT_ID" ] || [ "$PAYPAL_CLIENT_ID" = "configuration_value" ]; then
        log "${YELLOW}⚠️  WARNING: PayPal credentials not configured!${NC}"
        log "${YELLOW}   Tests will use demo mode fallback.${NC}"
        log "${YELLOW}   Configure PayPal in: http://localhost:80/admin${NC}"
        log ""
    else
        log "${GREEN}✓ PayPal credentials found${NC}"
    fi
else
    log "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    log "${BLUE}🔵 DEMO MODE - No PayPal required${NC}"
    log "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
fi

log ""

# Test counter
TESTS_RUN=0
TESTS_PASSED=0
TESTS_FAILED=0

# Function to run a test
run_test() {
    local TEST_NAME="$1"
    local METHOD="$2"
    local ENDPOINT="$3"
    local DATA="$4"
    local EXPECTED_STATUS="$5"
    
    TESTS_RUN=$((TESTS_RUN + 1))
    
    log "${BLUE}[Test ${TESTS_RUN}] ${TEST_NAME}${NC}"
    log "  ${METHOD} ${ENDPOINT}"
    
    if [ -n "$DATA" ]; then
        RESPONSE=$(curl -s -w "\n%{http_code}" -X "${METHOD}" "${BASE_URL}${ENDPOINT}" \
            -H "Content-Type: application/json" \
            -H "API-Version: ${API_VERSION}" \
            -H "Authorization: Bearer ${ACCESS_TOKEN}" \
            -d "${DATA}" 2>&1)
    else
        RESPONSE=$(curl -s -w "\n%{http_code}" -X "${METHOD}" "${BASE_URL}${ENDPOINT}" \
            -H "Content-Type: application/json" \
            -H "API-Version: ${API_VERSION}" \
            -H "Authorization: Bearer ${ACCESS_TOKEN}" 2>&1)
    fi
    
    HTTP_CODE=$(echo "$RESPONSE" | tail -n1)
    BODY=$(echo "$RESPONSE" | sed '$d')
    
    log "  Response Code: ${HTTP_CODE}"
    log "  Response Body: ${BODY:0:500}"
    
    if [ "$HTTP_CODE" -eq "$EXPECTED_STATUS" ]; then
        log "  ${GREEN}✓ PASSED${NC}"
        TESTS_PASSED=$((TESTS_PASSED + 1))
    else
        log "  ${RED}✗ FAILED${NC} (Expected HTTP $EXPECTED_STATUS, got $HTTP_CODE)"
        TESTS_FAILED=$((TESTS_FAILED + 1))
    fi
    
    log ""
    
    # Return body for use in subsequent tests
    echo "$BODY"
}

# Extract JSON value
extract_json() {
    local JSON="$1"
    local KEY="$2"
    echo "$JSON" | python3 -c "import sys, json; data=json.load(sys.stdin); print(data.get('${KEY}', ''))" 2>/dev/null || \
    echo "$JSON" | grep -o "\"${KEY}\":\"[^\"]*\"" | cut -d'"' -f4
}

# STEP 1: Authentication
log "${YELLOW}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
log "${YELLOW}Step 1: OAuth Authentication${NC}"
log "${YELLOW}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
log ""

log "Getting OAuth access token..."
AUTH_RESPONSE=$(curl -s -X POST "${BASE_URL}/api/oauth/token" \
    -d "client_id=${CLIENT_ID}" \
    -d "client_secret=${CLIENT_SECRET}" \
    -d "grant_type=client_credentials" 2>&1)

log "Auth Response: ${AUTH_RESPONSE:0:200}..."

ACCESS_TOKEN=$(echo "$AUTH_RESPONSE" | python3 -c "import sys, json; print(json.load(sys.stdin).get('access_token', ''))" 2>/dev/null)

if [ -z "$ACCESS_TOKEN" ]; then
    log "${RED}✗ Failed to get access token${NC}"
    exit 1
fi

log "${GREEN}✓ Access token obtained${NC}"
log "Token: ${ACCESS_TOKEN:0:50}..."
log ""

# STEP 2: Create Payment Token
log "${YELLOW}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
log "${YELLOW}Step 2: Create Payment Token${NC}"
if [ "$PRODUCTIVE_MODE" = true ]; then
    log "${MAGENTA}(Testing with PayPal integration)${NC}"
else
    log "${BLUE}(Demo mode - no PayPal)${NC}"
fi
log "${YELLOW}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
log ""

PAYMENT_DATA='{
  "payment_method": {
    "type": "card",
    "card_number_type": "fpan",
    "virtual": false,
    "number": "4111111111111111",
    "exp_month": "12",
    "exp_year": "2026",
    "cvc": "123",
    "display_card_funding_type": "credit",
    "display_brand": "visa",
    "display_last4": "1111",
    "metadata": {}
  },
  "allowance": {
    "reason": "one_time",
    "max_amount": 50000,
    "currency": "eur",
    "checkout_session_id": "test_'$(date +%s)'",
    "merchant_id": "shop_test",
    "expires_at": "2025-12-31T23:59:59Z"
  },
  "risk_signals": [
    {"type": "card_testing", "score": 5, "action": "authorized"}
  ],
  "metadata": {
    "source": "automated_test",
    "mode": "'$([ "$PRODUCTIVE_MODE" = true ] && echo "production" || echo "demo")'"
  }
}'

TOKEN_RESPONSE=$(run_test "Create Payment Token" "POST" "/api/agentic_commerce/delegate_payment" "$PAYMENT_DATA" "201")

log "DEBUG TOKEN_RESPONSE: ${TOKEN_RESPONSE:0:1000}"
PAYMENT_TOKEN=$(extract_json "$TOKEN_RESPONSE" "id")

if [ -n "$PAYMENT_TOKEN" ]; then
    log "${GREEN}✓ Payment Token Created: ${PAYMENT_TOKEN}${NC}"
    
    # Check token type
    if [[ "$PAYMENT_TOKEN" == vt_paypal_* ]]; then
        log "${MAGENTA}   🎉 PAYPAL TOKEN! Production mode is active!${NC}"
        USING_PAYPAL=true
    elif [[ "$PAYMENT_TOKEN" == vt_card_* ]]; then
        log "${BLUE}   ℹ️  Card token (Demo mode)${NC}"
        USING_PAYPAL=false
    fi
else
    log "${RED}✗ Failed to extract payment token ID${NC}"
fi
log ""

# STEP 3: Get Real Product
log "${YELLOW}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
log "${YELLOW}Step 3: Get Real Product from Shopware${NC}"
log "${YELLOW}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
log ""

log "Fetching real products from Shopware..."

PRODUCT_RESP=$(curl -s -X POST "${BASE_URL}/api/search/product" \
    -H "Content-Type: application/json" \
    -H "Authorization: Bearer ${ACCESS_TOKEN}" \
    -d '{"limit": 1, "filter": [{"type": "equals", "field": "active", "value": true}]}' 2>&1)

PRODUCT_NUMBER=$(echo "$PRODUCT_RESP" | python3 -c "import sys, json; data=json.load(sys.stdin); print(data['data'][0]['productNumber'] if data.get('data') else '')" 2>/dev/null || echo "SWDEMO10006")

log "Using product: ${PRODUCT_NUMBER}"
log ""

# STEP 4: Create Checkout Session
log "${YELLOW}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
log "${YELLOW}Step 4: Create Checkout Session${NC}"
log "${YELLOW}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
log ""

SESSION_DATA='{
  "items": [
    {"id": "'"${PRODUCT_NUMBER}"'", "quantity": 2}
  ],
  "buyer": {
    "first_name": "Test",
    "last_name": "User",
    "email": "test@acptest.com"
  },
  "fulfillment_address": {
    "name": "Test User",
    "line_one": "123 Test Street",
    "city": "Berlin",
    "state": "BE",
    "country": "DE",
    "postal_code": "10115"
  }
}'

SESSION_RESPONSE=$(run_test "Create Checkout Session" "POST" "/api/checkout_sessions" "$SESSION_DATA" "201")

SESSION_ID=$(extract_json "$SESSION_RESPONSE" "id")

if [ -n "$SESSION_ID" ]; then
    log "${GREEN}✓ Checkout Session Created: ${SESSION_ID}${NC}"
    
    # Extract session details
    SESSION_STATUS=$(extract_json "$SESSION_RESPONSE" "status")
    SESSION_CURRENCY=$(extract_json "$SESSION_RESPONSE" "currency")
    
    log "   Status: ${SESSION_STATUS}"
    log "   Currency: ${SESSION_CURRENCY}"
else
    log "${YELLOW}⚠ Session creation had issues (product might not exist)${NC}"
    SESSION_ID="test_session_$(date +%s)"
fi
log ""

# STEP 5: Retrieve Session
if [ "$SESSION_ID" != "test_session_"* ]; then
    log "${YELLOW}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    log "${YELLOW}Step 5: Retrieve Checkout Session${NC}"
    log "${YELLOW}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    log ""
    
    RETRIEVE_RESPONSE=$(run_test "Retrieve Checkout Session" "GET" "/api/checkout_sessions/${SESSION_ID}" "" "200")
fi

# STEP 6: Update Session
if [ "$SESSION_ID" != "test_session_"* ]; then
    log "${YELLOW}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    log "${YELLOW}Step 6: Update Checkout Session${NC}"
    log "${YELLOW}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    log ""
    
    UPDATE_DATA='{
      "items": [
        {"id": "'"${PRODUCT_NUMBER}"'", "quantity": 3}
      ]
    }'
    
    UPDATE_RESPONSE=$(run_test "Update Checkout Session" "POST" "/api/checkout_sessions/${SESSION_ID}" "$UPDATE_DATA" "200")
fi

# STEP 7: Complete Checkout
if [ "$SESSION_ID" != "test_session_"* ] && [ -n "$PAYMENT_TOKEN" ]; then
    log "${YELLOW}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    log "${YELLOW}Step 7: Complete Checkout Session${NC}"
    if [ "$USING_PAYPAL" = true ]; then
        log "${MAGENTA}(With PayPal payment processing!)${NC}"
    fi
    log "${YELLOW}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    log ""
    
    COMPLETE_DATA='{
      "buyer": {
        "first_name": "Test",
        "last_name": "User",
        "email": "test@acptest.com",
        "phone_number": "+491234567890"
      },
      "payment_data": {
        "token": "'"${PAYMENT_TOKEN}"'",
        "provider": "shopware"
      }
    }'
    
    COMPLETE_RESPONSE=$(run_test "Complete Checkout Session" "POST" "/api/checkout_sessions/${SESSION_ID}/complete" "$COMPLETE_DATA" "200")
    
    ORDER_ID=$(extract_json "$COMPLETE_RESPONSE" "id")
    ORDER_PERMALINK=$(extract_json "$COMPLETE_RESPONSE" "permalink_url")
    
    if [ -n "$ORDER_ID" ]; then
        log "${GREEN}✓ Order Created: ${ORDER_ID}${NC}"
        if [ -n "$ORDER_PERMALINK" ]; then
            log "   Permalink: ${ORDER_PERMALINK}"
        fi
        
        # Check order in Shopware
        log ""
        log "   ${BLUE}→ Check order in Shopware Admin:${NC}"
        log "   ${BLUE}  http://localhost:80/admin#/sw/order/detail/${ORDER_ID}${NC}"
    fi
fi

# STEP 8: Cancel Test
log ""
log "${YELLOW}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
log "${YELLOW}Step 8: Cancel Checkout Session Test${NC}"
log "${YELLOW}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
log ""

CANCEL_SESSION_DATA='{
  "items": [
    {"id": "'"${PRODUCT_NUMBER}"'", "quantity": 1}
  ]
}'

CANCEL_SESSION_RESPONSE=$(run_test "Create Session to Cancel" "POST" "/api/checkout_sessions" "$CANCEL_SESSION_DATA" "201")
CANCEL_SESSION_ID=$(extract_json "$CANCEL_SESSION_RESPONSE" "id")

if [ -n "$CANCEL_SESSION_ID" ] && [ "$CANCEL_SESSION_ID" != "test_session_"* ]; then
    log "${GREEN}✓ Session for cancel test: ${CANCEL_SESSION_ID}${NC}"
    log ""
    
    CANCEL_RESPONSE=$(run_test "Cancel Checkout Session" "POST" "/api/checkout_sessions/${CANCEL_SESSION_ID}/cancel" "" "200")
    
    CANCEL_STATUS=$(extract_json "$CANCEL_RESPONSE" "status")
    if [ "$CANCEL_STATUS" = "canceled" ]; then
        log "${GREEN}✓ Session successfully canceled${NC}"
    fi
fi

# STEP 9: API Version Validation
log ""
log "${YELLOW}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
log "${YELLOW}Step 9: API Version Validation${NC}"
log "${YELLOW}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
log ""

log "Testing request WITHOUT API-Version header..."
INVALID_RESPONSE=$(curl -s -w "\n%{http_code}" -X POST "${BASE_URL}/api/checkout_sessions" \
    -H "Content-Type: application/json" \
    -H "Authorization: Bearer ${ACCESS_TOKEN}" \
    -d '{"items": [{"id": "test", "quantity": 1}]}' 2>&1)

INVALID_CODE=$(echo "$INVALID_RESPONSE" | tail -n1)
INVALID_BODY=$(echo "$INVALID_RESPONSE" | sed '$d')

log "  Response Code: ${INVALID_CODE}"
log "  Response Body: ${INVALID_BODY:0:200}"

if [ "$INVALID_CODE" -eq "400" ]; then
    log "  ${GREEN}✓ PASSED - Correctly rejected request without API version${NC}"
    TESTS_PASSED=$((TESTS_PASSED + 1))
else
    log "  ${RED}✗ FAILED - Should return 400${NC}"
    TESTS_FAILED=$((TESTS_FAILED + 1))
fi

TESTS_RUN=$((TESTS_RUN + 1))
log ""

# STEP 10: Check PayPal Integration (if productive mode)
if [ "$PRODUCTIVE_MODE" = true ]; then
    log "${YELLOW}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    log "${YELLOW}Step 10: PayPal Integration Check${NC}"
    log "${YELLOW}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    log ""
    
    # Check if payment method exists
    PAYPAL_METHOD=$(docker exec -u www-data shopware-acp php /var/www/html/bin/console dal:search payment_method --filter "handlerIdentifier:PayPal" 2>&1 | grep -i "swag_paypal_acdc" || echo "")
    
    if [ -n "$PAYPAL_METHOD" ]; then
        log "${GREEN}✓ PayPal ACDC payment method found${NC}"
    else
        log "${YELLOW}⚠️  PayPal payment method not found or not configured${NC}"
    fi
    
    # Check if vault token was created
    if [ "$USING_PAYPAL" = true ]; then
        log "${GREEN}✓ PayPal vault tokens are being created${NC}"
        log "   Token format: vt_paypal_xxx ✓"
    else
        log "${YELLOW}⚠️  Using demo tokens (vt_card_xxx)${NC}"
        log "   Reason: PayPal not configured or demo mode active"
    fi
    
    log ""
fi

# Summary
log "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
log "${GREEN}TEST SUMMARY${NC}"
log "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
log ""
log "Mode:         $([ "$PRODUCTIVE_MODE" = true ] && echo "🔴 PRODUCTION (PayPal)" || echo "🔵 DEMO")"
log "Total Tests:  ${TESTS_RUN}"
log "Passed:       ${GREEN}${TESTS_PASSED}${NC}"
log "Failed:       ${RED}${TESTS_FAILED}${NC}"
log ""

if [ -n "$PAYMENT_TOKEN" ]; then
    log "${BLUE}Created Resources:${NC}"
    log "  Payment Token:  ${PAYMENT_TOKEN}"
    if [ "$USING_PAYPAL" = true ]; then
        log "  Token Type:     ${MAGENTA}PayPal Vault Token ✓${NC}"
    else
        log "  Token Type:     Demo Card Token"
    fi
    if [ -n "$SESSION_ID" ] && [ "$SESSION_ID" != "test_session_"* ]; then
        log "  Checkout Session: ${SESSION_ID}"
    fi
    if [ -n "$ORDER_ID" ]; then
        log "  ${GREEN}Order ID:        ${ORDER_ID}${NC}"
        log "  ${GREEN}→ View in Admin: http://localhost:80/admin#/sw/order/detail/${ORDER_ID}${NC}"
    fi
    log ""
fi

log "${BLUE}Log file saved to: ${LOG_FILE}${NC}"
log ""

if [ $TESTS_FAILED -eq 0 ]; then
    log "${GREEN}✓✓✓ All tests passed!${NC}"
    
    if [ "$PRODUCTIVE_MODE" = true ]; then
        if [ "$USING_PAYPAL" = true ]; then
            log "${MAGENTA}🎉 PRODUCTION MODE SUCCESS! PayPal integration working!${NC}"
        else
            log "${YELLOW}⚠️  Running in production mode but using demo tokens${NC}"
            log "${YELLOW}   Configure PayPal in Shopware Admin for real payments${NC}"
        fi
    else
        log "${BLUE}Demo mode working perfectly!${NC}"
    fi
    
    exit 0
else
    log "${RED}✗✗✗ Some tests failed${NC}"
    exit 1
fi
