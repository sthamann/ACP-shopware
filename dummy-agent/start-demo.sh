#!/bin/bash

# Quick start script for ACP Dummy Agent Demo

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TESTS_DIR="$SCRIPT_DIR/../tests"

GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}🤖 ACP Dummy Agent Demo${NC}"
echo -e "${GREEN}========================================${NC}"
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

# Check if port 3000 is available
if lsof -Pi :3000 -sTCP:LISTEN -t >/dev/null 2>&1 ; then
    echo -e "${YELLOW}⚠ Port 3000 is already in use${NC}"
    echo -e "${BLUE}Killing existing process...${NC}"
    lsof -ti:3000 | xargs kill
    sleep 2
fi

echo -e "${BLUE}Starting Dummy Agent Server...${NC}"
echo ""

# Start server
npm start

