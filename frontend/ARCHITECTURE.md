# 🎓 Vue Projekt-Architektur für Anfänger

## 📁 Projektstruktur

```
src/
├── views/              # Seiten (1 Route = 1 View)
├── components/         # Wiederverwendbare UI-Teile
├── router/            # URL-Routing (welche URL → welche View)
├── stores/            # Globaler State (Pinia)
├── composables/       # Wiederverwendbare Logik
├── i18n/              # Übersetzungen (Deutsch/Englisch)
├── mocks/             # Test-Daten (später: echte API)
├── services/          # API-Calls
└── utils/             # Hilfsfunktionen
```

---

## 🧩 Vue Komponenten-Grundlagen

### Aufbau einer Vue-Komponente (3 Teile)

```vue
<!-- 1. TEMPLATE = HTML-Struktur -->
<template>
  <div class="surface-card p-6">
    <h1>{{ title }}</h1>
    <button @click="handleClick">Klick mich</button>
  </div>
</template>

<!-- 2. SCRIPT = JavaScript-Logik -->
<script setup lang="ts">
import { ref } from 'vue'

// Reaktive Variable (ändert sich → UI updated automatisch)
const title = ref('Hallo Welt')

// Funktion
const handleClick = () => {
  title.value = 'Geklickt!'
}
</script>

<!-- 3. STYLE = CSS (optional) -->
<style scoped>
/* Nur für diese Komponente */
</style>
```

---

## 🔄 Reaktivität – Das Herzstück von Vue

### `ref()` – Einzelne reaktive Werte

```typescript
const count = ref(0)        // Erstelle reaktiven Wert
count.value++               // Ändern (WICHTIG: .value!)
console.log(count.value)    // Lesen
```

```vue
<template>
  <!-- Im Template KEIN .value nötig! -->
  <p>{{ count }}</p>
</template>
```

### `computed()` – Berechnete Werte

```typescript
const firstName = ref('Max')
const lastName = ref('Mustermann')

// Wird automatisch neu berechnet wenn sich firstName/lastName ändert
const fullName = computed(() => {
  return `${firstName.value} ${lastName.value}`
})
```

### `watch()` – Auf Änderungen reagieren

```typescript
const searchQuery = ref('')

// Führt Code aus wenn sich searchQuery ändert
watch(searchQuery, (newValue, oldValue) => {
  console.log(`Suche geändert von "${oldValue}" zu "${newValue}"`)
  performSearch(newValue)
})
```

---

## 🔀 Routing – Navigation zwischen Seiten

**`src/router/index.ts`**
```typescript
{
  path: '/tools/chat-widget',
  name: 'tools-chat-widget',
  component: () => import('../views/ToolsView.vue'),
  meta: { requiresAuth: true, helpId: 'tools.chatWidget' }
}
```

**In Komponenten navigieren:**
```typescript
import { useRouter } from 'vue-router'

const router = useRouter()

// Zu anderer Route wechseln
router.push('/tools/chat-widget')
router.push({ name: 'tools-chat-widget' })
```

**Aktuelle Route lesen:**
```typescript
import { useRoute } from 'vue-router'

const route = useRoute()
console.log(route.path)      // '/tools/chat-widget'
console.log(route.meta)      // { requiresAuth: true, ... }
```

---

## 🌍 i18n – Mehrsprachigkeit

**`src/i18n/en.json`**
```json
{
  "mail": {
    "title": "Mail Handler Configuration",
    "testConnection": "Test Connection"
  }
}
```

**In Komponenten verwenden:**
```vue
<template>
  <h1>{{ $t('mail.title') }}</h1>
  <button>{{ $t('mail.testConnection') }}</button>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const title = t('mail.title')  // Im Script: t() statt $t()
</script>
```

---

## 📦 Stores (Pinia) – Globaler State

**Was ist ein Store?**
Daten, die von mehreren Komponenten genutzt werden.

**Beispiel: `src/stores/sidebar.ts`**
```typescript
export const useSidebarStore = defineStore('sidebar', () => {
  const isOpen = ref(true)
  
  function toggle() {
    isOpen.value = !isOpen.value
  }
  
  return { isOpen, toggle }
})
```

**In Komponente nutzen:**
```typescript
import { useSidebarStore } from '@/stores/sidebar'

const sidebarStore = useSidebarStore()

// Lesen
console.log(sidebarStore.isOpen)

// Funktion aufrufen
sidebarStore.toggle()
```

---

## 🎣 Composables – Wiederverwendbare Logik

**Was ist ein Composable?**
Eine Funktion, die Vue-Features (ref, computed, watch) nutzt und in mehreren Komponenten verwendet werden kann.

**Beispiel: `src/composables/useHelp.ts`**
```typescript
export function useHelp() {
  const isHelpOpen = ref(false)
  
  function openHelp() {
    isHelpOpen.value = true
  }
  
  function closeHelp() {
    isHelpOpen.value = false
  }
  
  return {
    isHelpOpen,
    openHelp,
    closeHelp
  }
}
```

**Verwendung:**
```typescript
const { isHelpOpen, openHelp, closeHelp } = useHelp()
```

---

## 🎨 Tailwind CSS & Design System

### Klassen-System

```vue
<template>
  <!-- Tailwind Utility-Klassen -->
  <div class="p-6 rounded-lg border">
    <h1 class="text-2xl font-semibold mb-4">Titel</h1>
  </div>
  
  <!-- Projekt-eigene Tokens (siehe style.css) -->
  <div class="surface-card txt-primary">
    <button class="btn-primary">Speichern</button>
  </div>
</template>
```

### Wichtige Token

- **Backgrounds**: `surface-card`, `bg-chat`, `bg-sidebar`
- **Text**: `txt-primary` (dunkel), `txt-secondary` (grau)
- **Buttons**: `btn-primary`
- **Borders**: `border-light-border/30 dark:border-dark-border/20`

---

## 🔗 Props & Emits – Komponenten-Kommunikation

### Props (Parent → Child)

**Parent-Komponente:**
```vue
<MailHandlerList :handlers="mailHandlers" />
```

**Child-Komponente (`MailHandlerList.vue`):**
```typescript
interface Props {
  handlers: SavedMailHandler[]
}

const props = defineProps<Props>()

// Nutzen: props.handlers
```

### Emits (Child → Parent)

**Child-Komponente:**
```typescript
const emit = defineEmits<{
  save: [config: MailConfig]
  cancel: []
}>()

// Event senden
emit('save', myConfig)
emit('cancel')
```

**Parent-Komponente:**
```vue
<MailHandlerConfiguration 
  @save="handleSave"
  @cancel="handleCancel"
/>
```

---

## 🎯 Beispiel-Flow: Mail Handler

### 1. **View** (`ToolsView.vue`)
```typescript
// State
const mailHandlers = ref<SavedMailHandler[]>(mockMailHandlers)
const showEditor = ref(false)

// Handler erstellen
const createMailHandler = () => {
  showEditor.value = true
}

// Handler speichern
const saveMailHandler = (name: string, config: MailConfig) => {
  mailHandlers.value.push({ id: '...', name, config, ... })
  showEditor.value = false
}
```

```vue
<template>
  <!-- Liste anzeigen -->
  <MailHandlerList 
    v-if="!showEditor"
    :handlers="mailHandlers"
    @create="createMailHandler"
  />
  
  <!-- Editor anzeigen -->
  <MailHandlerConfiguration
    v-else
    @save="saveMailHandler"
    @cancel="showEditor = false"
  />
</template>
```

### 2. **Liste** (`MailHandlerList.vue`)
```vue
<template>
  <div v-for="handler in handlers" :key="handler.id">
    <h3>{{ handler.name }}</h3>
    <button @click="$emit('edit', handler)">Bearbeiten</button>
  </div>
  
  <button @click="$emit('create')">Neu erstellen</button>
</template>

<script setup lang="ts">
interface Props {
  handlers: SavedMailHandler[]
}
defineProps<Props>()

defineEmits<{
  create: []
  edit: [handler: SavedMailHandler]
}>()
</script>
```

### 3. **Editor** (`MailHandlerConfiguration.vue`)
```vue
<template>
  <input v-model="config.mailServer" />
  <button @click="save">Speichern</button>
</template>

<script setup lang="ts">
const config = ref<MailConfig>({
  mailServer: '',
  port: 993,
  ...
})

const emit = defineEmits<{
  save: [name: string, config: MailConfig]
}>()

const save = () => {
  emit('save', 'Handler Name', config.value)
}
</script>
```

---

## 🎪 Direktiven – Spezial-Attribute

### `v-if` / `v-else` – Bedingtes Rendering
```vue
<div v-if="isLoggedIn">Willkommen!</div>
<div v-else>Bitte einloggen</div>
```

### `v-for` – Listen rendern
```vue
<div v-for="item in items" :key="item.id">
  {{ item.name }}
</div>
```

### `v-model` – 2-Wege-Binding
```vue
<input v-model="username" />
<!-- Äquivalent zu: -->
<input 
  :value="username" 
  @input="username = $event.target.value"
/>
```

### `@click` / `@input` – Event-Handler
```vue
<button @click="handleClick">Klick</button>
<input @input="handleInput" />
```

### `:class` – Dynamische Klassen
```vue
<div :class="{ 'bg-green-500': isActive, 'bg-red-500': !isActive }">
  Status
</div>

<div :class="['p-4', isActive && 'bg-green-500']">
  Status
</div>
```

---

## 🔧 Lifecycle Hooks

```typescript
import { onMounted, onUnmounted, watch } from 'vue'

// Wenn Komponente im DOM ist
onMounted(() => {
  console.log('Komponente geladen')
  fetchData()
})

// Vor dem Entfernen aus DOM
onUnmounted(() => {
  console.log('Komponente wird entfernt')
  cleanup()
})
```

---

## 🎯 TypeScript-Grundlagen

### Interfaces (Typen definieren)
```typescript
interface MailConfig {
  mailServer: string
  port: number
  protocol: 'IMAP' | 'POP3'  // Nur diese Werte erlaubt
  deleteAfter: boolean
}

// Nutzen
const config: MailConfig = {
  mailServer: 'mail.example.com',
  port: 993,
  protocol: 'IMAP',
  deleteAfter: false
}
```

### Generics
```typescript
// Array mit spezifischem Typ
const handlers = ref<SavedMailHandler[]>([])

// Optional (kann undefined sein)
const currentHandler = ref<SavedMailHandler | undefined>(undefined)
```

---

## 📋 Typische Patterns im Projekt

### 1. **List + Editor Pattern** (z.B. Widgets, Mail Handlers)
```typescript
// Liste anzeigen oder Editor?
const showEditor = ref(false)
const currentItem = ref<Item | undefined>(undefined)

const createItem = () => {
  showEditor.value = true
  currentItem.value = undefined  // Neu erstellen
}

const editItem = (item: Item) => {
  showEditor.value = true
  currentItem.value = item  // Bearbeiten
}

const saveItem = () => {
  // Speichern...
  showEditor.value = false
}
```

### 2. **Wizard/Steps Pattern** (z.B. Mail Handler)
```typescript
const currentStep = ref(0)
const steps = ['connection', 'departments', 'test']

const nextStep = () => {
  if (currentStep.value < steps.length - 1) {
    currentStep.value++
  }
}

const prevStep = () => {
  if (currentStep.value > 0) {
    currentStep.value--
  }
}
```

### 3. **Loading States**
```typescript
const isLoading = ref(false)
const testResult = ref<{ success: boolean; message: string } | null>(null)

const testConnection = async () => {
  isLoading.value = true
  testResult.value = null
  
  await new Promise(resolve => setTimeout(resolve, 2000))
  
  testResult.value = { success: true, message: 'Connected!' }
  isLoading.value = false
}
```

---

## 🎨 Help System – Konkretes Beispiel

### 1. **Help Content** (`src/data/helpContent.ts`)
```typescript
export const helpContent = {
  'tools.docSummary': {
    title: 'Document Summary',
    steps: [
      {
        title: 'Quick Presets',
        content: 'Select a preset...',
        selector: '[data-help="presets"]'  // CSS Selector
      },
      { ... }
    ]
  }
}
```

### 2. **Elemente markieren** (`SummaryConfiguration.vue`)
```vue
<div data-help="presets">
  <!-- Diese Box wird im Help-Modus hervorgehoben -->
</div>
```

### 3. **Help öffnen** (`HelpButton.vue`)
```typescript
const { openHelp } = useHelp()

const handleClick = () => {
  openHelp()  // Startet Tour
}
```

### 4. **Tour anzeigen** (`HelpTour.vue`)
```typescript
// Aktueller Schritt
const currentStepIndex = ref(0)

// Element finden und highlighten
const updateHighlight = () => {
  const step = props.steps[currentStepIndex.value]
  if (step?.selector) {
    const element = document.querySelector(step.selector)
    highlightedElement.value = element
  }
}
```

---

## 🚀 Entwicklungs-Workflow

### 1. **Neue Feature-Seite erstellen**

```
1. Mock-Daten erstellen       → src/mocks/myfeature.ts
2. View erstellen             → src/views/MyFeatureView.vue
3. Route hinzufügen           → src/router/index.ts
4. Komponenten erstellen      → src/components/myfeature/
5. i18n Keys hinzufügen       → src/i18n/en.json
6. Help Content hinzufügen    → src/data/helpContent.ts
```

### 2. **Dev-Server starten**
```bash
npm run dev
```

### 3. **App öffnen**
```
http://localhost:5173
```

---

## 🎓 Wichtige Konzepte nochmal

| Konzept | Zweck | Beispiel |
|---------|-------|----------|
| **ref()** | Reaktive Variable | `const count = ref(0)` |
| **computed()** | Berechneter Wert | `const total = computed(() => a + b)` |
| **watch()** | Auf Änderung reagieren | `watch(value, () => {...})` |
| **Props** | Daten von Parent zu Child | `<Child :data="myData" />` |
| **Emits** | Events von Child zu Parent | `emit('save', data)` |
| **Store** | Globaler State | `useSidebarStore()` |
| **Composable** | Wiederverwendbare Logik | `useHelp()` |
| **Router** | Navigation | `router.push('/page')` |
| **i18n** | Übersetzungen | `$t('mail.title')` |

---

## 💡 Hilfreiche Links

- **Vue 3 Docs**: https://vuejs.org/guide/introduction.html
- **Composition API**: https://vuejs.org/guide/extras/composition-api-faq.html
- **Pinia (Stores)**: https://pinia.vuejs.org
- **Vue Router**: https://router.vuejs.org
- **Tailwind CSS**: https://tailwindcss.com/docs

---

**Fragen? Schau dir die existierenden Komponenten an – sie folgen alle diesen Patterns!** 🚀

