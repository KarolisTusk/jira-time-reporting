#!/bin/bash

# DigitalOcean App Platform Deployment Script for JIRA Reporter
# This script helps deploy and manage the application on DigitalOcean

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
PURPLE='\033[0;35m'
NC='\033[0m' # No Color

# Configuration
PROJECT_NAME="jira-reporter"
DO_APP_SPEC=".do/app.yaml"

echo -e "${BLUE}🚀 DigitalOcean App Platform Deployment for JIRA Reporter${NC}"

# Check if doctl is installed
check_doctl() {
    if ! command -v doctl &> /dev/null; then
        echo -e "${RED}❌ doctl CLI not found. Please install it first:${NC}"
        echo -e "${YELLOW}   • macOS: brew install doctl${NC}"
        echo -e "${YELLOW}   • Linux: Download from https://github.com/digitalocean/doctl/releases${NC}"
        echo -e "${YELLOW}   • Then run: doctl auth init${NC}"
        exit 1
    fi
}

# Check if user is authenticated
check_auth() {
    if ! doctl account get >/dev/null 2>&1; then
        echo -e "${RED}❌ Not authenticated with DigitalOcean. Run: doctl auth init${NC}"
        exit 1
    fi
}

# Validate environment configuration
validate_config() {
    echo -e "${BLUE}🔍 Validating configuration...${NC}"
    
    if [ ! -f "$DO_APP_SPEC" ]; then
        echo -e "${RED}❌ DigitalOcean app spec not found: $DO_APP_SPEC${NC}"
        exit 1
    fi
    
    # Check if GitHub repo is configured
    if grep -q "your-username/jira-reporter" "$DO_APP_SPEC"; then
        echo -e "${YELLOW}⚠️  Please update the GitHub repository in $DO_APP_SPEC${NC}"
        echo -e "${YELLOW}   Change 'your-username/jira-reporter' to your actual repository${NC}"
        exit 1
    fi
    
    echo -e "${GREEN}✅ Configuration validation passed${NC}"
}

# Build frontend assets locally
build_assets() {
    echo -e "${BLUE}📦 Building frontend assets...${NC}"
    
    if [ ! -f "package.json" ]; then
        echo -e "${RED}❌ package.json not found${NC}"
        exit 1
    fi
    
    # Clean install and build
    npm ci
    npm run build
    
    echo -e "${GREEN}✅ Frontend assets built successfully${NC}"
}

# Create the app on DigitalOcean
create_app() {
    echo -e "${BLUE}🏗️  Creating application on DigitalOcean...${NC}"
    
    # Create the app using the spec file
    doctl apps create --spec "$DO_APP_SPEC" --format ID --no-header > .do-app-id
    
    if [ -f ".do-app-id" ]; then
        APP_ID=$(cat .do-app-id)
        echo -e "${GREEN}✅ Application created successfully!${NC}"
        echo -e "${BLUE}📊 App ID: $APP_ID${NC}"
        echo "$APP_ID" > .do-app-id
    else
        echo -e "${RED}❌ Failed to create application${NC}"
        exit 1
    fi
}

# Update existing app
update_app() {
    if [ ! -f ".do-app-id" ]; then
        echo -e "${RED}❌ No existing app found. Run 'create' first.${NC}"
        exit 1
    fi
    
    APP_ID=$(cat .do-app-id)
    echo -e "${BLUE}🔄 Updating application $APP_ID...${NC}"
    
    doctl apps update "$APP_ID" --spec "$DO_APP_SPEC"
    echo -e "${GREEN}✅ Application updated successfully!${NC}"
}

# Get application status and URLs
get_status() {
    if [ ! -f ".do-app-id" ]; then
        echo -e "${RED}❌ No app found. Run 'create' first.${NC}"
        exit 1
    fi
    
    APP_ID=$(cat .do-app-id)
    echo -e "${BLUE}📊 Application Status:${NC}"
    
    # Get app info
    doctl apps get "$APP_ID"
    
    echo -e "\n${BLUE}🔗 Application URLs:${NC}"
    doctl apps get "$APP_ID" --format LiveURL --no-header
    
    echo -e "\n${BLUE}⚙️  Services Status:${NC}"
    doctl apps get "$APP_ID" --format Spec.Services
}

# View application logs
view_logs() {
    if [ ! -f ".do-app-id" ]; then
        echo -e "${RED}❌ No app found. Run 'create' first.${NC}"
        exit 1
    fi
    
    APP_ID=$(cat .do-app-id)
    
    echo -e "${BLUE}📝 Choose service to view logs:${NC}"
    echo "1. web (Main application)"
    echo "2. queue-worker (Background jobs)"
    echo "3. scheduler (Task scheduler)"
    
    read -p "Enter choice [1-3]: " choice
    
    case $choice in
        1) SERVICE="web" ;;
        2) SERVICE="queue-worker" ;;
        3) SERVICE="scheduler" ;;
        *) echo -e "${RED}❌ Invalid choice${NC}"; exit 1 ;;
    esac
    
    echo -e "${BLUE}📝 Viewing logs for $SERVICE service...${NC}"
    doctl apps logs "$APP_ID" --service "$SERVICE" --follow
}

# Set environment variables
set_env_vars() {
    if [ ! -f ".do-app-id" ]; then
        echo -e "${RED}❌ No app found. Run 'create' first.${NC}"
        exit 1
    fi
    
    APP_ID=$(cat .do-app-id)
    
    echo -e "${BLUE}⚙️  Setting up environment variables...${NC}"
    echo -e "${YELLOW}📝 Please set these environment variables in DigitalOcean Dashboard:${NC}"
    echo ""
    echo "1. Go to: https://cloud.digitalocean.com/apps/$APP_ID/settings"
    echo "2. Click on 'Environment Variables'"
    echo "3. Add these required variables:"
    echo ""
    echo -e "${PURPLE}JIRA_HOST${NC}=https://your-company.atlassian.net"
    echo -e "${PURPLE}JIRA_USER${NC}=your-jira-email@company.com"
    echo -e "${PURPLE}JIRA_TOKEN${NC}=your_jira_api_token"
    echo -e "${PURPLE}APP_KEY${NC}=base64:your_generated_app_key"
    echo ""
    echo -e "${YELLOW}💡 To generate APP_KEY, run: php artisan key:generate --show${NC}"
    echo ""
    echo -e "${BLUE}4. Click 'Save' and redeploy the app${NC}"
}

# Delete the application
delete_app() {
    if [ ! -f ".do-app-id" ]; then
        echo -e "${RED}❌ No app found.${NC}"
        exit 1
    fi
    
    APP_ID=$(cat .do-app-id)
    
    echo -e "${RED}⚠️  This will permanently delete your application and all data!${NC}"
    read -p "Are you sure you want to delete app $APP_ID? (yes/no): " confirm
    
    if [ "$confirm" = "yes" ]; then
        echo -e "${BLUE}🗑️  Deleting application...${NC}"
        doctl apps delete "$APP_ID" --force
        rm -f .do-app-id
        echo -e "${GREEN}✅ Application deleted successfully${NC}"
    else
        echo -e "${YELLOW}🚫 Deletion cancelled${NC}"
    fi
}

# Show help
show_help() {
    echo -e "${BLUE}DigitalOcean App Platform Deployment Script${NC}"
    echo ""
    echo "Usage: $0 [command]"
    echo ""
    echo -e "${PURPLE}Commands:${NC}"
    echo "  create      - Create new application on DigitalOcean"
    echo "  update      - Update existing application"
    echo "  status      - Show application status and URLs"
    echo "  logs        - View application logs"
    echo "  env         - Show environment variables setup guide"
    echo "  delete      - Delete the application"
    echo "  help        - Show this help message"
    echo ""
    echo -e "${PURPLE}Setup Steps:${NC}"
    echo "1. Install doctl: brew install doctl"
    echo "2. Authenticate: doctl auth init"
    echo "3. Update GitHub repo in .do/app.yaml"
    echo "4. Run: $0 create"
    echo "5. Set environment variables: $0 env"
    echo "6. Monitor deployment: $0 status"
    echo ""
    echo -e "${PURPLE}Estimated Cost:${NC}"
    echo "• Basic App: \$5/month"
    echo "• Database: \$15/month"
    echo "• Redis: \$15/month"
    echo "• Total: ~\$35/month"
}

# Pre-flight checks
preflight_checks() {
    check_doctl
    check_auth
    validate_config
}

# Main script logic
case "${1:-help}" in
    "create")
        preflight_checks
        build_assets
        create_app
        echo ""
        echo -e "${GREEN}🎉 Deployment initiated successfully!${NC}"
        echo -e "${YELLOW}📝 Next steps:${NC}"
        echo "1. Run: $0 env (to set up environment variables)"
        echo "2. Run: $0 status (to check deployment progress)"
        echo "3. Wait for deployment to complete (~5-10 minutes)"
        ;;
    "update")
        preflight_checks
        build_assets
        update_app
        ;;
    "status")
        check_doctl
        check_auth
        get_status
        ;;
    "logs")
        check_doctl
        check_auth
        view_logs
        ;;
    "env")
        set_env_vars
        ;;
    "delete")
        check_doctl
        check_auth
        delete_app
        ;;
    "help")
        show_help
        ;;
    *)
        echo -e "${RED}❌ Unknown command: $1${NC}"
        echo "Run '$0 help' for usage information."
        exit 1
        ;;
esac