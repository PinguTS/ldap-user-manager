# LDAP Backup Guide

This guide explains how to backup and restore your LDAP directory data.

## Overview

Regular backups are essential for protecting your LDAP data. This guide covers:
- **Automated backup procedures**
- **Manual backup methods**
- **Restore procedures**
- **Backup verification**
- **Backup scheduling**

## Backup Methods

### Method 1: Docker Volume Backup (Recommended)

#### Automated Backup Script
```bash
#!/bin/bash
# backup-ldap.sh

BACKUP_DIR="/backups/ldap"
DATE=$(date +%Y%m%d_%H%M%S)
CONTAINER_NAME="ldap-user-manager_ldap_1"

# Create backup directory
mkdir -p $BACKUP_DIR

# Stop LDAP service (optional, for consistent backup)
docker-compose stop ldap

# Backup LDAP data
docker run --rm \
  --volumes-from $CONTAINER_NAME \
  -v $BACKUP_DIR:/backup \
  alpine tar czf /backup/ldap_backup_$DATE.tar.gz /var/lib/ldap

# Start LDAP service
docker-compose start ldap

echo "Backup completed: ldap_backup_$DATE.tar.gz"
```

#### Manual Backup Commands
```bash
# Create backup directory
mkdir -p /backups/ldap

# Backup LDAP data volume
docker run --rm \
  --volumes-from ldap-user-manager_ldap_1 \
  -v /backups/ldap:/backup \
  alpine tar czf /backup/ldap_backup_$(date +%Y%m%d_%H%M%S).tar.gz /var/lib/ldap
```

### Method 2: LDIF Export (Human Readable)

#### Export All Data
```bash
# Export entire LDAP directory
docker-compose exec ldap slapcat -n 0 > backup_$(date +%Y%m%d_%H%M%S).ldif
```

#### Export Specific Branches
```bash
# Export organizations only
docker-compose exec ldap slapcat -n 0 -b "ou=organizations,dc=example,dc=com" > orgs_backup.ldif

# Export system users only
docker-compose exec ldap slapcat -n 0 -b "ou=people,dc=example,dc=com" > users_backup.ldif
```

#### Export with Filtering
```bash
# Export only active users
docker-compose exec ldap slapcat -n 0 -l active_users.ldif "(objectClass=inetOrgPerson)"
```

## Restore Procedures

### Method 1: Docker Volume Restore

#### Restore from Volume Backup
```bash
#!/bin/bash
# restore-ldap.sh

BACKUP_FILE="$1"
CONTAINER_NAME="ldap-user-manager_ldap_1"

if [ -z "$BACKUP_FILE" ]; then
    echo "Usage: $0 <backup_file.tar.gz>"
    exit 1
fi

# Stop LDAP service
docker-compose stop ldap

# Restore from backup
docker run --rm \
  --volumes-from $CONTAINER_NAME \
  -v $(pwd):/backup \
  alpine tar xzf /backup/$BACKUP_FILE -C /

# Start LDAP service
docker-compose start ldap

echo "Restore completed from $BACKUP_FILE"
```

### Method 2: LDIF Restore

#### Restore from LDIF
```bash
# Stop LDAP service
docker-compose stop ldap

# Restore from LDIF
docker-compose exec ldap slapadd -n 0 -l backup.ldif

# Start LDAP service
docker-compose start ldap
```

#### Partial Restore
```bash
# Restore specific organization
docker-compose exec ldap ldapadd -D "cn=admin,dc=example,dc=com" -w admin -f org_restore.ldif
```

## Backup Verification

### Verify Backup Integrity
```bash
# Check LDIF syntax
docker-compose exec ldap slapcat -n 0 -l test.ldif
slapcat -n 0 -l test.ldif | head -20

# Verify volume backup
tar -tzf ldap_backup_20231201_120000.tar.gz | head -10
```

### Test Restore (Safe Environment)
```bash
# Create test container
docker run -d --name ldap-test \
  -v ldap_backup_20231201_120000:/var/lib/ldap \
  osixia/openldap:latest

# Test LDAP connection
ldapsearch -H ldap://localhost:389 -D "cn=admin,dc=example,dc=com" -w admin -b "dc=example,dc=com" -s base

# Clean up test container
docker stop ldap-test && docker rm ldap-test
```

## Backup Scheduling

### Cron Job Setup
```bash
# Edit crontab
crontab -e

# Add daily backup at 2 AM
0 2 * * * /path/to/backup-ldup.sh

# Add weekly backup on Sunday at 3 AM
0 3 * * 0 /path/to/backup-ldap.sh weekly
```

### Automated Backup Script
```bash
#!/bin/bash
# automated-backup.sh

BACKUP_DIR="/backups/ldap"
RETENTION_DAYS=30
DATE=$(date +%Y%m%d_%H%M%S)

# Create backup
docker run --rm \
  --volumes-from ldap-user-manager_ldap_1 \
  -v $BACKUP_DIR:/backup \
  alpine tar czf /backup/ldap_backup_$DATE.tar.gz /var/lib/ldap

# Clean up old backups
find $BACKUP_DIR -name "ldap_backup_*.tar.gz" -mtime +$RETENTION_DAYS -delete

# Log backup completion
echo "$(date): Backup completed - ldap_backup_$DATE.tar.gz" >> /var/log/ldap-backup.log
```

## Backup Best Practices

### Backup Strategy
- **Daily backups** for active systems
- **Weekly full backups** for less critical systems
- **Monthly archives** for long-term retention
- **Test restores** regularly

### Storage Considerations
- **Local storage** for quick access
- **Remote storage** for disaster recovery
- **Encrypted backups** for sensitive data
- **Compressed backups** to save space

### Security
- **Secure backup storage** with restricted access
- **Encrypted backup files** for sensitive data
- **Backup file permissions** (600 or 400)
- **Backup access logs** for audit trails

## Monitoring and Alerting

### Backup Monitoring
```bash
#!/bin/bash
# check-backup.sh

BACKUP_DIR="/backups/ldap"
LATEST_BACKUP=$(find $BACKUP_DIR -name "ldap_backup_*.tar.gz" -mtime -1 | head -1)

if [ -z "$LATEST_BACKUP" ]; then
    echo "WARNING: No recent backup found!"
    exit 1
fi

echo "Latest backup: $LATEST_BACKUP"
exit 0
```

### Backup Size Monitoring
```bash
# Check backup sizes
du -sh /backups/ldap/*.tar.gz | sort -hr

# Alert if backup size changes significantly
CURRENT_SIZE=$(du -s /backups/ldap/ldap_backup_$(date +%Y%m%d)*.tar.gz | awk '{print $1}')
PREVIOUS_SIZE=$(du -s /backups/ldap/ldap_backup_$(date -d "yesterday" +%Y%m%d)*.tar.gz | awk '{print $1}')

if [ $CURRENT_SIZE -lt $((PREVIOUS_SIZE * 80 / 100)) ]; then
    echo "WARNING: Backup size decreased significantly!"
fi
```

## Disaster Recovery

### Complete System Restore
```bash
#!/bin/bash
# disaster-recovery.sh

BACKUP_FILE="$1"
NEW_HOST="$2"

if [ -z "$BACKUP_FILE" ] || [ -z "$NEW_HOST" ]; then
    echo "Usage: $0 <backup_file> <new_host>"
    exit 1
fi

# Transfer backup to new host
scp $BACKUP_FILE $NEW_HOST:/tmp/

# On new host, restore LDAP
ssh $NEW_HOST << EOF
cd /path/to/ldap-user-manager
docker-compose down
docker volume rm ldap-user-manager_ldap_data
docker run --rm -v ldap-user-manager_ldap_data:/var/lib/ldap -v /tmp:/backup alpine tar xzf /backup/$(basename $BACKUP_FILE) -C /
docker-compose up -d
EOF
```

### Configuration Backup
```bash
# Backup configuration files
tar czf config_backup_$(date +%Y%m%d_%H%M%S).tar.gz \
  docker-compose.yml \
  .env \
  apache/ \
  caddy/ \
  dex/
```

## Troubleshooting

### Common Backup Issues

#### Backup Fails
```bash
# Check disk space
df -h

# Check Docker volumes
docker volume ls

# Check container status
docker-compose ps ldap
```

#### Restore Fails
```bash
# Check LDIF syntax
slapcat -n 0 -l test.ldif | head -20

# Check LDAP service
docker-compose logs ldap

# Verify permissions
ls -la /var/lib/ldap/
```

#### Backup Size Issues
```bash
# Check for large entries
docker-compose exec ldap slapcat -n 0 | grep -E "^[a-zA-Z]" | sort | uniq -c | sort -nr

# Check for duplicate entries
docker-compose exec ldap slapcat -n 0 | grep "^dn:" | sort | uniq -d
```

## Next Steps

- **Set up automated backups**: Configure cron jobs
- **Test restore procedures**: Verify backup integrity
- **Monitor backup success**: Set up alerts
- **Document procedures**: Create runbooks for your team
