# 🔒 Working Rules — i18n, Design System, Mock Data

## i18n (English only)

* **Do not touch i18n setup.**
* **Only edit** `src/i18n/en.json`. Add new keys there when you introduce UI text.
* **Always use** `$t()` or `<translate>` in templates. No hardcoded strings.
* **Reuse existing keys** if possible; if unsure, ask instead of guessing.

**Example:**

```vue
<h2>{{ $t('welcome') }}</h2>
<p>{{ $t('chatInput.placeholder') }}</p>
```

```json
// src/i18n/en.json
{
  "welcome": "Welcome",
  "chatInput": { "placeholder": "Type your message..." }
}
```

---

## Design System — Don’t break it

**Global style is already configured in `style.css` + Tailwind. Keep using it.**

### Tokens / Utilities (must use)

* Backgrounds: `bg-app`, `bg-sidebar`, `bg-chat`, `surface-card`, `surface-chip`
* Text: `txt-primary`, `txt-secondary`
* Buttons: `btn-primary`
* Nav: `nav-item`, `nav-item--active`
* Scroll helpers: `sidebar-scroll`, `chat-input-scroll`, `scroll-thin`, `scroll-gutter`
* Pills/Dropdowns: `pill`, `pill--active`, `dropdown-panel`, `dropdown-item`, `dropdown-up`
* Bubbles: `bubble-ai`, `bubble-user`
* Misc: `hover-surface`, `icon-ghost`, `border-light-border`, `border-dark-border`

**Do:**

* Reuse **existing** classes/tokens.
* Keep spacing with Tailwind utilities (no inline styles).
* Keep typography consistent (e.g. `text-xl`, `text-2xl`, `font-semibold`).
* Keep layouts inside existing shells: `MainLayout`, `ChatMessage`, `ChatInput`, `Sidebar`.

**Don’t:**

* Don’t add new colors or CSS variables.
* Don’t override tokens in components.
* Don’t introduce new button/nav patterns.
* Don’t import another CSS framework.

---

## Dark Mode

* Respect `.dark` variables already defined in `style.css`.
* **No manual color overrides** in components.
* If you add elements that look like cards/chips, use **`surface-card` / `surface-chip`**.

---

## Components & Views

* **Views** go in `/views`, **reusable UI** in `/components`.
* Use Composition API + TypeScript.
* Keep components small and focused.
* **Use @ alias** for imports: `import X from '@/components/X.vue'` (not `../`)
* **Do not modify** streaming logic, message grouping, or model mapping in `chatview.vue` unless explicitly asked.
* **Do not change** theme bootstrapping (`useTheme()` in `App.vue`).

---

## Sidebar / Navigation

* Use existing `nav-item` pattern.
* For nested menus, follow the `Tools` collapsible example (`isToolsExpanded`, chevron rotate).
* Keep labels translatable (`$t('...')`) and add keys to `en.json`.

---

## Forms / Inputs

* Use Tailwind + existing utilities, borders: `border-light-border/30` (light), `dark:border-dark-border/20`.
* Focus styles: `focus:ring-2 focus:ring-[var(--brand)]`.
* No inline hex colors; use tokens or current CSS variables.

---

## Mock Data (separate, typed)

* Place in `src/mocks/*` (or `src/mock/*`) — **never** inside components.
* Use interfaces and exported consts.
* Keep timestamps as `Date` where needed to match existing patterns.
* **Centralized data sources**: Use stores and services for shared data (e.g., AI models)

**Example (`src/mocks/statistics.ts`):**

```ts
export interface Statistics {
  totalMessages: number
  messagesSent: number
  messagesReceived: number
  totalFiles: number
  filesSent: number
  filesReceived: number
}

export const mockStatistics: Statistics = {
  totalMessages: 129, messagesSent: 50, messagesReceived: 79,
  totalFiles: 32, filesSent: 15, filesReceived: 17
}
```

**Data Flow for API Integration:**

```
Component → Store → Service (API) → Backend
           ↓
        Mock Data (fallback)
```

**Example:**

* `src/stores/models.ts` - Centralized model state
* `src/services/apiService.ts` - API calls (with mock fallback)
* `src/utils/providerIcons.ts` - Shared utility functions

---

## Accessibility & UX

* Keep interactive elements keyboard-focusable (`focus-visible`, buttons not divs).
* Use `aria-label` where icons are interactive (see `sidebar.vue`).
* Preserve existing scroll behaviors (auto-scroll in chat; don’t remove).

---

## Performance & Cleanliness

* No new global listeners or heavy watchers.
* Prefer computed + small refs.
* Leave `// TODO(brief)` if unsure rather than guessing.
* **Ask before** changing layout, tokens, or streaming logic.

---

## Token Budget & Output Minimalism

* Keep responses **short** (bullets, 1–2 sentences max).
* **Don’t create new Markdown files** unless explicitly requested.
* Apply changes directly in files; **don’t paste full code** into chat.
* Show only what’s necessary; **no over-detailed explanations**.
* Add **only new i18n keys** to `src/i18n/en.json` (don’t echo the whole file).
* **Minimal code comments** – only where truly needed.
* If unsure: **ask one short question** or leave `// TODO(short)` and stop.

---

## Quick Checklist (per change)

1. All user-facing text uses `$t()` / `<translate>`.
2. New keys added **only** in `src/i18n/en.json`.
3. Styles use existing tokens/utilities (no new colors).
4. No changes to theme, layout shells, or streaming logic.
5. Mock data lives in `src/mocks/*`, typed.
6. If uncertain: **ask** or leave a concise TODO.
7. **Kept output minimal** (no big code/MD dumps).

---

That’s it. Keep it consistent, small, and reusable.
