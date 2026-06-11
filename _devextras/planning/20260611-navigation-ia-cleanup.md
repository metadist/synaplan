# Navigation & Information Architecture Cleanup — Planning

**Date:** 2026-06-11 (rev. 6 — implementation status; rev. 5 — Q7 superseded:
"Task Prompts" → **"AI Instructions"** (Furkan); rev. 4: partner review —
CI-matrix honesty, staged axe rollout, testid decoupling in phase 0.5,
feature-key checkpoint, path corrections)
**Status:** **Implemented through phase 7 (partial)** — see §9 implementation log.
Phases 0.5–6 are fully landed; phase 7 landed except the parts that are
deliberately deferred (transitional redirects ≥ 2 releases, Summarizer page
retirement once the in-chat tool has soaked).
**Scope:** Frontend navigation & related surfaces (`frontend/src`): sidebar, menus, routes, chat-input action row, Files/Search pages, i18n wording in EN / DE / ES / TR, mobile navigation UX.
**Out of scope:** Backend, widget embed code, plugin internals, any feature behaviour.
(One scoped exception, decided during phase 4: the backend's widget hand-off
URL builder + its unit test fixtures moved to the canonical
`/channels/widgets/...` path — `WidgetPublicController.php`,
`SlackNotificationServiceTest.php`.)

---

## 1. Goal

The main menu has grown organically and now mixes several mental models. The words
**"Agents"**, **"Inbound/Incoming/Connections"**, **"Files / File Manager / RAG /
Semantic Search"** each appear with different meanings in different places — and
differently in each of the four languages. The goal:

1. **One concept = one word** — a fixed glossary, consistently translated in all 4 locales.
2. **Everything has exactly one logical place** in the menu; menu structure matches the URL structure.
3. **No navigation by tooltip.** Every navigation icon carries a short, always-visible
   label ("New", "History", …) — on desktop **and** mobile. Tooltips/`title` may add a
   longer description ("Chat history") but are never the only affordance.
4. **The chat-input action row stops colliding with the main menu** (no duplicate
   navigation buttons under the textarea).
5. **The Files & Search (RAG) experience is cleaned up together with the menu** — same
   vocabulary, one surface, an obvious loop into chat.

Everything below was verified against the code (file references inline).

---

## 2. Current State (verified inventory)

### 2.1 Navigation surfaces today

| Surface | File | Notes |
|---|---|---|
| Icon rail sidebar (64 px, icon-only) | `components/SidebarV2.vue` | Labels only via `:title` tooltip → **invisible on touch** |
| Flyout dropdowns (Settings, Plugins, Admin children) | `SidebarV2.vue` (teleported, absolutely positioned) | Positioned for desktop; floats next to rail on mobile |
| User avatar menu (bottom of rail) | `SidebarV2.vue` | Profile, Memories (dialog), Settings, Statistics, Subscription, Logout |
| Chat modal (rail "Chat" icon opens recent-chats bottom sheet) | `SidebarV2.vue` | Good mobile pattern (bottom sheet) |
| Mobile burger button | `components/Header.vue` | Opens the rail as an overlay |
| Chat-input action row (pills under the textarea) | `components/ChatInput.vue` | See §2.6 — partially duplicates the main menu |
| In-chat "Agents" dropdown (web search / image / video) | `components/ToolsDropdown.vue` | Labeled "Agents" via `chatInput.tools.label` |
| Command palette (slash commands) | `components/CommandPalette.vue` + `stores/commands` | |
| 404 quick links | `views/NotFoundView.vue` | Uses `nav.tools` ("Agents") |

### 2.2 Current menu tree (advanced mode, admin user)

```
RAIL                          FLYOUT CHILDREN (grouped)
────                          ─────────────────────────
[+] New chat                  (icon only, tooltip "New Chat")
[💬] Chat                     → opens recent-chats modal (it is really the HISTORY)
[📁] Files                    /files
[🔍] Semantic Search          /rag
[⚙] Settings                  group "Channels":
                                Chat Widget        /tools/chat-widget
                                Email Integration  /tools/mail-handler
                                Connections        /config/inbound
                              group "AI & Agents":
                                AI Models          /config/ai-models
                                API Keys           /config/api-keys
                                AI Prompts         /config/task-prompts
                                Routing            /config/sorting-prompt
                                Summarizer         /tools/doc-summary
[🧩] Agent Plugins            /plugins/<name> …
[🛡] Admin                    Dashboard /admin · Feature Status /admin/features (dev) · System Config /admin/config
[✨] Upgrade                  /subscription (non-pro only)
[👤] Avatar menu              Profile /profile · Memories (dialog → /memories) · Settings /settings ·
                              Statistics /statistics · Subscription · Logout
```

- **Easy mode** hides Settings + Plugins entirely (`appMode.ts`, `SidebarV2.vue` line ~793).
- **Guests** see the Settings icon but it is gate-locked (advertising).
- Reachable **only** via secondary links, not the menu: `/config/api-documentation`
  (link inside API Keys + Inbound pages), `/feedbacks` (links from message context +
  Memories view), `/tools/chat-widget/live-support`, `/statistics#chats` ("show all chats").

### 2.3 The wording matrix — evidence of the chaos

Same route, different names depending on where you look (`i18n/{en,de,es,tr}.json`):

| Route | `nav.*` (menu) EN | `pageTitles.*` (tab title) EN | DE menu | ES menu | TR menu |
|---|---|---|---|---|---|
| `/config/inbound` | **Connections** | **Inbound Channels** | **Eingehend** | Entrante | Gelen |
| — page H1 itself | — | `config.inbound.title` = **"Inbound"** | | | |
| `/tools/*` section | **Agents** (`nav.tools`) | **Agents** | **Agents** (untranslated!) | **Herramientas** (= Tools!) | **Araçlar** (= Tools!) |
| `/tools/mail-handler` | Email Integration | Mail Handler | E-Mail-Integration | Gestor de Correo | E-posta İşleyici |
| — page content | "**Email Agents**" (`mail.savedHandlers`) | | | | |
| `/tools/doc-summary` | Summarizer | Document Summary | Zusammenfasser | Resumen de Documentos | Belge Özeti |
| `/config/task-prompts` | **AI Prompts** | **Task Prompts** | KI-Prompts | Prompts de Tareas | Görev Promptları |
| `/files` | Files (`nav.files`; the *dead* key `nav.filesAndRag` still ships "File Manager") | Files | Dateien — dead key: "Dateimanager" | Archivos — dead key: "**Archivos & RAG**" | Dosyalar — dead key: "**Dosyalar & RAG**" |
| `/rag` | Semantic Search | Semantic Search | Semantische Suche | Búsqueda Semántica | Anlamsal Arama |
| — page H1 itself | — | **hardcoded** "📚 Semantic Search" (`RagSearchView.vue` L9) | | | |
| `/plugins/*` | **Agent Plugins** | Agent Plugins | Agenten-Plugins | Plugins de Agentes | Ajan Eklentileri |
| in-chat tools dropdown | "**Agents**" (`chatInput.tools.label`) | — | | | |
| settings flyout group | "**AI & Agents**" (`nav.settingsAiTools`) | — | "KI & Agents" | *(missing key)* | *(missing key)* |

**The word "Agents" currently means four unrelated things:**

1. The `/tools/*` page section (chat widget + summarizer + mail handler) — `pageTitles.tools`, `nav.tools`
2. The in-chat tool dropdown (web search, image gen, video gen) — `chatInput.tools.label`, `tools.title` ("Available Agents")
3. Plugins — `nav.plugins` ("Agent Plugins")
4. Email automation — `mail.savedHandlers` ("Email Agents"), plus the legitimate "Support Agent" (human) in live support

**The "Inbound" page** is called *Connections* (menu EN), *Inbound Channels* (tab title),
*Inbound* (page H1), *Eingehend* (DE menu), while the sidebar group containing it is
called *Channels* — five names for one concept.

### 2.4 Structural findings

- **The rail "Chat" item is mislabeled.** Clicking it does not navigate — it opens the
  recent-chats modal. It is a **history/log control** wearing a destination label.
- **Menu hierarchy ≠ URL hierarchy.** "Chat Widget" lives at `/tools/chat-widget` but
  is shown under *Settings → Channels*. "Summarizer" (`/tools/doc-summary`) — an interactive
  utility, not a setting — is shown under *Settings → AI & Agents*. `/config/*` and `/tools/*`
  both feed the same Settings flyout.
- **`/config/api-documentation` exists but is in no menu** (only cross-links from two pages).
- **Statistics doubles as the chat archive** (`/statistics#chats` is the "show all chats" target).
- **Memories** is a dialog from the avatar menu; the full `/memories` page only via "show all";
  `/feedbacks` is near-orphaned.
- ES + TR `nav` blocks are **missing keys** `profile`, `settings`, `settingsChannels`,
  `settingsAiTools` → those UI strings silently render in English (the AGENTS.md i18n rule
  is being violated today).
- DE `nav.tools` = "Agents" — the English word, while ES/TR translated it as "Tools". So the
  *same icon* is "Agents" in EN/DE and "Tools" in ES/TR.

### 2.5 Dead / legacy artifacts (cleanup candidates)

| Artifact | Evidence |
|---|---|
| `components/UserMenu.vue`, `components/ChatDropdown.vue` | No imports anywhere (replaced by SidebarV2) |
| i18n keys `nav.toolsIntroduction`, `nav.filesAndRag`, `nav.fileManager`, `nav.aiConfig`, `chat.tools/files/config` | Not referenced in any component |
| `nav.tools` | Only used by `NotFoundView.vue` quick links |
| Hardcoded English strings | `RagSearchView.vue` H1/subtitle, `ToolsView.vue` `getPageDescription()`, `ToolsDropdown.vue` "Ready"/"Setup Required" badges, several `ToolsView` notifications ("Summary generated successfully!", …) |

### 2.6 Chat-input action row (`ChatInput.vue`) — the collision

What sits around the textarea today (advanced mode, signed in):

| # | Control | Kind | Issue |
|---|---|---|---|
| in-shell | `+` attach, Enhance (✨), Mic, Send | actions | OK (44 px targets, aria-labels — the good example) |
| 1 | Model dropdown | context picker | OK |
| 2 | **"Agents"** dropdown (web search / image / video) | context picker | wrong word (they are tools); guest variant is even labeled "Tools" — two names for the same button |
| 3 | Knowledge-folder `<select>` | context picker | OK in principle; naming must match Files page ("knowledge folder") |
| 4 | **Manage-folders button** (`mdi:folder-cog-outline`) → `router.push('/files')` (L429) | **navigation!** | duplicates the rail's Files item — this is the collision; icon-only, no label |
| 5 | Thinking toggle (`mdi:brain`) | toggle | `mdi:brain` is **also** the Memories icon in the avatar menu — one icon, two meanings |
| 6 | Voice-reply toggle (`mdi:volume-high`) | toggle | row is now 6 pills wide; on mobile all labels are `hidden sm:inline` → mystery-icon row |

Net: the row mixes **pickers, toggles and navigation** in one strip, overflows on
mobile, and re-uses icons/words that mean something else elsewhere.

### 2.7 Files & Search (RAG) pages — current state

- `FilesView.vue` (~2 200 lines) stacks: storage-quota widget → Nextcloud/OpenCloud promo
  banner → big permanent upload card → "Your Files" card (search field, filter panel,
  folder grid, file table, root vs. folder views, bulk actions).
- **Every upload is always vectorized** (code comment L1341: "removed processLevel —
  always vectorize"). So Files *is* the knowledge base — but the page never says that,
  and the AI angle ("knowledge folder" in the chat input) is invisible here.
- `RagSearchView.vue` is a separate page: 4 stats cards + a search box over the same
  vectorized documents, hardcoded English H1 "📚 Semantic Search", subtitle
  "AI-powered search in your **vectorized** documents" (jargon).
- The chat input talks about "Knowledge folder", the Files page about "Your Files",
  the menu about "Files" + "Semantic Search", ES/TR menus about "Files & RAG" — four
  surfaces, four vocabularies, one feature.

### 2.8 Concrete mobile breakages already spotted (fix during execution)

| # | Bug | Evidence |
|---|---|---|
| B1 | **AI Models page header button breaks on mobile.** `AIModelsConfiguration.vue` L8–23: `flex items-center justify-between` puts the `text-2xl` title ("Default Model Configuration" / DE "Standard-Modellkonfiguration") and the reset button ("Select suggested models" / DE "Empfohlene Modelle auswählen") on **one unwrappable row**. No `flex-wrap`, no `min-w-0`, no mobile variant → on ~390 px the button wraps/overflows out of the card. Fix: stack the header on mobile (`flex-col gap-3 sm:flex-row sm:items-center sm:justify-between` — the pattern FilesView already uses). | `components/config/AIModelsConfiguration.vue` L8–23 |
| B2 | **`config.aiModels.resetDefaults` exists only in EN + DE** — ES/TR render the English fallback (same class as P6). | `i18n/{es,tr}.json` (key absent) |
| B3 | The same *title + right-aligned action* header pattern is used on other config pages — sweep them with the same fix while in there (the §5 guard's overflow check will list the offenders). | various `components/config/*.vue` |

These are exactly the class of bug the §5 Layer-1 overflow check catches mechanically —
the AI Models page is therefore a mandatory surface in the layout suite.

---

## 3. Problems, summarized

- **P1 — "Agents" is overloaded** (4 meanings, inconsistent across languages).
- **P2 — One page, five names** (Inbound / Incoming / Connections / Channels / Eingehend…).
- **P3 — Files vs RAG**: "RAG" is internal jargon in a URL; "File Manager / Files & RAG /
  Semantic Search / Knowledge folder" never aligned; two pages for one feature.
- **P4 — Settings flyout is a junk drawer**: channels, AI config, and an interactive tool
  all hide behind one gear icon; URL prefixes (`/tools`, `/config`) don't match the menu.
- **P5 — Two parallel naming systems** (`nav.*` vs `pageTitles.*`) that disagree.
- **P6 — Locale gaps** (ES/TR missing nav keys → mixed-language menus) + hardcoded strings.
- **P7 — Navigation depends on tooltips**: icon-only rail with `title` attributes —
  invisible on touch, weak on desktop; "Chat" item actually opens the history modal.
- **P8 — Chat input collides with the menu**: a navigation button to /files hides among
  toggles; 6-pill row overflows mobile; "Agents/Tools" double-labeling.
- **P9 — Icon hygiene**: `mdi:brain` = Thinking *and* Memories; `SparklesIcon` = Enhance
  *and* Subscription; heroicons and mdi mixed within the same surface.
- **P10 — Dead components/keys** add noise for every future change.

---

## 4. Target design

### 4.1 Design principles

1. **One concept = one term**, defined in the glossary (§4.2), used identically in menu
   label, page H1, browser tab title, and all four locales.
2. **Menu = mental model = URL.** Zones: *work* (New, History, Files), *connect & automate*
   (Channels), *configure AI* (AI Setup), *account* (avatar), *platform* (Admin).
3. **Labels are always visible.** Short word under every nav icon, desktop and mobile.
   Tooltips (`title`) may carry a longer description ("Chat history") but never new
   information that exists nowhere else. `aria-label` = the longer description.
4. **One icon = one meaning**, one icon family per surface (heroicons outline for
   navigation; mdi allowed inside content areas).
5. **The word "agent" disappears from the UI entirely** (Q2). Mail handlers become
   **"Email Automation"**, the in-chat dropdown goes back to **"Tools"**, plugins are
   just **"Plugins"**. Only the human "Support Agent" in live-support chat copy remains —
   it describes a person.
6. **Settings ≠ everything.** The gear is reserved for *personal preferences* (theme,
   language, app mode) under the avatar as **"Preferences"**.
7. **Navigation lives in the menu, context lives at the input.** The chat-input row may
   *pick context* (model, tools, knowledge folder) and *toggle behaviour* — it must not
   *navigate*.
8. No information loss: every existing page keeps a home; old URLs redirect.

### 4.2 Glossary — canonical terms in 4 languages

Nav items get two strings: **Label** (short, always visible) and **Description**
(longer; used for `aria-label`, hover `title`, flyout headers).

| Concept (key) | EN label | EN description | DE | ES | TR |
|---|---|---|---|---|---|
| New (chat) | New | Start a new chat | Neu | Nuevo | Yeni |
| **History** (was rail "Chat") | History | Chat history | Verlauf *(Chat-Verlauf)* | Historial *(Historial de chats)* | Geçmiş *(Sohbet geçmişi)* |
| Files | Files | Files & knowledge | Dateien | Archivos | Dosyalar |
| Search (tab in Files) | Search | Search your documents | Suche | Búsqueda | Arama |
| Knowledge folder | Knowledge folder | — | Wissensordner | Carpeta de conocimiento | Bilgi klasörü |
| **Channels** (section) | Channels | Connected channels | Kanäle | Canales | Kanallar |
| Channels overview | Overview | Connected channels & status | Übersicht | Resumen | Genel Bakış |
| Chat Widget | Chat Widget | — | Chat-Widget | Widget de chat | Sohbet Widget'ı |
| Live Support | Live Support | — | Live-Support | Soporte en vivo | Canlı Destek |
| **Email Automation** (was "Email Agents", Q2) | Email Automation | Automated mail handlers | E-Mail-Automatisierung | Automatización de correo | E-posta Otomasyonu |
| WhatsApp | WhatsApp | — | WhatsApp | WhatsApp | WhatsApp |
| API Keys | API Keys | — | API-Schlüssel | Claves de API | API Anahtarları |
| API Docs | API Docs | — | API-Dokumentation | Documentación de la API | API Dokümantasyonu |
| **AI Setup** (section) | AI Setup | Models, prompts & routing | KI-Konfiguration | Configuración de IA | AI Yapılandırması |
| AI Models | AI Models | — | KI-Modelle | Modelos de IA | AI Modelleri |
| **AI Instructions** (was "Task Prompts", Q7 rev.) | AI Instructions | aka system prompts — how the AI handles each task | KI-Anweisungen *(auch: System-Prompts)* | Instrucciones de IA | AI Talimatları |
| Routing | Routing | — | Routing | Enrutamiento | Yönlendirme |
| Summarizer | Summarizer | — | Zusammenfasser | Resumidor | Özetleyici |
| Tools (in-chat dropdown) | Tools | Chat tools | Tools | Herramientas | Araçlar |
| Plugins | Plugins | — | Plugins | Plugins | Eklentiler |
| Memories | Memories | — | Erinnerungen | Recuerdos | Anılar |
| Feedback | Feedback | — | Feedback | Comentarios | Geri Bildirim |
| Statistics | Statistics | Usage & chat archive | Statistiken | Estadísticas | İstatistikler |
| **Preferences** (was "Settings") | Preferences | App preferences | Einstellungen | Preferencias | Tercihler |
| Profile | Profile | — | Profil | Perfil | Profil |
| Admin | Admin | — | Admin | Admin | Yönetici |
| Subscription | Subscription | — | Abonnement | Suscripción | Abonelik |
| Upgrade | Upgrade | — | Upgrade | Mejorar | Yükselt |

Language conventions: **KI** in German, **IA** in Spanish, **AI** kept in Turkish
(matches existing TR strings), "Prompt" stays untranslated everywhere (already the
de-facto rule). Never use "Inbound/Incoming/Eingehend/Entrante/Gelen" in any UI string
again — the `config.inbound.*` key block is renamed `channels.*`.

**"AI Instructions" rule (Furkan, rev. 5):** the nav label and page title avoid the
jargon word "prompt" — normal users understand "instructions". The technical term
survives in the tooltip/description (**"aka system prompts"**) and in docs/API, so
power users and existing documentation still connect. Other *user-facing* "task prompt"
copy (e.g. the widget creation form's prompt picker label) adopts the same wording in
phase 1; code identifiers, i18n key names, e2e spec filenames, and backend topic keys
stay technical (`task-prompts`, `taskPrompts.*`) — renaming those buys nothing.

### 4.3 Navigation anatomy — best practice we adopt

This answers "what is best practice for these navigational changes":

1. **Labeled navigation rail (desktop).** The rail widens from 64 px to **~80 px**
   (Material 3 navigation-rail spec): 24 px icon + an always-visible **10–11 px label**
   underneath, 44–48 px touch/click target, active state = filled icon + brand tint.
   Icon-only navigation measurably hurts findability (NN/g: "icons need a text label");
   tooltips do not exist on touch devices.
2. **Bottom tab bar (mobile)** instead of a hidden drawer for the primary items:
   **New · History · Files · More** — each with icon + label (the native pattern on
   iOS/Android, 4–5 items max). "More" opens a labeled sheet/drawer with the remaining
   sections (Channels, AI Setup, Plugins, Admin, account block). The existing
   recent-chats bottom sheet stays as-is — it is already the best mobile element here.
3. **Flyouts become accordions on touch.** Desktop keeps hover/click flyouts next to the
   rail; in the mobile "More" sheet the same groups render as expandable lists inline.
4. **Tooltip discipline.** Visible label = short word; `title`/`aria-label` = full
   description from the glossary ("History" / "Chat history"). Never label-by-tooltip-only;
   never tooltip text identical to the visible label (screen-reader duplication).
5. **One icon, one meaning** (see §4.5) and one icon family per surface.
6. **Rename safely:** every moved URL gets a redirect; `data-testid`s migrate with the
   labels; ship wording → structure → URLs as separate releases so support/docs can follow.

### 4.4 Target menu structure

```
RAIL (desktop, ~80 px, icon + label)      CHILDREN
────────────────────────────────────      ────────
[＋]  New                                 creates chat & navigates (accent button)
[🕘] History                              opens recent-chats sheet (active chat highlighted)
[📁] Files                                /files — tabs inside the page: Browse | Search
[📡] Channels                             Overview · Chat Widgets (incl. Live Support) ·
                                          Email Automation · API (Keys + Docs)
[🎛] AI Setup                             AI Models · AI Instructions · Routing
[🧩] Plugins                              (only if plugins exist)
[🛡] Admin                                Dashboard · Feature Status (dev) · System Config
[⤴] Upgrade                               (unchanged, non-pro only)
[👤] Account                              Profile · Memories · Statistics · Preferences ·
                                          Subscription · Sign out

MOBILE: bottom tab bar  [＋ New] [🕘 History] [📁 Files] [☰ More]
        "More" sheet = Channels / AI Setup / Plugins / Admin as accordions + account block
```

- **"Chat" disappears as a label** — it described a destination, but the control is a
  history browser. "History" says what it does; the longer description "Chat history"
  lives in `aria-label`/tooltip. (Alternatives considered: "Chats", "Recent", "Log" —
  "History" is the clearest and translates compactly: Verlauf / Historial / Geçmiş.)
- **Easy mode (Q6 — decided): locked, not hidden.** Channels and AI Setup stay visible
  in the rail with a small lock badge; tapping opens a dialog with a one-click
  **"Switch to Advanced mode"** action (writes `appMode`, then navigates). This mirrors
  the guest pattern — guests keep the signup gate-modal instead. The easy/advanced
  toggle in Preferences remains the explicit switch; `appMode.ts` no longer *filters*
  nav items, it *decorates* them.
- **Summarizer (Q3 — decided):** moves out of the nav into the **in-chat Tools dropdown**
  (it is a one-shot utility, not configuration). Until that integration is built it stays
  as the fourth child of AI Setup — explicitly transitional. The feature is **API-first**:
  `POST /api/v1/summary/generate` is consumed by `synaplan-nextcloud` and other plugins —
  that route is untouched by this whole plan, and the **API Docs page (Channels → API)
  lists the Summarize endpoint** so the capability stays discoverable after the page
  retires.
- **Statistics** keeps doubling as chat archive (`#chats`) — unchanged (Q5).

### 4.5 Icon map (one icon = one meaning, heroicons outline for nav)

| Item | Today | Proposed | Why |
|---|---|---|---|
| New | `mdi:plus` | `PlusIcon` (accent pill) | unchanged, gets label "New" |
| History | `ChatBubbleLeftRightIcon` | `ClockIcon` | bubble said "chat page"; clock says history |
| Files | `FolderIcon` | `FolderIcon` | fine |
| Search (tab) | `MagnifyingGlassIcon` | `MagnifyingGlassIcon` | moves inside Files page |
| Channels | — (was gear group) | `SignalIcon` | broadcast/connectivity metaphor |
| AI Setup | — (was gear group) | `CpuChipIcon` | "the AI machinery"; gear is freed for Preferences |
| Plugins | `PuzzlePieceIcon` | `PuzzlePieceIcon` | fine |
| Admin | `ShieldCheckIcon` | `ShieldCheckIcon` | fine |
| Upgrade | `mdi:auto-fix` | `RocketLaunchIcon` | frees sparkles for "Enhance" only |
| Preferences | `Cog6ToothIcon` (overloaded) | `Cog6ToothIcon` | gear now means *only* preferences |
| Memories | `mdi:brain` | `mdi:brain` (keep) | brain = memories, nowhere else |
| Thinking toggle (chat) | `mdi:brain` (**collision**) | `LightBulbIcon` / `mdi:lightbulb-on-outline` | resolves the double meaning |
| Enhance (chat) | `SparklesIcon` (also = subscription) | `SparklesIcon` (keep) | subscription menu icon changes instead |
| Subscription (avatar menu) | `SparklesIcon` | `CreditCardIcon` | one icon, one meaning |

### 4.6 URL map and redirects

| Old path | New path | Note |
|---|---|---|
| `/files` | `/files` | unchanged (Browse tab) |
| `/rag` | `/files/search` | redirect; kills "RAG" from the UI/URL |
| `/config/inbound` | `/channels` | section overview |
| `/tools/chat-widget` | `/channels/widgets` | |
| `/tools/chat-widget/:id` | `/channels/widgets/:id` | + `/chats` subroute |
| `/tools/chat-widget/live-support` | `/channels/widgets/live-support` | |
| `/tools/mail-handler` | `/channels/email` | |
| `/config/api-keys` | `/channels/api` | API is a channel; keys configure it |
| `/config/api-documentation` | `/channels/api/docs` | now reachable from the menu |
| `/config/ai-models` | `/ai/models` | |
| `/config/task-prompts` | `/ai/instructions` | label = URL ("AI Instructions", Q7 rev.) |
| `/config/sorting-prompt` | `/ai/routing` | matches its label at last |
| `/tools/doc-summary` | `/ai/summarizer` *(transitional)* | page retires into chat Tools (Q3); backend `POST /api/v1/summary/generate` **unchanged** (Nextcloud + plugin consumers) and listed on `/channels/api/docs` |
| `/settings` | `/settings` | URL stays; UI label becomes "Preferences" |
| `/tools`, `/config` | redirect to `/channels` | |
| everything else | unchanged | `/memories`, `/feedbacks`, `/statistics`, `/profile`, `/admin/*`, `/subscription`, `/plugins/*`, public routes |

All old paths stay as **router redirects for at least 2 releases** (bookmarks, docs,
support articles). `mapPathToFeatureKey()` (`router/index.ts` L395, guest gating) must be
updated to the new prefixes — **and must keep returning the *same* feature keys**
(`'files'`, `'settings'`, `'memories'`, `'statistics'`): these strings are backend
feature-status keys (admin Feature Status), so `/channels/*` and `/ai/*` map to
`'settings'` and `/files/search` to `'files'`. A wrong key here fails **silently** (guest
gate shows the wrong upsell or none) — explicit checkpoint in phase 4.

### 4.7 Chat-input action row — streamlined

Rule from §4.1: *pick context and toggle behaviour here — never navigate.*

```
TODAY   [Model ▾] [Agents ▾] [Folder ▾] [⚙→/files] [🧠 Thinking] [🔊 Voice reply]   (6 pills)
TARGET  [Model ▾] [Tools ▾] [Knowledge folder ▾]                                    (3 pills)
```

1. **Remove the manage-folders navigation button** (`mdi:folder-cog-outline` →
   `router.push('/files')`). Replacement: the knowledge-folder picker becomes a small
   dropdown panel whose last row is "Manage folders… → Files" (clearly a link, inside
   the picker, not a sibling button). The main menu remains the one way to "go to Files".
2. **"Agents" dropdown → "Tools"** (`chatInput.tools.label`), same word in all 4 locales
   (Tools / Tools / Herramientas / Araçlar); the guest pill already says "Tools" — now
   they match. **Thinking** and **Voice reply** move into this dropdown as toggle rows
   (with their state visible on the pill via a small badge/dot when active — Q8 decided).
   Summarizer joins here as a tool entry (Q3 decided).
3. **Folder picker speaks glossary**: label "Knowledge folder", same term as the Files
   page and docs. Selecting a folder shows it as an active pill (as today).
4. **Mobile:** the three remaining pills keep their short labels visible (no more
   `hidden sm:inline` icon mystery); the row no longer overflows.
5. Icon fixes per §4.5 (Thinking → lightbulb).

The in-shell buttons (attach `+`, Enhance, Mic, Send) are already correct (44 px,
aria-labels) and stay unchanged.

### 4.8 Files & Search — one surface, one story

Target: `/files` is **the knowledge base**, with two tabs and the chat loop wired in.

```
/files
├─ Tab "Files"  (default)        ├─ Tab "Search"
│  · upload (button + drag&drop)  │  · one search box over your documents
│  · folder grid = knowledge      │  · results with open/preview actions
│    folders (vector status dot)  │  · compact status line (n docs indexed)
│  · file table, filters          │    instead of 4 jargon stat cards
```

1. **One vocabulary.** Page intro says what the chat input already implies: *"Files you
   upload become searchable knowledge for your chats. Folders can be used as knowledge
   folders to scope a conversation."* The word "RAG" and "vectorized" disappear from
   the UI ("indexed"/"searchable" instead); "Semantic Search" becomes just **Search**
   (within Files context the qualifier is noise).
2. **Close the loop with chat.** Folder cards get a **"Use in chat"** action → opens a
   chat with that knowledge folder preselected (sets the picker from §4.7). The picker's
   "Manage folders…" row deep-links back here. One concept, both directions.
3. **De-clutter the page top.** Storage quota shrinks to a compact bar in the page
   header (full card only near limits); the Nextcloud/OpenCloud promo banner moves below
   the file list (it currently outranks the user's own content); the permanent upload
   card becomes a primary "Upload" button + the existing full-page drag&drop overlay.
4. **Search tab = cleaned RagSearchView**: i18n'd header (fixes the hardcoded H1),
   stats collapsed to one status line, result rows reuse the file-table actions
   (view / download / open folder).
5. The chat-side file features (`@`-mention palette, attach button, `FileSelectionModal`)
   are already consistent with this model and stay unchanged.

### 4.9 i18n key restructure

1. **Single source of truth per concept.** New flat block `nav.*` keyed by the glossary,
   each entry with `label` + `description` (e.g. `nav.history.label` = "History",
   `nav.history.description` = "Chat history"). `pageTitles.*` keeps only entries with
   no menu item (login, register, 404, shared chat…); every menu-reachable route's
   `titleKey` points at the **same** `nav.*` key the menu uses — menu label, H1, and
   browser tab can never diverge again.
2. **Fill the gaps:** add the missing ES/TR keys; every new key lands in all 4 files in
   the same commit (house rule).
3. **Replace hardcoded strings** found in §2.5 / §2.7 with keys.
4. **Delete dead keys** (§2.5) once SidebarV2 / NotFoundView are updated.
5. Rename `config.inbound.*` → `channels.*`; update non-menu usages of retired terms:
   `tools.title` ("Available Agents"), `chatInput.tools.label` ("Agents" → "Tools"),
   `nav.settingsAiTools` (deleted), guest gate texts referencing "tools", and the
   `mail.*` block — "Email Agents" copy (`mail.savedHandlers` etc.) becomes
   **"Email Automation"** wording in all 4 locales (Q2).

---

## 5. Quality gate — how every step is verified (desktop + mobile)

**Decision:** we extend the **existing Playwright e2e suite** with a small, deterministic
**"UI guard"** (layout contracts + contrast checks + a hard-capped set of visual
snapshots), run it on desktop **and** a mobile viewport, build it **before** the refactor
starts (phase 0.5), and let it run in the per-PR CI gate forever. No new *platform*
(Chromatic/Percy/…), one new dev dependency: `@axe-core/playwright` (ask-first approval).

**Honesty box (from partner review, code-verified):** "rides the existing gate" still
requires **three new CI building blocks** — (a) a new entry in the e2e job **matrix**
(`.github/workflows/ci.yml` L328–352 pins `npm_script` + `grep_filter` per entry; nothing
joins automatically), (b) a `test:e2e:mobile` npm script for the `chromium-mobile`
project, (c) the `@visual` job + a workflow-dispatch baseline-update job. The layout
suite must be **`@ci`-tagged**, otherwise the password-auth matrix entries
(`--grep "@ci"`) never execute it.

### 5.1 What exists today (verified)

- Playwright e2e **already gates every PR**: `.github/workflows/ci.yml` runs a 4-entry
  **matrix** (chromium + firefox with `--grep "@ci"`, two OIDC variants) against the
  docker-built app, and `All Checks Passed` requires it. The pipeline exists — but each
  matrix entry hard-codes its `npm_script` + `grep_filter`, so new projects must be added
  to the matrix explicitly (see honesty box above).
- `frontend/tests/e2e/tests/navigation.spec.ts` already covers the sidebar *functionally*
  (easy/advanced modes, flyouts, user menu, admin) — it must be rewritten alongside
  phases 2/4/6 anyway; the selectors live centrally in
  `frontend/tests/e2e/helpers/selectors.ts`.
- **No mobile coverage exists.** All three Playwright projects are `Desktop Chrome` /
  `Desktop Firefox`; nothing in the suite sets a viewport. Mobile is completely
  unverified today.
- **No visual regression** (`toHaveScreenshot` unused anywhere) and **no a11y/contrast
  automation** (no axe).
- Vitest component tests run in jsdom, which has **no layout engine** — "does it render
  nicely / overflow / overlap" is untestable at unit level by construction. Playwright is
  the right (and already-paid-for) place for it.

### 5.2 The three layers of the UI guard

**Layer 1 — layout contracts (deterministic geometry — the core).**
New `frontend/tests/e2e/tests/layout.spec.ts`, run in two projects: the existing desktop chromium
**plus a new `chromium-mobile` project** (`devices['iPhone 14']`, 390×844, plus one
320 px narrow check for the rail/bottom bar). The mobile project is **grep-limited to
the layout suite** — unleashing 25 desktop-designed functional specs on a phone viewport
would produce noise, not signal. Per key surface (chat, History sheet, Files,
one Channels page, the **AI Models page** — a known offender, see §2.8 B1 — and login)
it asserts exactly the contracts of this redesign:

| Contract | Assertion (plain Playwright, no plugins) |
|---|---|
| No horizontal overflow | `document.documentElement.scrollWidth <= clientWidth` — the #1 mobile breakage |
| Labels always visible | the nav item's *label node* `toBeVisible()` — enforces §4.1 #3 mechanically (tooltip text does NOT count) |
| Touch targets | `boundingBox()` of every nav/tap control ≥ 44 px in the mobile project |
| Reachability | chat input + send button fully inside the viewport on mobile |
| No menu collision | the chat-input action row contains no control that navigates to a rail destination (selector-based, guards §4.7 #1) |

These are normal `@ci` tests: fail-fast, deterministic, no screenshots — they follow
`docs/E2E_TESTING.md` 1:1. They are still **full-stack e2e** (login + seeded backend,
like every other spec), so "fast" means the assertion style, not unit-test runtimes —
see the honest cost table in §5.3.

**Layer 2 — contrast & a11y (axe-core) — introduced as a ratchet, not a day-1 gate.**
`@axe-core/playwright` scans the same surfaces **in light and dark mode**
(`page.emulateMedia({ colorScheme: 'dark' })`) for serious/critical violations including
`color-contrast`. **Staged rollout (partner review):** an organically grown app almost
certainly carries existing violations (muted text, icon-only buttons, brand colors), and
contrast fixes are often *design-token decisions*, not quick bugfixes — gating on the
first run would turn CI red before phase 1 even starts. Therefore:

1. **Phase 0.5:** axe runs **report-only** (CI artifact + PR annotation, non-blocking);
   findings are triaged into the phase 1/2 work.
2. **Phases 1–2:** violations are fixed surface-by-surface (token changes reviewed with
   design, not patched ad hoc).
3. **Flip to blocking** per surface as it reaches zero serious/critical — target: fully
   gating by end of phase 2, and any *new* violation fails the PR from then on.

"Right contrast, also in dark mode" still becomes a machine check — it just earns its
gate instead of detonating on arrival. Same spec file, both projects.

**Layer 3 — visual snapshots (deliberately capped at ~10).**
`toHaveScreenshot` for a fixed list of stable, masked shots: rail (light + dark), mobile
bottom bar + "More" sheet, chat-input row, Files header, one flyout. Tagged `@visual`
and **CI-only** (grep-excluded locally): font rendering differs between dev machines
(WSL/macOS) and the ubuntu runner, so local baselines would be permanently red.
Baselines are regenerated by a small workflow-dispatch CI job (`--update-snapshots`,
result committed via PR) — **never from a laptop**: baselines are only valid when
produced in the same runner image/container that CI uses, anything else is permanently
red. Same re-record discipline as the routing snapshots (`UPDATE_ROUTING_SNAPSHOTS`).
Animations disabled in snapshot mode. To be explicit (partner review): the `@visual` job
and the baseline-update dispatch job **are new CI building blocks** — this is the most
expensive, most fragile layer of the three, which is exactly why it is capped at ~10
shots and why **a snapshot diff is reviewed like code, never blind-approved.** If the
cap or the flake rate is ever breached, we drop shots before we add process.

### 5.3 Cadence — how often, and is it "normal Playwright"?

| When | What runs | Cost (honest) |
|---|---|---|
| Inner dev loop (after each change/step) | layout spec for the touched surface, mobile project, headed — new make target `make -C frontend test-e2e-layout` (wraps `npx playwright test layout --project=chromium-mobile`) | **~1–3 min**, and it is full-stack: requires the dev stack running (`docker compose up`) + host-side Playwright (`make -C frontend deps-host`, e2e runs on the host like `make -C frontend test-e2e` today) + login against seeded backend. Not a unit-test loop — it replaces *manual* browser checking, which it beats easily |
| **Every PR (automatic, forever)** | Layers 1+2 via **new `chromium-mobile` matrix entry** (+ layout spec `@ci`-tagged so the desktop chromium entry picks it up too); Layer 3 as the separate `@visual` job | +2–4 min wall-clock on an already-running workflow |
| Per refactor phase (§6) | full UI guard green on desktop + mobile = part of the phase's definition of done; `@visual` baselines re-approved in the same PR when a phase intentionally changes pixels | — |
| Nightly / pre-release | unchanged full e2e matrix | — |

So yes: **these are normal Playwright tests.** They are not throwaway refactor scaffolding —
they encode the navigation contracts (labels visible, no overflow, 44 px targets, no
input-row navigation, contrast) and keep guarding every future feature exactly the same way.

### 5.4 Sequencing decision

The UI guard lands as **phase 0.5, before any wording or structure changes**, baselined
against the *current* UI. Every later phase then works under an already-green safety
net, and the guard's own diff documents what each phase intentionally changed (e.g.
phase 2 switches the "label visible" assertion from tooltip attribute to label node).

Phase 0.5 also performs the **testid decoupling** (partner review): the sidebar currently
*generates* testids from route paths —

```78:78:frontend/src/components/SidebarV2.vue
        :data-testid="`btn-sidebar-v2-${item.path.replace(/\//g, '-')}`"
```

(child links likewise, L312). Left alone, the URL migration (phase 4) would silently
rename **every** nav testid and break `selectors.ts` + all consumers a second time after
phase 2. So 0.5 replaces the derivation with **stable keys** (`item.key` →
`btn-sidebar-v2-files`, `link-sidebar-v2-ai-models`, …) in the same PR that builds the
guard: a behaviour- and pixel-invariant change, after which neither phase 2's
restructure nor phase 4's URL renames touch nav selectors for *mechanical* reasons —
only for *intentional* ones (items added/renamed). Vitest component tests referencing
the generated ids are updated in the same PR.

### 5.5 What we deliberately do NOT do

- **No new visual-testing platform** (Chromatic, Percy, Storybook + Lost Pixel, …) —
  external infra + cost while a per-PR Playwright pipeline already exists. (The honest
  delta we *do* add is listed in the §5 honesty box: matrix entry, npm script, `@visual`
  + baseline-update jobs.) Re-evaluate only if the snapshot list genuinely needs to
  outgrow its cap.
- **No axe gate on day 1** — report-only first, ratchet to blocking per surface (§5.2
  Layer 2); a red wall on the first run helps nobody.
- **No full-page screenshots of dynamic pages** (chat with AI messages, statistics) —
  non-deterministic content guarantees flakes; the geometry assertions cover those pages.
- **No layout assertions in Vitest/jsdom** — no layout engine, false confidence.
- **No per-commit full e2e** — the inner loop uses the scoped layout spec; the full
  suite stays a PR/CI concern (matches the existing house split of unit vs e2e).

---

## 6. Rollout plan (phased, each independently shippable)

| Phase | Content | Risk | Touches |
|---|---|---|---|
| **0. Sign-off** | ✅ Done 2026-06-11 — glossary (§4.2), tree (§4.4), URL map (§4.6), QA gate (§5) agreed; all Q1–Q9 decided (§7). | — | this doc |
| **0.5 UI guard** | Build the Playwright UI guard against the **current** UI (§5): `layout.spec.ts` (`@ci`-tagged), **new `chromium-mobile` CI matrix entry + `test:e2e:mobile` npm script**, axe scans **report-only**, `@visual` baselines + dispatch job, `test-e2e-layout` make target. **Decouple sidebar testids from route paths** (§5.4 — stable keys, pixel-invariant). **Then fix the breakages the guard immediately exposes** so the baseline is green — known: §2.8 B1 (AI Models header button on mobile) + B3 sweep, B2 locale gap. | Low | `frontend/tests/e2e/` (config + 1 spec + selectors), `.github/workflows/ci.yml` (matrix + visual/baseline jobs), `frontend/package.json` (scripts + axe dep — ask first), `SidebarV2.vue` (testids only), Vitest specs touching `btn-sidebar-v2-*`, `components/config/AIModelsConfiguration.vue` (+ siblings per B3), `i18n/{es,tr}.json` |
| **1. Wording only** | New/renamed i18n keys in all 4 locales (incl. New/History labels + descriptions), fix ES/TR gaps, de-hardcode strings, "Agents"→"Tools" in chat, "Agent Plugins"→"Plugins", "Email Agents"→**"Email Automation"** (`mail.*`, Q2), "Task Prompts"→**"AI Instructions"** (+ "aka system prompts" tooltip, Q7 rev.), kill all "Inbound/Incoming" strings. **No route or layout changes.** | Low | `i18n/*.json`, `SidebarV2.vue` labels, `ToolsDropdown.vue`, `RagSearchView.vue`, `ToolsView.vue`, `NotFoundView.vue`, mail-handler components |
| **2. Rail redesign** | 80 px labeled rail (icon + visible label), icon swaps per §4.5, split Settings flyout into **Channels** + **AI Setup**, avatar menu reorder ("Preferences"), **easy mode shows Channels/AI Setup locked with "Switch to Advanced" dialog instead of hiding them (Q6)** — `appMode` decorates, no longer filters. Old routes still working. | Medium | `SidebarV2.vue`, `stores/commands`, `stores/appMode.ts` |
| **3. Chat-input streamline** | §4.7: remove nav button, fold Thinking/Voice-reply into Tools dropdown, folder-picker panel with "Manage…" row, mobile labels. | Medium | `ChatInput.vue`, `ToolsDropdown.vue` |
| **4. URL migration** | New route tree + redirects (§4.6); `mapPathToFeatureKey` — **checkpoint: the returned feature keys (`'files'`, `'settings'`, `'memories'`, `'statistics'`) are backend feature-status keys and MUST stay identical for the new prefixes (`/channels/*`, `/ai/*` → `'settings'`; `/files/search` → `'files'`), otherwise guest gating breaks silently**; `helpContent.ts`; e2e specs (§6.1) + new `redirects.spec.ts`; docs. Nav testids are already path-independent since 0.5. | Medium-high | `router/index.ts` (incl. L395 `mapPathToFeatureKey`), `ConfigView.vue`/`ToolsView.vue` split, e2e per §6.1 |
| **5. Files & Search merge** | §4.8: tabs, page de-clutter, "Use in chat" loop, Search tab cleanup. `/rag` redirect lands here if not in phase 4. | Medium | `FilesView.vue`, `RagSearchView.vue`, `ChatInput.vue` (picker preselect) |
| **6. Mobile navigation** | §4.3: bottom tab bar (New/History/Files/More), "More" sheet with accordions, 44 px targets, aria, safe-area insets. | Medium | `SidebarV2.vue` (split into `SidebarRail` + `MobileNav`), `Header.vue` |
| **7. Cleanup** | Delete `UserMenu.vue`, `ChatDropdown.vue`, dead keys; retire the Summarizer page once the in-chat tool ships (Q3 — **API route + API-docs listing stay**); drop transitional redirects (≥ 2 releases later). | Low | misc |

**Pre-merge checks per phase** (house gate): `make lint && make -C backend phpstan && make test
&& docker compose exec -T frontend npm run check:types` — plus the **UI guard from §5
green on desktop + mobile** (it runs in the per-PR e2e job automatically from phase 0.5
on) and, for phases 4–6, a manual pass of the full Playwright e2e suite and a grep of
**backend templates/emails for hardcoded frontend paths** before shipping redirects.

**Cross-repo impact check (phase 4):** `synaplan-website` / docs / support articles linking
to `web.synaplan.com/...` paths; Synamail is unaffected (`/addin/connect` untouched);
widget embed (`widget.ts`) and plugin routes (`/plugins/sortx`) unchanged.

### 6.1 e2e suite impact map (update WITH the code, same PR)

Verified against `frontend/tests/e2e/` (2026-06-11). Two findings up front:

- **Good:** no spec asserts a nav *label text* (`hasText` is only used for generated
  test data like handler/widget names) → **phase 1 (wording) breaks zero e2e tests.**
  And no spec touches the Tools dropdown / Thinking / Voice-reply / knowledge-folder
  pills → **phase 3 breaks zero e2e tests** (it only *lacks* coverage — the new Tools
  dropdown content gets a test then).
- **Structural trap:** sidebar testids are **derived from route paths**
  (`SidebarV2.vue` L78 `btn-sidebar-v2-${item.path.replace(/\//g,'-')}`, child links
  L312). Left as-is, nav selectors break **twice** (phase 2 restructure *and* phase 4
  URL renames). **Resolved (partner review): the decoupling to stable, name-based
  testids** (`btn-sidebar-v2-files`, `btn-sidebar-v2-channels`, `link-sidebar-v2-ai-models`,
  …) **lands in phase 0.5** together with the UI guard (§5.4) — then neither phase 2 nor
  phase 4 touches nav selectors for mechanical reasons.

| File | Uses today | Update in phase |
|---|---|---|
| `frontend/tests/e2e/helpers/selectors.ts` | the single registry: `nav.*` (path-derived ids, incl. `sidebarV2Settings` = `btn-sidebar-v2--settings`), `userMenu.settingsBtn`, `rag.page`, `pages.tools` | **0.5** (stable-id scheme, §5.4), **2** (Settings icon → `sidebarV2Channels` + `sidebarV2AiSetup`; History; `userMenu.settingsBtn` → `preferencesBtn`), **4** (`pages.tools` retired), **5** (`rag.*` → files-search tab ids) |
| `tests/navigation.spec.ts` | the whole sidebar contract: `ensureAdvancedMode()` **waits for the Settings icon**, Settings flyout open/navigate (Chat Widget, AI Models), chat-modal open, user-menu Settings entry, admin flyout | **2** (substantial rewrite: two flyouts instead of one, Preferences, History; easy-mode assertion flips from "icon hidden" to "icon visible + locked + switch-to-advanced dialog" per Q6), **4** (flyout destinations), **6** (add mobile-drawer/bottom-bar variants) |
| `tests/task-prompts.spec.ts` | `goto('/config/task-prompts')` ×3 (`PAGE` const, L7) | **4** → `/ai/instructions` (spec filename stays) |
| `tests/inbound-email-handler.spec.ts` | `goto('/tools/mail-handler')` (L32) | **4** → `/channels/email` |
| `tests/widget.spec.ts` + `helpers/widget.ts` | `goto('/tools/chat-widget')` ×4 | **4** → `/channels/widgets` |
| `tests/rag-search.spec.ts` | `goto('/rag')` (L64), `selectors.rag.*`, `page-rag-search` | **4** (route) + **5** (page becomes Files → Search tab; stats-card assertions per §4.8) |
| `tests/chat-share.spec.ts` | opens the chat list via the rail item (`modalChatManager`, `chatV2Row*`) | **2** (only the rail-item selector rename to History; modal internals unchanged) |
| `tests/auth.spec.ts`, `oidc-auth.spec.ts` | `userMenu.button` + `logoutBtn` only | none (verify-only — avatar + Sign out keep ids) |
| `tests/oidc-admin.spec.ts` | `goto('/admin')`, `toHaveURL(/admin/)` | none (admin URLs unchanged) |
| `tests/subscription*.spec.ts` + `helpers/billing.ts` | `userMenu.subscriptionBtn`, `btn-sidebar-v2-upgrade` | none (ids stay; icon swap only) |
| `tests/chat*.spec.ts`, `multitask`, `ollama-integration`, `email`, `whatsapp`, `guest-chat`, `castingdata-plugin`, `registration`, `auth-api`, `inbound-email-handler-api`, remaining `oidc-*` | `selectors.chat.*` (input/send/attach), guest banner, pure API | none |

Additional rules for the execution PRs:

1. **Redirects ≠ excuse:** phase 4's redirects keep old `goto()` paths green, but specs
   are updated to the canonical new paths **in the same PR**, plus a small new
   `redirects.spec.ts` asserting each §4.6 old → new mapping (it also becomes the
   watchdog for when the transitional redirects are removed in phase 7).
2. **Selector edits only in `helpers/selectors.ts`** (house rule) — every phase's PR
   touches the registry + the specs, never inline strings.
3. `navigation.spec.ts` helpers (`ensureAdvancedMode`, `openSettings`) encode the *old*
   IA — phase 2 replaces them with helpers for the new tree (`openChannels`,
   `openAiSetup`, `openPreferences`).

---

## 7. Decisions (all questions resolved 2026-06-11)

Earlier review feedback already fixed: labels **always visible** under nav icons
(desktop + mobile, no tooltip-only navigation); rail "Chat" renamed; Files & Search
cleaned up together with the menu; QA = Playwright UI guard per §5 (extend the existing
suite, desktop + mobile projects, per-PR cadence, capped visual snapshots).

Q1–Q9 were answered by the team on 2026-06-11; the resolutions below are **final** and
already propagated into §4/§6. Q2 and Q6 diverge from the original recommendation.

| # | Question | Decision |
|---|---|---|
| Q1 | Name for the renamed rail item (was "Chat")? | ✅ **History** (Verlauf / Historial / Geçmiş); full "Chat history" in tooltip/aria. |
| Q2 | "Email Agents" vs "Email Automation"? | ✅ **Email Automation** — the word "agent" now disappears from the UI **entirely** (strengthens §4.1 #5). `mail.*` copy ("Email Agents") is rewritten in phase 1. |
| Q3 | Retire the Summarizer page into the in-chat Tools dropdown? | ✅ **Yes** — in-chat Tools is the interactive home. **But the Summarizer is API-first**: `POST /api/v1/summary/generate` (`SummaryController`, `docsummary` prompt) is consumed by `synaplan-nextcloud` (`SynaplanClient.php` ×2) and other plugins — the **API route must stay untouched**, and the **API Docs page (Channels → API) must list it** so the capability remains discoverable. Page parks under AI Setup transitionally; frontend URL redirects per §4.6. |
| Q4 | "API" under Channels or avatar/developer area? | ✅ **Channels** (`/channels/api` + `/channels/api/docs`). |
| Q5 | Rename Statistics? | ✅ Keep **Statistics**; revisit when the chat archive gets its own surface. |
| Q6 | Easy mode: locked Channels/AI Setup vs hidden? | ✅ **Show locked** — easy mode now mirrors the guest pattern: Channels/AI Setup visible with a lock badge; tapping opens a dialog offering one-click **"Switch to Advanced mode"** (guests keep the signup gate). Feature discovery beats minimalism. |
| Q7 | DE: "Task-Prompts" vs "Aufgaben-Prompts"? | ✅ **Superseded (Furkan, rev. 5):** the feature is renamed **"AI Instructions"** — EN "AI Instructions", DE "KI-Anweisungen", ES "Instrucciones de IA", TR "AI Talimatları" — with tooltip/description **"aka system prompts"** so docs/API stay connected. Clearer for normal users; the DE anglicism question dissolves. URL becomes `/ai/instructions` (§4.6). |
| Q8 | Thinking + Voice-reply into Tools dropdown? | ✅ **Move them** — 3-pill row; active state via badge on the pill; slash commands as power-user shortcut. |
| Q9 | Mobile pattern? | ✅ **Bottom tab bar** (New/History/Files/More); drawer survives as the "More" sheet. |

---

## 8. Appendix — file touchpoints inventory

| Area | Files |
|---|---|
| Menu definition | `frontend/src/components/SidebarV2.vue` (navItems, ~L774–877) |
| Routes & guards | `frontend/src/router/index.ts` (incl. `mapPathToFeatureKey` L395) |
| Page shells | `views/ConfigView.vue`, `views/ToolsView.vue` (both switch on path substrings), `views/FilesView.vue`, `views/RagSearchView.vue`, `views/SettingsView.vue`, `components/config/AIModelsConfiguration.vue` (§2.8 B1) |
| Chat input | `components/ChatInput.vue` (action row L210–316, `goToKnowledgeFiles` L429), `components/ToolsDropdown.vue`, `components/ModelDropdown.vue` |
| Locales | `frontend/src/i18n/{en,de,es,tr}.json` (`nav`, `pageTitles`, `config.inbound`→`channels`, `tools`, `chatInput.tools`, `files`, `mail`, guest gate keys) |
| Secondary nav | `components/CommandPalette.vue` + `stores/commands`, `views/NotFoundView.vue`, `data/helpContent.ts` |
| Dead code | `components/UserMenu.vue`, `components/ChatDropdown.vue` |
| Tests | Vitest component tests + Playwright e2e referencing `data-testid="btn-sidebar-v2-*"` and old paths — **full per-file impact map in §6.1**; headline: `navigation.spec.ts` rewrite (2/4/6), `selectors.ts` registry (2/4/5), `task-prompts`/`inbound-email-handler`/`widget`/`rag-search` specs (4/5), new `redirects.spec.ts` (4) |
| UI guard (new, §5) | `frontend/tests/e2e/tests/layout.spec.ts`, `frontend/tests/e2e/playwright.config.ts` (`chromium-mobile` + `@visual` projects), `frontend/tests/e2e/helpers/selectors.ts`, `frontend/package.json` (`test:e2e:mobile` script + axe dep), `frontend/Makefile` (`test-e2e-layout`), `.github/workflows/ci.yml` (**new matrix entry** + visual job + baseline-update dispatch) |

---

## 9. Implementation log (2026-06-11, rev. 6)

All phases implemented on `main`-tracking branch in seven commits, one per
phase, each gated locally (lint → vue-tsc → Vitest 610 → Playwright chromium
@ci → chromium-mobile @layout; backend lint/PHPStan/PHPUnit re-run for the
phase-4 backend touch).

| Phase | Commit summary | Notes / deviations from plan |
|---|---|---|
| 0.5 UI guard | `test(e2e): layout UI guard…` | `layout.spec.ts` (overflow / labels / targets / collision / 320px), axe **report-only**, `chromium-mobile` + `chromium-visual` projects, CI matrix entry + `visual-baselines.yml` dispatch, stable testid scheme in `SidebarV2` + `selectors.ts`. B1 (AI-models header) + B2 (ES/TR keys) fixed. |
| 1 Wording | `feat(frontend): adopt the navigation glossary…` | All §4.2 renames in 4 locales; `config.inbound.*` → `channels.*`; "Agents"/"Inbound"/"RAG" eliminated from UI strings; `RagSearchView` de-hardcoded; "AI Instructions" incl. *aka system prompts* tooltips. |
| 2 Rail | `feat(frontend): labeled 80px rail…` | 80 px labeled rail, split Channels/AI-Setup flyouts, avatar menu reorder + Preferences, easy-mode lock dialog (Q6), icon map §4.5; `navigation.spec.ts` rewritten. |
| 3 Chat input | `feat(frontend): three-pill chat input…` | Model / Tools / Knowledge-folder pills; Thinking + Voice reply as toggle rows in Tools with pill badge (Q8); Summarizer link row (Q3); new `KnowledgeFolderPicker.vue`; `chat-input.spec.ts`. |
| 4 URLs | `feat(frontend): canonical §4.6 URL tree…` | Router rewrite + param/query-preserving redirects; `mapPathToFeatureKey` checkpoint kept (`'settings'`/`'files'` keys unchanged); all in-app links updated; backend hand-off URL + test fixtures (scoped exception, see header); `redirects.spec.ts`. |
| 5 Files+Search | `feat(frontend): merge Files & Search…` | `FilesTabs.vue` (Browse \| Search) on `/files` + `/files/search`; RAG jargon header + 4 stat cards → 1 status line; integrations banner demoted below list; folder-card "Use in chat" → `/?folder=` deep link consumed by `ChatInput`. |
| 6 Mobile | `feat(frontend): mobile bottom tab bar…` | `MobileNav.vue` bottom bar (New/History/Files/More, 44 px+, safe-area, normal-flow) + More sheet with accordions & account block; `useNavItems.ts` composable = single nav source; drawer/burger/`Header.vue` deleted; history sheet state shared via sidebar store; standalone Search rail item retired (tab since phase 5); target floor in guard raised to 44 px. |
| 7 Cleanup (partial) | `chore(frontend): retire dead drawer/menu code…` | `UserMenu.vue`, `ChatDropdown.vue`, drawer store state, burger/`bg-header` CSS, 6 dead `nav.*` keys × 4 locales. **Deliberately left:** transitional redirects (≥ 2 releases — `redirects.spec.ts` is the watchdog), Summarizer page under AI Setup (retires after the in-chat tool soaks), axe flip to blocking (follow-up once findings are triaged). |

**Known non-blockers:** local-only `@subscription` e2e failures (webhook helper
signs with the CI fake secret `whsec_fakeWebhookSecretForTests`; this dev
machine's backend has a real Stripe secret — passes in CI, pre-existing).
Visual baselines for the renamed/new surfaces must be regenerated on the CI
runner via the `visual-baselines.yml` dispatch after merge (local rendering
differs by design).

**Follow-ups (not in this change-set):** axe ratchet to blocking per surface;
Synamail / docs screenshots referencing old paths ride the redirects;
phase-7 remainder (drop redirects ≥ 2 releases, retire Summarizer page).
