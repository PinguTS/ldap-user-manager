# Disaster Recovery Guide

This guide provides comprehensive procedures for backing up and recovering your LDAP User Manager system in case of disasters.

## Overview

A disaster recovery plan ensures business continuity by providing:
- **Data Protection**: Regular backups of all critical data
- **Recovery Procedures**: Step-by-step recovery instructions
- **Testing**: Regular testing of backup and recovery procedures
- **Documentation**: Complete documentation of recovery processes

## Backup Strategy

### 1. LDAP Data Backup

#### Automated LDIF Export
```bash
#!/bin/bash
# backup-ldap.sh

# Configuration
BACKUP_DIR="/backups/ldap"
DATE=$(date +%Y%m%d_%H%M%S)
LDAP_URI="ldaps://ldap-server:636"
LDAP_BIND_DN="cn=admin,dc=example,dc=com"
LDAP_BIND_PWD="admin123"
LDAP_BASE_DN="dc=example,dc=com"

# Create backup directory
mkdir -p "$BACKUP_DIR"

# Export LDAP data
echo "Exporting LDAP data..."
ldapsearch -H "$LDAP_URI" \
  -D "$LDAP_BIND_DN" \
  -w "$LDAP_BIND_PWD" \
  -b "$LDAP_BASE_DN" \
  -s sub \
  > "$BACKUP_DIR/ldap_backup_$DATE.ldif"

# Compress backup
gzip "$BACKUP_DIR/ldap_backup_$DATE.ldif"

# Keep only last 30 days of backups
find "$BACKUP_DIR" -name "ldap_backup_*.ldif.gz" -mtime +30 -delete

echo "LDAP backup completed: ldap_backup_$DATE.ldif.gz"
```

#### Docker Volume Backup
```bash
#!/bin/bash
# backup-volumes.sh

# Configuration
BACKUP_DIR="/backups/volumes"
DATE=$(date +%Y%m%d_%H%M%S)

# Create backup directory
mkdir -p "$BACKUP_DIR"

# Stop services (optional, for consistent backup)
docker-compose stop

# Backup LDAP volumes
docker run --rm \
  -v ldap_user_manager_ldap_data:/data \
  -v "$BACKUP_DIR":/backup \
  alpine tar czf "/backup/ldap_data_$DATE.tar.gz" -C /data .

# Backup LDAP configuration
docker run --rm \
  -v ldap_user_manager_ldap_config:/data \
  -v "$BACKUP_DIR":/backup \
  alpine tar czf "/backup/ldap_config_$DATE.tar.gz" -C /data .

# Backup Caddy data
docker run --rm \
  -v ldap_user_manager_caddy_data:/data \
  -v "$BACKUP_DIR":/backup \
  alpine tar czf "/backup/caddy_data_$DATE.tar.gz" -C /data .

# Restart services
docker-compose start

echo "Volume backup completed"
```

### 2. Configuration Backup

#### Environment Configuration
```bash
#!/bin/bash
# backup-config.sh

# Configuration
BACKUP_DIR="/backups/config"
DATE=$(date +%Y%m%d_%H%M%S)

# Create backup directory
mkdir -p "$BACKUP_DIR"

# Backup environment files
cp .env "$BACKUP_DIR/env_$DATE"
cp docker-compose.yml "$BACKUP_DIR/docker-compose_$DATE.yml"

# Backup configuration files
cp -r dex/ "$BACKUP_DIR/dex_$DATE/"
cp -r caddy/ "$BACKUP_DIR/caddy_$DATE/"
cp -r apache/ "$BACKUP_DIR/apache_$DATE/"

# Backup certificates
if [ -d "certs" ]; then
  cp -r certs/ "$BACKUP_DIR/certs_$DATE/"
fi

# Compress configuration backup
tar czf "$BACKUP_DIR/config_backup_$DATE.tar.gz" \
  -C "$BACKUP_DIR" \
  "env_$DATE" \
  "docker-compose_$DATE.yml" \
  "dex_$DATE" \
  "caddy_$DATE" \
  "apache_$DATE" \
  "certs_$DATE"

# Clean up uncompressed files
rm -rf "$BACKUP_DIR/env_$DATE" \
       "$BACKUP_DIR/docker-compose_$DATE.yml" \
       "$BACKUP_DIR/dex_$DATE" \
       "$BACKUP_DIR/caddy_$DATE" \
       "$BACKUP_DIR/apache_$DATE" \
       "$BACKUP_DIR/certs_$DATE"

echo "Configuration backup completed: config_backup_$DATE.tar.gz"
```

### 3. Application Code Backup

#### Version Control Backup
```bash
#!/bin/bash
# backup-code.sh

# Configuration
BACKUP_DIR="/backups/code"
DATE=$(date +%Y%m%d_%H%M%S)

# Create backup directory
mkdir -p "$BACKUP_DIR"

# Create code snapshot
tar czf "$BACKUP_DIR/code_snapshot_$DATE.tar.gz" \
  --exclude='.git' \
  --exclude='node_modules' \
  --exclude='vendor' \
  --exclude='*.log' \
  .

echo "Code backup completed: code_snapshot_$DATE.tar.gz"
```

### 4. Automated Backup Schedule

#### Cron Job Setup
```bash
# /etc/cron.d/ldap-user-manager-backup

# Daily LDAP data backup at 2 AM
0 2 * * * root /opt/ldap-user-manager/scripts/backup-ldap.sh

# Weekly full backup on Sunday at 3 AM
0 3 * * 0 root /opt/ldap-user-manager/scripts/backup-full.sh

# Monthly configuration backup on 1st of month at 4 AM
0 4 1 * * root /opt/ldap-user-manager/scripts/backup-config.sh
```

#### Full Backup Script
```bash
#!/bin/bash
# backup-full.sh

# Configuration
BACKUP_DIR="/backups/full"
DATE=$(date +%Y%m%d_%H%M%S)

# Create backup directory
mkdir -p "$BACKUP_DIR"

echo "Starting full backup..."

# Run all backup scripts
/opt/ldap-user-manager/scripts/backup-ldap.sh
/opt/ldap-user-manager/scripts/backup-volumes.sh
/opt/ldap-user-manager/scripts/backup-config.sh
/opt/ldap-user-manager/scripts/backup-code.sh

# Create backup manifest
cat > "$BACKUP_DIR/backup_manifest_$DATE.txt" << EOF
Full Backup Manifest - $DATE
================================

Backup Components:
- LDAP Data: ldap_backup_$DATE.ldif.gz
- LDAP Volumes: ldap_data_$DATE.tar.gz, ldap_config_$DATE.tar.gz
- Caddy Data: caddy_data_$DATE.tar.gz
- Configuration: config_backup_$DATE.tar.gz
- Application Code: code_snapshot_$DATE.tar.gz

System Information:
- Hostname: $(hostname)
- Date: $(date)
- Version: $(docker-compose exec ldap-user-manager php -r "echo file_get_contents('version.txt');" 2>/dev/null || echo "Unknown")

Recovery Instructions:
1. Extract all backup files
2. Restore LDAP data using ldapadd
3. Restore Docker volumes
4. Restore configuration files
5. Restart services
6. Verify system functionality

EOF

echo "Full backup completed: backup_manifest_$DATE.txt"
```

## Recovery Procedures

### 1. Full System Recovery

#### Prerequisites
- Fresh server with Docker and Docker Compose installed
- All backup files available
- Network connectivity restored

#### Recovery Steps
```bash
#!/bin/bash
# recover-full.sh

# Configuration
BACKUP_DATE="20240115_143000"  # Set to your backup date
BACKUP_DIR="/backups/full"
RESTORE_DIR="/tmp/restore"

# Create restore directory
mkdir -p "$RESTORE_DIR"
cd "$RESTORE_DIR"

echo "Starting full system recovery..."

# 1. Extract backup files
echo "Extracting backup files..."
tar xzf "$BACKUP_DIR/config_backup_$BACKUP_DATE.tar.gz"
tar xzf "$BACKUP_DIR/code_snapshot_$BACKUP_DATE.tar.gz"

# 2. Restore configuration
echo "Restoring configuration..."
cp "env_$BACKUP_DATE" .env
cp "docker-compose_$BACKUP_DATE.yml" docker-compose.yml
cp -r "dex_$BACKUP_DATE" dex/
cp -r "caddy_$BACKUP_DATE" caddy/
cp -r "apache_$BACKUP_DATE" apache/
if [ -d "certs_$BACKUP_DATE" ]; then
  cp -r "certs_$BACKUP_DATE" certs/
fi

# 3. Start services with empty volumes
echo "Starting services..."
docker-compose up -d

# 4. Wait for services to be ready
echo "Waiting for services to be ready..."
sleep 30

# 5. Restore LDAP data
echo "Restoring LDAP data..."
gunzip -c "$BACKUP_DIR/ldap_backup_$BACKUP_DATE.ldif.gz" | \
  ldapadd -H "ldaps://localhost:636" \
  -D "cn=admin,dc=example,dc=com" \
  -w "admin123"

# 6. Restore Docker volumes
echo "Restoring Docker volumes..."
docker run --rm \
  -v ldap_user_manager_ldap_data:/data \
  -v "$BACKUP_DIR":/backup \
  alpine tar xzf "/backup/ldap_data_$BACKUP_DATE.tar.gz" -C /data

docker run --rm \
  -v ldap_user_manager_ldap_config:/data \
  -v "$BACKUP_DIR":/backup \
  alpine tar xzf "/backup/ldap_config_$BACKUP_DATE.tar.gz" -C /data

docker run --rm \
  -v ldap_user_manager_caddy_data:/data \
  -v "$BACKUP_DIR":/backup \
  alpine tar xzf "/backup/caddy_data_$BACKUP_DATE.tar.gz" -C /data

# 7. Restart services
echo "Restarting services..."
docker-compose restart

# 8. Verify recovery
echo "Verifying recovery..."
sleep 10

# Check service status
docker-compose ps

# Test LDAP connection
ldapsearch -H "ldaps://localhost:636" \
  -D "cn=admin,dc=example,dc=com" \
  -w "admin123" \
  -b "dc=example,dc=com" \
  -s base

# Test web interface
curl -f http://localhost:8080/setup/

echo "Full system recovery completed!"
```

### 2. Partial Recovery

#### LDAP Data Only Recovery
```bash
#!/bin/bash
# recover-ldap-data.sh

# Configuration
BACKUP_DATE="20240115_143000"
BACKUP_DIR="/backups/ldap"

echo "Starting LDAP data recovery..."

# Stop services
docker-compose stop ldap

# Restore LDAP data
gunzip -c "$BACKUP_DIR/ldap_backup_$BACKUP_DATE.ldif.gz" | \
  ldapadd -H "ldaps://localhost:636" \
  -D "cn=admin,dc=example,dc=com" \
  -w "admin123"

# Start LDAP service
docker-compose start ldap

echo "LDAP data recovery completed!"
```

#### Configuration Only Recovery
```bash
#!/bin/bash
# recover-config.sh

# Configuration
BACKUP_DATE="20240115_143000"
BACKUP_DIR="/backups/config"

echo "Starting configuration recovery..."

# Extract configuration backup
tar xzf "$BACKUP_DIR/config_backup_$BACKUP_DATE.tar.gz"

# Restore configuration files
cp "env_$BACKUP_DATE" .env
cp "docker-compose_$BACKUP_DATE.yml" docker-compose.yml
cp -r "dex_$BACKUP_DATE" dex/
cp -r "caddy_$BACKUP_DATE" caddy/
cp -r "apache_$BACKUP_DATE" apache/
if [ -d "certs_$BACKUP_DATE" ]; then
  cp -r "certs_$BACKUP_DATE" certs/
fi

# Restart services
docker-compose down
docker-compose up -d

echo "Configuration recovery completed!"
```

### 3. Point-in-Time Recovery

#### LDAP Data Point-in-Time Recovery
```bash
#!/bin/bash
# recover-point-in-time.sh

# Configuration
RECOVERY_DATE="2024-01-15 14:30:00"
BACKUP_DIR="/backups/ldap"

echo "Starting point-in-time recovery..."

# Find the most recent backup before recovery date
BACKUP_FILE=$(find "$BACKUP_DIR" -name "ldap_backup_*.ldif.gz" | \
  while read file; do
    FILE_DATE=$(echo "$file" | sed 's/.*ldap_backup_\(.*\)\.ldif\.gz/\1/' | \
      sed 's/\([0-9]\{8\}\)_\([0-9]\{6\}\)/\1 \2/' | \
      sed 's/\([0-9]\{4\}\)\([0-9]\{2\}\)\([0-9]\{2\}\) \([0-9]\{2\}\)\([0-9]\{2\}\)\([0-9]\{2\}\)/\1-\2-\3 \4:\5:\6/')
    if [[ "$FILE_DATE" < "$RECOVERY_DATE" ]]; then
      echo "$file"
    fi
  done | tail -1)

if [ -z "$BACKUP_FILE" ]; then
  echo "No suitable backup found for recovery date: $RECOVERY_DATE"
  exit 1
fi

echo "Using backup: $BACKUP_FILE"

# Perform recovery
gunzip -c "$BACKUP_FILE" | \
  ldapadd -H "ldaps://localhost:636" \
  -D "cn=admin,dc=example,dc=com" \
  -w "admin123"

echo "Point-in-time recovery completed!"
```

## Backup Verification

### 1. Backup Integrity Check
```bash
#!/bin/bash
# verify-backup.sh

# Configuration
BACKUP_DATE="20240115_143000"
BACKUP_DIR="/backups/full"

echo "Verifying backup integrity..."

# Check file existence
for file in \
  "ldap_backup_$BACKUP_DATE.ldif.gz" \
  "ldap_data_$BACKUP_DATE.tar.gz" \
  "ldap_config_$BACKUP_DATE.tar.gz" \
  "caddy_data_$BACKUP_DATE.tar.gz" \
  "config_backup_$BACKUP_DATE.tar.gz" \
  "code_snapshot_$BACKUP_DATE.tar.gz"; do
  
  if [ -f "$BACKUP_DIR/$file" ]; then
    echo "✅ $file exists"
  else
    echo "❌ $file missing"
  fi
done

# Verify LDAP backup content
echo "Verifying LDAP backup content..."
gunzip -c "$BACKUP_DIR/ldap_backup_$BACKUP_DATE.ldif.gz" | \
  grep -c "^dn:" | \
  while read count; do
    echo "LDAP backup contains $count entries"
  done

# Verify archive integrity
echo "Verifying archive integrity..."
for archive in \
  "ldap_data_$BACKUP_DATE.tar.gz" \
  "ldap_config_$BACKUP_DATE.tar.gz" \
  "caddy_data_$BACKUP_DATE.tar.gz" \
  "config_backup_$BACKUP_DATE.tar.gz" \
  "code_snapshot_$BACKUP_DATE.tar.gz"; do
  
  if tar tzf "$BACKUP_DIR/$archive" > /dev/null 2>&1; then
    echo "✅ $archive is valid"
  else
    echo "❌ $archive is corrupted"
  fi
done

echo "Backup verification completed!"
```

### 2. Recovery Testing
```bash
#!/bin/bash
# test-recovery.sh

# Configuration
BACKUP_DATE="20240115_143000"
TEST_DIR="/tmp/recovery-test"

echo "Starting recovery testing..."

# Create test environment
mkdir -p "$TEST_DIR"
cd "$TEST_DIR"

# Run recovery in test environment
/opt/ldap-user-manager/scripts/recover-full.sh

# Test system functionality
echo "Testing system functionality..."

# Test web interface
if curl -f http://localhost:8080/setup/ > /dev/null 2>&1; then
  echo "✅ Web interface accessible"
else
  echo "❌ Web interface not accessible"
fi

# Test LDAP connection
if ldapsearch -H "ldaps://localhost:636" \
  -D "cn=admin,dc=example,dc=com" \
  -w "admin123" \
  -b "dc=example,dc=com" \
  -s base > /dev/null 2>&1; then
  echo "✅ LDAP connection working"
else
  echo "❌ LDAP connection failed"
fi

# Test user authentication
if curl -f -X POST http://localhost:8080/login/ \
  -d "user_id=admin@example.com&password=admin123" > /dev/null 2>&1; then
  echo "✅ User authentication working"
else
  echo "❌ User authentication failed"
fi

# Clean up test environment
cd /
rm -rf "$TEST_DIR"
docker-compose down

echo "Recovery testing completed!"
```

## Disaster Recovery Planning

### 1. Recovery Time Objectives (RTO)

| Recovery Type | Target RTO | Description |
|---------------|------------|-------------|
| **Full System** | 4 hours | Complete system recovery |
| **LDAP Data** | 1 hour | User data recovery only |
| **Configuration** | 30 minutes | Settings recovery only |
| **Web Interface** | 15 minutes | Web service recovery |

### 2. Recovery Point Objectives (RPO)

| Data Type | Target RPO | Backup Frequency |
|-----------|------------|------------------|
| **LDAP Data** | 1 hour | Hourly LDIF exports |
| **Configuration** | 24 hours | Daily configuration backup |
| **Application Code** | 1 week | Weekly code snapshots |
| **Docker Volumes** | 24 hours | Daily volume backups |

### 3. Recovery Team Roles

| Role | Responsibilities |
|------|------------------|
| **Recovery Coordinator** | Overall recovery management |
| **System Administrator** | Infrastructure recovery |
| **Database Administrator** | LDAP data recovery |
| **Network Administrator** | Network connectivity |
| **Application Administrator** | Application recovery |

### 4. Communication Plan

#### Internal Communication
- **Immediate**: Email/SMS to recovery team
- **1 hour**: Status update to management
- **4 hours**: Detailed recovery report
- **24 hours**: Post-recovery analysis

#### External Communication
- **Immediate**: Service status page update
- **1 hour**: Customer notification (if applicable)
- **4 hours**: Detailed status report
- **24 hours**: Recovery completion notification

## Best Practices

### 1. Backup Best Practices
- **Automate backups**: Use cron jobs for regular backups
- **Test backups**: Regularly verify backup integrity
- **Store offsite**: Keep backups in multiple locations
- **Encrypt backups**: Protect sensitive backup data
- **Document procedures**: Maintain detailed backup documentation

### 2. Recovery Best Practices
- **Practice regularly**: Conduct recovery drills
- **Document procedures**: Maintain step-by-step recovery guides
- **Test procedures**: Verify recovery procedures work
- **Train staff**: Ensure team knows recovery procedures
- **Monitor backups**: Track backup success/failure

### 3. Security Considerations
- **Secure backup storage**: Encrypt backup files
- **Access control**: Limit backup access to authorized personnel
- **Audit trails**: Log all backup and recovery activities
- **Recovery testing**: Test recovery procedures regularly
- **Incident response**: Have incident response procedures

## Monitoring and Alerting

### 1. Backup Monitoring
```bash
#!/bin/bash
# monitor-backups.sh

# Check backup status
BACKUP_DIR="/backups/ldap"
LATEST_BACKUP=$(find "$BACKUP_DIR" -name "ldap_backup_*.ldif.gz" -printf '%T@ %p\n' | sort -n | tail -1 | cut -d' ' -f2-)

if [ -n "$LATEST_BACKUP" ]; then
  BACKUP_TIME=$(stat -c %Y "$LATEST_BACKUP")
  CURRENT_TIME=$(date +%s)
  AGE_HOURS=$(( (CURRENT_TIME - BACKUP_TIME) / 3600 ))
  
  if [ $AGE_HOURS -gt 24 ]; then
    echo "WARNING: Latest backup is $AGE_HOURS hours old"
    # Send alert
    mail -s "Backup Alert: LDAP backup is $AGE_HOURS hours old" admin@example.com
  fi
fi
```

### 2. Recovery Monitoring
```bash
#!/bin/bash
# monitor-recovery.sh

# Monitor recovery progress
RECOVERY_LOG="/var/log/recovery.log"

if [ -f "$RECOVERY_LOG" ]; then
  LAST_UPDATE=$(tail -1 "$RECOVERY_LOG" | cut -d' ' -f1-2)
  echo "Last recovery update: $LAST_UPDATE"
  
  # Check for recovery errors
  if grep -q "ERROR\|FAILED" "$RECOVERY_LOG"; then
    echo "WARNING: Recovery errors detected"
    # Send alert
    mail -s "Recovery Alert: Errors detected in recovery process" admin@example.com
  fi
fi
```

## Conclusion

A comprehensive disaster recovery plan is essential for maintaining business continuity. This guide provides:

- **Automated backup procedures** for all critical data
- **Step-by-step recovery procedures** for different scenarios
- **Testing and verification** procedures to ensure reliability
- **Monitoring and alerting** to detect issues early
- **Best practices** for maintaining a robust recovery system

Regular testing and updating of these procedures ensures your system can recover quickly and reliably from any disaster scenario.
