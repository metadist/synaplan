# WhatsApp Response Routing Bug Fix

## Problem Description

**Bug**: WhatsApp responses were being sent to the wrong person. In marketing mode, all messages are processed by user ID 2 (platform owner), but responses were being sent to user ID 2's WhatsApp number instead of to the original sender's phone number.

**User Report**: "The friend asks questions and I get the answers. That is not sending the answer to the right person!"

## Root Cause Analysis

### The Design (Marketing Mode)
- WhatsApp is a marketing feature - no new user creation
- All incoming WhatsApp messages are assigned `BUSERID = 2` (platform owner with configured prompts)
- Responses should go back to **the original sender's phone**, not to user ID 2's phone

### The Bug Flow

1. **In `webhookwa.php`**:
   - Friend's message arrives from phone `+1234567890`
   - Message is assigned `BUSERID = 2` ✅ (correct - marketing mode)
   - Message is saved to database
   - **BUT**: Sender's phone number was NOT stored anywhere ❌

2. **In `outprocessor.php`** (lines 37, 120-137):
   - AI generates response for message
   - Gets user by `BUSERID` (user ID 2)
   - **BUG**: Sends to `$usrArr['BPROVIDERID']` (user ID 2's phone) ❌
   - Result: Friend's answer goes to user ID 2's phone!

### Why It Happened
The `BMESSAGES` table doesn't have a field for the sender's phone number. It has:
- `BUSERID` - which user owns the message (user ID 2 for marketing)
- `BPROVIDX` - WhatsApp message ID (not the phone number)
- But no field for the actual sender's phone!

## The Fix

### Solution: Store Sender Phone in BMESSAGEMETA

Since the sender's phone isn't in the main message record, we store it in the metadata table.

### Changes Made

#### 1. In `webhookwa.php` (lines 163-169)
**Store the sender's phone number in metadata:**

```php
// Store the sender's phone number in BMESSAGEMETA for outprocessor to use
// This is critical for marketing mode where BUSERID=2 but we need to reply to the actual sender
if ($msgDBID > 0 && isset($message['from'])) {
    $senderPhone = db::EscString($message['from']);
    $metaSQL = "INSERT INTO BMESSAGEMETA (BID, BMESSID, BTOKEN, BVALUE) VALUES (DEFAULT, {$msgDBID}, 'SENDER_PHONE', '{$senderPhone}')";
    db::Query($metaSQL);
}
```

#### 2. In `outprocessor.php` (lines 113-125)
**Retrieve the sender's phone from metadata:**

```php
// Get the actual sender's phone number from BMESSAGEMETA
// This is needed for marketing mode where BUSERID=2 but we reply to the original sender
$recipientPhone = $usrArr['BPROVIDERID']; // Default to user's provider ID

$senderPhoneSQL = 'SELECT BVALUE FROM BMESSAGEMETA WHERE BMESSID = ' . intval($msgId) . " AND BTOKEN = 'SENDER_PHONE' LIMIT 1";
$senderPhoneRes = db::Query($senderPhoneSQL);
if ($senderPhoneRow = db::FetchArr($senderPhoneRes)) {
    $recipientPhone = $senderPhoneRow['BVALUE']; // Use the original sender's phone
    if (!empty($GLOBALS['debug'])) {
        error_log("Outprocessor: Using sender phone from meta: {$recipientPhone} (instead of user's BPROVIDERID: {$usrArr['BPROVIDERID']})");
    }
}
```

#### 3. In `outprocessor.php` (lines 134-156)
**Use `$recipientPhone` instead of `$usrArr['BPROVIDERID']`:**

```php
// All sending now uses $recipientPhone:
$myRes = $waSender->sendImage($recipientPhone, $aiAnswer);
$myRes = $waSender->sendAudio($recipientPhone, $aiAnswer);
$myRes = $waSender->sendText($recipientPhone, $aiAnswer['BTEXT']);
```

#### 4. In `outprocessor.php` (line 186)
**Error notifications also use the correct recipient:**

```php
if (isset($waDetailsArr) && isset($recipientPhone)) {
    $errorSender = new waSender($waDetailsArr);
    $errorSender->sendText($recipientPhone, $errorNotification);
}
```

## How It Works Now

### Marketing Mode Flow (User ID 2)

1. **Friend sends message** from `+1234567890`:
   ```
   BMESSAGES:
     BUSERID = 2 (marketing mode)
     BTEXT = "Friend's question"
   
   BMESSAGEMETA:
     BTOKEN = 'SENDER_PHONE'
     BVALUE = '+1234567890'  ← Stored here!
   ```

2. **AI processes** using user ID 2's prompts

3. **Outprocessor sends response**:
   - Looks up `SENDER_PHONE` from metadata = `+1234567890`
   - Sends response to `+1234567890` ✅ (friend gets it!)

### Regular User Mode (BUSERID ≠ 2)

If a registered user sends a WhatsApp message:
- `BUSERID` = their actual user ID
- `SENDER_PHONE` metadata still stores their phone
- `$recipientPhone` defaults to `$usrArr['BPROVIDERID']` if no metadata
- Works for both modes!

## Benefits

1. **Fixes Response Routing**: Each sender gets their own response, regardless of which user processes it
2. **Preserves Marketing Mode**: User ID 2 processes all WhatsApp messages as intended
3. **Backward Compatible**: Falls back to `$usrArr['BPROVIDERID']` if no metadata exists
4. **Better Debugging**: Debug logs show which phone is being used

## Testing

### Test Case 1: Two Different Senders
```
1. Friend A (+111111) sends: "Hello from A"
2. Friend B (+222222) sends: "Hello from B"
3. Verify Friend A receives response to their question
4. Verify Friend B receives response to their question
```

### Test Case 2: Verify Marketing Mode
```sql
-- Both messages should be assigned to user ID 2
SELECT BID, BUSERID, BTEXT FROM BMESSAGES 
WHERE BMESSTYPE = 'WA' AND BDIRECT = 'IN' 
ORDER BY BID DESC LIMIT 10;

-- Should show BUSERID = 2 for both
```

### Test Case 3: Check Metadata
```sql
-- Verify sender phones are stored
SELECT m.BID, m.BTEXT, meta.BVALUE as SENDER_PHONE
FROM BMESSAGES m
JOIN BMESSAGEMETA meta ON m.BID = meta.BMESSID
WHERE meta.BTOKEN = 'SENDER_PHONE'
ORDER BY m.BID DESC LIMIT 10;
```

## Files Modified

1. `/wwwroot/synaplan/public/webhookwa.php` (lines 163-169)
   - Added storage of sender phone in BMESSAGEMETA

2. `/wwwroot/synaplan/public/outprocessor.php` (lines 113-186)
   - Retrieves sender phone from metadata
   - Uses sender phone for all WhatsApp sends
   - Uses sender phone for error notifications

## Date Fixed

October 26, 2025

## Related Tables

- `BMESSAGES` - Main message table (no sender phone field)
- `BMESSAGEMETA` - Message metadata (BTOKEN='SENDER_PHONE', BVALUE=phone number)
- `BWAIDS` - WhatsApp phone IDs for outbound sending
- `BUSER` - User table (BPROVIDERID contains user's own phone, not sender's)

