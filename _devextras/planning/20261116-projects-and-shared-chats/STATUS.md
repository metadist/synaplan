# Projects and shared chats — STATUS

Step log for [`00_master_plan.md`](./00_master_plan.md). A walk is an entry
here, not a chat message. Numbers come from commands, with the command next
to them.

| Step | State | PR | Notes |
| ---- | ----- | -- | ----- |
| PJ0 | draft | — | plan written 2026-11-16; §0 partly decided, see below |
| PJ1–PJ28 | not started | — | plan only until the owner orders row 46 |

---

## 2026-11-16 — plan written (PJ0)

**What the owner decided** in the sessions of 2026-11-12 and 2026-11-13
(marked "Yes — owner" in §0): projects without templates, sources indexed
once; the model decides how long and how it works; no fixed time, no
maximum of steps or rounds; a cost limit and circle detection as the only
guard rails, both ending with a result; web chat and projects, not the
widget; the Chat model for everything, no list of verified models; notes
with a visible "Conversation summarized"; files usable by ID for the whole
chat; a question card with options and free text, several questions, only
the starter answers in a shared chat; chats that several people write in;
sender pays, or the owner sets an amount that only the others draw from,
with a bar and an automatic switch back; no personal memories in shared
chats; apps off by default, per person and per chat, usable only by their
owner; a chip that names whose app data an answer contains; project memory
that names its member and comes only from the project; conflicts pointed
out by the model; the memory choice in a private project chat; push on the
phone, nothing in the browser.

**What is open** (☐ in §0), by weight:

1. Ask-first items: the schema (#63), the push plugin (#61), the relay
   service (#62).
2. Phase B waits for AU26 and AH8, and changes three rows of row 25 for
   chat (#22, #23).
3. The value of the cost limit and the token cap for unpriced models
   (#12, #14).
4. Shared chats: does the AI answer every message (#36), one run per chat
   with "Answer now" (#37), the sender-only check before an app is used
   (#45), writes always ask (#46), the sender's model (#67).
5. Notion "Only these pages" after a spike (#49).
6. Private chats first in a shared project (#55); the default of the
   memory choice (#54).
7. Removing the planner card from Settings → AI Models (#20).

**Corrections against earlier statements in the chat:**

- Dropbox has no read path (save only). There is nothing to narrow.
- WebDAV reads are already limited to the connection's folder; a grant
  narrows further.
- The rolling summary uses the `ANALYZE` model, else the Chat model. The
  `SUMMARIZE` row is not read at runtime.

**Code map:** `main` @ `0d228e323`. Facts and paths in `01_research.md`
§1. Not found in the code: `AgentToolLoop`, `App\Service\Run`, `BRUNS`
(planned in rows 22 and 25), anything named project, a push plugin in
`synaplan-apps`.

**Gate for this PR:** `_devextras/**` only, class `no-app-impact`.
