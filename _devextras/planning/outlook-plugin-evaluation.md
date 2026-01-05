# Synaplan Outlook Add-in: Evaluation & Planning Document

**Status**: Evaluation / Proposal  
**Date**: January 2026  
**Target Audience**: Senior Developer Review

---

## Executive Summary

This document evaluates the feasibility and effort required to develop a **Microsoft Outlook Add-in** that integrates with Synaplan's AI services. The add-in would provide:

1. **Email Summarization** - On-the-fly AI-powered summaries via API
2. **Related Email Search** - Find emails by sender/topic to build relationship overviews
3. **Live Translation** - In-place translation between language pairs (de↔en, en↔fr, it↔fr, etc.)
4. **User Configuration** - Settings UI for API key and server address (default: `web.synaplan.com`)

**Estimated Total Effort**: 6-10 weeks (1-2 senior developers)

---

## Table of Contents

1. [Product Overview](#1-product-overview)
2. [Technical Architecture](#2-technical-architecture)
3. [Outlook Add-in Development](#3-outlook-add-in-development)
4. [Synaplan Backend Requirements](#4-synaplan-backend-requirements)
5. [Work Breakdown & Estimates](#5-work-breakdown--estimates)
6. [Security Considerations](#6-security-considerations)
7. [Deployment Strategy](#7-deployment-strategy)
8. [Risks & Open Questions](#8-risks--open-questions)
9. [Appendix: Technical References](#9-appendix-technical-references)

---

## 1. Product Overview

### 1.1 Feature Description

#### Primary UX: One “Synaplan” Button with Dropdown (Fast Actions)

The add-in is designed to be **very fast and low-friction**. The primary entry point is a **single “Synaplan” button** in the Outlook ribbon. Clicking it opens a dropdown with the three main actions:

- **Summarize Mail**
- **Summarize Relationship**
- **Translate to…** → sub-menu with **4–5 preconfigured target languages** (user-configurable)
  - Example defaults: **English (en)**, **German (de)**, **French (fr)**, **Italian (it)**, **Spanish (es)**

**UX goals:**
- **One click to action** (dropdown → action runs immediately)
- **No heavy multi-tab UI**; results appear in a lightweight task pane
- **Minimal configuration**: only **Server URL** + **API Key** required to be productive

#### Feature 1: Email Summarization
- **Trigger**: User selects **Synaplan → Summarize Mail**
- **Flow**: Email body text + metadata → Synaplan API → AI model generates summary → Display in task pane
- **Output**: Concise summary (default: bullets) displayed in a lightweight sidebar panel

#### Feature 2: Related Email Search (Relationship Builder)
- **Trigger**: User selects **Synaplan → Summarize Relationship**
- **Flow**: Extract sender + (optional) subject topics → Search inbox → Build short relationship overview → Display in task pane
- **Capabilities**:
  - Search by sender (all emails from/to this person)
  - Search by topic/subject keywords
  - Build chronological overview of communication history
- **Output**: Short overview + key facts, plus an optional expandable list of related emails (date/subject/snippet)

#### Feature 3: Live Translation
- **Trigger**: User selects **Synaplan → Translate to… → {Language}**
- **Supported pairs**: Configurable, but **UI exposes only 4–5 preconfigured target languages** for speed
- **Flow**: Selected text (preferred) or email body excerpt → Synaplan Translation API → Show translated text immediately
- **“In-place” behavior**:
  - **Read mode**: show translation in task pane (copy button), optionally highlight what was translated
  - **Compose mode**: translate current selection; optionally replace selection (if permitted)
- **Note**: Modifying received email bodies is limited in Outlook; “in-place” is primarily **side-by-side display**, plus **copy/insert** actions

#### Feature 4: Configuration Screen
- **Settings**:
  - Server URL (default: `https://web.synaplan.com`)
  - API Key (stored securely in add-in settings)
  - Translation target languages shown under **“Translate to…”** (choose 4–5 defaults, reorder, enable/disable)
  - Optional: default summary format (bullets/brief), include action items yes/no
- **Storage**: Use Office.js `RoamingSettings` (preferred) or browser localStorage

**Configuration UX requirement:** If API key/server are missing, the first attempt to use any dropdown action should show a **single, minimal setup screen** and return the user to the last action after saving.

### 1.2 Target Users
- Business users managing email relationships
- Multilingual teams needing quick translations
- Sales/support teams needing relationship context
- Anyone wanting AI-assisted email triage

---

## 2. Technical Architecture

### 2.1 High-Level Architecture

```
┌─────────────────────────────────────────────────────────────────────────┐
│                        Microsoft Outlook Client                          │
│  ┌────────────────┐  ┌──────────────────────────────────────────────┐  │
│  │  Mail Item     │  │        Outlook Add-in (Task Pane)            │  │
│  │  ────────────  │  │  ┌────────────────────────────────────────┐  │  │
│  │  From: ...     │  │  │  HTML/CSS/JavaScript (TypeScript)      │  │  │
│  │  Subject: ...  │  │  │  - Office.js SDK                       │  │  │
│  │  Body: ...     │◄─┼──│  - Vue.js / React / Vanilla            │  │  │
│  │                │  │  │  - Configuration UI                    │  │  │
│  └────────────────┘  │  └───────────────┬────────────────────────┘  │  │
│                      │                  │                            │  │
└──────────────────────┼──────────────────┼────────────────────────────┘  │
                       │                  │ HTTPS (REST API)              │
                       │                  ▼                               │
┌──────────────────────┼──────────────────────────────────────────────────┘
│                      │
│     Synaplan Backend (web.synaplan.com or self-hosted)
│     ┌─────────────────────────────────────────────────────────────────┐
│     │  New API Endpoints (/api/v1/outlook/*)                          │
│     │  ┌─────────────┐  ┌─────────────┐  ┌─────────────────────────┐  │
│     │  │ /summarize  │  │ /translate  │  │ /search (placeholder)   │  │
│     │  └──────┬──────┘  └──────┬──────┘  └────────────┬────────────┘  │
│     │         │                │                      │               │
│     │         ▼                ▼                      │               │
│     │  ┌──────────────────────────────────────┐      │               │
│     │  │         AiFacade Service             │      │               │
│     │  │   (OpenAI/Anthropic/Ollama/Groq)     │      │               │
│     │  └──────────────────────────────────────┘      │               │
│     └───────────────────────────────────────────────┬─────────────────┘
│                                                     │
│     Note: Email search happens CLIENT-SIDE via     │
│     Office.js / Microsoft Graph API, NOT via       │
│     Synaplan backend.                              │
└─────────────────────────────────────────────────────┘
```

### 2.2 Communication Flow

1. **Add-in ↔ Outlook**: Office JavaScript API (`Office.js`)
   - Read email body: `Office.context.mailbox.item.body.getAsync()`
   - Access metadata: `Office.context.mailbox.item.from`, `.subject`, `.dateTimeCreated`
   - Search mailbox: Microsoft Graph API or `Office.context.mailbox.search()` (limited)

2. **Add-in ↔ Synaplan**: REST API over HTTPS
   - Authentication: API Key in `X-API-Key` header
   - Endpoints: `/api/v1/outlook/summarize`, `/api/v1/outlook/translate`
   - CORS: Must allow add-in domains (Office CDN hosted or custom domain)

---

## 3. Outlook Add-in Development

### 3.1 Technology Stack (Add-in Side)

| Component | Technology | Notes |
|-----------|------------|-------|
| **Runtime** | Office JavaScript API (Office.js) | Microsoft's SDK for add-ins |
| **UI Framework** | TypeScript + Vue 3 *or* React | Recommend Vue to match Synaplan frontend |
| **Build System** | Vite or Webpack | Office provides Yo Office generator |
| **Styling** | Tailwind CSS / Custom | Match Synaplan design system |
| **Manifest** | XML manifest (legacy) or JSON (unified manifest) | JSON preferred for new development |
| **Hosting** | Static files on CDN or Synaplan server | Add-in code must be served over HTTPS |

### 3.2 Add-in Manifest Structure

The manifest defines how the add-in appears in Outlook and what permissions it needs.

**Key Manifest Elements:**

```xml
<!-- manifest.xml (legacy format example) -->
<OfficeApp xmlns="http://schemas.microsoft.com/office/appforoffice/1.1" 
           xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
           xsi:type="MailApp">
  <Id>synaplan-outlook-addin-guid</Id>
  <Version>1.0.0</Version>
  <ProviderName>Synaplan</ProviderName>
  <DefaultLocale>en-US</DefaultLocale>
  <DisplayName DefaultValue="Synaplan AI Assistant"/>
  <Description DefaultValue="Summarize emails, search relationships, translate"/>
  
  <Hosts>
    <Host Name="Mailbox"/>
  </Hosts>
  
  <Requirements>
    <Sets>
      <Set Name="Mailbox" MinVersion="1.3"/>
    </Sets>
  </Requirements>
  
  <FormSettings>
    <Form xsi:type="ItemRead">
      <DesktopSettings>
        <SourceLocation DefaultValue="https://web.synaplan.com/outlook-addin/taskpane.html"/>
        <RequestedHeight>400</RequestedHeight>
      </DesktopSettings>
    </Form>
  </FormSettings>
  
  <Permissions>ReadItem</Permissions>
  
  <Rule xsi:type="RuleCollection" Mode="Or">
    <Rule xsi:type="ItemIs" ItemType="Message" FormType="Read"/>
    <Rule xsi:type="ItemIs" ItemType="Message" FormType="Edit"/>
  </Rule>
</OfficeApp>
```

**Permissions Required:**
- `ReadItem` - Read email content (minimum for summarization/translation)
- `ReadWriteMailbox` - If we need to modify drafts or search extensively
- Consider using Microsoft Graph API for advanced search (requires Azure AD app registration)

### 3.3 Office.js API Usage

**Reading Email Content:**

```typescript
// Get email body text for summarization
Office.context.mailbox.item.body.getAsync(
  Office.CoercionType.Text,
  (result) => {
    if (result.status === Office.AsyncResultStatus.Succeeded) {
      const emailBody = result.value
      // Send to Synaplan API for summarization
      summarizeEmail(emailBody)
    }
  }
)

// Get email metadata
const from = Office.context.mailbox.item.from.emailAddress
const subject = Office.context.mailbox.item.subject
const date = Office.context.mailbox.item.dateTimeCreated
```

**Searching Related Emails (via Microsoft Graph):**

```typescript
// Requires Azure AD app with Mail.Read permission
import { Client } from '@microsoft/microsoft-graph-client'

async function searchRelatedEmails(senderEmail: string): Promise<EmailResult[]> {
  const client = Client.init({ /* auth provider */ })
  
  const response = await client.api('/me/messages')
    .filter(`from/emailAddress/address eq '${senderEmail}'`)
    .select('subject,receivedDateTime,bodyPreview')
    .top(50)
    .orderby('receivedDateTime desc')
    .get()
  
  return response.value
}
```

### 3.4 Add-in UI Components

**UI philosophy:** Ribbon dropdown for actions; task pane for results (no “app-like” navigation).

**Ribbon / Command UI (primary):**
- Button: **Synaplan**
  - Menu item: **Summarize Mail**
  - Menu item: **Summarize Relationship**
  - Submenu: **Translate to…**
    - **English (en)**
    - **German (de)**
    - **French (fr)**
    - **Italian (it)**
    - **Spanish (es)**

**Task pane structure (result-first):**

```
┌─────────────────────────────────┐
│  [Logo] Synaplan AI Assistant   │
├─────────────────────────────────┤
│                                 │
│  ┌─────────────────────────┐    │
│  │  Result                 │    │
│  │  (Summary / Relationship│    │
│  │   / Translation)        │    │
│  │  • Key point 1          │    │
│  │  • Key point 2          │    │
│  │  • Action items...      │    │
│  └─────────────────────────┘    │
│                                 │
│  [Regenerate] [Copy] [Insert]   │
│                                 │
├─────────────────────────────────┤
│  [Settings]  Powered by Synaplan│
└─────────────────────────────────┘
```

**Settings access:** Keep it out of the main workflow. A small **Settings** link/button in the task pane is enough; optionally also offer a dedicated command in Outlook add-in settings/deployment (enterprise).

---

## 4. Synaplan Backend Requirements

### 4.1 New API Endpoints

The Outlook add-in requires dedicated API endpoints. These should be scoped under `/api/v1/outlook/` and protected with API key authentication.

#### Endpoint 1: POST `/api/v1/outlook/summarize`

**Purpose**: Generate AI summary of email content

**Request:**
```json
{
  "content": "Full email body text...",
  "format": "bullets" | "paragraph" | "brief",
  "language": "en" | "de" | "fr",
  "context": {
    "from": "sender@example.com",
    "subject": "Re: Project Update",
    "date": "2026-01-05T10:30:00Z"
  }
}
```

**Response:**
```json
{
  "success": true,
  "summary": "• Key point 1\n• Key point 2\n• Action: Follow up by Friday",
  "usage": {
    "prompt_tokens": 450,
    "completion_tokens": 120,
    "total_tokens": 570
  }
}
```

**Backend Implementation:**
- Use existing `AiFacade::chat()` with a system prompt for summarization
- Apply user's configured AI model (from API key owner's settings)
- Rate limit by API key / user

#### Endpoint 2: POST `/api/v1/outlook/translate`

**Purpose**: Translate text between language pairs

**Request:**
```json
{
  "text": "Sehr geehrter Herr Müller, vielen Dank für Ihre Nachricht...",
  "source_language": "de",
  "target_language": "en"
}
```

**Response:**
```json
{
  "success": true,
  "translation": "Dear Mr. Müller, thank you for your message...",
  "source_language": "de",
  "target_language": "en",
  "usage": {
    "prompt_tokens": 50,
    "completion_tokens": 45,
    "total_tokens": 95
  }
}
```

**Backend Implementation:**
- Use `AiFacade::chat()` with translation prompt
- Supported pairs: de↔en, en↔fr, it↔fr, es↔en (configurable)
- Could leverage specialized translation models if available

#### Endpoint 3: POST `/api/v1/outlook/relationship-summary` (Optional Enhancement)

**Purpose**: Given a list of email summaries, generate a relationship overview

**Request:**
```json
{
  "contact_email": "john@acme.com",
  "contact_name": "John Smith",
  "emails": [
    { "date": "2025-12-01", "direction": "received", "subject": "...", "snippet": "..." },
    { "date": "2025-12-15", "direction": "sent", "subject": "...", "snippet": "..." }
  ]
}
```

**Response:**
```json
{
  "success": true,
  "overview": "John Smith from ACME Corp. First contacted in Dec 2025 regarding...",
  "key_topics": ["Product Demo", "Pricing", "Contract Renewal"],
  "sentiment": "positive",
  "last_interaction": "2025-12-15"
}
```

### 4.2 Authentication & Scopes

Extend the existing API key system to support Outlook-specific scopes:

**New Scopes:**
- `outlook:summarize` - Access to summarization endpoint
- `outlook:translate` - Access to translation endpoint
- `outlook:relationship` - Access to relationship summary endpoint
- `outlook:*` - Full Outlook add-in access (bundle scope)

**Implementation:**
```php
// In ApiKeyAuthenticator or dedicated middleware
$apiKey = $request->attributes->get('api_key');
$requiredScope = 'outlook:summarize';

if (!$apiKey->hasScope($requiredScope) && !$apiKey->hasScope('outlook:*')) {
    throw new AccessDeniedHttpException('API key missing required scope');
}
```

### 4.3 Rate Limiting Considerations

The Outlook add-in could generate many requests depending on usage patterns (e.g., relationship summary may summarize multiple emails). Consider:

- Per-endpoint rate limits in `RateLimiterService`
- Add `outlook_summarize` and `outlook_translate` as rate limit scopes
- Configurable limits based on user tier/subscription

### 4.4 CORS Configuration

The add-in runs in an Office iframe hosted on Microsoft domains. Update CORS to allow:

```php
// In CorsListener or nelmio_cors config
'allow_origin' => [
    // ... existing origins
    'https://outlook.office.com',
    'https://outlook.office365.com',
    'https://outlook.live.com',
    '*.officeapps.live.com',
],
```

---

## 5. Work Breakdown & Estimates

### 5.1 Phase 1: Foundation (Week 1-2)

| Task | Effort | Dependencies |
|------|--------|--------------|
| Set up Outlook add-in project (Yo Office / manual) | 0.5 days | None |
| Create manifest with **Synaplan ribbon button** + dropdown commands (+ Translate submenu) | 1 day | None |
| Set up TypeScript + Vue 3 build pipeline | 1 day | None |
| Implement **minimal** configuration screen (API key, server URL) + “prompt on first use” flow | 1 day | None |
| Create API key secure storage (RoamingSettings) | 0.5 days | Config screen |
| Implement lightweight task pane (result view + copy/insert + settings link) | 1 day | Build pipeline |
| Backend: Create `/api/v1/outlook/` route group | 0.5 days | None |
| Backend: Implement basic summarize endpoint | 1 day | AiFacade |
| Backend: Add CORS for Office domains | 0.5 days | None |
| Basic end-to-end: dropdown → summarize mail → show result in task pane | 1 day | All above |

**Phase 1 Total: ~1.5 weeks**

### 5.2 Phase 2: Core Features (Week 3-5)

| Task | Effort | Dependencies |
|------|--------|--------------|
| Implement translation endpoint (backend) | 1 day | AiFacade |
| Implement translation command flow: **Translate to…** submenu with 4–5 languages | 1 day | Backend |
| Implement related email search (Office.js) | 2 days | None |
| Build search results UI with timeline view | 1.5 days | Search impl |
| Microsoft Graph API integration (optional, for better search) | 2 days | Azure AD setup |
| Build relationship overview UI (short overview + optional expandable list) | 1 day | Search |
| Implement relationship summary endpoint (backend) | 1 day | AiFacade |
| API key scope validation (outlook:* scopes) | 0.5 days | Backend |
| Rate limiting for Outlook endpoints | 0.5 days | RateLimiter |
| Error handling & loading states | 1 day | All features |

**Phase 2 Total: ~2.5 weeks**

### 5.3 Phase 3: Polish & Testing (Week 6-7)

| Task | Effort | Dependencies |
|------|--------|--------------|
| UI polish (Synaplan design system) | 2 days | Features complete |
| Accessibility (keyboard nav, screen reader) | 1 day | UI |
| Testing across Outlook clients (Windows, Mac, Web) | 2 days | All |
| Performance optimization (caching, debounce) | 1 day | Testing |
| Documentation (user guide, API docs) | 1 day | All |
| OpenAPI annotations for new endpoints | 0.5 days | Backend |
| Frontend schema generation | 0.5 days | OpenAPI |

**Phase 3 Total: ~1.5 weeks**

### 5.4 Phase 4: Deployment & Distribution (Week 8)

| Task | Effort | Dependencies |
|------|--------|--------------|
| Azure AD app registration (if using Graph) | 0.5 days | None |
| Set up add-in hosting on Synaplan CDN | 0.5 days | Build pipeline |
| Create sideload instructions for testing | 0.5 days | Hosting |
| Prepare for Microsoft AppSource submission | 2 days | All testing |
| Admin center deployment guide | 0.5 days | Documentation |
| Production monitoring & logging | 0.5 days | Backend |

**Phase 4 Total: ~1 week**

### 5.5 Summary

| Phase | Duration | Effort |
|-------|----------|--------|
| Phase 1: Foundation | 1.5 weeks | ~7 days |
| Phase 2: Core Features | 2.5 weeks | ~12 days |
| Phase 3: Polish & Testing | 1.5 weeks | ~8 days |
| Phase 4: Deployment | 1 week | ~4.5 days |
| **Total** | **6.5-8 weeks** | **~31.5 days** |

**Buffer for unknowns**: Add 20-30% → **8-10 weeks total**

---

## 6. Security Considerations

### 6.1 API Key Security

- **Storage**: Use `Office.context.roamingSettings` for cross-device persistence, or localStorage for single-device
- **Never expose in UI**: Mask API key after entry (show only `sk_abc1****`)
- **HTTPS only**: All API calls must use TLS
- **Key rotation**: Encourage users to rotate keys periodically

### 6.2 Data Privacy

- **Email content**: Sent to Synaplan servers for AI processing
- **User consent**: Add-in must clearly inform users that email content is transmitted
- **Data retention**: Clarify if Synaplan stores email content (recommend: don't persist)
- **GDPR compliance**: May need data processing agreement for EU users

### 6.3 Permissions Principle

- Request minimum permissions needed (`ReadItem` vs `ReadWriteMailbox`)
- Only access data when user explicitly triggers action
- Don't pre-fetch or cache email content unnecessarily

### 6.4 Microsoft Graph Considerations

If using Graph API for search:
- Requires Azure AD app registration
- Requires user consent for `Mail.Read` scope
- OAuth flow must be handled in add-in
- Consider Delegated vs Application permissions

---

## 7. Deployment Strategy

### 7.1 Development/Testing

1. **Sideloading**: Developers can sideload manifest via Outlook settings
   - Outlook Web: Settings → Manage Add-ins → Custom add-ins
   - Outlook Desktop: File → Manage Add-ins → Custom Add-ins

2. **Local hosting**: Serve add-in from `https://localhost:3000` with self-signed cert

### 7.2 Internal Distribution

1. **Centralized Deployment** (Recommended for organizations):
   - Admin deploys via Microsoft 365 Admin Center
   - Users get add-in automatically
   - No AppSource submission required

2. **SharePoint App Catalog**:
   - Upload add-in package to SharePoint
   - Users can install from internal catalog

### 7.3 Public Distribution (AppSource)

1. **Microsoft Partner Center**: Create seller account
2. **Validation**: Microsoft reviews add-in (~3-5 business days)
3. **Requirements**:
   - Privacy policy URL
   - Support URL
   - Detailed description and screenshots
   - No prohibited content

### 7.4 Hosting Requirements

Add-in HTML/JS/CSS must be hosted on HTTPS. Options:

1. **Synaplan CDN**: Host at `https://web.synaplan.com/outlook-addin/`
2. **Dedicated subdomain**: `https://outlook.synaplan.com/`
3. **Azure Static Web Apps**: If using Azure AD

---

## 8. Risks & Open Questions

### 8.1 Technical Risks

| Risk | Impact | Mitigation |
|------|--------|------------|
| Microsoft Graph API complexity | Medium | Start with basic Office.js search, Graph as enhancement |
| Cross-platform inconsistencies | Medium | Test early on all platforms (Windows, Mac, Web) |
| Office.js API limitations | Medium | Prototype critical features first |
| Performance with large emails | Low | Implement truncation, streaming |

### 8.2 Business Risks

| Risk | Impact | Mitigation |
|------|--------|------------|
| AppSource rejection | Medium | Follow Microsoft guidelines strictly |
| GDPR compliance concerns | High | Legal review, clear privacy policy |
| User adoption | Medium | Polish UX, provide good documentation |
| AI cost per user | Medium | Rate limiting, usage tracking |

### 8.3 Open Questions

1. **Microsoft Graph API**: Do we need Azure AD integration for better search, or is Office.js sufficient?
2. **Command behavior**: Should **each dropdown action** always open the task pane, or should some actions (e.g., translate selection) support a “toast”/inline response first and open task pane only on demand?
3. **No auto mode (recommended)**: Confirm we explicitly do **not** implement auto-summarize; it conflicts with the “fast & cheap” principle and can cause surprise costs.
4. **Multi-account**: Should add-in support users with multiple Outlook accounts?
5. **Pricing**: Is this a free feature for all users or a premium add-on?
6. **Translation menu defaults**: Which **4–5 default target languages** should ship, and do we allow users to configure more than 5 (hidden behind settings)?
7. **Translation models**: Use general AI models or specialized translation models?
8. **In-place translation for compose**: Can we support replacing selected text when composing (vs copy/insert only)?

---

## 9. Appendix: Technical References

### 9.1 Microsoft Documentation

- [Office Add-ins Overview](https://learn.microsoft.com/en-us/office/dev/add-ins/overview/office-add-ins)
- [Outlook Add-in Quickstart](https://learn.microsoft.com/en-us/office/dev/add-ins/quickstarts/outlook-quickstart)
- [Office.js API Reference](https://learn.microsoft.com/en-us/javascript/api/outlook)
- [Microsoft Graph Mail API](https://learn.microsoft.com/en-us/graph/api/resources/mail-api-overview)
- [Deploy Add-ins in Admin Center](https://learn.microsoft.com/en-us/microsoft-365/admin/manage/manage-deployment-of-add-ins)

### 9.2 Existing Synaplan Infrastructure

| Component | Relevance |
|-----------|-----------|
| `ApiKeyAuthenticator` | Existing auth - extend with outlook scopes |
| `AiFacade` | Use for summarization and translation |
| `RateLimiterService` | Add outlook-specific rate limits |
| CORS configuration | Update for Office domains |
| OpenAPI/Swagger | Document new endpoints |

### 9.3 Tools & Frameworks

- [Yeoman Generator for Office Add-ins](https://github.com/OfficeDev/generator-office) - Scaffold add-in project
- [Office Add-in Debugger (VS Code)](https://marketplace.visualstudio.com/items?itemName=msoffice.microsoft-office-add-in-debugger)
- [Script Lab](https://appsource.microsoft.com/product/office/wa104380862) - Prototype Office.js code

### 9.4 Sample Prompts for AI Features

**Summarization System Prompt:**
```
You are an email summarization assistant. Given an email, provide a concise summary with:
- Key points (bullet list)
- Any action items or deadlines mentioned
- Overall tone/sentiment

Keep the summary under 200 words. Be objective and factual.
```

**Translation System Prompt:**
```
Translate the following text from {source_language} to {target_language}.
Maintain the original formatting, tone, and formality level.
Do not add explanations or notes - output only the translation.
```

---

## Decision Required

This evaluation is ready for senior developer review. Key decisions needed:

- [ ] **Approve/modify scope** - Which features to include in MVP?
- [ ] **Technology choice** - Vue 3 (match Synaplan) or React (more Office samples)?
- [ ] **Graph API** - Include Azure AD integration or defer?
- [ ] **Timeline** - Is 8-10 weeks acceptable?
- [ ] **Pricing** - Free feature or premium?
- [ ] **Team allocation** - Who will lead this project?

---

*Document prepared for evaluation. No code changes made.*
