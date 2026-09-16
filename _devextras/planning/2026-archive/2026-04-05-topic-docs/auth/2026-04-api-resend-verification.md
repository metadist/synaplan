# Resend Email Verification API

## Endpoint
`POST /api/v1/auth/resend-verification`

## Request
```json
{
  "email": "user@example.com"
}
```

## Response Codes & Behavior

### ✅ 200 OK - Success (Email Sent)
**Conditions:** Valid unverified user + rate limit not exceeded

```json
{
  "success": true,
  "message": "Verification email sent successfully",
  "remainingAttempts": 4,
  "cooldownMinutes": 2
}
```

**Frontend Action:**
- Show success message
- Start 2-minute countdown
- Disable button during countdown
- Update remaining attempts counter

---

### ✅ 200 OK - Generic Success (No Email Sent)
**Conditions:** User doesn't exist OR already verified (security - don't reveal user existence)

```json
{
  "success": true,
  "message": "If your email is registered and unverified, you will receive a verification email."
}
```

**Backend Behavior:**
- Rate limit attempt is STILL tracked
- No email is sent
- Generic message (security)

**Frontend Action:**
- Show success message
- Don't start countdown (no cooldown data in response)

---

### ⚠️ 429 Too Many Requests - Rate Limited (Cooldown Active)
**Conditions:** User trying to resend before cooldown expires

```json
{
  "error": "Please wait before requesting another verification email",
  "waitSeconds": 112,
  "remainingAttempts": 4,
  "nextAvailableAt": "2025-10-11T09:40:09+00:00"
}
```

**Frontend Action:**
- Show error message with wait time
- Start countdown from `waitSeconds`
- Disable button during countdown
- Show remaining attempts

---

### ⚠️ 429 Too Many Requests - Max Attempts Reached
**Conditions:** User exceeded maximum resend attempts (5)

```json
{
  "error": "Maximum verification attempts reached",
  "message": "You have reached the maximum number of verification email requests. Please contact support if you need assistance.",
  "maxAttemptsReached": true
}
```

**Frontend Action:**
- Show error message
- Set `remainingAttempts = 0`
- Permanently disable button
- Show "Maximum attempts reached" text

---

### ❌ 500 Internal Server Error - Technical Problem
**Conditions:** Mail server down, SMTP error, template rendering error, etc.

```json
{
  "error": "Technical error",
  "message": "An error occurred while sending the verification email. Please try again later."
}
```

**Backend Behavior:**
- Exception caught from MailerService
- Logged with error details
- Generic message to user (no technical details exposed)

**Frontend Action:**
- Show error message
- Allow retry (button not disabled)
- No countdown

---

### ❌ 400 Bad Request - Validation Error
**Conditions:** Empty or invalid email

```json
{
  "error": "Email required"
}
```

**Frontend Action:**
- Show validation error
- Allow retry

---

## Rate Limiting Configuration

```env
EMAIL_VERIFICATION_COOLDOWN_MINUTES=2  # 2 minutes between attempts
EMAIL_VERIFICATION_MAX_ATTEMPTS=5      # Max 5 attempts total
EMAIL_VERIFICATION_CLEANUP_DAYS=30     # Clean old entries after 30 days
```

## Security Features

1. **User Enumeration Prevention:**
   - Same success message for non-existent/verified users
   - Rate limiting applied to ALL emails, not just valid ones

2. **Spam Prevention:**
   - Cooldown applies to EVERY request
   - Maximum attempts limit
   - IP tracking (logged for abuse detection)

3. **Error Handling:**
   - No technical details exposed to frontend
   - All errors logged in backend
   - Generic messages for users

## Database Schema

### BEMAILVERIFICATION Table
```sql
BID (int) - Primary Key
BEMAIL (string) - Email address (indexed, unique)
BATTEMPTS (int) - Number of attempts (default: 1)
BLASTATTEMPTAT (datetime) - Last attempt timestamp
BCREATEDAT (datetime) - First attempt timestamp
BIPADDRESS (string) - User IP address
```

## Testing

### Test Scenario 1: Normal Flow
1. User requests resend → 200 OK (email sent)
2. User requests resend again immediately → 429 (cooldown active)
3. User waits 2 minutes → 200 OK (email sent)

### Test Scenario 2: Max Attempts
1. User requests resend 5 times (with 2 min waits) → 200 OK each time
2. 6th attempt → 429 (max attempts reached)

### Test Scenario 3: Technical Error
1. Mail server down
2. User requests resend → 500 (technical error)
3. User can retry after fixing mail server

### Test Scenario 4: Non-existent User
1. User enters wrong email
2. Request → 200 OK (generic message, no email sent)
3. Rate limit still applies to this email

