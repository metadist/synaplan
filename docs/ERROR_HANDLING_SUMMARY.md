# WhatsApp Error Handling Implementation Summary

## Overview

Comprehensive error handling has been added to the WhatsApp messaging pipeline to catch, log, and report any issues that prevent message delivery.

## Implementation Date

October 26, 2025

## Changes Made

### 1. `/app/inc/support/_tools.php` - Formatting Error Handling

**Function:** `Tools::formatForWhatsApp()`

**Error Handling Added:**
- Try/catch wrapper around entire function
- Input validation (empty check)
- Message length validation (4096 char WhatsApp limit)
- Automatic truncation for long messages
- Graceful fallback to original text on error
- Error logging for debugging

**Example Error:**
```
formatForWhatsApp: Message exceeds WhatsApp 4096 char limit (5234 chars)
```

### 2. `/app/inc/integrations/_wasender.php` - API Error Handling

**Methods Updated:**
- `sendText()`
- `sendImage()`
- `sendDoc()`
- `sendAudio()`

**Error Handling Added:**
- Try/catch around all WhatsApp API calls
- Detailed error messages with phone number context
- API response logging in debug mode
- Error re-throwing with enhanced context
- Success logging when debug enabled

**Example Error:**
```
WhatsApp API Error (sendText to 491234567890): Message rejected by WhatsApp
```

### 3. `/public/outprocessor.php` - Complete Flow Error Handling

**Error Handling Added:**
- Master try/catch wrapper around entire WhatsApp section
- Nested try/catch for formatting (non-critical)
- Error notification sent to user via WhatsApp
- Complete error details logged
- Message IDs included for tracking
- User-friendly error reporting

**User Error Notification Format:**
```
⚠️ *Error Sending Message*

Sorry, there was an error delivering your response.

*Error Details:*
```[Error message up to 200 chars]```

Please email this error to:
team@synaplan.net

_Message ID: 12345 | Answer ID: 67890_
```

## Error Detection Levels

```
┌─────────────────────────────────────────────┐
│  1. FORMATTING LEVEL                        │
│     Tools::formatForWhatsApp()              │
│     - Regex errors                          │
│     - Encoding issues                       │
│     - Length validation                     │
│     └─ Fallback: Use original text         │
└─────────────────────────────────────────────┘
                    ↓
┌─────────────────────────────────────────────┐
│  2. API LEVEL                               │
│     waSender::sendText/Image/Audio/Doc()    │
│     - Network errors                        │
│     - Authentication failures               │
│     - Content policy violations             │
│     - Rate limiting                         │
│     └─ Throw exception with context        │
└─────────────────────────────────────────────┘
                    ↓
┌─────────────────────────────────────────────┐
│  3. DELIVERY LEVEL                          │
│     outprocessor.php                        │
│     - Database errors                       │
│     - Configuration missing                 │
│     - Complete flow failures                │
│     └─ Send error notification to user     │
└─────────────────────────────────────────────┘
```

## Benefits

### For Users
- **Immediate Feedback**: Users know when something went wrong
- **Actionable Information**: Error details help diagnose issues
- **Support Path**: Clear instructions to email team@synaplan.net
- **Context**: Message IDs included for tracking

### For Developers
- **Full Visibility**: All errors logged with stack traces
- **Debug Mode**: Detailed logging when enabled
- **Error Context**: Phone numbers, message IDs, file/line numbers
- **API Responses**: WhatsApp API errors captured in detail

### For Support Team
- **Error Reports**: Users forward errors to team@synaplan.net
- **Message IDs**: Easy to track specific issues in database
- **Full Logs**: Complete error information in system logs
- **Pattern Detection**: Can identify systematic issues (e.g., content filtering)

## Testing the Error Handling

### Simulate an Error

To test error notifications, you can temporarily add a test exception in `outprocessor.php`:

```php
// After line 115 (creating waSender), add:
throw new Exception("TEST ERROR - This is a test of error handling");
```

The user should receive the error notification via WhatsApp.

### Check Logs

Error logs location (typical Linux setup):
- **PHP Error Log**: `/var/log/php/error.log` or `/var/log/apache2/error.log`
- **Custom Debug Log**: `/wwwroot/synaplan/public/debug_websearch.log` (when DEBUG=true)

### Debug Mode

Enable in `.env` or environment:
```bash
APP_DEBUG=true
```

This will log:
- Every successful message send
- API responses
- Formatting details
- Service and model information

## Common Error Scenarios

### 1. WhatsApp Content Policy Violation

**Symptom:** User asks about LLMs or AI models, no response received

**Error Message (in logs):**
```
WhatsApp API Error (sendText to 491234567890): Content policy violation
```

**User Sees:**
```
⚠️ Error Sending Message
Error Details:
```Content policy violation```
Please email to team@synaplan.net
```

**Solution:** Review WhatsApp Business API content policies

### 2. Message Too Long

**Symptom:** Long AI responses truncated

**Error Message (in logs):**
```
formatForWhatsApp: Message exceeds WhatsApp 4096 char limit (5234 chars)
```

**User Sees:** Message with `... _(message truncated - too long)_` at end

**Solution:** Message automatically truncated, no action needed

### 3. Network/API Timeout

**Symptom:** Intermittent message delivery failures

**Error Message (in logs):**
```
WhatsApp API Error (sendText to 491234567890): cURL error 28: Operation timed out
```

**User Sees:** Error notification with timeout details

**Solution:** Check network connectivity, WhatsApp API status

### 4. Rate Limiting

**Symptom:** Multiple messages fail in succession

**Error Message (in logs):**
```
WhatsApp API Error (sendText to 491234567890): Rate limit exceeded
```

**User Sees:** Error notification with rate limit message

**Solution:** Reduce message frequency, upgrade WhatsApp API tier

## Monitoring

### What to Monitor

1. **Error Rate**: Count of error notifications sent
2. **Error Types**: Categories of errors (API, formatting, config)
3. **User Reports**: Emails to team@synaplan.net
4. **Success Rate**: Ratio of successful vs. failed sends

### Log Analysis

Search logs for WhatsApp errors:
```bash
grep "WhatsApp API Error" /var/log/php/error.log
grep "outprocessor.php WhatsApp ERROR" /var/log/php/error.log
grep "formatForWhatsApp ERROR" /var/log/php/error.log
```

## Support Workflow

When user emails error to team@synaplan.net:

1. **Extract Message IDs** from error message
2. **Check Database**:
   ```sql
   SELECT * FROM BMESSAGES WHERE BID = [msgId];
   SELECT * FROM BMESSAGES WHERE BID = [aiLastId];
   ```
3. **Check Logs** for matching error entries
4. **Review Content** for policy violations
5. **Respond to User** with findings and resolution

## Files Modified

- `/app/inc/support/_tools.php` - Added error handling to formatForWhatsApp()
- `/app/inc/integrations/_wasender.php` - Added try/catch to all send methods
- `/public/outprocessor.php` - Added master error handler and user notifications
- `/docs/WHATSAPP_FORMATTING.md` - Updated with error handling documentation

## Future Enhancements

Potential improvements:

1. **Error Dashboard**: Web interface showing error statistics
2. **Automatic Retry**: Retry failed sends with exponential backoff
3. **Error Categories**: Classify errors for better reporting
4. **User Preferences**: Let users opt-in/out of error notifications
5. **Circuit Breaker**: Pause sends if error rate exceeds threshold

