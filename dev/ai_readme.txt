# Synaplan Web & App — Engineering Guide (README)

> **Purpose:** This document gives new devs and coding AIs (e.g., Cursor / GPT-Max) the ground rules, architecture, and do/don’t list for working in this repo. It also includes a starter task for website config, media/sorting rules, and acceptance tests to prevent regressions (especially around media routing).

---

## 0) Quick Start for a New Chat / Coding Session

Paste this into a **new** AI chat to bootstrap context:

> You are working on the Synaplan web + app codebase.
> **Hard rules:**
>
> * Do not add keyword heuristics to infer media intent. Media type comes only from sorter (`BMEDIA`), explicit slash tokens (`/vid|/pic|/audio`), or meta `BMEDIA`. Never from raw prompt scanning or stale `BTAG`.
> * Never change application DB schema or touch app code unless explicitly requested.
> * Keep guardrails: anonymous widget users and quota limits must block provider calls.
> * Preserve streaming logs; be explicit where media was resolved from (`sorter|slash|meta`).
> * Don’t downgrade video/image intents to audio.
> * Do not write `BTAG` until you actually call a tool this turn.
>   **Primary task right now:** replicate `_confdb.php` to `website/inc/` with fixed localhost credentials (see “1) Setup Task”) and keep `_s()` translation helper.
>   If you propose new APIs, write the spec and call points, but do not modify app code unless requested.

---

## 1) Setup Task (Website Config)

**Goal:** Create a DB/config for the website side that mirrors the app, without environment lookups.

* **Source:** `app/_confdb.php`
* **Destination:** `website/inc/_confdb.php`
* **Changes:**

  * Remove all ENV references.
  * Use fixed DB settings:

    * host: `localhost`
    * db: `<same as app>` (or `synaplan` if the app uses that DB name)
    * user: `synaplan`
    * pass: `synaplan`
  * Copy the `_s()` translation helper into the website copy for i18n.
* **Translations:** We’ll call the Synaplan **app API** using our API key. If the website needs a helper, **propose** (don’t implement in app yet) a service:

  * `snippetTranslate(sourceText, sourceLang, destLang): string`
  * Provide interface + example usage (see §6).
  * Do not edit app code unless a separate task explicitly asks for it.

---

## 2) High-Level Architecture

* **Messages (`BMESSAGES`)**: Each user turn (IN) → processing → AI/tool output (OUT).
* **Meta (`BMESSAGEMETA`)**: Key–value telemetry (model, service, BTAG, BMEDIA, etc.).
* **Prompts (`BPROMPTS`, `BPROMPTMETA`)**: Named tasks/routing + tool flags (internet, files, screenshot).
* **Models (`BMODELS`)**: Model catalog; `BTAG` used for Again dispatch (but **never** for initial media inference).
* **Processing flow:**

  1. `ProcessMethods::init()` – load message, user, thread.
  2. `sortMessage()` – resolve topic & language using sorter (unless the user typed a tool slash).
  3. If tool slash → `BasicAI::toolPrompt()`.
  4. Else → `processMessage()` routes to `topicPrompt()` or special handlers (mediamaker, analyzefile).
  5. `saveAnswerToDB()` writes the OUT message and telemetry.

---

## 3) Ground Rules (Do / Don’t)

### Do

* **Resolve media strictly** in this order:

  1. sorter output `BMEDIA`
  2. explicit current-turn slash token in `BTEXT` (`/vid|/video`, `/pic|/image`, `/audio|/sound`)
  3. meta `BMEDIA` (the sorter may have written it to meta only)

* **Keep guardrails**:

  * Anonymous widget users must be blocked with a UI alert; set `__BLOCK_MEDIA=1`; **no provider call**.
  * NEW user quotas read from config; if limit hit, show alert; **no provider call**.

* **Write `BTAG`** only **right before** you actually call a tool **this turn** (reflect the tool: `text2vid|text2pic|text2sound`). Never earlier.

* **Log resolution**: when media is resolved, stream `[media] resolved=<image|video|audio> via <sorter|slash|meta>`.

* **If mediamaker is unresolved**, set `BTOPIC='general'`, **strip any leading slash** from `BTEXT`, and continue as chat. Do **not** fall into any tool by accident.

* **Normalize tool results**: Prefer `OUTTEXT -> CAPTION -> TEXT -> BTEXT` for display.

* **Preserve fields**: Preserve essential fields (`BID`, `BUSERID`, `BTRACKID`, `BMESSTYPE`, `BDIRECT`) when merging arrays.

### Don’t

* ❌ **No keyword/regex** scanning of the user’s raw prompt to guess media (e.g., “video|film|clip” etc.).
* ❌ Do not infer initial media from **stale `BTAG`**. `BTAG` is for Again routing and **only** after a tool call.
* ❌ Do not silently degrade a video/image request to audio.
* ❌ Don’t write `BTAG` until you actually call the tool in that turn.
* ❌ Don’t bypass guardrails or call providers when blocked.

---

## 4) Message Lifecycle & Handlers

### 4.1 Sorting (`tools:sort`)

* Input to sorter: `{BDATETIME, BFILEPATH, BTOPIC, BLANG, BTEXT, BFILETEXT}` + `thread`.
* Output (JSON): **must** contain `BTOPIC` and `BLANG`; may contain `BTEXT` and `BMEDIA`.
* If `BTOPIC` starts with `tools:`, convert it to a slash command in `BTEXT` (e.g., `tools:filesort` → `/filesort`).

### 4.2 Mediamaker

* Trigger: `BTOPIC === 'mediamaker'`.
* **Resolve media** (strict order above). If unresolved:

  * Log `[media] unresolved — skipping mediamaker`.
  * Set `BTOPIC='general'`, **strip any leading slash** from `BTEXT`, proceed to chat.
* On success:

  * Map `image → /pic`, `video → /vid`, `audio → /audio`.
  * Write `BTAG` for this turn only when calling the tool.
  * Call `BasicAI::toolPrompt()` with normalized `BTEXT` (`/<tool> <payload>`).
  * Keep `BMEDIA` set in the OUT payload (even if tool returned only text).

### 4.3 Tool routing (`BasicAI::toolPrompt`)

* Slash parsing: first token = tool; rest = payload.
* **Hard guards**: If `__BLOCK_MEDIA` is set → **do not** call providers, return empty file.
* **Again** support: add `[Again-<timestamp>]` token to force generation variance.
* Store `AISERVICE`, `AIMODEL`, `AIMODELID` after provider calls.

### 4.4 Again Flow

* Dispatch by `BTAG` (from model used previously):

  * `text2pic|text2vid|text2sound` → mediamaker again; set `IS_AGAIN` and forced model; skip sorter.
  * `pic2text|sound2text` → `analyzefile`.
  * Else → general chat.

---

## 5) Acceptance Tests (Run Manually or in Logs)

1. **Video path**
   Input: “generiere ein video von einem reh”
   Expect: sorter `BTOPIC=mediamaker`, `BMEDIA=video` → mediamaker logs

   ```
   Calling extra mediamaker. [media] resolved=video via sorter|slash
   ```

   and calls `/vid …`. **No `/audio`** anywhere.

2. **Image path**
   Input: “generiere ein bild von einem hund”
   Expect: `/pic …` invoked; `BMEDIA=image`.

3. **Audio path**
   Input: “mach mir audio aus diesem text: …”
   Expect: `/audio …` invoked; `BMEDIA=audio`.

4. **Unresolved**
   If sorter returns no `BMEDIA` and user provided no slash, mediamaker logs:

   ```
   [media] unresolved — skipping mediamaker
   ```

   Then `BTOPIC=general`, any leading slash is **removed** from `BTEXT`. No tool invoked.

5. **Anonymous/quota**
   Anonymous widget user or limit exceeded → UI alert shown, `__BLOCK_MEDIA=1`, **no provider call**, no `BTAG` written.

---

## 6) Translations (Website → App API)

* The website needs to translate arbitrary UI snippets via the **app API** with our API key.
* **Do not** change app code right now.
* **Proposed service** (to be implemented in app later if needed):

**Interface (proposal):**

```http
POST /api/snippetTranslate
Authorization: Bearer <API_KEY>
Content-Type: application/json

{
  "sourceText": "Hello world",
  "sourceLang": "en",
  "destLang": "de"
}
```

**Response:**

```json
{
  "ok": true,
  "translatedText": "Hallo Welt"
}
```

**Website helper (pseudo/PHP usage):**

```php
function snippetTranslate($text, $src, $dst) {
    $apiUrl = APP_BASE_URL . '/api/snippetTranslate';
    $payload = json_encode([
        'sourceText' => $text,
        'sourceLang' => $src,
        'destLang'   => $dst,
    ]);
    // do curl POST with Authorization: Bearer <API_KEY>
    // handle timeouts and fallback to original text on failure
    return $translatedText ?? $text;
}
```

**Note:** Keep the `_s()` helper in `website/inc/_confdb.php`; `_s()` can call `snippetTranslate()` under the hood when a translation is missing from local catalogs.

---

## 7) Coding Conventions

* **PHP**: strict comparisons, `Tools::migrateArray()` for safe merges, use `DB::EscString()` for SQL.
* **Streaming logs**: concise, user-friendly.
* **Telemetry**: record `AISERVICE`, `AIMODEL`, `AIMODELID`, `AITOOL`, `BMEDIA`, and `IS_AGAIN` where applicable.
* **Preserve essential fields** on merges.
* **No hidden side effects**: write `BTAG` only when a tool is invoked this turn.

---

## 8) Common Pitfalls & Fixes

* **Media flips to /audio**
  Cause: reading stale `BTAG` or leaving a leading slash after mediamaker unresolved.
  Fix: never read `BTAG` in mediamaker resolver; strip leading slash on unresolved fallback.

* **Sticky state between turns**
  Fix: reset `ProcessMethods::$AIdetailArr` in `init()`.

* **Quota bypass**
  Fix: call `enforceMediaLimits()` before *every* media provider. Respect `__BLOCK_MEDIA`.

---

## 9) Deployment / Local Credentials

For **local dev only** (website side):

```php
$dbHost = 'localhost';
$dbName = '<same-as-app-or-synaplan>';
$dbUser = 'synaplan';
$dbPass = 'synaplan';
```

Keep any secrets in local env files for the app API (API key), **not** committed.

---

## 10) Definition of Done (per PR / AI change)

* All acceptance tests in §5 pass by reading the logs.
* No keyword heuristics for media.
* Guardrails intact; no provider calls when blocked.
* `BTAG` written only at tool invocation time.
* Debug shows `[media] resolved=… via …` or `[media] unresolved — skipping mediamaker`.
* No regressions to sorting or Again behavior.

---

### Appendix A — One-Shot Instruction for AI Agents

> * Never infer media from keywords or BTAG. Only `BMEDIA (sorter) → slash → meta BMEDIA`.
> * If unresolved: set `BTOPIC='general'`, remove leading slash from `BTEXT`, continue to chat.
> * Honor guardrails and quotas: block provider calls when required.
> * Write `BTAG` only when a tool is actually invoked this turn.
> * Keep streaming logs and normalize tool outputs.
> * For website config, replicate `_confdb.php` to `website/inc/_confdb.php` with localhost/synaplan creds and include `_s()`.

---
