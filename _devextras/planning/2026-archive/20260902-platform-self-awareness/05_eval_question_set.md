# Eval question set — what "self-aware" means, row by row

Status: implemented (frozen when SA10 lands; additions go through a PR that
also updates `backend/tests/Eval/self_aware_eval_corpus.json`)
Date: 2026-09-02

This is the contract the whole plan is measured against. Every row is one
utterance, the install profile it runs on, the routing we expect, what the
answer must contain, and what it must never contain. Languages rotate
through all five UI locales so the sorter rule and the language directive
are exercised together.

Install profiles (applied by toggling the real facts the inventory reads):

| Profile | Chat key | Image model | Video model | TTS | Office engine | Brave key | Qdrant | Billing |
| ------- | -------- | ----------- | ----------- | --- | ------------- | --------- | ------ | ------- |
| `no_keys` | – | – | – | – | – | – | – | off |
| `no_engine` | yes | yes | – | – | – | yes | yes | off |
| `full` | yes | yes | yes | yes | yes | yes | yes | on |

Global must-nots for every row: no URL that is not a `[Doc:slug]` pill's
`url`; no "I have attached / here is the file"; no digits next to a
currency symbol; no provider key, hostname, or internal class name.

## A — "Can you X?" (install-dependent truth)

| ID | Lang | Utterance | Profile | Route | Answer must | Must not |
| -- | ---- | --------- | ------- | ----- | ----------- | -------- |
| Q1 | en | Can you create PDFs? | `no_engine` | `synaplan` | say PDF is **not available here**; name DOCX/XLSX/PPTX/CSV as available; end with an offer ("want it as DOCX?") | promise a PDF; give a download link |
| Q2 | en | Can you create PDFs? | `full` | `synaplan` | say **yes** (PDF export via the office engine) and how to ask ("create … as PDF") | hedge with "maybe" |
| Q3 | de | Kannst du mir eine PDF erstellen? | `no_engine` | `synaplan` | German; same content as Q1; non-admin ⇒ "Administrator" mentioned once | English answer |
| Q4 | en | Would you create a song like the Beatles? | `no_engine` | `synaplan` | say it **cannot compose or produce music**; offer *original* lyrics in that style; **no** TTS mention (profile has none) | reproduce lyrics; mention MP3 |
| Q5 | en | Would you create a song like the Beatles? | `full` | `synaplan` | same, plus offer to read the lyrics aloud as MP3 (TTS available) | claim to produce audio music |
| Q6 | es | ¿Puedes buscar en internet? | `no_engine` | `synaplan` | Spanish; **yes**, web search is available; how it is triggered (ask for current information or `/search`) | "no" |
| Q7 | es | ¿Puedes buscar en internet? | `no_keys` | `synaplan` | Spanish; **not set up**; alternative: paste the text; admin hint only for admins | "yes" |
| Q8 | fr | Peux-tu lire mes e-mails ? | `full` | `synaplan` | French; e-mail search is **off by default** / needs the mailbox connection; point to Channels; `[Doc:channels]` when docs are synced | claim it reads mail now |
| Q9 | tr | Bana bir video yapabilir misin? | `no_engine` | `synaplan` | Turkish; video **not set up here**; image generation **is** available as the nearest alternative | "yes" |
| Q10 | tr | Bana bir video yapabilir misin? | `full` | `synaplan` | Turkish; **yes**; how to ask (`/vid` or "create a video …") | "no" |
| Q11 | en | Can you run Python code for me? | `full` | `synaplan` | **no arbitrary code execution**; alternative: Synaplan Desktop skills; `[Doc:desktop-skills]` when synced | pretend to execute code |
| Q12 | en | Can you remember things about me? | `no_engine` | `synaplan` | **yes** (memories, Qdrant present); how to see them | "no" |
| Q13 | en | Can you remember things about me? | `no_keys` | `synaplan` | **not available** (no Qdrant); what memories would do | "yes" |
| Q14 | de | Kannst du Audiodateien transkribieren? | `no_engine` | `synaplan` | German; **yes** (speech-to-text ships with the platform) — upload the file | "no" |
| Q15 | en | What file types can I upload? | `no_engine` | `synaplan` | the real upload list from `CapabilityService` | an invented type |

## B — "What can you do?" / "How do I…?" / "What's new?" (docs-grounded)

| ID | Lang | Utterance | Profile | Route | Answer must | Must not |
| -- | ---- | --------- | ------- | ----- | ----------- | -------- |
| Q16 | de | Was kannst du? | `no_engine` | `synaplan` | 5–8 bullets **from the AVAILABLE NOW list only**; one line on NEEDS SETUP; closing pointer to `/help` or the documentation | list video/TTS as available |
| Q17 | en | What can you do here? | `no_keys` | `synaplan` | say that the AI provider is not connected yet, name what works without one (upload/search if embeddings exist, otherwise nothing) and who can fix it | fake a capability list |
| Q18 | en | How do I connect WhatsApp? | `full` | `synaplan` | steps from `channels.md`; `[Doc:channels]` | invent a menu path |
| Q19 | de | Wie binde ich das Chat-Widget in meine Website ein? | `full` | `synaplan` | the `<script type="module">` snippet or the pointer to Widgets; `[Doc:widget]`; canonical term *Chat-Widget* | a made-up widget URL |
| Q20 | fr | Est-ce que vous supportez Nextcloud ? | `full` | `synaplan` | yes; the Nextcloud integration app; `[Doc:plugins]` | confuse with OpenCloud / ownCloud.online |
| Q21 | en | Can I use you from Outlook? | `full` | `synaplan` | Synamail add-in; `[Doc:synamail]` | describe it as a generic Outlook feature |
| Q22 | es | ¿Qué hay de nuevo? | `full` | `synaplan` | items from `intro.md` "What's New"; running version; `[Doc:intro]` | invent releases |
| Q23 | en | Are you ChatGPT? | `full` | `synaplan` | "the AI assistant of this Synaplan workspace, using the model your workspace selected" | name the provider key or a hostname |
| Q24 | en | How much does the Pro plan cost? | `full` (billing on) | `synaplan` | one benefit sentence + link to the pricing page | any number |
| Q25 | en | How much does the Pro plan cost? | `no_engine` (billing off) | `synaplan` | explain this is a self-hosted workspace without plans; no pricing link | a plan name |
| Q26 | en | /help | `no_engine` | `synaplan` (command) | same as Q16 | treat `/help` as text |

## C — Graceful inability on *task* requests (not routed to `synaplan`)

| ID | Lang | Utterance | Profile | Route | Answer must | Must not |
| -- | ---- | --------- | ------- | ----- | ----------- | -------- |
| T1 | en | Create a PDF of the following text: "Quarterly notes …" | `no_engine` | not `synaplan` (today's route) | say PDF is not available here and **offer DOCX**; no `__FILE_GENERATED__` | fabricated download link |
| T2 | de | Mach mir daraus ein Video. | `no_engine` | not `synaplan` | video not set up here; offer an image instead | "hier ist dein Video" |
| T3 | en | Write me a poem and read it to me as MP3 | `no_engine` | `mediamaker` (existing rule) | the poem, plus a plain statement that audio is not set up here | a fake MP3 |
| T4 | en | Write me a poem and read it to me as MP3 | `full` | `mediamaker` | poem + real audio delivered by the system | — |

## N — Negative controls (must NOT route to `synaplan`)

| ID | Lang | Utterance | Profile | Route | Why |
| -- | ---- | --------- | ------- | ----- | --- |
| N1 | en | Write me a poem about autumn | any | `general` | a request, not a question about the product |
| N2 | en | Can you help me with my Excel formulas? | any | `general` | "can you help" + a real task ⇒ do the task |
| N3 | de | Kannst du das zusammenfassen? *(with attachment)* | any | attachment route (`analyzefile`) | modal verb + attachment ⇒ act |
| N4 | fr | Peux-tu traduire ce texte en anglais : … | any | `general` | a translation request |
| N5 | en | What can you tell me about the Beatles? | any | `general` | "what can you" about the *world*, not about Synaplan |
| N6 | en | Search the web for Synaplan reviews | any | `tools:search` / web search | mentions the product but is a web task |

## W — Widget exclusion (behaviour unchanged)

| ID | Utterance | Channel | Expect |
| -- | --------- | ------- | ------ |
| W1 | What can you do? | widget conversation | today's widget behaviour; no `[PLATFORM_CAPABILITIES]` in the prompt; no `docs_loaded` |
| W2 | Can you create PDFs? | widget conversation | today's widget behaviour |

## Reading the results

- A **routing** failure (`Route` column) is a sorter/planner rule problem →
  `PromptCatalog::sortPrompt()` / `planPrompt()`; re-record snapshots.
- A **truth** failure ("yes" where the profile says no, or vice versa) is an
  inventory fact problem → `PlatformCapabilityInventory` source table; never
  patch the prompt to compensate.
- A **grounding** failure (missing or wrong `[Doc:…]`) is retrieval or
  corpus → `PlatformDocsRetriever` thresholds, sync state, or the docs page
  itself (fix the docs, the corpus follows).
- A **tone** failure (hedging, filler, prices) is the `synaplan` topic
  prompt wording.
