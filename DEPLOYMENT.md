# 🚀 JIRA Reporter Deployment Guide

This guide covers deploying the JIRA Time Reporting application using Docker in production.

## 📋 Prerequisites

- Docker and Docker Compose installed
- Domain name (optional, can use IP address)
- JIRA instance with API access
- SSL certificates (for HTTPS)

## ⚡ Quick Start

### 1. Configure Environment

```bash
# Copy the production environment template
cp .env.production .env

# Edit the configuration
nano .env
```

### 2. Required Environment Variables

Update these critical settings in `.env`:

```bash
# Application
APP_URL=https://your-domain.com
APP_KEY=  # Will be generated automatically

# Database
DB_PASSWORD=your_secure_database_password

# JIRA Integration (REQUIRED)
JIRA_HOST=https://your-company.atlassian.net
JIRA_USER=your-jira-email@company.com
JIRA_TOKEN=your_jira_api_token
```

### 3. Deploy

```bash
# Make deployment script executable
chmod +x deploy.sh

# Deploy the application
./deploy.sh deploy
```

## 🔧 Configuration Details

### JIRA API Setup

1. **Create JIRA API Token:**
   - Go to: https://id.atlassian.com/manage-profile/security/api-tokens
   - Create new token
   - Copy the token value

2. **Update .env:**
   ```bash
   JIRA_HOST=https://yourcompany.atlassian.net
   JIRA_USER=your.email@company.com
   JIRA_TOKEN=your_api_token_here
   ```

### Database Configuration

The application uses PostgreSQL with the following default settings:

```bash
DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=jira_reporter
DB_USERNAME=jira_user
DB_PASSWORD=your_secure_password  # Change this!
```

### Queue System

The application uses Redis with Laravel Horizon for queue management:

- **High Priority Queue:** `jira-sync-high` (manual syncs)
- **Daily Queue:** `jira-sync-daily` (automated syncs)
- **Worklog Queue:** `jira-worklog-sync` (incremental syncs)
- **Background Queue:** `jira-background` (reports, cleanup)

## 🐳 Docker Services

The deployment includes these services:

### Core Application
- **app:** Main Laravel application with Nginx
- **postgres:** PostgreSQL 15 database
- **redis:** Redis for caching and queues

### Queue Processing
- **queue-worker:** Dedicated Horizon worker
- **scheduler:** Laravel task scheduler for automation

### Load Balancing
- **nginx:** Reverse proxy and SSL termination

## 🔒 SSL/HTTPS Setup

### Option 1: Let's Encrypt (Recommended)

```bash
# Install certbot
sudo apt install certbot

# Generate certificates
sudo certbot certonly --standalone -d your-domain.com

# Copy certificates to docker/ssl/
sudo cp /etc/letsencrypt/live/your-domain.com/fullchain.pem docker/ssl/cert.pem
sudo cp /etc/letsencrypt/live/your-domain.com/privkey.pem docker/ssl/key.pem
```

### Option 2: Self-Signed (Development)

```bash
# Generate self-signed certificates
./deploy.sh ssl
```

## 📊 Monitoring & Maintenance

### Check Application Status

```bash
# View service status
./deploy.sh status

# View logs
./deploy.sh logs

# View Horizon dashboard
# Go to: http://your-domain.com/horizon
```

### Database Backups

```bash
# Create manual backup
./deploy.sh backup

# Automated backups (add to crontab)
0 2 * * * cd /path/to/jira-reporter && ./deploy.sh backup
```

### Application Updates

```bash
# Update to latest version
./deploy.sh update
```

## 🔧 Common Commands

```bash
# Deploy fresh installation
./deploy.sh deploy

# Check service status
./deploy.sh status

# View live logs
./deploy.sh logs

# Stop all services
./deploy.sh stop

# Update application
./deploy.sh update

# Backup database
./deploy.sh backup

# Setup SSL
./deploy.sh ssl
```

## 🎯 Performance Optimization

### For High Volume (500+ users)

1. **Scale queue workers:**
   ```bash
   # In docker-compose.yml, increase replicas
   docker-compose up --scale queue-worker=3
   ```

2. **Database optimization:**
   - Use read replicas
   - Increase PostgreSQL shared_buffers
   - Add connection pooling

3. **Redis optimization:**
   - Increase memory allocation
   - Configure persistence
   - Use Redis Cluster for high availability

### For Enterprise Scale (1000+ users)

1. **Load balancing:**
   - Multiple app instances
   - External load balancer (nginx/HAProxy)
   - CDN for static assets

2. **Database:**
   - PostgreSQL cluster
   - Connection pooling (PgBouncer)
   - Read replicas

3. **Monitoring:**
   - Prometheus + Grafana
   - Application monitoring (New Relic/DataDog)
   - Log aggregation (ELK stack)

## 🚨 Troubleshooting

### Common Issues

1. **Queue jobs stuck:**
   ```bash
   # Restart queue workers
   docker-compose restart queue-worker
   
   # Check Horizon dashboard
   http://your-domain.com/horizon
   ```

2. **JIRA connection errors:**
   ```bash
   # Test JIRA connection
   docker-compose exec app php artisan jira:test-app
   ```

3. **Database connection issues:**
   ```bash
   # Check database logs
   docker-compose logs postgres
   
   # Connect to database
   docker-compose exec postgres psql -U jira_user jira_reporter
   ```

4. **Memory issues:**
   ```bash
   # Increase memory limits in docker-compose.yml
   services:
     app:
       deploy:
         resources:
           limits:
             memory: 1G
   ```

### Log Locations

- **Application logs:** `storage/logs/laravel.log`
- **Queue logs:** `/var/log/supervisor/horizon.log`
- **Nginx logs:** `/var/log/nginx/`
- **Database logs:** Docker logs for postgres service

## 🔗 Useful URLs

After deployment, access these URLs:

- **Application:** `http://your-domain.com`
- **Horizon Dashboard:** `http://your-domain.com/horizon`
- **Health Check:** `http://your-domain.com/`

## 🛡️ Security Considerations

1. **Change default passwords**
2. **Use strong JIRA API tokens**
3. **Enable HTTPS with valid certificates**
4. **Configure firewall rules**
5. **Regular security updates**
6. **Monitor access logs**
7. **Use environment-specific secrets**

## 📞 Support

For deployment issues:

1. Check application logs: `./deploy.sh logs`
2. Verify configuration: `./deploy.sh status`
3. Review this guide's troubleshooting section
4. Check CLAUDE.md for development details

## 🔄 Automated Deployment

For CI/CD integration, the `deploy.sh` script can be used in automated pipelines:

```bash
# In your CI/CD pipeline
./deploy.sh deploy
```

The script includes error handling and will exit with appropriate codes for automation.