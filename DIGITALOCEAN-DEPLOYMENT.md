# 🌊 DigitalOcean App Platform Deployment Guide

Complete guide for deploying your JIRA Time Reporter application on DigitalOcean App Platform with managed PostgreSQL and Redis.

## 🎯 Why DigitalOcean App Platform?

**Perfect for your JIRA Reporter app because:**
- ✅ **Native Laravel support** with auto-detection
- ✅ **Managed PostgreSQL & Redis** included  
- ✅ **Auto-scaling queue workers** (solves your queue issues!)
- ✅ **Built-in SSL** and global CDN
- ✅ **Cost-effective** (~$35/month for production)
- ✅ **Easy deployment** from GitHub
- ✅ **Zero downtime deployments**

## 💰 Cost Breakdown

| Service | Plan | Monthly Cost |
|---------|------|-------------|
| Web App | Basic | $5 |
| Queue Worker | Basic | $5 |
| Scheduler | Basic | $5 |
| PostgreSQL | Basic | $15 |
| Redis | Basic | $15 |
| **Total** | | **~$45/month** |

*Note: First month often includes credits*

## 🚀 Quick Start (5 Minutes)

### Prerequisites

```bash
# 1. Install DigitalOcean CLI
brew install doctl  # macOS
# or download from: https://github.com/digitalocean/doctl/releases

# 2. Authenticate
doctl auth init
# Follow prompts to connect your DigitalOcean account
```

### Step 1: Prepare Repository

```bash
# 1. Update GitHub repository in configuration
nano .do/app.yaml
# Change 'your-username/jira-reporter' to your actual repo

# 2. Commit and push to GitHub
git add .
git commit -m "Add DigitalOcean deployment configuration"
git push origin main
```

### Step 2: Deploy

```bash
# Deploy with one command
./deploy-digitalocean.sh create
```

### Step 3: Configure Environment

```bash
# Set up JIRA credentials and other environment variables
./deploy-digitalocean.sh env
```

**That's it!** Your application will be live in ~10 minutes.

## 📋 Detailed Setup Guide

### 1. Repository Configuration

Update `.do/app.yaml` with your GitHub repository:

```yaml
github:
  repo: your-github-username/jira-reporter
  branch: main
  deploy_on_push: true
```

### 2. Environment Variables Setup

After deployment, set these **required** variables in the DigitalOcean dashboard:

**🔐 JIRA Configuration (REQUIRED):**
```bash
JIRA_HOST=https://your-company.atlassian.net
JIRA_USER=your-jira-email@company.com
JIRA_TOKEN=your_jira_api_token
```

**🔑 Laravel Configuration:**
```bash
APP_KEY=base64:your_generated_key
# Generate with: php artisan key:generate --show
```

**📧 Email Configuration (Optional):**
```bash
MAIL_HOST=smtp.mailgun.org
MAIL_USERNAME=your-mailgun-username
MAIL_PASSWORD=your-mailgun-password
```

### 3. Domain Configuration (Optional)

To use a custom domain:

1. **In DigitalOcean Dashboard:**
   - Go to your app → Settings → Domains
   - Add your custom domain
   - Configure DNS records as instructed

2. **Update app.yaml:**
   ```yaml
   domains:
     - domain: your-domain.com
       type: PRIMARY
   ```

## 🔧 Available Commands

```bash
# Create new application
./deploy-digitalocean.sh create

# Update existing application  
./deploy-digitalocean.sh update

# Check application status
./deploy-digitalocean.sh status

# View application logs
./deploy-digitalocean.sh logs

# Environment variables guide
./deploy-digitalocean.sh env

# Delete application
./deploy-digitalocean.sh delete

# Show help
./deploy-digitalocean.sh help
```

## 🏗️ Architecture Overview

Your deployed application includes:

### **Web Service**
- **Laravel application** with Nginx
- **Auto-scaling** based on traffic
- **Health checks** and monitoring
- **SSL termination** included

### **Queue Worker Service**  
- **Dedicated Horizon worker** for JIRA sync jobs
- **Auto-restart** on failures
- **Processes all queues:** `jira-sync-high`, `jira-sync-daily`, etc.
- **Solves your current queue worker issues!**

### **Scheduler Service**
- **Laravel task scheduler** for automated daily syncs
- **Runs every minute** to check for scheduled tasks
- **Handles JIRA daily sync automation**

### **Managed Databases**
- **PostgreSQL 15** with automated backups
- **Redis** for queues, cache, and sessions
- **Automatic connection configuration**

## 📊 Monitoring & Maintenance

### Application Monitoring

**Built-in Monitoring:**
- **App Platform Dashboard** shows CPU, memory, requests
- **Health checks** every 30 seconds
- **Automatic restarts** on failures
- **Email alerts** for issues

**Access Monitoring:**
```bash
# Check application status
./deploy-digitalocean.sh status

# View real-time logs
./deploy-digitalocean.sh logs

# Horizon dashboard (queue monitoring)
https://your-app-url.ondigitalocean.app/horizon
```

### Database Management

**Automatic Features:**
- **Daily backups** retained for 7 days
- **Point-in-time recovery**
- **Connection pooling**
- **SSL encryption**

**Manual Backup:**
```bash
# Connect to database
doctl databases connection jira-reporter-db

# Create manual backup
pg_dump -h hostname -U username -d database > backup.sql
```

### Queue Monitoring

**Horizon Dashboard:** `https://your-app/horizon`
- **Real-time job processing** statistics
- **Failed job management**
- **Queue worker status**
- **Performance metrics**

## 🔍 Troubleshooting

### Common Issues

**1. Deployment Fails**
```bash
# Check deployment logs
./deploy-digitalocean.sh logs

# Common causes:
# - Missing environment variables
# - GitHub authentication issues
# - Build command failures
```

**2. Database Connection Issues**
```bash
# Verify database is created and running
doctl databases list

# Check connection in logs
./deploy-digitalocean.sh logs
# Look for: "SQLSTATE[08006] connection error"
```

**3. Queue Jobs Not Processing**
```bash
# Check queue worker logs
./deploy-digitalocean.sh logs
# Select: queue-worker

# Restart queue worker
./deploy-digitalocean.sh update
```

**4. JIRA Sync Fails**
```bash
# Verify JIRA credentials in environment variables
# Check Horizon dashboard for failed jobs
# Review error messages in logs
```

### Performance Issues

**Scale Up Resources:**
```yaml
# In .do/app.yaml, increase instance size:
instance_size_slug: basic-s  # $12/month
# or
instance_size_slug: basic-m  # $24/month
```

**Scale Horizontally:**
```yaml
# Increase instance count:
instance_count: 2  # Run 2 instances for high availability
```

## 🔒 Security Best Practices

### Environment Variables
- **Never commit** sensitive values to Git
- **Use DigitalOcean dashboard** to set secrets
- **Rotate JIRA tokens** regularly

### Access Control
- **Enable 2FA** on DigitalOcean account
- **Use team accounts** for organization access
- **Monitor access logs** regularly

### SSL/HTTPS
- **Automatic SSL** certificates for .ondigitalocean.app domains
- **Let's Encrypt integration** for custom domains
- **HSTS headers** enabled by default

## 📈 Scaling Strategies

### Traffic-Based Scaling
```yaml
# Auto-scale based on traffic
autoscaling:
  min_instance_count: 1
  max_instance_count: 3
  metrics:
    cpu_percentage: 70
```

### Performance Optimization
```yaml
# Upgrade to performance instances
instance_size_slug: professional-xs  # $25/month
# Includes: 1 vCPU, 2GB RAM, better performance
```

### Database Scaling
```yaml
# Upgrade database for larger datasets
size: db-s-2vcpu-2gb  # $30/month
# or enable read replicas for reporting
```

## 💡 Pro Tips

### Development Workflow
1. **Push to GitHub** triggers automatic deployment
2. **Use feature branches** for testing
3. **Review deployment logs** before promoting
4. **Test queue processing** after each deployment

### Cost Optimization
1. **Start with smallest instances** and scale up
2. **Use development database** for testing ($15 vs $30)
3. **Monitor usage** in DigitalOcean dashboard
4. **Set billing alerts**

### Backup Strategy
1. **Database backups** are automatic (7 days retention)
2. **Code backups** via GitHub
3. **Environment variables** documented securely
4. **Test restore procedures** monthly

## 🆘 Support & Resources

### Getting Help
- **DigitalOcean Documentation:** https://docs.digitalocean.com/products/app-platform/
- **Community Forum:** https://www.digitalocean.com/community
- **Support Tickets:** Available with paid accounts

### Useful Resources
- **App Platform Pricing:** https://www.digitalocean.com/pricing/app-platform
- **Laravel on App Platform:** https://docs.digitalocean.com/tutorials/app-laravel-deploy/
- **Database Management:** https://docs.digitalocean.com/products/databases/

## 🎯 Next Steps After Deployment

1. **✅ Verify JIRA connection** works
2. **✅ Test queue processing** with a small sync
3. **✅ Set up monitoring alerts**
4. **✅ Configure custom domain** (optional)
5. **✅ Run full JIRA sync** to restore your 2,297 issues
6. **✅ Set up automated daily syncs**
7. **✅ Train team** on new deployment process

**Your JIRA Reporter will be production-ready with automatic queue processing!** 🎉