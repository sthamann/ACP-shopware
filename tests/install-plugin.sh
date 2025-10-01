#!/bin/bash

# Plugin Installation and Verification Script
# Installs the ACP plugin into the Shopware Docker container

set -e  # Exit on error

CONTAINER_NAME="shopware-acp"
PLUGIN_NAME="AcpShopwarePlugin"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
PLUGIN_DIR="$PROJECT_ROOT/shopware-acp-plugin"

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}ACP Plugin Installation Script${NC}"
echo -e "${GREEN}========================================${NC}"
echo ""

# Check if container is running
if ! docker ps --format '{{.Names}}' | grep -q "^${CONTAINER_NAME}$"; then
    echo -e "${RED}✗ Container '${CONTAINER_NAME}' is not running${NC}"
    echo -e "${YELLOW}Start it with: ./docker-start.sh start${NC}"
    exit 1
fi

echo -e "${GREEN}✓ Container is running${NC}"
echo ""

# Step 1: Copy plugin to container
echo -e "${BLUE}[1/8] Copying plugin files to container...${NC}"
docker exec "${CONTAINER_NAME}" mkdir -p /var/www/html/custom/plugins/${PLUGIN_NAME}

docker cp "${PLUGIN_DIR}/src" "${CONTAINER_NAME}:/var/www/html/custom/plugins/${PLUGIN_NAME}/"
docker cp "${PLUGIN_DIR}/composer.json" "${CONTAINER_NAME}:/var/www/html/custom/plugins/${PLUGIN_NAME}/"

echo -e "${GREEN}✓ Plugin files copied${NC}"
echo ""

# Step 2: Set correct permissions
echo -e "${BLUE}[2/8] Setting permissions...${NC}"
docker exec -u root "${CONTAINER_NAME}" chown -R www-data:www-data /var/www/html/custom/plugins/${PLUGIN_NAME}
docker exec -u root "${CONTAINER_NAME}" chmod -R 755 /var/www/html/custom/plugins/${PLUGIN_NAME}

echo -e "${GREEN}✓ Permissions set${NC}"
echo ""

# Step 3: Check if SwagPayPal is installed
echo -e "${BLUE}[3/8] Checking SwagPayPal installation...${NC}"
if docker exec "${CONTAINER_NAME}" test -d /var/www/html/custom/plugins/SwagPayPal; then
    echo -e "${GREEN}✓ SwagPayPal is installed${NC}"
else
    echo -e "${YELLOW}⚠ SwagPayPal not found - installing...${NC}"
    docker exec -u www-data "${CONTAINER_NAME}" composer require swag/paypal --working-dir=/var/www/html || true
fi
echo ""

# Step 4: Refresh plugin list
echo -e "${BLUE}[4/8] Refreshing plugin list...${NC}"
docker exec -u www-data "${CONTAINER_NAME}" php /var/www/html/bin/console plugin:refresh

echo -e "${GREEN}✓ Plugin list refreshed${NC}"
echo ""

# Step 5: Install plugin
echo -e "${BLUE}[5/8] Installing plugin...${NC}"
docker exec -u www-data "${CONTAINER_NAME}" php /var/www/html/bin/console plugin:install ${PLUGIN_NAME} --activate || {
    echo -e "${YELLOW}⚠ Plugin might already be installed, trying to reinstall...${NC}"
    docker exec -u www-data "${CONTAINER_NAME}" php /var/www/html/bin/console plugin:uninstall ${PLUGIN_NAME} || true
    docker exec -u www-data "${CONTAINER_NAME}" php /var/www/html/bin/console plugin:install ${PLUGIN_NAME} --activate
}

echo -e "${GREEN}✓ Plugin installed and activated${NC}"
echo ""

# Step 6: Run migrations
echo -e "${BLUE}[6/8] Running database migrations...${NC}"
docker exec -u www-data "${CONTAINER_NAME}" php /var/www/html/bin/console database:migrate --all ${PLUGIN_NAME}

echo -e "${GREEN}✓ Migrations executed${NC}"
echo ""

# Step 7: Clear cache
echo -e "${BLUE}[7/8] Clearing cache...${NC}"
docker exec -u www-data "${CONTAINER_NAME}" php /var/www/html/bin/console cache:clear

echo -e "${GREEN}✓ Cache cleared${NC}"
echo ""

# Step 8: Verify installation
echo -e "${BLUE}[8/8] Verifying installation...${NC}"
echo ""

# Check if plugin is installed
PLUGIN_STATUS=$(docker exec -u www-data "${CONTAINER_NAME}" php /var/www/html/bin/console plugin:list | grep ${PLUGIN_NAME} || echo "not found")

if echo "$PLUGIN_STATUS" | grep -q "Yes"; then
    echo -e "${GREEN}✓ Plugin is installed and active${NC}"
    echo ""
    echo "Plugin Status:"
    echo "$PLUGIN_STATUS"
else
    echo -e "${RED}✗ Plugin installation verification failed${NC}"
    echo "$PLUGIN_STATUS"
    exit 1
fi

echo ""

# Check database tables
echo "Checking database tables..."
TABLES=$(docker exec "${CONTAINER_NAME}" mysql -u root -proot shopware -e "SHOW TABLES LIKE 'acp_%';" 2>/dev/null || echo "")

if [ -n "$TABLES" ]; then
    echo -e "${GREEN}✓ ACP database tables created:${NC}"
    echo "$TABLES"
else
    echo -e "${YELLOW}⚠ No ACP tables found (might be expected on first run)${NC}"
fi

echo ""
echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}Installation Complete!${NC}"
echo -e "${GREEN}========================================${NC}"
echo ""
echo -e "${BLUE}Next steps:${NC}"
echo "1. Access Shopware Admin: http://localhost:80/admin"
echo "2. Configure PayPal credentials (if needed)"
echo "3. Test the API endpoints:"
echo ""
echo "   Create checkout session:"
echo "   curl -X POST http://localhost:80/api/checkout_sessions \\"
echo "     -H 'Content-Type: application/json' \\"
echo "     -H 'API-Version: 2025-09-29' \\"
echo "     -d '{\"items\": [{\"id\": \"PRODUCT-ID\", \"quantity\": 1}]}'"
echo ""
echo "Run tests with: ./run-tests.sh"
echo ""

