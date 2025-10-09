# Synaplan Testing Suite

This directory contains automated testing scripts for the Synaplan platform. The test suite validates user creation, API functionality, and complete user deletion.

## 🔒 Security

This directory is protected by HTTP Basic Authentication (see `.htaccess` and `.htpasswd`).

**Default credentials:**
- Username: `tester`
- Password: (configured in `.htpasswd`)

## 📋 Test Scripts

### 1. test-createuser.php
Creates a test user (ID: 3) with the following credentials:
- **Email:** team@synaplan.com
- **Password:** testing (MD5 encrypted)
- **User Level:** NEW (auto-activated)

The script also generates an API key for the user and logs all results to an HTML report.

**Direct URL:** `https://yourdomain.com/admin/test-createuser.php`

**Returns:**
```json
{
    "result": true|false,
    "test": "Create User",
    "user_id": 3,
    "email": "team@synaplan.com",
    "api_key": "sk_live_...",
    "error": "",
    "timestamp": "2025-10-09 12:00:00"
}
```

### 2. test-simpleinference.php
Tests API inference functionality with a simple prompt:
- **Prompt:** "what is the weather in uzbekistan?"
- **Uses:** API key from user ID 3
- **Validates:** API response and processing

**Direct URL:** `https://yourdomain.com/admin/test-simpleinference.php`

**Returns:**
```json
{
    "result": true|false,
    "test": "Simple Inference",
    "user_id": 3,
    "prompt": "what is the weather in uzbekistan?",
    "api_key": "sk_live_...",
    "response": "...",
    "response_time_ms": 1234.56,
    "error": "",
    "timestamp": "2025-10-09 12:00:00"
}
```

### 3. test-deleteuser.php
Completely deletes test user ID 3 with all associated data:
- API keys
- User files directory
- BCONFIG entries
- BPROMPTS and BPROMPTMETA entries
- BRAG entries
- BMESSAGES and BMESSAGEMETA entries
- User record from BUSER

After deletion, the script:
1. Closes the HTML report
2. Sends the report via email to team@synaplan.com

**Direct URL:** `https://yourdomain.com/admin/test-deleteuser.php`

**Returns:**
```json
{
    "result": true|false,
    "test": "Delete User",
    "user_id": 3,
    "deletion_log": [
        "API Keys: Deleted 1 key(s)",
        "Files: No files directory found",
        "Config: Deleted 0 record(s)",
        "Prompts: Deleted 0 prompt(s) and 0 meta record(s)",
        "RAG: Deleted 0 record(s)",
        "Messages: Deleted 2 message(s) and 0 meta record(s)",
        "User Record: Deleted"
    ],
    "email_sent": true,
    "email_recipient": "team@synaplan.com",
    "error": "",
    "timestamp": "2025-10-09 12:00:00"
}
```

### 4. run-all-tests.php (Master Runner)
Executes all three tests in sequence and provides a combined result.

**Direct URL:** `https://yourdomain.com/admin/run-all-tests.php`

**Returns:**
```json
{
    "success": true|false,
    "total_tests": 3,
    "passed": 3,
    "failed": 0,
    "tests": [...],
    "email_sent": true,
    "total_duration_ms": 5678.90,
    "timestamp": "2025-10-09 12:00:00"
}
```

## 🚀 Usage

### Running Individual Tests

You can run each test individually by accessing its URL directly:

```bash
# Test 1: Create User
curl -u tester:password https://yourdomain.com/admin/test-createuser.php

# Test 2: API Inference
curl -u tester:password https://yourdomain.com/admin/test-simpleinference.php

# Test 3: Delete User & Send Report
curl -u tester:password https://yourdomain.com/admin/test-deleteuser.php
```

### Running Complete Test Suite

To run all tests in sequence:

```bash
curl -u tester:password https://yourdomain.com/admin/run-all-tests.php
```

Or access via browser (will prompt for authentication):
```
https://yourdomain.com/admin/run-all-tests.php
```

## 📊 HTML Report

Each test run generates/updates an HTML report file:
- **Location:** `/admin/test-report.html`
- **Includes:** All test results with timestamps, durations, and detailed logs
- **Styling:** Modern, responsive design with color-coded status badges
- **Email:** Automatically sent to team@synaplan.com after Test 3 completes

### Sample Report Sections:
- ✅ **Test 1:** User creation details
- ✅ **Test 2:** API inference performance
- ✅ **Test 3:** Deletion log with detailed breakdown
- 📊 **Summary:** Overall test suite statistics

## 🔧 Backend Methods

### UserRegistration Class (Extended)

The following methods were added to `/app/inc/auth/userregistration.php`:

#### `deleteUserCompletely(int $userId): array`
Master deletion method that orchestrates complete user removal.

#### Private Helper Methods:
- `deleteUserApiKeys(int $userId): array`
- `deleteUserFiles(int $userId): array`
- `deleteUserConfig(int $userId): array`
- `deleteUserPrompts(int $userId): array`
- `deleteUserRAG(int $userId): array`
- `deleteUserMessages(int $userId): array`
- `deleteUserRecord(int $userId): array`
- `recursiveDelete(string $dir): bool`

## ⚠️ Important Notes

1. **User ID 3:** The test suite specifically uses user ID 3. If this ID exists, it will be deleted before creating a new test user.

2. **Email Notifications:** Test completion emails are sent to `team@synaplan.com` using the existing `EmailService` class.

3. **Data Safety:** The deletion methods are designed to only affect the specified user ID (3). However, always run tests in a development/staging environment first.

4. **Auto-Increment:** Test 1 attempts to create user with ID 3. If auto-increment has moved beyond 3, the script will note the discrepancy but continue with the assigned ID.

5. **Report File:** The HTML report is overwritten on each complete test suite run (when using `run-all-tests.php`).

## 🛡️ Database Tables Affected

The test suite interacts with the following database tables:

- **BUSER** - User accounts
- **BAPIKEYS** - API keys
- **BCONFIG** - User configuration
- **BPROMPTS** - User prompts
- **BPROMPTMETA** - Prompt metadata
- **BRAG** - RAG (Retrieval-Augmented Generation) data
- **BMESSAGES** - User messages
- **BMESSAGEMETA** - Message metadata

## 📝 Example Workflow

```bash
# 1. Run complete test suite
curl -u tester:password https://synaplan.com/admin/run-all-tests.php

# 2. Check your email at team@synaplan.com for the HTML report

# 3. Review the JSON output for programmatic validation
```

## 🐛 Troubleshooting

### Test 1 fails with "User already exists"
- Run Test 3 first to clean up any existing test user
- Or manually delete user ID 3 from the database

### Test 2 fails with "No API key found"
- Ensure Test 1 completed successfully
- Check that the API key was created in BAPIKEYS table

### Test 3 fails with "User deletion failed"
- Check database permissions
- Verify UPLOAD_DIR is set correctly
- Review PHP error logs

### Email not received
- Check EmailService configuration
- Verify SMTP settings
- Check spam folder
- Review PHP mail logs

## 📧 Support

For issues or questions about the testing suite, contact the development team or review the source code comments in each test file.

---

**Generated for Synaplan Platform**  
**© 2025 metadist GmbH**

