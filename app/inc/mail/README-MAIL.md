# Mail System Overview

The Synaplan application has three distinct mail handling systems, each serving different purposes with different technologies.

## Summary

The application uses **three separate email systems**: (1) **EmailService/\_mymail** for sending transactional emails via AWS SMTP/PHPMailer, (2) **Mail Handler** for IMAP/POP3-based email routing and forwarding to departments, and (3) **myGMail** for Gmail API-based user email ingestion into the chat system. Each system operates independently with its own authentication and processing logic.

---

## 1. Registration & Transactional Emails

**Purpose:** Send system-generated emails (confirmations, password resets, notifications)

**Files:**
- `emailservice.php` - High-level email service class
- `_mail.php` - Low-level mail sending function

**Method:** 
- Primary: **PHPMailer** with **AWS SES SMTP** (email-smtp.eu-west-1.amazonaws.com:587)
- Fallback: PHP native `mail()` function

**Key Functions:**
- `EmailService::sendRegistrationConfirmation()` - Send email verification links
- `EmailService::sendPasswordResetEmail()` - Send new passwords
- `EmailService::sendLimitNotification()` - Usage limit alerts
- `_mymail()` - Core sending function with AWS SMTP credentials

**Authentication:** AWS access key & secret key (from `ApiKeys::getAWS()`)

**Flow:**
```
EmailService → _mymail() → PHPMailer → AWS SES SMTP → Recipient
```

---

## 2. Mail Handler (IMAP/POP3 Routing)

**Purpose:** Per-user email inbox monitoring with AI-powered routing to department addresses

**Files:**
- `c_mailhandler.php` - Frontend configuration UI
- `_toolmailhandler.php` - Backend processing class

**Method:**
- **webklex/php-imap** library for IMAP/POP3 connections
- **OAuth 2.0** support (Google, Microsoft) or password authentication
- AI-powered routing decisions using chat models

**Key Functions:**
- `mailHandler::imapConnectForUser()` - Establish IMAP/POP3 connection per user
- `mailHandler::processNewEmailsForUser()` - Fetch, route, and forward new emails
- `mailHandler::runRoutingForUser()` - AI determines target department
- `mailHandler::imapForwardMessageAll()` - Forward email with all attachments
- `mailHandler::oauthStart/oauthCallback()` - OAuth flow for Gmail/Outlook

**Authentication:**
- Password-based: User provides IMAP credentials (stored in `BCONFIG`)
- OAuth-based: Google/Microsoft OAuth tokens with automatic refresh

**Configuration Storage:** User-specific settings in `BCONFIG` table:
- `BGROUP='mailhandler'` - Server, port, protocol, credentials
- `BGROUP='mailhandler_dept'` - Department email targets and rules
- `BGROUP='mailhandler_oauth'` - OAuth tokens (if applicable)
- `BGROUP='mailhandler_state'` - Last seen timestamp for incremental fetching

**Flow:**
```
Cron → processNewEmailsForUser() → IMAP fetch → AI routing decision → 
Forward to department email via _mymail() → Mark as read → Update last_seen
```

---

## 3. Gmail API Service (User Mail Ingestion)

**Purpose:** Fetch user emails from Gmail and convert them to chat messages in the system

**Files:**
- `_myGMail.php` - Gmail API integration class

**Method:**
- **Google Gmail API** (not IMAP) via `google/apiclient`
- OAuth 2.0 authentication with mail.google.com scope
- Processes attachments and saves to user directories

**Key Functions:**
- `myGMail::getMail()` - Fetch up to 20 messages from INBOX
- `myGMail::processMessage()` - Extract headers, body, attachments
- `myGMail::saveToDatabase()` - Convert emails to `BMESSAGE` entries
- `myGMail::deleteMessage()` - Move processed emails to trash

**Authentication:** OAuth 2.0 via `OAuthConfig::createGoogleClient()`

**Special Features:**
- Phone number/tag extraction from email addresses (e.g., `smart+49175407011@gmail.com`)
- User matching by email and phone/tag
- Attachment filtering (allowed types: pdf, docx, pptx, jpg, png, mp3, md, html, htm)
- HTML sanitization for security
- Rate limiting support
- Automatic conversation threading via `Central::searchConversation()`

**Flow:**
```
Cron → getMail() → OAuth refresh → Gmail API fetch → 
Process message → Match user → Save attachments → 
Insert BMESSAGE → Start preprocessor → Delete from Gmail
```

**Status:** Not completed yet (as noted by user) - appears functional but may need additional features.

---

## Key Differences

| Aspect | EmailService | Mail Handler | myGMail |
|--------|-------------|--------------|---------|
| **Direction** | Outbound only | Inbound → Forward | Inbound → Database |
| **Protocol** | SMTP | IMAP/POP3 | Gmail API |
| **Auth** | AWS credentials | Per-user IMAP/OAuth | System OAuth |
| **Purpose** | System notifications | Department routing | Chat message creation |
| **Per-user** | No (system-wide) | Yes (multi-user) | Yes (user matching) |
| **AI Integration** | No | Yes (routing) | Yes (via preprocessor) |

---

## Configuration Notes

- **EmailService**: Requires AWS SES credentials in `ApiKeys::getAWS()`
- **Mail Handler**: Each user configures their own IMAP server via frontend UI
- **myGMail**: Requires OAuth app setup in `_oauth.php` (OAuthConfig class)

## Dependencies

- `phpmailer/phpmailer` - SMTP sending
- `webklex/php-imap` - IMAP/POP3 client
- `google/apiclient` - Gmail API
- `stevenmaguire/oauth2-microsoft` - Microsoft OAuth (optional)

---

*Last updated: 2025-10-09*

