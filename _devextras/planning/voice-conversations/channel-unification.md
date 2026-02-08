# Channel Unification — WhatsApp & Email in Chat History

**Phase 5 of Voice Conversations**

## Problem

WhatsApp and Email messages are stored in `BMESSAGES` with `BCHATID` set, but:
1. The `BCHATS` table has no `source` field — all chats look the same in the sidebar
2. The sidebar only shows "My Chats" and "Widget Sessions" — no WhatsApp/Email section
3. File attachments from WhatsApp/Email messages are not rendered in the web chat view

## Solution

### 1. Database Migration

Add `BSOURCE` column to `BCHATS`:

```sql
ALTER TABLE BCHATS ADD COLUMN BSOURCE VARCHAR(16) NOT NULL DEFAULT 'web' AFTER BUPDATEDAT;
CREATE INDEX idx_chat_source ON BCHATS (BSOURCE);
```

Backfill from existing data:
```sql
-- WhatsApp chats: any chat containing a WTSP message
UPDATE BCHATS c SET c.BSOURCE = 'whatsapp'
WHERE EXISTS (
    SELECT 1 FROM BMESSAGES m
    WHERE m.BCHATID = c.BID AND m.BMESSTYPE = 'WTSP'
    LIMIT 1
);

-- Email chats: any chat containing a MAIL message
UPDATE BCHATS c SET c.BSOURCE = 'email'
WHERE c.BSOURCE = 'web' AND EXISTS (
    SELECT 1 FROM BMESSAGES m
    WHERE m.BCHATID = c.BID AND m.BMESSTYPE = 'MAIL'
    LIMIT 1
);

-- Widget chats: any chat linked to a widget session
UPDATE BCHATS c SET c.BSOURCE = 'widget'
WHERE c.BSOURCE = 'web' AND EXISTS (
    SELECT 1 FROM BWIDGET_SESSIONS ws
    WHERE ws.BCHATID = c.BID
);
```

### 2. Entity Changes

**`Chat.php`** — add source field:
```php
#[ORM\Column(name: 'BSOURCE', length: 16, options: ['default' => 'web'])]
private string $source = 'web';

public function getSource(): string { return $this->source; }
public function setSource(string $source): self { $this->source = $source; return $this; }
```

### 3. Backend: Set Source on Chat Creation

**`EmailChatService::findOrCreateChatContext()`** — set `$chat->setSource('email')`
**`WhatsAppService`** — when creating chat for WhatsApp conversation, set `$chat->setSource('whatsapp')`
**`WidgetSessionService`** — set `$chat->setSource('widget')` (if not already)
**`ChatController::create()`** — default stays `web`

### 4. Backend: Return Source in API

**`ChatController::list()`** — add to response:
```php
'source' => $chat->getSource(),
```

**`ChatController::show()`** — same.

### 5. Frontend: Chat Interface Update

**`stores/chats.ts`** — extend interface:
```typescript
export interface Chat {
  // ... existing fields
  source?: 'web' | 'whatsapp' | 'email' | 'widget'
}
```

### 6. Frontend: Sidebar Channel Groups

**`components/Sidebar.vue`** (or wherever chats are listed):

Group chats by source with disclosure toggles:

```
▼ My Chats (12)
  💬 Debug session
  💬 Code review

▼ WhatsApp (3)
  🟢 +49 175 407 0111
  🟢 +49 176 xxx xxxx

▼ Email (2)
  📧 Re: Invoice question
  📧 Support request

▶ Widget Sessions (5)
```

**Channel icons:**
| Source | Icon | Color |
|--------|------|-------|
| `web` | `mdi:chat` | default |
| `whatsapp` | `mdi:whatsapp` | green |
| `email` | `mdi:email` | blue |
| `widget` | `mdi:widgets` | purple |

**Sidebar disclosure state** — extend `sidebar.ts`:
```typescript
const chatDisclosure = ref({
  my: true,
  widget: false,
  whatsapp: true,  // NEW
  email: true,     // NEW
})
```

### 7. Frontend: Render File Attachments in Chat History

Currently `history.ts` handles `m.file` (single generated file) and `m.files` (user uploads). WhatsApp and Email messages may have:
- Voice messages (audio)
- Image attachments
- Document attachments

These are stored as `MessageFile` entities linked via `BMESSAGEFILES`.

**Ensure `ChatController::messages()`** returns files for all message types (it already does via `$m->getFiles()`). Verify that:
- Audio files get `type: 'audio'` → renders `MessageAudio.vue`
- Image files get `type: 'image'` → renders `MessageImage.vue`
- Document files get download link

**`history.ts` `loadMessages()`** already parses `m.files`:
```typescript
if (m.files && Array.isArray(m.files)) {
  // Maps to MessageFile[] with id, filename, fileType, etc.
}
```

Verify the frontend renders these for non-web messages (WhatsApp/Email) the same way.

### 8. WhatsApp Chat Title

Currently WhatsApp chats get generic titles. Improve:
- Title format: `WhatsApp: +{phone}` or use contact name if available
- Set via `WhatsAppService` when creating the chat

### 9. Email Chat Title

Already handled in `EmailChatService::findOrCreateChatContext()`:
- Uses `Email: {keyword}` or `Email Conversation` format

## Migration Script

```php
// migrations/Version20260207_AddChatSource.php
public function up(Schema $schema): void
{
    $this->addSql("ALTER TABLE BCHATS ADD COLUMN BSOURCE VARCHAR(16) NOT NULL DEFAULT 'web'");
    $this->addSql("CREATE INDEX idx_chat_source ON BCHATS (BSOURCE)");

    // Backfill
    $this->addSql("UPDATE BCHATS c SET c.BSOURCE = 'whatsapp' WHERE EXISTS (SELECT 1 FROM BMESSAGES m WHERE m.BCHATID = c.BID AND m.BMESSTYPE = 'WTSP' LIMIT 1)");
    $this->addSql("UPDATE BCHATS c SET c.BSOURCE = 'email' WHERE c.BSOURCE = 'web' AND EXISTS (SELECT 1 FROM BMESSAGES m WHERE m.BCHATID = c.BID AND m.BMESSTYPE = 'MAIL' LIMIT 1)");
    $this->addSql("UPDATE BCHATS c SET c.BSOURCE = 'widget' WHERE c.BSOURCE = 'web' AND EXISTS (SELECT 1 FROM BWIDGET_SESSIONS ws WHERE ws.BCHATID = c.BID)");
}
```

## Verification

- [ ] WhatsApp chats appear in sidebar under "WhatsApp" group
- [ ] Email chats appear under "Email" group
- [ ] Opening a WhatsApp chat shows full conversation with audio players for voice messages
- [ ] Opening an Email chat shows conversation with document attachments
- [ ] New web chats default to `source=web`
- [ ] Channel icons display correctly in sidebar
- [ ] Migration backfills existing chats correctly
