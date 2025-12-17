# Mail Handler Cronjob Setup

## Overview

The mail handler checks IMAP/POP3 mailboxes and forwards emails using AI-based routing.

## Built-in Lock Protection

The command prevents overlapping executions automatically:
- Lock timeout: 15 minutes
- Safe for 1-minute intervals

## Cronjob Setup

### User Crontab (Development)

```bash
crontab -e
```

Add this line:
```cron
# Synaplan Mail Handler - runs every minute
* * * * * cd /path/to/synaplan && docker compose exec -T backend php bin/console app:process-mail-handlers >> /var/log/synaplan-mail.log 2>&1
```

### System Crontab (Production)

Create `/etc/cron.d/synaplan-mail-handler`:

```cron
* * * * * username cd /path/to/synaplan && docker compose exec -T backend php bin/console app:process-mail-handlers >> /var/log/synaplan-mail.log 2>&1
```

Replace `username` with the appropriate user.

### Systemd Timer (Alternative)

Create `/etc/systemd/system/synaplan-mail-handler.service`:

```ini
[Unit]
Description=Synaplan Mail Handler

[Service]
Type=oneshot
User=username
WorkingDirectory=/path/to/synaplan
ExecStart=/usr/bin/docker compose exec -T backend php bin/console app:process-mail-handlers
```

Create `/etc/systemd/system/synaplan-mail-handler.timer`:

```ini
[Unit]
Description=Run Synaplan Mail Handler every minute

[Timer]
OnBootSec=1min
OnUnitActiveSec=1min
Persistent=true

[Install]
WantedBy=timers.target
```

Enable:
```bash
sudo systemctl enable synaplan-mail-handler.timer
sudo systemctl start synaplan-mail-handler.timer
```

## Change Interval

Edit the cron schedule pattern (first 5 characters):
- `* * * * *` = Every minute (current)
- `*/5 * * * *` = Every 5 minutes
- `*/10 * * * *` = Every 10 minutes

## Manual Execution

```bash
cd /path/to/synaplan
docker compose exec backend php bin/console app:process-mail-handlers
```

## Monitoring

```bash
# View logs
tail -f /var/log/synaplan-mail.log

# Check if cron is running
sudo systemctl status cron

# Check systemd timer (if using systemd)
systemctl status synaplan-mail-handler.timer
```

## Troubleshooting

**Cronjob not running:**
```bash
sudo systemctl restart cron
grep CRON /var/log/syslog | tail -20
```

**Docker not found:**
```bash
which docker  # Use full path: /usr/bin/docker
```

**Permissions:**
Ensure the cron user can access the project directory and execute docker commands.
