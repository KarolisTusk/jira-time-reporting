# Docker Deployment Guide

## Overview

The JIRA Reporter application uses a **single, standardized Docker configuration** based on Alpine Linux for optimal performance and reliability.

## Current Configuration

- **Main Dockerfile**: `Dockerfile` (Alpine-based, multi-stage build)
- **Docker Configuration**: `docker/digitalocean/` (nginx, supervisor, PHP)
- **App Specification**: `app-spec.yaml` (Docker-based deployment)
- **Registry**: Docker Hub or DigitalOcean Container Registry

## Quick Deployment

### 1. Build Docker Image
```bash
docker build -t jira-reporter:latest .
```

### 2. Test Locally
```bash
docker run --rm -d --name jira-test -p 8080:8080 \
  -e APP_KEY=base64:$(openssl rand -base64 32) \
  -e DB_CONNECTION=sqlite \
  -e DB_DATABASE=/tmp/database.sqlite \
  jira-reporter:latest

# Test health endpoint
curl http://localhost:8080/health

# Stop test
docker stop jira-test
```

### 3. Push to Registry

**Docker Hub:**
```bash
docker tag jira-reporter:latest YOUR_USERNAME/jira-reporter:latest
docker push YOUR_USERNAME/jira-reporter:latest
```

**DigitalOcean Container Registry:**
```bash
doctl registry login
docker tag jira-reporter:latest registry.digitalocean.com/YOUR_REGISTRY/jira-reporter:latest
docker push registry.digitalocean.com/YOUR_REGISTRY/jira-reporter:latest
```

### 4. Deploy to DigitalOcean

Update `app-spec.yaml` with your registry details:
```yaml
image:
  registry_type: DOCKER_HUB  # or DOCR
  repository: YOUR_USERNAME/jira-reporter  # Update this
  tag: latest
```

Deploy:
```bash
doctl apps create --spec app-spec.yaml
```

## Architecture

### Docker Configuration
- **Base**: `php:8.2-fpm-alpine` (lightweight, secure)
- **Frontend**: `node:20-alpine` (build stage only)
- **Web Server**: nginx (configured for DigitalOcean App Platform)
- **Process Manager**: supervisor (manages nginx + php-fpm)
- **Port**: 8080 (DigitalOcean App Platform requirement)

### Key Features
- ✅ Multi-stage build (optimized image size: ~592MB)
- ✅ Health check endpoint (`/health`)
- ✅ Laravel optimizations (config, routes, views cached)
- ✅ Production-ready PHP configuration
- ✅ Redis support via Alpine package
- ✅ PostgreSQL support for Neon database

### File Structure
```
/
├── Dockerfile                    # Main Alpine-based configuration
├── app-spec.yaml                # DigitalOcean App Platform spec
├── docker/
│   ├── digitalocean/            # Production Docker configs
│   │   ├── nginx.conf           # Nginx configuration
│   │   ├── default.conf         # Server configuration
│   │   ├── supervisord.conf     # Process management
│   │   └── php.ini              # PHP optimizations
│   └── legacy/                  # Archived configurations
└── deploy/
    └── legacy/                  # Archived deployment scripts
```

## Legacy Files

The following files have been archived and are no longer used:
- `docker/legacy/Dockerfile.ubuntu` - Ubuntu-based configuration
- `docker/legacy/Dockerfile.simple` - Minimal CLI-only configuration
- `deploy/legacy/app-spec-source.yaml` - Source-based deployment
- `deploy/legacy/nginx/`, `deploy/legacy/supervisor/` - Old configurations

## Environment Variables

Required environment variables are defined in `app-spec.yaml`:
- Database: Neon PostgreSQL configuration
- Cache: Redis Cloud configuration
- Laravel: APP_KEY, APP_ENV, APP_DEBUG
- JIRA: Application-specific settings

## Troubleshooting

### Common Issues
1. **Container won't start**: Check logs with `docker logs CONTAINER_NAME`
2. **Database connection**: Verify Neon PostgreSQL credentials in `app-spec.yaml`
3. **Health check fails**: Ensure nginx is running on port 8080
4. **Build failures**: Clear Docker cache with `docker builder prune`

### Support
- Local testing: Use SQLite database for development
- Production: Uses Neon PostgreSQL + Redis Cloud
- Monitoring: Laravel Horizon for queue monitoring

## Next Steps

1. Update `app-spec.yaml` with your registry details
2. Configure environment variables for your environment
3. Deploy to DigitalOcean App Platform
4. Monitor via DigitalOcean dashboard and Laravel Horizon

---

**Note**: This configuration has been tested and verified to work with DigitalOcean App Platform.