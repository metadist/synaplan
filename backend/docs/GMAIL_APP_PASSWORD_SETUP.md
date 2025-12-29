# Gmail App Password Setup for IMAP

This guide explains how to generate a Gmail App Password for IMAP access and configure it in your Synaplan installation.

## What is an App Password?

Gmail App Passwords are 16-character passwords that allow third-party applications to access your Gmail account via IMAP/SMTP. They are required when:
- 2-Step Verification is enabled on your Google account
- You want to use IMAP/SMTP instead of OAuth

## Prerequisites

1. **Gmail Account** (`smart@synaplan.net` or your Gmail account)
2. **2-Step Verification Enabled** (required to generate App Passwords)

## Step-by-Step Guide

### Step 1: Enable 2-Step Verification

If you haven't already enabled 2-Step Verification:

1. Go to [Google Account Security](https://myaccount.google.com/security)
2. Under "Signing in to Google", click **2-Step Verification**
3. Follow the prompts to enable it
4. You'll need a phone number for verification

### Step 2: Generate App Password

1. Go to [Google Account App Passwords](https://myaccount.google.com/apppasswords)
   - Or navigate: Google Account → Security → 2-Step Verification → App passwords

2. You may be asked to sign in again

3. Under "Select app", choose **Mail**

4. Under "Select device", choose **Other (Custom name)**

5. Enter a name for this App Password (e.g., "Synaplan IMAP" or "Smart Email Handler")

6. Click **Generate**

7. **Copy the 16-character password** immediately
   - It will look like: `abcd efgh ijkl mnop`
   - Remove spaces when using it: `abcdefghijklmnop`
   - ⚠️ **You won't be able to see it again!**

### Step 3: Configure in Synaplan

#### Option A: Using InboundEmailHandler (Recommended)

1. Log in to Synaplan admin panel
2. Go to **Tools → Mail Handler** (or **AI Config → Inbound**)
3. Create or edit an Inbound Email Handler
4. Configure:
   - **Mail Server**: `imap.gmail.com`
   - **Port**: `993`
   - **Protocol**: `IMAP`
   - **Security**: `SSL/TLS`
   - **Username**: Your Gmail address (e.g., `smart@synaplan.net`)
   - **Password**: The 16-character App Password (without spaces)
5. Click **Test Connection** to verify
6. Save the handler

#### Option B: Using Environment Variable (For Development)

If you're using the `app:process-emails` command or need to set it globally:

1. Add to `backend/.env`:
   ```env
   GMAIL_PASSWORD=abcdefghijklmnop
   ```

2. Restart the backend container:
   ```bash
   docker compose restart backend
   ```

## App Password Format

- **Length**: 16 characters
- **Format**: `xxxx xxxx xxxx xxxx` (with spaces) or `xxxxxxxxxxxxxxxx` (without spaces)
- **Usage**: Always use without spaces in configuration
- **Example**: `abcd efgh ijkl mnop` → Use as `abcdefghijklmnop`

## Gmail IMAP Settings

When configuring the InboundEmailHandler, use these settings:

| Setting | Value |
|---------|-------|
| Mail Server | `imap.gmail.com` |
| Port | `993` |
| Protocol | `IMAP` |
| Security | `SSL/TLS` |
| Username | Your Gmail address (e.g., `smart@synaplan.net`) |
| Password | 16-character App Password (no spaces) |

## Testing the Connection

### Using the Admin Panel

1. Go to **Tools → Mail Handler**
2. Click **Test Connection** on your handler
3. You should see: ✅ "Connection successful"

### Using Command Line

```bash
# Test IMAP connection
docker compose exec backend php bin/console app:process-emails --test
```

## Troubleshooting

### "Invalid credentials" error

- ✅ Verify the App Password is correct (no spaces, all 16 characters)
- ✅ Check that 2-Step Verification is enabled
- ✅ Make sure you're using the App Password, not your regular Gmail password
- ✅ Verify the username is the full Gmail address (e.g., `smart@synaplan.net`)

### "IMAP extension not available"

- Install the PHP IMAP extension:
  ```bash
  # In Docker, it should already be installed
  docker compose exec backend php -m | grep imap
  ```

### "Connection timeout"

- ✅ Check firewall settings
- ✅ Verify port 993 is not blocked
- ✅ Test with: `telnet imap.gmail.com 993`

### "App passwords not available"

- ✅ 2-Step Verification must be enabled
- ✅ Some Google Workspace accounts may have App Passwords disabled by admin
- ✅ Check if "Less secure app access" is enabled (older accounts)

## Security Best Practices

1. **Use App Passwords for specific applications only**
   - Don't reuse the same App Password for multiple services
   - Generate a new one for each application

2. **Store securely**
   - App Passwords are stored encrypted in the database
   - Never commit them to git (`.env` is gitignored)

3. **Revoke when not needed**
   - If you stop using a service, revoke its App Password
   - Go to [App Passwords](https://myaccount.google.com/apppasswords) and delete it

4. **Regular rotation**
   - Consider rotating App Passwords periodically
   - Generate a new one and update your configuration

## Revoking an App Password

If you need to revoke an App Password:

1. Go to [Google Account App Passwords](https://myaccount.google.com/apppasswords)
2. Find the App Password you want to revoke
3. Click the trash icon (🗑️) next to it
4. Confirm deletion

After revoking, you'll need to generate a new App Password and update your configuration.

## Alternative: Using Regular Password (Not Recommended)

If 2-Step Verification is **not** enabled, you can use your regular Gmail password, but this is **not recommended** for security reasons. Google may also block this method.

**Better approach**: Enable 2-Step Verification and use App Passwords.

## Related Documentation

- [Google App Passwords Help](https://support.google.com/accounts/answer/185833)
- [Gmail IMAP Settings](https://support.google.com/mail/answer/7126229)
