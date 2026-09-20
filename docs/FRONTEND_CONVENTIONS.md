# Frontend Conventions

## TypeScript Standards

- No semicolons
- Single quotes for strings
- 2-space indentation
- Explicit types (no `any`)
- Interfaces for object shapes
- Async/await (not `.then()`)

## Vue Standards

- Composition API with `<script setup>`
- TypeScript required
- Props with interfaces
- Emits with type safety
- No Options API
- All text through `vue-i18n`

## Design System (MUST USE)

Always use CSS variables from `frontend/src/style.css`. **NEVER** use Tailwind colors directly.

### Tokens

| Category | Variables |
|----------|-----------|
| Background | `var(--bg-app)`, `var(--bg-sidebar)`, `var(--bg-chat)`, `var(--bg-card)`, `var(--bg-chip)` |
| Text | `var(--txt-primary)`, `var(--txt-secondary)`, `var(--brand)`, `var(--brand-light)` |

### Utility Classes

| Class | Purpose |
|-------|---------|
| `surface-card` | Card with subtle shadow |
| `surface-chip` | Pill with border |
| `surface-elevated` | Elevated surface |
| `txt-primary`, `txt-secondary`, `txt-brand` | Text colors |
| `btn-primary` | Primary button |
| `hover-surface` | Hover state |
| `pill`, `pill--active` | Pill buttons |
| `nav-item`, `nav-item--active` | Navigation items |
| `tab-nav`, `tab-nav-item`, `tab-nav-item--active` | Page tab bars (canonical pill style; prefer `TabNav.vue`) |

### Standard Layout

Always use `<MainLayout>` with a standard container:

```vue
<template>
  <MainLayout>
    <div class="min-h-screen bg-chat p-4 md:p-8 overflow-y-auto scroll-thin">
      <div class="max-w-4xl mx-auto">
        <!-- content -->
      </div>
    </div>
  </MainLayout>
</template>
```

## Iconography (house icon map)

One action ⇒ one glyph, on every surface (U9/U12). The canonical set is
**Heroicons outline 24**: prefer the `@heroicons/vue/24/outline` components,
`heroicons:` Iconify strings where a component cannot be used (dynamic `:icon`
bindings). What the user sees is the glyph — `heroicons:eye` and `EyeIcon` are
the same glyph and both fine; `mdi:eye-outline` next to either of them is the
bug. `mdi:` is reserved for glyphs Heroicons lacks — today that is only the
file-type icons behind `previewIconForName()` (`@/services/filePreview.ts`).

| Action | Glyph |
| ------ | ----- |
| Download | `ArrowDownTrayIcon` |
| Preview / view | `EyeIcon` |
| Delete | `TrashIcon` (`text-red-400/70 hover:text-red-500`, never the secondary ink) |
| Open in chat | `ChatBubbleLeftRightIcon` |
| Upload | `CloudArrowUpIcon` |
| Search | `MagnifyingGlassIcon` |
| Close | `XMarkIcon` |
| Share | `ShareIcon` |
| Folder / new folder | `FolderIcon` / `FolderPlusIcon` |

File rows never hand-roll buttons: `frontend/src/components/files/FileRowActions.vue`
owns the glyphs, order (chat → preview → download → delete), density (`p-1.5`,
`w-4 h-4` icons) and delete styling. Callers pass titles, test IDs and handlers
only. A new file-row action extends the component, never a second button set.

## i18n (Internationalization)

**Always update ALL five locales, in the matching namespace file.** Strings live in `frontend/src/i18n/locales/{en,de,es,fr,tr}/<namespace>.json`. A missing key silently falls back to English (`fallbackLocale: 'en'`), but only after that English namespace chunk is loaded too.

Put a new top-level key in the namespace that already owns its siblings. The map is `NAMESPACE_KEYS` in `frontend/src/i18n/namespaces.ts`:

| File | Top-level keys |
| ---- | -------------- |
| `core.json` | announcements, branding, common, cookies, error, forceUpdate, header, iap, loading, models, native, nav, network, notFound, pageTitles, realtime, search, shared, sidebar, system, unsavedChanges, updates, welcome, welcomeUser |
| `auth.json` | accountDeletion, adminSetup, auth, biometricLock, forcedPasswordChange, guest, localAiDownload, nativeServer, onboarding, setup, setupBanner |
| `chat.json` | approvals, chat, chatError, chatInput, chatMessage, chatShare, chats, commands, companionLinks, incognito, message, messageRefs, modelMix, moderation, processing, promoTips, selfAware, summary, taskPlan |
| `files.json` | fileMention, fileSelection, files, rag, storage, vectorStorage |
| `knowledge.json` | feedback, memories |
| `assistants.json` | assistants, bundle |
| `widgets.json` | liveSupport, widget, widgetSessions, widgets |
| `admin.json` | admin, adminModelStatus, aiInfra, iam, modules, people, platformConnect, providerHelp, statistics |
| `config.json` | config |
| `settings.json` | export, externalLink, limitReached, marketingNews, paywall, profile, settings, subscription, usageTaximeter |
| `tools.json` | aiAccounts, aiProvider, channels, compute, customTools, help, jobs, linkedPlatforms, mail, mcpServers, messagesGateway, plugins, tools, workflows |

Do not rename existing dotted keys when adding a file — `$t('config.savedTasks.saveAsTask')` must keep working. The embeddable widget only ships `core` + `chat` + `widgets`; never put widget-visible copy in `admin` / `config` / `tools`. Authenticated chrome (sidebar / mobile nav) always loads `chat` + `auth` + `admin` + `settings` + `tools` (jobs tray, help, media-job toasts) so Incoming and Logout never render as raw keys. Every route declares its namespaces in `meta.i18n`; `tests/unit/i18n/routeCoverage.spec.ts` locks the hand-triaged minimum per route and fails the build on keys absent from the EN catalog.

`tests/unit/i18n/localeParity.spec.ts` still gates full-tree key parity against `localeParityBaseline.json`, a frozen ledger of pre-existing drift. Add an English-only key and the suite fails, naming the key. When you translate a key that is listed in the ledger, remove it from the ledger in the same change — the comparison is exact, so the debt can only shrink.

Never add a new language by editing a picker by hand. `supportedLanguages` and `languageOptions` in `frontend/src/i18n/shared.ts` (re-exported from `@/i18n`) are the single source of truth; every locale switcher derives from them. Switch the UI language through `setLocale()` so the new locale's chunks load before the locale flips.

```vue
<!-- In templates -->
{{ $t('widget.title') }}

<!-- In script -->
const { t } = useI18n()
t('common.save')
```

Common keys: `common.ok`, `common.cancel`, `common.save`, `common.delete`, `common.error`, `common.success`

## File Organization

```
frontend/src/
├── components/MyFeature/
│   ├── MyFeatureList.vue
│   └── MyFeatureItem.vue
├── views/MyFeatureView.vue
├── services/api/myFeatureApi.ts
└── stores/myFeature.ts
```

- **Views**: Orchestrate components
- **Components**: Modular, reusable, under 300 lines
- **Services**: API call logic only
- **Stores**: Pinia state management

## UI Patterns

**Dialogs**: Use `useDialog()` composable
```typescript
const { confirm, alert, prompt } = useDialog()
```

**Notifications**: Use `useNotification()`
```typescript
const { success, error, warning } = useNotification()
```

**Modals**: Use `<Teleport to="body">` with backdrop and `surface-card`

## Vue Component Example

```vue
<script setup lang="ts">
import { ref } from 'vue'
import MainLayout from '@/components/layout/MainLayout.vue'

interface Props {
  widgetId: string
  primaryColor?: string
}

const props = withDefaults(defineProps<Props>(), {
  primaryColor: '#007bff'
})

const emit = defineEmits<{
  (e: 'open'): void
  (e: 'close'): void
}>()

const isOpen = ref(false)

function handleOpen() {
  isOpen.value = true
  emit('open')
}
</script>

<template>
  <div class="surface-card p-6">
    <h1 class="text-2xl font-semibold txt-primary mb-1">{{ $t('widget.title') }}</h1>
    <p class="txt-secondary text-sm">{{ $t('widget.description') }}</p>
    <button
      type="button"
      class="btn-primary mt-4 px-4 py-2.5 rounded-lg text-sm font-medium"
      @click="handleOpen"
    >
      {{ $t('actions.open') }}
    </button>
  </div>
</template>
```

## TypeScript Example

```typescript
export interface Widget {
  id: number
  widgetId: string
  name: string
  config: WidgetConfig
  isActive: boolean
}

export async function createWidget(
  name: string,
  config: WidgetConfig
): Promise<Widget> {
  const data = await httpClient<{ widget: Widget }>(
    '/api/v1/widgets',
    {
      method: 'POST',
      body: JSON.stringify({ name, config })
    }
  )
  return data.widget
}
```

## Commands

```bash
make -C frontend lint          # Type check
make -C frontend test          # Run tests
make -C frontend build         # Build app + widget
make -C frontend build-widget  # Build widget only
make -C frontend deps          # Install dependencies
```
