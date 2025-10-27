# HTML File Sanitization Security Feature

## Overview
This security feature prevents uploaded HTML/HTM files from being served as malicious landing pages by automatically converting them to plain text files.

## What It Does
1. **Detects HTML uploads**: Identifies `.html` and `.htm` files during upload
2. **Strips all HTML tags**: Removes all HTML markup using `strip_tags()`
3. **Converts to plain text**: Saves the content as a `.txt` file instead
4. **Preserves content**: Maintains the text content for vectorization and RAG

## Implementation Details

### Core Sanitization Function
**Location**: `/app/inc/support/_central.php`
- `Central::sanitizeHtmlUpload()` - Strips HTML and converts to plain text
- Decodes HTML entities
- Cleans excessive whitespace
- Returns sanitized content ready for saving

### Protected Upload Locations

1. **Chat File Uploads** (`/app/inc/_frontend.php`)
   - Line 290-314: Sanitizes HTML before saving

2. **RAG File Manager** (`/app/inc/domain/files/filemanager.php`)
   - Line 131-155: Sanitizes HTML for document vectorization

3. **Gmail Attachments** (`/app/inc/mail/_myGMail.php`)
   - Line 406-428: Sanitizes HTML attachments from emails

4. **OpenAI API Uploads** (`/app/inc/api/_openaiapi.php`)
   - Line 416-448: Sanitizes HTML files uploaded via API

5. **WhatsApp Webhook** (`/public/webhookwa.php`)
   - Line 377-389: Post-processes downloaded HTML files

## Security Benefits

✅ **Prevents phishing attacks**: HTML files can't be served as fake login pages  
✅ **Blocks XSS attacks**: JavaScript in HTML files is completely stripped  
✅ **Stops malicious redirects**: No HTML means no meta redirects or iframes  
✅ **Maintains functionality**: Text content is preserved for RAG/vectorization  
✅ **User-transparent**: Automatic conversion without user interaction required

## File Type Mapping

After sanitization:
- **Original**: `document.html` → **Saved as**: `document.txt`
- **Original**: `page.htm` → **Saved as**: `page.txt`
- **File extension in DB**: Changed to `'txt'`
- **MIME type**: Changed to `'text/plain'`
- **BRAG table type**: Type 5 (txt) instead of Type 6 (html)

## How It Works

```
1. User uploads "malicious.html"
   ↓
2. System detects HTML file extension
   ↓
3. sanitizeHtmlUpload() is called
   ↓
4. HTML tags are stripped: <script>alert('xss')</script> → removed
   ↓
5. Text content is extracted and cleaned
   ↓
6. File is saved as "malicious.txt"
   ↓
7. Database records it as a txt file (BTYPE=5)
   ↓
8. File is vectorized and searchable as text
```

## Testing

To verify the security feature is working:

1. Try uploading an HTML file with this content:
```html
<html>
<head><title>Test</title></head>
<body>
<h1>Header</h1>
<p>This is a test paragraph.</p>
<script>alert('This should be removed');</script>
</body>
</html>
```

2. Expected result:
   - File is saved with `.txt` extension
   - Content contains: "Test Header This is a test paragraph."
   - No HTML tags or JavaScript present
   - File can be vectorized and searched

## Notes

- Markdown files (`.md`) are **NOT** sanitized - they remain as-is since they don't pose the same security risk
- The feature is active for all upload methods (chat, email, API, WhatsApp, file manager)
- Original filename is preserved but with `.txt` extension
- No performance impact - sanitization happens inline during upload

## Updated: 2025-10-06

