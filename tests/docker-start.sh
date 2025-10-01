#!/bin/bash

# Docker Container Start/Restart Script for Shopware ACP Testing
# Usage: ./docker-start.sh [start|stop|restart|status]

CONTAINER_NAME="shopware-acp"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}Shopware ACP Docker Management${NC}"
echo -e "${GREEN}========================================${NC}"
echo ""

# Function to check if container exists
container_exists() {
    docker ps -a --format '{{.Names}}' | grep -q "^${CONTAINER_NAME}$"
}

# Function to check if container is running
container_running() {
    docker ps --format '{{.Names}}' | grep -q "^${CONTAINER_NAME}$"
}

# Function to start container
start_container() {
    echo -e "${YELLOW}Starting container '${CONTAINER_NAME}'...${NC}"
    
    if container_running; then
        echo -e "${GREEN}✓ Container is already running${NC}"
        show_status
        return 0
    fi
    
    if container_exists; then
        echo -e "${YELLOW}Starting existing container...${NC}"
        docker start "${CONTAINER_NAME}"
    else
        echo -e "${RED}✗ Container '${CONTAINER_NAME}' does not exist${NC}"
        echo -e "${YELLOW}Please create the container first with:${NC}"
        echo -e "  docker run -d --name ${CONTAINER_NAME} -p 80:80 -v \$(pwd):/app shopware/docker-base"
        return 1
    fi
    
    # Wait for container to be ready
    echo -e "${YELLOW}Waiting for Shopware to be ready...${NC}"
    sleep 5
    
    if container_running; then
        echo -e "${GREEN}✓ Container started successfully${NC}"
        show_status
        return 0
    else
        echo -e "${RED}✗ Failed to start container${NC}"
        return 1
    fi
}

# Function to stop container
stop_container() {
    echo -e "${YELLOW}Stopping container '${CONTAINER_NAME}'...${NC}"
    
    if ! container_running; then
        echo -e "${YELLOW}Container is not running${NC}"
        return 0
    fi
    
    docker stop "${CONTAINER_NAME}"
    
    if ! container_running; then
        echo -e "${GREEN}✓ Container stopped successfully${NC}"
        return 0
    else
        echo -e "${RED}✗ Failed to stop container${NC}"
        return 1
    fi
}

# Function to restart container
restart_container() {
    echo -e "${YELLOW}Restarting container '${CONTAINER_NAME}'...${NC}"
    
    if container_exists; then
        docker restart "${CONTAINER_NAME}"
        sleep 5
        
        if container_running; then
            echo -e "${GREEN}✓ Container restarted successfully${NC}"
            show_status
            return 0
        else
            echo -e "${RED}✗ Failed to restart container${NC}"
            return 1
        fi
    else
        echo -e "${RED}✗ Container '${CONTAINER_NAME}' does not exist${NC}"
        return 1
    fi
}

# Function to show container status
show_status() {
    echo ""
    echo -e "${GREEN}Container Status:${NC}"
    echo "=================="
    
    if container_exists; then
        if container_running; then
            echo -e "Status: ${GREEN}Running${NC}"
            echo ""
            echo "Container Info:"
            docker ps --filter "name=${CONTAINER_NAME}" --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}"
            echo ""
            echo -e "${GREEN}Access Shopware:${NC}"
            echo "  Frontend: http://localhost:80"
            echo "  Admin:    http://localhost:80/admin"
            echo ""
            echo -e "${GREEN}Container Logs:${NC}"
            echo "  docker logs ${CONTAINER_NAME}"
            echo "  docker logs -f ${CONTAINER_NAME}  (follow)"
        else
            echo -e "Status: ${YELLOW}Stopped${NC}"
        fi
    else
        echo -e "Status: ${RED}Does not exist${NC}"
    fi
    
    echo ""
}

# Function to show logs
show_logs() {
    if container_running; then
        echo -e "${YELLOW}Showing logs for '${CONTAINER_NAME}'...${NC}"
        echo -e "${YELLOW}Press Ctrl+C to exit${NC}"
        echo ""
        docker logs -f "${CONTAINER_NAME}"
    else
        echo -e "${RED}✗ Container is not running${NC}"
        return 1
    fi
}

# Function to access container shell
access_shell() {
    if container_running; then
        echo -e "${GREEN}Accessing container shell...${NC}"
        echo -e "${YELLOW}Type 'exit' to leave the container${NC}"
        echo ""
        docker exec -it "${CONTAINER_NAME}" /bin/bash
    else
        echo -e "${RED}✗ Container is not running${NC}"
        return 1
    fi
}

# Main script logic
case "${1:-status}" in
    start)
        start_container
        ;;
    stop)
        stop_container
        ;;
    restart)
        restart_container
        ;;
    status)
        show_status
        ;;
    logs)
        show_logs
        ;;
    shell|bash)
        access_shell
        ;;
    *)
        echo "Usage: $0 {start|stop|restart|status|logs|shell}"
        echo ""
        echo "Commands:"
        echo "  start   - Start the Docker container"
        echo "  stop    - Stop the Docker container"
        echo "  restart - Restart the Docker container"
        echo "  status  - Show container status (default)"
        echo "  logs    - Show and follow container logs"
        echo "  shell   - Access container bash shell"
        exit 1
        ;;
esac

exit $?

