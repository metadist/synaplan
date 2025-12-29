# Smart Email Cronjob Setup

## Overview

The `app:process-emails` command checks Gmail IMAP for emails to `smart@synaplan.net` (and `smart+keyword@synaplan.net`) and processes them through the AI pipeline.

**Note:** This is a separate feature from `app:process-mail-handlers` (Mail Handler for department routing). Both cronjobs can run simultaneously - they serve different purposes:
- `app:process-mail-handlers`: User-configured email routing to departments (AI Config > Inbound)
- `app:process-emails`: Smart email chat (`smart@synaplan.net` for AI conversations)

## Options

### Option 1: Watch Mode (Development/Testing)

Run the command in watch mode - it will continuously check for emails:

```bash
docker compose exec backend php bin/console app:process-emails --watch --interval=30
```

- `--watch` or `-w`: Enable continuous monitoring
- `--interval` or `-i`: Check interval in seconds (default: 10, minimum recommended: 30)
- Press `CTRL+C` to stop

**Note:** Watch mode keeps the process running. For production, use a cronjob instead.

### Option 2: Cronjob (Production)

#### A. Cronjob Inside Docker Container

Add a cron service to `docker-compose.yml`:

```yaml
  email-processor:
    image: ghcr.io/metadist/synaplan:latest
    container_name: synaplan-email-processor
    env_file:
      - backend/.env
    volumes:
      - ./backend:/var/www/backend
    command: >
      sh -c "
        echo '* * * * * cd /var/www/backend && php bin/console app:process-emails >> /var/log/synaplan-email.log 2>&1' | crontab - &&
        crond -f -d 8
      "
    depends_on:
      - backend
    restart: unless-stopped
    networks:
      - synaplan-network
```

#### B. Cronjob on Host System (Recommended)

**User Crontab (Development):**

```bash
crontab -e
```

Add this line (adjust path and interval as needed):

```cron
# Synaplan Smart Email Processor - runs every 2 minutes
*/2 * * * * cd /netroot/synaplanCluster/synaplan-compose && docker compose exec -T backend php bin/console app:process-emails >> /var/log/synaplan-email.log 2>&1
```

**Note:** If you already have a cronjob for `app:process-mail-handlers`, you can add this as a second line. Both commands can run simultaneously.

**System Crontab (Production):**

Create `/etc/cron.d/synaplan-smart-email`:

```cron
# Synaplan Smart Email Processor
# Runs every 2 minutes
*/2 * * * * root cd /netroot/synaplanCluster/synaplan-compose && docker compose exec -T backend php bin/console app:process-emails >> /var/log/synaplan-email.log 2>&1
```

**Or add to existing cron script:**

If you have `/netroot/synaplanCluster/synaplan-compose/cron-gmail.sh`, you can add a second line:

```bash
#!/bin/bash

export SYNDBHOST=10.0.0.2

cd /netroot/synaplanCluster/synaplan-compose

# Mail Handler (existing - for department routing)
docker compose exec -T backend php bin/console app:process-mail-handlers

# Smart Email Handler (new - for smart@synaplan.net)
docker compose exec -T backend php bin/console app:process-emails
```

Replace:
- `root` with the appropriate user (if different)
- `/netroot/synaplanCluster/synaplan-compose` with your actual project path

Make it executable:
```bash
sudo chmod 644 /etc/cron.d/synaplan-smart-email
```

#### C. Systemd Timer (Alternative)

Create `/etc/systemd/system/synaplan-smart-email.service`:

```ini
[Unit]
Description=Synaplan Smart Email Processor
After=network.target docker.service

[Service]
Type=oneshot
User=username
WorkingDirectory=/path/to/synaplan
ExecStart=/usr/bin/docker compose exec -T backend php bin/console app:process-emails
StandardOutput=append:/var/log/synaplan-email.log
StandardError=append:/var/log/synaplan-email.log
```

Create `/etc/systemd/system/synaplan-smart-email.timer`:

```ini
[Unit]
Description=Run Synaplan Smart Email Processor every 2 minutes

[Timer]
OnBootSec=2min
OnUnitActiveSec=2min
Persistent=true

[Install]
WantedBy=timers.target
```

Enable and start:

```bash
sudo systemctl enable synaplan-smart-email.timer
sudo systemctl start synaplan-smart-email.timer
```

## Recommended Intervals

- **Every 1 minute** (`* * * * *`): For high-priority use cases
- **Every 2 minutes** (`*/2 * * * *`): Recommended for most cases
- **Every 5 minutes** (`*/5 * * * *`): For lower priority
- **Every 10 minutes** (`*/10 * * * *`): For testing/development

## Manual Execution

Test the command manually:

```bash
cd /wwwroot/synaplan
docker compose exec backend php bin/console app:process-emails
```

## Monitoring

### View Logs

```bash
# Host system logs (if using host cronjob)
tail -f /var/log/synaplan-email.log

# Docker container logs (if using watch mode or container cronjob)
docker compose logs -f backend | grep -i email
```

### Check Cron Status

```bash
# Check if cron is running
sudo systemctl status cron

# Check systemd timer (if using systemd)
sudo systemctl status synaplan-smart-email.timer

# View cron execution history
grep CRON /var/log/syslog | grep synaplan
```

## Troubleshooting

### Command Not Found

If `docker compose` is not found in cron, use full path:

```bash
which docker compose
# Use the full path in cron, e.g.:
/usr/local/bin/docker compose exec -T backend php bin/console app:process-emails
```

### Permission Issues

Ensure the user running cron has:
- Access to Docker
- Read access to the project directory
- Write access to log file directory

### Emails Not Processing

1. Check Gmail credentials in `backend/.env`:
   - `GMAIL_USERNAME` (e.g., `admin@ralfs.ai`)
   - `GMAIL_PASSWORD` (Gmail App Password)

2. Test IMAP connection manually:
   ```bash
   docker compose exec backend php bin/console app:process-emails
   ```

3. Check backend logs:
   ```bash
   docker compose logs backend | grep -i email
   ```

### Overlapping Executions

The command is designed to be safe for frequent execution. Each run:
- Only processes unread emails
- Marks emails as read after successful processing
- Can run concurrently without conflicts

## Environment Variables

Required in `backend/.env`:

```bash
GMAIL_USERNAME=your-email@gmail.com
GMAIL_PASSWORD=your-app-password
APP_URL=http://localhost:8000  # Used for webhook URL (auto-adjusted for Docker)
```

See `backend/docs/GMAIL_APP_PASSWORD_SETUP.md` for Gmail App Password setup instructions.
