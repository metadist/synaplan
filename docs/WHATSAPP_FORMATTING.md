# WhatsApp Message Formatting

## Overview

WhatsApp messages are now automatically formatted to display properly in the WhatsApp client. The system converts standard markdown formatting to WhatsApp-compatible formatting.

## Implementation

### Core Function

The formatting is handled by `Tools::formatForWhatsApp()` in `/app/inc/support/_tools.php`.

### Integration Points

The formatting is automatically applied at these locations:

1. **Main AI Responses** (`/public/outprocessor.php` line 76)
   - All AI-generated responses sent via WhatsApp are formatted before sending
   - Applies to text messages, image captions, and document captions

2. **Rate Limit Messages** (`/public/webhookwa.php` line 134)
   - Rate limiting notifications are formatted for better readability

## Formatting Conversions

| Markdown | WhatsApp | Example |
|----------|----------|---------|
| `**bold**` | `*bold*` | This is *bold* text |
| `_italic_` | `_italic_` | This is _italic_ text |
| `~~strike~~` | `~strike~` | This is ~struck~ text |
| `` `code` `` | ` ```code``` ` | Use ```print()``` function |
| `[text](url)` | `text (url)` | Visit Google (https://google.com) |
| `# Header` | `*Header*` | *Important Section* |
| `- item` | `• item` | • List item |
| ` ```code block``` ` | ` ```code block``` ` | (unchanged) |

## Supported Features

✅ **Bold text** conversion  
✅ **Italic text** (already compatible)  
✅ **Strikethrough** conversion  
✅ **Inline code** conversion  
✅ **Code blocks** (native WhatsApp support)  
✅ **Links** converted to readable format  
✅ **Headers** converted to bold  
✅ **Bullet lists** converted to bullets  
✅ **Numbered lists** preserved  
✅ **HTML tag removal** for safety  
✅ **UTF-8 encoding** ensured  
✅ **Excessive newlines** limited (max 2 consecutive)  

## Example

### Before Formatting (Markdown)
```
**Important Update**

Here's what you need to know:

1. Check your `config.json` file
2. Visit [our docs](https://example.com/docs)
3. The old API is ~~deprecated~~ removed

## Next Steps

Run this command:
```bash
npm install --save
```

Contact us if you need help!
```

### After Formatting (WhatsApp)
```
*Important Update*

Here's what you need to know:
1. Check your ```config.json``` file
2. Visit our docs (https://example.com/docs)
3. The old API is ~deprecated~ removed

*Next Steps*

Run this command:
```bash
npm install --save
```

Contact us if you need help!
```

## Technical Details

- **Function:** `Tools::formatForWhatsApp(string $text): string`
- **Location:** `/app/inc/support/_tools.php`
- **Processing:** Applied before message is sent to WhatsApp API
- **Scope:** All outgoing WhatsApp messages (text, captions, notifications)

## Notes

- The formatting preserves WhatsApp's native capabilities while improving readability
- Code blocks with triple backticks are kept as-is (WhatsApp supports them natively)
- Links are converted to inline format with URL in parentheses
- HTML tags are stripped for security and compatibility
- The function is idempotent - can be safely called multiple times on the same text

## Error Handling

The WhatsApp message flow now includes comprehensive error handling to catch and report issues:

### Error Detection

Errors are caught at multiple levels:

1. **Formatting Errors** (`Tools::formatForWhatsApp()`)
   - Input validation
   - Regex processing errors
   - UTF-8 encoding issues
   - Message length validation (4096 char limit)
   - Falls back to original text if formatting fails

2. **WhatsApp API Errors** (`waSender` class)
   - Network/connection errors
   - Authentication issues
   - Content policy violations
   - Rate limiting
   - Media upload failures

3. **Delivery Errors** (`outprocessor.php`)
   - Database lookup failures
   - Missing configuration
   - Complete message flow errors

### User Notification

If an error occurs while sending a WhatsApp message, the user receives:

```
⚠️ *Error Sending Message*

Sorry, there was an error delivering your response.

*Error Details:*
```[error message snippet]```

Please email this error to:
team@synaplan.net

_Message ID: [msgId] | Answer ID: [aiLastId]_
```

### Error Logging

All errors are logged to the system error log with:
- Full error message
- Stack trace
- File and line number
- WhatsApp API response (if available)
- Message IDs for tracking

### Debugging

To enable detailed logging, set `APP_DEBUG=true` in your environment. This logs:
- Successful message sends
- API responses
- Message formatting details
- Service and model information

## Maintenance

To modify the formatting rules, edit the `formatForWhatsApp()` function in `/app/inc/support/_tools.php`.

## Troubleshooting

### No Response to Messages

If messages are sent but no response is received:

1. **Check Error Logs**: Look for errors in system logs
2. **Enable Debug Mode**: Set `APP_DEBUG=true` to see detailed flow
3. **Check WhatsApp Status**: Verify WhatsApp Business API is operational
4. **Review Error Messages**: If user received error notification, check team@synaplan.net inbox

### Common Issues

- **Message Too Long**: Messages over 4096 characters are automatically truncated
- **Content Policy**: WhatsApp may filter certain content (check error notification)
- **Rate Limiting**: Too many messages in short time may be throttled
- **Invalid Formatting**: Falls back to plain text automatically

