# Monitoring Guide

This guide explains how to monitor your LDAP User Manager system for health, performance, and security.

## Overview

Effective monitoring helps you:
- **Detect issues** before they become problems
- **Track system performance** and usage
- **Monitor security** and access patterns
- **Plan capacity** based on usage trends
- **Ensure availability** and uptime

## Monitoring Components

### System Health Monitoring
- **Docker container status**
- **Service availability**
- **Resource usage** (CPU, memory, disk)
- **Network connectivity**

### Application Monitoring
- **Web interface availability**
- **LDAP server health**
- **OIDC provider status**
- **User authentication success/failure**

### Security Monitoring
- **Failed login attempts**
- **Unauthorized access attempts**
- **Suspicious activity patterns**
- **Configuration changes**
- **Rate limiting events**
- **Session security violations**
- **File upload security events**

## Basic Monitoring Setup

### Health Check Script
```bash
#!/bin/bash
# health-check.sh

# Check Docker services
echo "=== Docker Services ==="
docker-compose ps

# Check service health
echo "=== Service Health ==="

# Web interface
if curl -f -s http://localhost:8080 > /dev/null; then
    echo "✅ Web interface: OK"
else
    echo "❌ Web interface: FAILED"
fi

# LDAP server
if ldapsearch -H ldap://localhost:389 -D "cn=admin,dc=example,dc=com" -w admin -b "dc=example,dc=com" -s base > /dev/null 2>&1; then
    echo "✅ LDAP server: OK"
else
    echo "❌ LDAP server: FAILED"
fi

# OIDC provider (if enabled)
if curl -f -s -k https://id.example.org/.well-known/openid_configuration > /dev/null; then
    echo "✅ OIDC provider: OK"
else
    echo "⚠️  OIDC provider: Not accessible"
fi

# Security features
echo "=== Security Features ==="

# Rate limiting (check Caddy logs)
if docker-compose logs caddy | grep -q "rate limit"; then
    echo "✅ Rate limiting: Active"
else
    echo "⚠️  Rate limiting: No recent events"
fi

# Security headers
if curl -I http://localhost:8080 | grep -q "X-Frame-Options"; then
    echo "✅ Security headers: Present"
else
    echo "❌ Security headers: Missing"
fi
```

### Resource Monitoring
```bash
#!/bin/bash
# resource-monitor.sh

echo "=== Resource Usage ==="

# Docker stats
echo "Docker Container Stats:"
docker stats --no-stream --format "table {{.Container}}\t{{.CPUPerc}}\t{{.MemUsage}}\t{{.NetIO}}"

# Disk usage
echo "Disk Usage:"
df -h | grep -E "(Filesystem|/dev/)"

# Memory usage
echo "Memory Usage:"
free -h

# Load average
echo "Load Average:"
uptime
```

## Advanced Monitoring

### Security Event Monitoring
```bash
#!/bin/bash
# security-monitor.sh

echo "=== Security Events ==="

# Check for failed login attempts
echo "Failed Login Attempts (last hour):"
docker-compose logs --since=1h ldap-user-manager | grep -i "login.*fail\|authentication.*fail" | wc -l

# Check for rate limiting events
echo "Rate Limiting Events (last hour):"
docker-compose logs --since=1h caddy | grep -i "rate limit" | wc -l

# Check for security header violations
echo "Security Header Violations (last hour):"
docker-compose logs --since=1h ldap-user-manager | grep -i "security.*header\|csp.*violation" | wc -l

# Check for file upload security events
echo "File Upload Security Events (last hour):"
docker-compose logs --since=1h ldap-user-manager | grep -i "file.*upload\|upload.*security" | wc -l

# Check for session security events
echo "Session Security Events (last hour):"
docker-compose logs --since=1h ldap-user-manager | grep -i "session.*timeout\|session.*expired" | wc -l
```

### Log Monitoring
```bash
#!/bin/bash
# log-monitor.sh

LOG_DIR="/var/log/ldap-user-manager"
mkdir -p $LOG_DIR

# Monitor Docker logs
docker-compose logs --tail=100 > $LOG_DIR/docker.log

# Monitor LDAP access logs
docker-compose exec ldap tail -100 /var/log/ldap/access.log > $LOG_DIR/ldap-access.log

# Monitor web access logs
docker-compose exec ldap-user-manager tail -100 /var/log/apache2/access.log > $LOG_DIR/web-access.log

# Check for errors
echo "=== Recent Errors ==="
docker-compose logs --tail=50 | grep -i error
```

### Performance Monitoring
```bash
#!/bin/bash
# performance-monitor.sh

echo "=== Performance Metrics ==="

# Response time check
START_TIME=$(date +%s.%N)
curl -s http://localhost:8080 > /dev/null
END_TIME=$(date +%s.%N)
RESPONSE_TIME=$(echo "$END_TIME - $START_TIME" | bc)
echo "Web response time: ${RESPONSE_TIME}s"

# LDAP query performance
START_TIME=$(date +%s.%N)
ldapsearch -H ldap://localhost:389 -D "cn=admin,dc=example,dc=com" -w admin -b "dc=example,dc=com" -s base > /dev/null
END_TIME=$(date +%s.%N)
RESPONSE_TIME=$(echo "$END_TIME - $START_TIME" | bc)
echo "LDAP response time: ${RESPONSE_TIME}s"
```

## Alerting Setup

### Simple Alert Script
```bash
#!/bin/bash
# alert-check.sh

ALERT_EMAIL="admin@example.com"
ALERT_LOG="/var/log/ldap-user-manager/alerts.log"

# Check web interface
if ! curl -f -s http://localhost:8080 > /dev/null; then
    echo "$(date): Web interface down!" >> $ALERT_LOG
    echo "Web interface is down at $(date)" | mail -s "LDAP User Manager Alert" $ALERT_EMAIL
fi

# Check LDAP server
if ! ldapsearch -H ldap://localhost:389 -D "cn=admin,dc=example,dc=com" -w admin -b "dc=example,dc=com" -s base > /dev/null 2>&1; then
    echo "$(date): LDAP server down!" >> $ALERT_LOG
    echo "LDAP server is down at $(date)" | mail -s "LDAP User Manager Alert" $ALERT_EMAIL
fi

# Check disk space
DISK_USAGE=$(df / | awk 'NR==2 {print $5}' | sed 's/%//')
if [ $DISK_USAGE -gt 80 ]; then
    echo "$(date): Disk usage high: ${DISK_USAGE}%" >> $ALERT_LOG
    echo "Disk usage is ${DISK_USAGE}% at $(date)" | mail -s "LDAP User Manager Alert" $ALERT_EMAIL
fi
```

### Cron Job Setup
```bash
# Edit crontab
crontab -e

# Add monitoring checks every 5 minutes
*/5 * * * * /path/to/health-check.sh >> /var/log/ldap-user-manager/health.log 2>&1

# Add resource monitoring every hour
0 * * * * /path/to/resource-monitor.sh >> /var/log/ldap-user-manager/resources.log 2>&1

# Add alerting every 10 minutes
*/10 * * * * /path/to/alert-check.sh
```

## Monitoring Tools

### Prometheus Integration
```yaml
# docker-compose.monitoring.yml
version: '3.8'
services:
  prometheus:
    image: prom/prometheus:latest
    ports:
      - "9090:9090"
    volumes:
      - ./prometheus.yml:/etc/prometheus/prometheus.yml
      - prometheus_data:/prometheus
    command:
      - '--config.file=/etc/prometheus/prometheus.yml'
      - '--storage.tsdb.path=/prometheus'
      - '--web.console.libraries=/etc/prometheus/console_libraries'
      - '--web.console.templates=/etc/prometheus/consoles'
      - '--storage.tsdb.retention.time=200h'
      - '--web.enable-lifecycle'

  grafana:
    image: grafana/grafana:latest
    ports:
      - "3000:3000"
    volumes:
      - grafana_data:/var/lib/grafana
    environment:
      - GF_SECURITY_ADMIN_PASSWORD=admin

volumes:
  prometheus_data:
  grafana_data:
```

### Prometheus Configuration
```yaml
# prometheus.yml
global:
  scrape_interval: 15s

scrape_configs:
  - job_name: 'ldap-user-manager'
    static_configs:
      - targets: ['localhost:8080']
    metrics_path: '/metrics'
    scrape_interval: 30s

  - job_name: 'ldap-server'
    static_configs:
      - targets: ['localhost:389']
    scrape_interval: 30s
```

## Security Monitoring

### Failed Login Monitoring
```bash
#!/bin/bash
# security-monitor.sh

LOG_FILE="/var/log/ldap-user-manager/security.log"

# Monitor failed LDAP bind attempts
FAILED_BINDS=$(docker-compose logs ldap | grep "bind failed" | wc -l)
echo "$(date): Failed LDAP binds: $FAILED_BINDS" >> $LOG_FILE

# Monitor failed web logins
FAILED_LOGINS=$(docker-compose logs ldap-user-manager | grep "login failed" | wc -l)
echo "$(date): Failed web logins: $FAILED_LOGINS" >> $LOG_FILE

# Alert on suspicious activity
if [ $FAILED_BINDS -gt 10 ] || [ $FAILED_LOGINS -gt 10 ]; then
    echo "$(date): High number of failed attempts detected!" >> $LOG_FILE
    echo "Security alert: High number of failed attempts at $(date)" | mail -s "Security Alert" admin@example.com
fi
```

### Access Pattern Monitoring
```bash
#!/bin/bash
# access-pattern-monitor.sh

# Monitor unusual access patterns
UNUSUAL_IPS=$(docker-compose logs ldap-user-manager | grep -oE "\b([0-9]{1,3}\.){3}[0-9]{1,3}\b" | sort | uniq -c | sort -nr | head -5)

echo "=== Top IP Addresses ==="
echo "$UNUSUAL_IPS"

# Monitor access times
NIGHT_ACCESS=$(docker-compose logs ldap-user-manager | grep "$(date +%Y-%m-%d)" | grep -E "(02|03|04):[0-5][0-9]" | wc -l)

if [ $NIGHT_ACCESS -gt 5 ]; then
    echo "$(date): Unusual night-time access detected: $NIGHT_ACCESS attempts" >> /var/log/ldap-user-manager/security.log
fi
```

## Dashboard Setup

### Grafana Dashboard
```json
{
  "dashboard": {
    "title": "LDAP User Manager Monitoring",
    "panels": [
      {
        "title": "Service Status",
        "type": "stat",
        "targets": [
          {
            "expr": "up{job=\"ldap-user-manager\"}"
          }
        ]
      },
      {
        "title": "Response Time",
        "type": "graph",
        "targets": [
          {
            "expr": "http_request_duration_seconds{job=\"ldap-user-manager\"}"
          }
        ]
      },
      {
        "title": "Failed Logins",
        "type": "graph",
        "targets": [
          {
            "expr": "rate(login_failures_total[5m])"
          }
        ]
      }
    ]
  }
}
```

## Monitoring Best Practices

### Monitoring Strategy
- **Start simple**: Basic health checks first
- **Add gradually**: Expand monitoring over time
- **Focus on critical**: Monitor what matters most
- **Test alerts**: Ensure alerts work correctly

### Alert Thresholds
- **Service down**: Immediate alert
- **High resource usage**: Alert at 80%+
- **Failed logins**: Alert after 10+ failures
- **Response time**: Alert if > 5 seconds

### Maintenance
- **Regular review**: Check monitoring effectiveness
- **Update thresholds**: Adjust based on usage patterns
- **Clean up logs**: Rotate log files regularly
- **Test procedures**: Verify monitoring works

## Troubleshooting Monitoring

### Common Issues
```bash
# Check monitoring scripts
chmod +x /path/to/monitoring-scripts/*.sh

# Verify cron jobs
crontab -l

# Check log files
tail -f /var/log/ldap-user-manager/*.log

# Test alerting
echo "Test alert" | mail -s "Test" admin@example.com
```

### Monitoring Health Check
```bash
#!/bin/bash
# monitor-health-check.sh

echo "=== Monitoring System Health ==="

# Check if monitoring scripts exist
if [ -f "/path/to/health-check.sh" ]; then
    echo "✅ Health check script: Found"
else
    echo "❌ Health check script: Missing"
fi

# Check if cron jobs are running
if crontab -l | grep -q "health-check"; then
    echo "✅ Health check cron: Active"
else
    echo "❌ Health check cron: Missing"
fi

# Check log files
if [ -f "/var/log/ldap-user-manager/health.log" ]; then
    echo "✅ Health logs: Found"
else
    echo "❌ Health logs: Missing"
fi
```

## Next Steps

- **Set up basic monitoring**: Start with health checks
- **Configure alerting**: Set up email/SMS alerts
- **Add advanced monitoring**: Consider Prometheus/Grafana
- **Create runbooks**: Document response procedures
- **Regular reviews**: Assess monitoring effectiveness
