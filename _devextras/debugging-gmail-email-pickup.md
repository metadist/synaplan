# Debugging Gmail Email Pickup

This guide explains how to debug Gmail email pickup configuration on the live platform (web.synaplan.com).

## Overview

The platform uses IMAP to connect to Gmail and pick up emails sent to `smart@synaplan.net` (which redirects to Gmail). The emails are then processed and routed to departments using AI.

## Debugging Methods

### 1. Check Handler Configuration via API

You can retrieve detailed debug information about a handler's configuration (without exposing passwords):

```bash
# Get your API token from the platform
TOKEN="your-api-token-here"
HANDLER_ID="123"  # Replace with your handler ID

# Get debug information
curl -X GET "https://web.synaplan.com/api/v1/inbound-email-handlers/${HANDLER_ID}/debug" \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "Content-Type: application/json"
```

**Response includes:**
- Connection details (server, port, protocol, security)
- Connection string that would be used
- Status and last checked timestamp
- Email filter configuration
- SMTP configuration (for forwarding)
- Department routing rules

### 2. Test IMAP Connection

Test if the Gmail IMAP connection is working:

```bash
curl -X POST "https://web.synaplan.com/api/v1/inbound-email-handlers/${HANDLER_ID}/test" \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "Content-Type: application/json"
```

This will:
- Attempt to connect to Gmail IMAP
- Update handler status to 'active' or 'error' based on result
- Return success/failure message

### 3. Manually Trigger Email Processing

Manually trigger email processing for a specific handler (useful for debugging):

```bash
curl -X POST "https://web.synaplan.com/api/v1/inbound-email-handlers/${HANDLER_ID}/process" \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "Content-Type: application/json"
```

**Response:**
```json
{
  "success": true,
  "processed": 3,
  "errors": []
}
```

### 4. Check Handler Status

List all handlers to see their status:

```bash
curl -X GET "https://web.synaplan.com/api/v1/inbound-email-handlers" \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "Content-Type: application/json"
```

Look for:
- `status`: 'active', 'inactive', or 'error'
- `lastChecked`: When the handler last checked for emails
- `checkInterval`: How often it checks (in minutes)

## Common Issues and Solutions

### Issue: Handler Status is "error"

**Possible causes:**
1. **Invalid Gmail credentials** - Check username/password
2. **Gmail App Password not set** - Gmail requires App Passwords for IMAP access
3. **IMAP not enabled** - Gmail account must have IMAP enabled
4. **Network/firewall issues** - Server can't reach Gmail IMAP servers

**Debug steps:**
1. Use the `/test` endpoint to see the exact error message
2. Check Gmail settings:
   - Enable IMAP in Gmail settings
   - Generate an App Password (not your regular password)
   - Use the App Password in the handler configuration
3. Verify Gmail IMAP settings:
   - Server: `imap.gmail.com`
   - Port: `993`
   - Security: `SSL/TLS`

### Issue: Emails are picked up but no response

**Possible causes:**
1. **No departments configured** - Handler needs at least one department
2. **AI routing failing** - Emails not matching department rules
3. **SMTP forwarding failing** - Can't forward emails to departments
4. **Emails marked as irrelevant** - AI determined emails don't match any department

**Debug steps:**
1. Check handler debug info - verify departments are configured
2. Manually trigger processing - see how many emails were processed
3. Check application logs for routing decisions
4. Test SMTP configuration separately

### Issue: Handler never processes emails

**Possible causes:**
1. **Handler is inactive** - Status is 'inactive'
2. **Cron job not running** - `app:process-mail-handlers` command not scheduled
3. **All emails already processed** - No new unseen emails
4. **Email filter too restrictive** - Filter mode excludes emails

**Debug steps:**
1. Check handler status - should be 'active'
2. Verify cron job is running:
   ```bash
   # On the server, check if cron is running the command
   crontab -l | grep process-mail-handlers
   ```
3. Manually trigger processing to see if emails are found
4. Check email filter configuration - might be too restrictive

## Gmail Configuration Checklist

When setting up Gmail email pickup:

- [ ] Gmail account has IMAP enabled
- [ ] App Password generated (not regular password)
- [ ] Handler configured with:
  - Server: `imap.gmail.com`
  - Port: `993`
  - Protocol: `IMAP`
  - Security: `SSL/TLS`
  - Username: Full Gmail address
  - Password: App Password (16 characters)
- [ ] At least one department configured
- [ ] SMTP configured for forwarding (if forwarding emails)
- [ ] Handler status is 'active'
- [ ] Test connection succeeds

## Using the API with an API Key

If you have an API key, you can use it instead of a Bearer token:

```bash
# Get API key from user profile
API_KEY="your-api-key-here"

curl -X GET "https://web.synaplan.com/api/v1/inbound-email-handlers" \
  -H "X-API-Key: ${API_KEY}" \
  -H "Content-Type: application/json"
```

## Server-Side Debugging

If you have server access, you can also:

### 1. Run the processing command manually

```bash
# Process all handlers once
php bin/console app:process-mail-handlers

# Watch mode (processes continuously)
php bin/console app:process-mail-handlers --watch --interval=60
```

### 2. Check application logs

```bash
# View recent logs
tail -f var/log/prod.log | grep -i "mail\|handler\|email"

# Search for errors
grep -i "error\|failed" var/log/prod.log | grep -i "mail\|handler"
```

### 3. Check database directly

```sql
-- Check handler status
SELECT BID, BNAME, BSTATUS, BLASTCHECKED, BMAILSERVER, BUSERNAME 
FROM BINBOUNDEMAILHANDLER 
WHERE BUSERID = YOUR_USER_ID;

-- Check last checked timestamp
SELECT BID, BNAME, BLASTCHECKED, 
       FROM_UNIXTIME(UNIX_TIMESTAMP(STR_TO_DATE(BLASTCHECKED, '%Y%m%d%H%i%s'))) as last_checked_readable
FROM BINBOUNDEMAILHANDLER 
WHERE BUSERID = YOUR_USER_ID;
```

## Getting Help

If you're still having issues:

1. **Collect debug information:**
   - Handler debug info (via `/debug` endpoint)
   - Test connection result (via `/test` endpoint)
   - Manual processing result (via `/process` endpoint)
   - Recent application logs

2. **Check common issues:**
   - Gmail App Password is correct
   - Handler status is 'active'
   - At least one department is configured
   - Cron job is running

3. **Contact support** with:
   - Handler ID
   - Debug information (without passwords)
   - Error messages from logs
   - Steps you've already tried

