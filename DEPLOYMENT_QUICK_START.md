# Quick Deployment Guide

## 🚀 Docker Deployment (Recommended)

This application uses a **standardized Alpine-based Docker configuration** that has been tested and optimized for DigitalOcean App Platform.

### Quick Start

```bash
# 1. Build the image
docker build -t jira-reporter:latest .

# 2. Test locally
docker run --rm -p 8080:8080 \
  -e APP_KEY=base64:$(openssl rand -base64 32) \
  -e DB_CONNECTION=sqlite \
  -e DB_DATABASE=/tmp/database.sqlite \
  jira-reporter:latest

# 3. Check health
curl http://localhost:8080/health

# 4. Deploy to DigitalOcean
# Update app-spec.yaml with your registry details, then:
doctl apps create --spec app-spec.yaml
```

### 📁 Key Files

- **`Dockerfile`** - Main Alpine-based Docker configuration
- **`app-spec.yaml`** - DigitalOcean App Platform deployment specification  
- **`docker/digitalocean/`** - Production configuration files
- **`DOCKER_DEPLOYMENT.md`** - Complete deployment documentation

### 🗃️ Legacy Files

All legacy configurations have been moved to:
- `docker/legacy/` - Old Dockerfiles and configurations
- `deploy/legacy/` - Archived deployment scripts and specs

### 📖 Documentation

- **Complete Guide**: See `DOCKER_DEPLOYMENT.md`
- **Architecture**: See `CLAUDE.md` for detailed system architecture
- **Troubleshooting**: Check `docs/troubleshooting/` directory

---

✅ **Status**: Configuration tested and verified working with DigitalOcean App Platform