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

## i18n (Internationalization)

**Always update BOTH `en.json` AND `de.json`!**

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
    <button class="btn-primary mt-4" @click="handleOpen">
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
