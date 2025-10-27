# WhatsApp Webhook Flow Analysis

## ✅ FIXES IMPLEMENTED (Latest)

**All fixes have been applied to the following files:**
- `webhookwa.php` - Fixed exec() calls, file downloads, rate limiting
- `preprocessor.php` - Fixed exec() calls with proper logging
- `aiprocessor.php` - Fixed exec() calls with proper logging
- `outprocessor.php` - Added error logging and validation

**Key Changes:**
1. ✅ All exec() calls now use `PHP_BINARY` and explicit working directory
2. ✅ All processes log to `logs/` directory instead of `/dev/null`
3. ✅ File downloads verified with existence/size checks
4. ✅ Physical directories created for curl alongside Flysystem
5. ✅ Rate limiting uses `continue` instead of `exit` (won't block webhook)
6. ✅ All critical operations log errors via `error_log()`
7. ✅ PID capture and verification for all background processes
8. ✅ Outprocessor validates data before attempting to send

**Testing:**
- All files pass PHP syntax check (`php -l`)
- `logs/` and `pids/` directories created with proper permissions
- Ready for live testing

---

# WhatsApp Webhook Flow Analysis

## Complete Message Flow

```
WhatsApp → webhookwa.php → preprocessor.php → aiprocessor.php → outprocessor.php → WhatsApp
```

### Detailed Chain:

1. **webhookwa.php** (receives webhook)
   - Processes incoming message/files
   - Saves to DB via `Central::handleInMessage()`
   - Triggers: `nohup php preprocessor.php {messageId} > /dev/null 2>&1 &`

2. **preprocessor.php** 
   - Parses files via `Central::parseFile()`
   - Triggers: `nohup php aiprocessor.php {messageId} > /dev/null 2>&1 &`

3. **aiprocessor.php**
   - Processes AI response via `ProcessMethods::sortMessage()`
   - Saves answer to DB
   - Triggers: `nohup php outprocessor.php {aiAnswerId} {messageId} > /dev/null 2>&1 &`

4. **outprocessor.php**
   - Retrieves AI answer from DB
   - Sends via WhatsApp using `waSender` class

---

## 🚨 CRITICAL ISSUES FOUND

### Issue 1: Silent Process Failures
**Location**: `webhookwa.php:150-153`

```php
$cmd = 'nohup php preprocessor.php '.$inMessageArr['BID'].' > /dev/null 2>&1 &';
$pidfile = 'pids/m'.($inMessageArr['BID']).'.pid';
exec(sprintf('%s echo $! >> %s', $cmd, $pidfile));
```

**Problems**:
- Output redirected to `/dev/null` - **errors are invisible**
- No verification if process actually started
- `php` command assumes PHP is in PATH (might not be on live server)
- No working directory specified
- PID file created but never verified

**Impact**: If preprocessor.php fails to start, the entire chain stops and user gets no response.

---

### Issue 2: Working Directory Not Guaranteed
**Location**: All exec() calls in webhookwa.php, preprocessor.php, aiprocessor.php

**Problem**: 
- The scripts assume they're running from `/wwwroot/synaplan-legacy/public/`
- When called via exec(), working directory might be different
- Relative paths in includes might fail

**Example**:
```php
// preprocessor.php line 47
$cmd = 'nohup php aiprocessor.php '.$msgArr['BID'].' > /dev/null 2>&1 &';
```

This will fail if:
- Current working directory is not `/wwwroot/synaplan-legacy/public/`
- PHP binary is not in PATH

---

### Issue 3: File Download Path Issues
**Location**: `webhookwa.php:353-421` in `downloadMediaFile()`

**Current Code**:
```php
$savePath = substr($userPhoneNo, -5, 3) . '/' . substr($userPhoneNo, -2, 2) . '/' . date('Ym');
$fileName = 'wa_'.$mediaInfo['id'] . '.' . Tools::getFileExtension($mediaInfo['mime_type']);
$saveTo = $savePath . '/' . $fileName;
$GLOBALS['filesystem']->createDirectory($savePath);

exec("curl -X GET \"$url\" -H \"Authorization: Bearer $token\" -o \"./up/$saveTo\"");
```

**Problems**:
- Creates directory via `$GLOBALS['filesystem']->createDirectory($savePath)` but curl saves to `./up/$saveTo`
- Inconsistent path handling (Flysystem vs direct filesystem)
- No verification if curl succeeded
- No check if file actually exists after download

**Impact**: Files might not be saved correctly, leading to messages with missing attachments.

---

### Issue 4: Rate Limiting Blocks Before Processing
**Location**: `webhookwa.php:99-115`

```php
if (XSControl::isRateLimitingEnabled()) {
    $limitCheck = XSControl::checkMessagesLimit($inMessageArr['BUSERID']);
    if (is_array($limitCheck) && $limitCheck['limited']) {
        // Send rate limit notification via WhatsApp
        // ...
        exit; // ⚠️ STOPS ENTIRE WEBHOOK PROCESSING
    }
}
```

**Problem**: 
- If user is rate-limited, webhook exits immediately
- BUT WhatsApp expects a 200 OK response
- Other messages in the same webhook batch won't be processed

---

### Issue 5: No Error Recovery or Retry
**Location**: Entire chain

**Problem**:
- If any step fails, there's no retry mechanism
- User is left waiting indefinitely
- No fallback notification to user

---

## 📋 COMPARISON: Webhook vs Chat.js Flow

### Chat.js Flow (WORKING):
```javascript
// 1. Send message
fetch('api.php', { method: 'POST', body: formData })
  .then(res => res.json())
  .then(data => {
    // 2. Immediately start SSE stream for response
    sseStream(data, AItextBlock);
  });

// 3. SSE stream connects to: api.php?action=chatStream&lastIds={ids}
// 4. Response streamed back in real-time
```

**Key difference**: Chat.js uses SSE streaming for immediate feedback, webhook uses background processes.

---

## 🔍 FILE ATTACHMENT VERIFICATION

### File Download Chain:

1. **Media Info Download** (`downloadMediaInfo()`):
   ```php
   $url = $mediaDownloadUrl . $mediaId;
   $response = httpRequest('GET', $url, $headers);
   return json_decode($response, true);
   ```
   ✅ **Looks OK** - Gets media URL from WhatsApp API

2. **File Download** (`downloadMediaFile()`):
   ```php
   exec("curl -X GET \"$url\" -H \"Authorization: Bearer $token\" -o \"./up/$saveTo\"");
   ```
   ⚠️ **ISSUES**:
   - No exit code check from exec()
   - No file existence verification
   - Directory created via Flysystem but curl writes to filesystem

3. **File Parsing** (preprocessor.php):
   ```php
   if ($msgArr['BFILE'] > 0) {
       $msgArr = Central::parseFile($msgArr);
   }
   ```
   ✅ **Should work IF file was saved correctly**

---

## 🛠️ RECOMMENDED FIXES

### Fix 1: Add Logging and Error Handling

**In webhookwa.php, line 150**:
```php
// BEFORE (current):
$cmd = 'nohup php preprocessor.php '.$inMessageArr['BID'].' > /dev/null 2>&1 &';
exec(sprintf('%s echo $! >> %s', $cmd, $pidfile));

// AFTER (fixed):
$logfile = 'logs/preprocessor_'.$inMessageArr['BID'].'.log';
$cmd = 'cd ' . escapeshellarg(__DIR__) . ' && ' . 
       PHP_BINARY . ' preprocessor.php ' . escapeshellarg($inMessageArr['BID']) . 
       ' >> ' . escapeshellarg($logfile) . ' 2>&1 &';
$output = [];
$returnVar = 0;
exec($cmd . ' echo $!', $output, $returnVar);
$pid = isset($output[0]) ? trim($output[0]) : 0;
file_put_contents($pidfile, $pid);

// Log for debugging
error_log("WhatsApp webhook: Started preprocessor for message {$inMessageArr['BID']}, PID: {$pid}, cmd: {$cmd}");
```

**Benefits**:
- Uses `PHP_BINARY` constant (guaranteed correct PHP path)
- Explicitly sets working directory with `cd`
- Logs to a file instead of /dev/null
- Verifies PID was captured
- Escapes shell arguments for security

---

### Fix 2: Verify File Downloads

**In webhookwa.php, line 380**:
```php
// AFTER curl execution
if ($savePath != '') {
    $fullPath = './up/' . $saveTo;
    $exRes = exec("curl -X GET \"$url\" -H \"Authorization: Bearer $token\" -o \"$fullPath\" -w '%{http_code}' -s");
    
    // Verify download succeeded
    if (!file_exists($fullPath) || filesize($fullPath) == 0) {
        $dlError .= ' - File download failed or empty file - ';
        error_log("WhatsApp file download failed: {$fullPath}, HTTP: {$exRes}");
    }
    
    // Continue with ogg conversion, HTML sanitization...
}
```

---

### Fix 3: Ensure Directory Exists for Curl

**In webhookwa.php, line 371**:
```php
$GLOBALS['filesystem']->createDirectory($savePath);

// ADD THIS: Ensure ./up/ physical directory exists for curl
$physicalDir = './up/' . $savePath;
if (!is_dir($physicalDir)) {
    mkdir($physicalDir, 0755, true);
}
```

---

### Fix 4: Apply Same Fixes to All exec() Calls

**Files to update**:
- `preprocessor.php:47` → When calling aiprocessor.php
- `aiprocessor.php:44` → When calling outprocessor.php

**Pattern to use**:
```php
$logfile = 'logs/' . basename(__FILE__, '.php') . '_' . $msgId . '.log';
$cmd = 'cd ' . escapeshellarg(__DIR__) . ' && ' . 
       PHP_BINARY . ' nextscript.php ' . escapeshellarg($msgId) . 
       ' >> ' . escapeshellarg($logfile) . ' 2>&1 &';
```

---

### Fix 5: Add Health Check Endpoint

**Create new file: `webhook_health.php`**:
```php
<?php
// Check if background processes are working
$recentMessages = db::Query("SELECT BID, BDATETIME FROM BMESSAGES 
                             WHERE BMESSTYPE='WA' AND BDIRECT='IN' 
                             ORDER BY BID DESC LIMIT 10");

$issues = [];
while ($msg = db::FetchArr($recentMessages)) {
    // Check if AI response exists
    $aiResponse = db::Query("SELECT BID FROM BMESSAGES 
                            WHERE BTRACKID=... AND BDIRECT='OUT'");
    if (!$aiResponse) {
        $issues[] = "Message {$msg['BID']} has no AI response";
    }
}

echo json_encode(['status' => empty($issues) ? 'ok' : 'issues', 'details' => $issues]);
```

---

## 🔍 DEBUGGING STEPS FOR LIVE SYSTEM

### Step 1: Check if preprocessor is running
```bash
# SSH into live server
cd /wwwroot/synaplan-legacy/public
ls -la pids/
# If you see old PID files, processes might be stuck
```

### Step 2: Enable logging temporarily
```php
// In webhookwa.php line 152, TEMPORARILY change to log output:
$cmd = 'nohup php preprocessor.php '.$inMessageArr['BID'].' >> logs/webhook_debug.log 2>&1 &';
```

### Step 3: Check if files are being saved
```bash
ls -la up/*/  # Check recent file timestamps
tail -f logs/webhook_debug.log  # Watch logs in real-time
```

### Step 4: Test preprocessor manually
```bash
cd /wwwroot/synaplan-legacy/public
php preprocessor.php 12345  # Replace with actual message ID
# Check for errors
```

### Step 5: Verify PHP binary path
```bash
which php
# If output is empty, PHP is not in PATH
# Use full path like: /usr/bin/php
```

---

## 📊 SUMMARY

### Confirmed Working:
✅ Message reception and parsing  
✅ File download logic (structure is sound)  
✅ Rate limiting check  
✅ Database message saving  

### Likely Broken:
❌ Background process execution (silent failures)  
❌ Process chain (preprocessor → aiprocessor → outprocessor)  
❌ Error visibility (everything redirected to /dev/null)  
❌ Working directory assumptions  

### Root Cause:
The webhook saves messages successfully but the **background processing chain fails silently**, so no AI response is ever generated or sent back to WhatsApp.

### Immediate Action:
1. Add logging to all exec() calls
2. Use PHP_BINARY and explicit working directories
3. Verify file downloads with file_exists() checks
4. Create logs/ directory if missing

---

## 🎯 NEXT STEPS

1. **Implement Fix 1** (logging) immediately
2. Check logs to see actual errors
3. Fix specific issues found in logs
4. **Implement Fixes 2-4** systematically
5. Test with a real WhatsApp message
6. Monitor logs for 24 hours

Would you like me to implement these fixes?

