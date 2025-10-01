#!/bin/bash

# Start script for ACP Dummy Agent in PRODUCTION MODE (PayPal Sandbox)

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TESTS_DIR="$SCRIPT_DIR/../tests"

GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
MAGENTA='\033[0;35m'
NC='\033[0m'

echo -e "${MAGENTA}========================================${NC}"
echo -e "${MAGENTA}🤖 ACP Dummy Agent - PRODUCTION MODE${NC}"
echo -e "${MAGENTA}========================================${NC}"
echo ""

# Check if Shopware is running
if ! docker ps --format '{{.Names}}' | grep -q "^shopware-acp$"; then
    echo -e "${YELLOW}⚠ Shopware container not running${NC}"
    echo -e "${BLUE}Starting Shopware...${NC}"
    cd "$TESTS_DIR"
    ./docker-start.sh start
    cd "$SCRIPT_DIR"
    echo ""
fi

echo -e "${GREEN}✓ Shopware is running${NC}"
echo ""

# Check if dependencies are installed
if [ ! -d "node_modules" ]; then
    echo -e "${BLUE}Installing dependencies...${NC}"
    npm install
    echo ""
fi

echo -e "${GREEN}✓ Dependencies installed${NC}"
echo ""

# Set plugin to production mode
echo -e "${BLUE}Setting ACP plugin to production mode...${NC}"
docker exec shopware-acp mysql -u root -proot -h 127.0.0.1 shopware -e "UPDATE system_config SET configuration_value = '{\"_value\": false}' WHERE configuration_key = 'AcpShopwarePlugin.config.demoMode';" 2>/dev/null || true
docker exec -u www-data shopware-acp php /var/www/html/bin/console cache:clear 2>&1 | grep -q "OK" && echo -e "${GREEN}✓ Cache cleared${NC}"
echo ""

# Check if port 3000 is available
if lsof -Pi :3000 -sTCP:LISTEN -t >/dev/null 2>&1 ; then
    echo -e "${YELLOW}⚠ Port 3000 is already in use${NC}"
    echo -e "${BLUE}Killing existing process...${NC}"
    lsof -ti:3000 | xargs kill
    sleep 2
fi

echo -e "${MAGENTA}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${MAGENTA}🔴 PRODUCTION MODE - Using PayPal${NC}"
echo -e "${MAGENTA}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""
echo -e "${BLUE}Starting Dummy Agent Server...${NC}"
echo -e "${BLUE}Payment tokens will use format: vt_paypal_*${NC}"
echo ""

# Start server with PRODUCTIVE_MODE=true
PRODUCTIVE_MODE=true npm start

