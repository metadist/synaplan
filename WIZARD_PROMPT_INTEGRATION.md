# Widget Wizard - Automatic Prompt Configuration

## Overview
When users upload RAG files through the Widget Setup Wizard, the system now intelligently manages the selected AI prompt's file search configuration to ensure the uploaded files are accessible.

## How It Works

### Step-by-Step Flow

1. **User Selects Prompt** (Step 1)
   - User chooses an AI prompt from the dropdown (e.g., "general", "support", etc.)

2. **User Uploads Files** (Step 2)
   - User optionally uploads RAG files with a group key (e.g., "WIDGET_KB")

3. **Automatic Prompt Configuration** (Background Magic)
   - System checks if the selected prompt has file search (`tool_files`) enabled
   - **If File Search is NOT enabled:**
     - Shows confirmation dialog: "Would you like to enable File Search on this prompt?"
     - If user confirms:
       - Creates a user-specific copy of the prompt (if it's a default prompt)
       - Enables `tool_files = 1` in BPROMPTMETA
       - Sets `tool_files_keyword = WIDGET_KB` (or user's group key)
   - **If File Search IS enabled but different filter:**
     - Shows confirmation: "Would you like to change the filter to use your newly uploaded files?"
     - If user confirms:
       - Updates `tool_files_keyword` to the new group key

4. **Files Are Uploaded**
   - Files are processed and vectorized as usual

5. **Widget Created**
   - Widget is configured with the updated prompt
   - Integration code is displayed

## Database Changes

### BPROMPTS Table
When a default prompt needs file search enabled:
- Creates a new row with `BOWNERID = {userId}` (user-specific copy)
- Copies all data from default prompt (BOWNERID = 0)

### BPROMPTMETA Table
The wizard ensures ALL required settings are present (matching c_prompts.php behavior):

```
BPROMPTID | BTOKEN              | BVALUE
----------|---------------------|----------
{id}      | aiModel             | -1 (or existing)
{id}      | tool_internet       | 0 (or existing)
{id}      | tool_files          | 1 (ENABLED)
{id}      | tool_screenshot     | 0 (or existing)
{id}      | tool_transfer       | 0 (or existing)
{id}      | tool_files_keyword  | WIDGET_KB
{id}      | tool_screenshot_x   | (preserved if exists)
{id}      | tool_screenshot_y   | (preserved if exists)
```

**Key Points:**
- Uses `INSERT ... ON DUPLICATE KEY UPDATE` to safely update or insert
- Preserves all existing tool settings (doesn't overwrite other configurations)
- Sets all required fields to ensure prompt works correctly
- Defaults to aiModel = -1 (automatic), all tools off except file search

## API Endpoints Added

### 1. `enablePromptFileSearch`
**Purpose:** Enable file search on a prompt and set group filter

**Parameters:**
- `promptKey`: string (e.g., "general")
- `groupKey`: string (e.g., "WIDGET_KB")

**Logic:**
1. Fetches the prompt (default or user-specific)
2. If default (BOWNERID = 0):
   - Checks if user already has custom copy
   - If not, creates user-specific copy
   - Copies all BPROMPTMETA settings from default
3. Enables file search (`tool_files = 1`)
4. Sets file group filter (`tool_files_keyword = groupKey`)

**Response:**
```json
{
  "success": true,
  "message": "File search enabled on prompt with filter: WIDGET_KB",
  "promptId": 145
}
```

### 2. `updatePromptFileSearchFilter`
**Purpose:** Update only the group filter on existing user prompt

**Parameters:**
- `promptKey`: string
- `groupKey`: string

**Logic:**
1. Finds user's custom prompt (only BOWNERID = userId)
2. Updates `tool_files_keyword` in BPROMPTMETA
3. Does NOT modify default prompts

**Response:**
```json
{
  "success": true,
  "message": "File search filter updated to: WIDGET_KB"
}
```

## User Experience

### Scenario 1: First-time User with Default Prompt
1. User selects "general" prompt (default)
2. Uploads 3 PDF files with group "PRODUCT_DOCS"
3. System detects "general" has no file search
4. Shows dialog: "Enable File Search on 'general' prompt?"
5. User clicks "OK"
6. System creates custom "general" prompt for user
7. Enables file search filtered to "PRODUCT_DOCS"
8. Files are uploaded and processed
9. Widget can now access the uploaded files via RAG

### Scenario 2: User with Custom Prompt Already Configured
1. User selects "support" prompt (custom, already has file search)
2. Current filter: "SUPPORT_DOCS"
3. Uploads files with group "NEW_DOCS"
4. System detects different filter
5. Shows dialog: "Change filter from 'SUPPORT_DOCS' to 'NEW_DOCS'?"
6. User clicks "OK"
7. Filter is updated
8. Files are uploaded

### Scenario 3: User Declines Modification
1. User selects prompt without file search
2. Uploads files
3. System asks to enable file search
4. User clicks "Cancel"
5. Files are still uploaded (can be used later)
6. Widget works but won't access uploaded files via RAG

## Code Locations

### Frontend
- **File:** `frontend/c_webwidget.php`
- **Functions:**
  - `checkAndEnableFileSearch()` - Checks prompt status
  - `enableFileSearchOnPrompt()` - Calls API to enable
  - `updatePromptFileSearchFilter()` - Calls API to update

### Backend
- **API Routes:** `app/inc/api/_api-restcalls.php`
  - Case `enablePromptFileSearch`
  - Case `updatePromptFileSearchFilter`
  
- **Business Logic:** `app/inc/ai/core/_basicai.php`
  - `BasicAI::enablePromptFileSearch()` - Main logic
  - `BasicAI::updatePromptFileSearchFilter()` - Update logic

## Benefits

1. **Automatic Configuration:** Users don't need to manually configure prompts
2. **User Confirmation:** System asks before making changes
3. **Non-Breaking:** Existing workflows still work
4. **Intelligent:** Detects and handles different scenarios
5. **Secure:** Only modifies user's own prompts, never default system prompts

## Similar Implementation
This follows the same pattern as WordPress Wizard's `enableFileSearchOnGeneralPrompt()` function but is adapted for logged-in users with existing prompt selections.

## Important Implementation Details

### Why All Settings Must Be Set
The wizard must set ALL BPROMPTMETA settings (not just `tool_files` and `tool_files_keyword`) because:

1. **Consistency with c_prompts.php:** The prompt editor always saves all tool settings together
2. **Required Fields:** The AI system expects all tool flags to be present (aiModel, tool_internet, tool_files, tool_screenshot, tool_transfer)
3. **Default Values:** Missing settings could cause undefined behavior

### Setting Preservation Strategy
The implementation follows this logic:

```php
// 1. Fetch existing settings
$existingSettings = [get from BPROMPTMETA];

// 2. Build complete settings array
$settings = [
    'aiModel' => $existingSettings['aiModel'] ?? '-1',  // Use existing or default
    'tool_internet' => $existingSettings['tool_internet'] ?? '0',
    'tool_files' => '1',  // FORCE ENABLE
    'tool_screenshot' => $existingSettings['tool_screenshot'] ?? '0',
    'tool_transfer' => $existingSettings['tool_transfer'] ?? '0',
    'tool_files_keyword' => $groupKey  // NEW VALUE
];

// 3. Preserve optional settings (screenshot dimensions)
if (isset($existingSettings['tool_screenshot_x'])) {
    $settings['tool_screenshot_x'] = $existingSettings['tool_screenshot_x'];
}

// 4. Insert or update ALL settings
foreach ($settings as $token => $value) {
    INSERT ... ON DUPLICATE KEY UPDATE ...
}
```

### What Was Fixed (v2 - Final)
**Initial Issues:**
1. Only `tool_files` and `tool_files_keyword` were being set
2. Other required settings (aiModel, tool_internet, etc.) were missing
3. Used `ON DUPLICATE KEY UPDATE` instead of DELETE→INSERT pattern
4. Did not follow c_prompts.php established pattern

**The Core Problem:**
The codebase uses a DELETE→INSERT pattern for prompt settings, NOT an update pattern:
```php
// c_prompts.php pattern:
DELETE FROM BPROMPTMETA WHERE BPROMPTID = X
INSERT INTO BPROMPTMETA (BPROMPTID, BTOKEN, BVALUE) VALUES (X, 'tool_files', '1')
INSERT INTO BPROMPTMETA (BPROMPTID, BTOKEN, BVALUE) VALUES (X, 'aiModel', '-1')
// ... etc for all settings
```

**Final Solution (matching c_prompts.php exactly):**
1. Fetch existing settings BEFORE deleting (to preserve user choices)
2. **DELETE all BPROMPTMETA entries WITH SECURITY CHECKS:**
   ```sql
   DELETE FROM BPROMPTMETA 
   WHERE BPROMPTID = X
   AND BPROMPTID IN (
       SELECT BID FROM BPROMPTS 
       WHERE BID = X 
       AND BOWNERID = {userId}
       AND BOWNERID > 0  -- Never delete default prompts
   )
   ```
3. INSERT fresh entries for ALL required settings:
   - `aiModel` (from existing or default `-1`)
   - `tool_internet` (from existing or `0`)
   - `tool_files` = `1` (FORCE ENABLE)
   - `tool_screenshot` (from existing or `0`)
   - `tool_transfer` (from existing or `0`)
   - `tool_files_keyword` (new group key)
   - Optional: `tool_screenshot_x`, `tool_screenshot_y` if they existed

**Why This Pattern:**
- Ensures consistency across the codebase
- Prevents partial/incomplete settings
- Matches user expectations from prompt editor
- Default prompts (BOWNERID=0) are never modified
- User-specific copies get complete, fresh settings

**Result:** File search now works correctly with all required BPROMPTMETA entries properly set.

## Security Considerations

### Critical Security Fix
The DELETE operations now include proper authorization checks:

```sql
DELETE FROM BPROMPTMETA 
WHERE BPROMPTID = X
AND BPROMPTID IN (
    SELECT BID FROM BPROMPTS 
    WHERE BID = X 
    AND BOWNERID = {userId}  -- Must belong to current user
    AND BOWNERID > 0          -- Never delete default (system) prompts
)
```

**Why This Matters:**
- ✅ Prevents users from deleting other users' prompt settings
- ✅ Protects system default prompts (BOWNERID = 0) from modification
- ✅ Ensures only the logged-in user can modify their own prompts
- ✅ Adds defense-in-depth even if prompt ID validation fails elsewhere

**Without This Check:**
A malicious or buggy request could potentially:
- Delete another user's prompt configuration
- Corrupt system default prompts
- Create security vulnerabilities

**With This Check:**
The subquery ensures that even if a wrong `BPROMPTID` is passed, the DELETE will only succeed if:
1. The prompt exists
2. The prompt belongs to the current user
3. The prompt is not a system default

