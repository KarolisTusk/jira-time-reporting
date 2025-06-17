#!/bin/bash

# JIRA Reporter Deployment Script
# This script deploys the application using Docker Compose

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuration
PROJECT_NAME="jira-reporter"
BACKUP_DIR="./backups"
DEPLOY_ENV="production"

echo -e "${BLUE}🚀 Starting JIRA Reporter Deployment${NC}"

# Check if .env exists
if [ ! -f ".env" ]; then
    echo -e "${YELLOW}⚠️  .env file not found. Creating from .env.production template...${NC}"
    cp .env.production .env
    echo -e "${RED}❌ Please edit .env file with your configuration and run this script again.${NC}"
    exit 1
fi

# Check if APP_KEY is set
if grep -q "APP_KEY=$" .env; then
    echo -e "${YELLOW}⚠️  Generating application key...${NC}"
    docker run --rm -v "$(pwd)":/app -w /app php:8.2-cli php artisan key:generate --no-interaction
fi

# Create backup directory
mkdir -p $BACKUP_DIR

# Function to backup database
backup_database() {
    if [ "$(docker ps -q -f name=${PROJECT_NAME}-db)" ]; then
        echo -e "${BLUE}📦 Creating database backup...${NC}"
        BACKUP_FILE="$BACKUP_DIR/db_backup_$(date +%Y%m%d_%H%M%S).sql"
        docker exec ${PROJECT_NAME}-db pg_dump -U $(grep DB_USERNAME .env | cut -d '=' -f2) $(grep DB_DATABASE .env | cut -d '=' -f2) > "$BACKUP_FILE"
        echo -e "${GREEN}✅ Database backup created: $BACKUP_FILE${NC}"
    fi
}

# Function to build and start services
deploy() {
    echo -e "${BLUE}🔨 Building application...${NC}"
    
    # Build frontend assets
    echo -e "${BLUE}📦 Building frontend assets...${NC}"
    npm ci
    npm run build
    
    # Build Docker images
    echo -e "${BLUE}🐳 Building Docker images...${NC}"
    docker-compose build --no-cache
    
    # Start services
    echo -e "${BLUE}🚀 Starting services...${NC}"
    docker-compose up -d
    
    # Wait for database to be ready
    echo -e "${BLUE}⏳ Waiting for database to be ready...${NC}"
    sleep 10
    
    # Run migrations
    echo -e "${BLUE}🗄️  Running database migrations...${NC}"
    docker-compose exec app php artisan migrate --force
    
    # Clear and cache configuration
    echo -e "${BLUE}⚡ Optimizing application...${NC}"
    docker-compose exec app php artisan config:cache
    docker-compose exec app php artisan route:cache
    docker-compose exec app php artisan view:cache
    
    # Start Horizon
    echo -e "${BLUE}🔄 Starting queue workers...${NC}"
    docker-compose exec -d queue-worker php artisan horizon
    
    echo -e "${GREEN}✅ Deployment completed successfully!${NC}"
}

# Function to show status
show_status() {
    echo -e "${BLUE}📊 Service Status:${NC}"
    docker-compose ps
    
    echo -e "\n${BLUE}🔗 Application URLs:${NC}"
    echo -e "Application: ${GREEN}http://localhost:$(grep APP_PORT .env | cut -d '=' -f2 | sed 's/[^0-9]*//g' || echo '8000')${NC}"
    echo -e "Horizon (Queue Dashboard): ${GREEN}http://localhost:$(grep APP_PORT .env | cut -d '=' -f2 | sed 's/[^0-9]*//g' || echo '8000')/horizon${NC}"
}

# Function to show logs
show_logs() {
    echo -e "${BLUE}📝 Application Logs:${NC}"
    docker-compose logs -f app
}

# Function to stop services
stop_services() {
    echo -e "${YELLOW}🛑 Stopping services...${NC}"
    docker-compose down
    echo -e "${GREEN}✅ Services stopped.${NC}"
}

# Function to update application
update() {
    echo -e "${BLUE}🔄 Updating application...${NC}"
    
    # Backup database before update
    backup_database
    
    # Pull latest changes (if using git)
    if [ -d ".git" ]; then
        echo -e "${BLUE}📥 Pulling latest changes...${NC}"
        git pull
    fi
    
    # Rebuild and deploy
    deploy
}

# Function to setup SSL
setup_ssl() {
    echo -e "${BLUE}🔒 Setting up SSL certificates...${NC}"
    
    # Create SSL directory
    mkdir -p docker/ssl
    
    # Generate self-signed certificate (replace with real certificates)
    if [ ! -f "docker/ssl/cert.pem" ]; then
        openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
            -keyout docker/ssl/key.pem \
            -out docker/ssl/cert.pem \
            -subj "/C=US/ST=State/L=City/O=Organization/CN=localhost"
        echo -e "${GREEN}✅ Self-signed SSL certificate generated.${NC}"
        echo -e "${YELLOW}⚠️  For production, replace with real SSL certificates!${NC}"
    fi
}

# Main script logic
case "${1:-deploy}" in
    "deploy")
        deploy
        show_status
        ;;
    "update")
        update
        show_status
        ;;
    "status")
        show_status
        ;;
    "logs")
        show_logs
        ;;
    "stop")
        stop_services
        ;;
    "backup")
        backup_database
        ;;
    "ssl")
        setup_ssl
        ;;
    "help")
        echo -e "${BLUE}JIRA Reporter Deployment Script${NC}"
        echo ""
        echo "Usage: $0 [command]"
        echo ""
        echo "Commands:"
        echo "  deploy    - Deploy the application (default)"
        echo "  update    - Update and redeploy the application"
        echo "  status    - Show service status"
        echo "  logs      - Show application logs"
        echo "  stop      - Stop all services"
        echo "  backup    - Backup database"
        echo "  ssl       - Setup SSL certificates"
        echo "  help      - Show this help message"
        ;;
    *)
        echo -e "${RED}❌ Unknown command: $1${NC}"
        echo "Run '$0 help' for usage information."
        exit 1
        ;;
esac