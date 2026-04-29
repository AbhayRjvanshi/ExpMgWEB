# Notification Cleanup Cron Configuration

## Overview
This file documents the cron job configuration for automatic notification cleanup.

---

## Cron Schedule

**Recommended Schedule:** Daily at 3:00 AM (low traffic period)

```cron
0 3 * * * php /path/to/ExpMgWEB/scripts/cleanup_notifications.php >> /path/to/ExpMgWEB/logs/cleanup.log 2>&1
```

### Explanation:
- `0 3 * * *` — Run at 3:00 AM every day
- `php /path/to/...` — Execute the cleanup script
- `>> /path/to/logs/cleanup.log` — Append output to log file
- `2>&1` — Redirect errors to same log file

---

## Installation Instructions

### Linux/Unix/macOS

1. Open crontab editor:
   ```bash
   crontab -e
   ```

2. Add the cron job (replace paths):
   ```cron
   0 3 * * * php /var/www/ExpMgWEB/scripts/cleanup_notifications.php >> /var/www/ExpMgWEB/logs/cleanup.log 2>&1
   ```

3. Save and exit

4. Verify cron job is scheduled:
   ```bash
   crontab -l
   ```

### Windows (Task Scheduler)

1. Open Task Scheduler
2. Create Basic Task
3. **Name:** Notification Cleanup
4. **Trigger:** Daily at 3:00 AM
5. **Action:** Start a program
   - **Program:** `C:\xampp\php\php.exe`
   - **Arguments:** `C:\xampp\htdocs\ExpMgWEB\scripts\cleanup_notifications.php`
   - **Start in:** `C:\xampp\htdocs\ExpMgWEB\scripts`
6. Finish

---

## Alternative Schedules

### Every 12 Hours
```cron
0 3,15 * * * php /path/to/scripts/cleanup_notifications.php
```
Runs at 3:00 AM and 3:00 PM daily.

### Every 6 Hours
```cron
0 */6 * * * php /path/to/scripts/cleanup_notifications.php
```
Runs at 12:00 AM, 6:00 AM, 12:00 PM, 6:00 PM.

### Weekly (Sundays at 3 AM)
```cron
0 3 * * 0 php /path/to/scripts/cleanup_notifications.php
```
Use only if notification volume is very low.

---

## Manual Execution

To run cleanup manually:

```bash
php scripts/cleanup_notifications.php
```

Expected output:
```
=== Notification Cleanup Started ===
Retention Policy: 3 days
Batch Size: 10000 rows
Timestamp: 2026-03-12 10:30:00

Notifications before cleanup: 45,230
Expired notifications found: 12,450

Starting batch deletion...
Batch 1: Deleted 10000 rows (Total: 10,000)
Batch 2: Deleted 2450 rows (Total: 12,450)

=== Cleanup Completed ===
Notifications before: 45,230
Notifications after:  32,780
Total deleted:        12,450
Iterations:           2
Duration:             1.23s
```

---

## Monitoring

### Check Cleanup Logs
```bash
tail -f logs/cleanup.log
```

### Check Application Logs
```bash
grep "Notification cleanup" logs/app.log
```

### Verify Cron is Running
```bash
# Linux/Unix
grep CRON /var/log/syslog | grep cleanup_notifications

# Check last run time
ls -lh logs/cleanup.log
```

---

## Troubleshooting

### Cron Job Not Running

**Check cron service:**
```bash
sudo service cron status
```

**Check cron logs:**
```bash
grep CRON /var/log/syslog
```

**Verify PHP path:**
```bash
which php
```

### Script Fails

**Check permissions:**
```bash
chmod +x scripts/cleanup_notifications.php
```

**Test manually:**
```bash
php scripts/cleanup_notifications.php
```

**Check database connection:**
```bash
php -r "require 'config/db.php'; echo 'DB OK';"
```

### High Deletion Count

If cleanup deletes >1M records, check:
- Notification volume spike
- Cron job missed multiple runs
- Application creating too many notifications

Review logs:
```bash
grep "WARNING.*cleanup" logs/app.log
```

---

## Performance Considerations

### Expected Performance

| Notifications | Deletion Time | Database Load |
|---|---|---|
| 10,000 | <1 second | Minimal |
| 100,000 | 5-10 seconds | Low |
| 1,000,000 | 1-2 minutes | Moderate |

### Optimization Tips

1. **Run during low traffic** — 3 AM is ideal
2. **Adjust batch size** — Increase to 50,000 for faster cleanup
3. **Monitor duration** — Alert if >60 seconds
4. **Check indexes** — Ensure `idx_notifications_created_at` exists

---

## Retention Policy

**Current Policy:** 3 days

To change retention period, edit `scripts/cleanup_notifications.php`:

```php
define('RETENTION_DAYS', 7); // Change to 7 days
```

**Recommended retention periods:**
- **3 days** — Standard (current)
- **7 days** — Extended history
- **1 day** — Minimal storage

---

## Disaster Recovery

If cleanup accidentally deletes too much:

1. **Check backups** — Restore from daily backup
2. **Review logs** — Identify what was deleted
3. **Adjust retention** — Increase RETENTION_DAYS if needed

**Prevention:**
- Test changes in staging first
- Monitor cleanup logs daily
- Keep database backups (30-day retention)

---

## Future Scalability

At 1M+ users, consider:

1. **Redis for notifications** — Automatic TTL expiration
2. **Partition by date** — Archive old notifications
3. **Async cleanup** — Background worker queue
4. **Read replicas** — Offload cleanup to replica

Current MySQL solution scales to ~500K daily notifications.
