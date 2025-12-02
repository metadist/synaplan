# Synaplan Development Guide

**Essential rules and patterns for developing new features in this codebase.**

---

## 🎨 Design System & Styling

### CSS Variables (MUST USE)
Always use CSS variables from `frontend/src/style.css` - **NEVER** use Tailwind colors directly.

#### Background Tokens
```css
var(--bg-app)         /* Page background */
var(--bg-sidebar)     /* Sidebar background */
var(--bg-chat)        /* Chat area background */
var(--bg-card)        /* Card background */
var(--bg-chip)        /* Chip/pill background */
```

#### Text Colors
```css
var(--txt-primary)    /* Primary text */
var(--txt-secondary)  /* Secondary/muted text */
var(--brand)          /* Brand color (teal) */
var(--brand-light)    /* Lighter brand (dark mode) */
var(--brand-hover)    /* Hover state */
var(--brand-alpha-light) /* Transparent brand bg */
```

#### Border Colors
```css
var(--border-light)   /* Subtle borders */
```

### Pre-defined CSS Classes (USE THESE!)

#### Surface Components
```vue
<div class="surface-card">     <!-- Cards with subtle shadow -->
<div class="surface-chip">     <!-- Pills/badges with border -->
<div class="surface-elevated"> <!-- Elevated elements -->
```

#### Text Utilities
```vue
<p class="txt-primary">   <!-- Primary text color -->
<p class="txt-secondary"> <!-- Secondary/muted text -->
<p class="txt-brand">     <!-- Brand color text -->
```

#### Interactive Elements
```vue
<button class="btn-primary">  <!-- Primary action buttons -->
<button class="hover-surface"> <!-- Subtle hover effect -->
<button class="pill">          <!-- Chat input pills -->
<button class="pill--active">  <!-- Active pill state -->
```

#### Navigation
```vue
<button class="nav-item">         <!-- Sidebar nav items -->
<button class="nav-item--active"> <!-- Active nav state -->
```

#### Dropdowns
```vue
<div class="dropdown-panel">   <!-- Dropdown container -->
<button class="dropdown-item"> <!-- Dropdown items -->
<div class="dropdown-up">      <!-- Drop-up menu -->
```

#### Scrollbars
```vue
<div class="scroll-thin">    <!-- Thin custom scrollbar -->
<div class="sidebar-scroll"> <!-- Sidebar scrollbar -->
```

#### Chat Bubbles
```vue
<div class="bubble-ai">   <!-- AI message bubble -->
<div class="bubble-user"> <!-- User message bubble -->
```

### Standard Layout Pattern

**ALWAYS follow this pattern for full-page views:**

```vue
<template>
  <MainLayout>
    <div class="min-h-screen bg-chat p-4 md:p-8 overflow-y-auto scroll-thin">
      <div class="max-w-4xl mx-auto space-y-6">
        
        <!-- Header Card -->
        <div class="surface-card p-6">
          <h1 class="text-2xl font-semibold txt-primary mb-1">Title</h1>
          <p class="txt-secondary text-sm">Description</p>
        </div>

        <!-- Content Cards -->
        <div class="surface-card p-6">
          <!-- Your content -->
        </div>

      </div>
    </div>
  </MainLayout>
</template>
```

### ❌ DON'T DO THIS
```vue
<!-- WRONG: Tailwind colors -->
<div class="bg-gray-50 dark:bg-gray-900">
<span class="text-green-500">

<!-- WRONG: Custom inline styles -->
<div style="background: #f0f0f0">

<!-- WRONG: Random layout structure -->
<div class="container mx-auto px-4">
```

### ✅ DO THIS
```vue
<!-- CORRECT: CSS variables & predefined classes -->
<div class="surface-card">
<span class="txt-brand">

<!-- CORRECT: Standard layout -->
<div class="min-h-screen bg-chat p-4 md:p-8 overflow-y-auto scroll-thin">
  <div class="max-w-4xl mx-auto space-y-6">
```

---

## 🌐 Internationalization (i18n)

### Translation Files
- `frontend/src/i18n/en.json` - English translations
- `frontend/src/i18n/de.json` - German translations

### Using Translations

#### In Templates
```vue
<!-- Simple translation -->
<h1>{{ $t('settings.title') }}</h1>

<!-- With interpolation -->
<p>{{ $t('welcome.greeting', { name: userName }) }}</p>

<!-- Pluralization -->
<span>{{ $t('common.minutesAgo', { count: 5 }) }}</span>
```

#### In Script (Composition API)
```vue
<script setup lang="ts">
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const title = t('settings.title')
</script>
```

### Adding New Translation Keys

**Always add to BOTH `en.json` AND `de.json`!**

```json
// en.json
{
  "myFeature": {
    "title": "My Feature",
    "description": "Feature description",
    "actions": {
      "save": "Save",
      "cancel": "Cancel"
    }
  }
}

// de.json
{
  "myFeature": {
    "title": "Meine Funktion",
    "description": "Funktionsbeschreibung",
    "actions": {
      "save": "Speichern",
      "cancel": "Abbrechen"
    }
  }
}
```

### Common Translation Keys
```
common.ok
common.cancel
common.save
common.delete
common.error
common.success
common.loading
common.retry
```

---

## 📁 File Organization & Modularity

### Component Structure

**NEVER put everything in one file!** Create modular, reusable components.

#### ✅ Good Structure
```
frontend/src/
├── components/
│   ├── MyFeature/
│   │   ├── MyFeatureList.vue       (List view)
│   │   ├── MyFeatureItem.vue       (Single item)
│   │   ├── MyFeatureModal.vue      (Modal dialog)
│   │   └── MyFeatureForm.vue       (Form component)
│   └── shared/
│       ├── ConfirmDialog.vue       (Reusable dialog)
│       └── LoadingSpinner.vue      (Reusable loader)
├── views/
│   └── MyFeatureView.vue           (Main view, uses components)
├── services/
│   └── myFeatureService.ts         (API calls)
└── stores/
    └── myFeature.ts                (State management)
```

#### ❌ Bad Structure
```
frontend/src/
└── components/
    └── MyFeatureEverything.vue     (3000 lines - NEVER DO THIS!)
```

### When to Create a New Component

**Create a new component if:**
- Code block is > 50 lines
- Logic is reusable elsewhere
- Component has distinct responsibility
- Makes parent component easier to read

**Example:**
```vue
<!-- BEFORE: All in one file (BAD) -->
<template>
  <div class="surface-card p-6">
    <!-- 200 lines of form fields -->
    <!-- 100 lines of modal -->
    <!-- 50 lines of confirmation dialog -->
  </div>
</template>

<!-- AFTER: Modular (GOOD) -->
<template>
  <div class="surface-card p-6">
    <MyFeatureForm @submit="handleSubmit" />
    <MyFeatureModal v-if="showModal" @close="showModal = false" />
    <ConfirmDialog v-if="showConfirm" @confirm="handleConfirm" />
  </div>
</template>

<script setup lang="ts">
import MyFeatureForm from '@/components/MyFeature/MyFeatureForm.vue'
import MyFeatureModal from '@/components/MyFeature/MyFeatureModal.vue'
import ConfirmDialog from '@/components/shared/ConfirmDialog.vue'
</script>
```

---

## 🔧 Service Layer (API Calls)

### Create Dedicated Service Files

**Pattern:** `frontend/src/services/{featureName}Service.ts`

```typescript
// frontend/src/services/myFeatureService.ts
import { api } from './apiService'

export interface MyFeature {
  id: number
  name: string
  enabled: boolean
}

export interface MyFeatureResponse {
  features: MyFeature[]
  total: number
}

/**
 * Get all features
 */
export async function getFeatures(): Promise<MyFeatureResponse> {
  const response = await api.get<MyFeatureResponse>('/api/v1/features')
  return response.data // IMPORTANT: Extract .data from response!
}

/**
 * Create new feature
 */
export async function createFeature(data: Partial<MyFeature>): Promise<MyFeature> {
  const response = await api.post<MyFeature>('/api/v1/features', data)
  return response.data
}

/**
 * Update feature
 */
export async function updateFeature(id: number, data: Partial<MyFeature>): Promise<MyFeature> {
  const response = await api.post<MyFeature>(`/api/v1/features/${id}`, data)
  return response.data
}

/**
 * Delete feature
 */
export async function deleteFeature(id: number): Promise<void> {
  await api.delete<void>(`/api/v1/features/${id}`)
}
```

### ⚠️ CRITICAL: API Response Structure

The `api.get/post/delete` wrappers return `{ data: T }`, so **ALWAYS** extract `.data`:

```typescript
// ❌ WRONG
export async function getFeatures(): Promise<MyFeatureResponse> {
  const response = await api.get<MyFeatureResponse>('/api/v1/features')
  return response // Returns { data: {...} } - WRONG!
}

// ✅ CORRECT
export async function getFeatures(): Promise<MyFeatureResponse> {
  const response = await api.get<MyFeatureResponse>('/api/v1/features')
  return response.data // Returns {...} - CORRECT!
}
```

---

## 📦 State Management (Pinia)

### Create Store Files

**Pattern:** `frontend/src/stores/{featureName}.ts`

```typescript
// frontend/src/stores/myFeature.ts
import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { getFeatures, type MyFeature } from '@/services/myFeatureService'

export const useMyFeatureStore = defineStore('myFeature', () => {
  // State
  const features = ref<MyFeature[]>([])
  const isLoading = ref(false)
  const error = ref<string | null>(null)

  // Getters
  const enabledFeatures = computed(() => 
    features.value.filter(f => f.enabled)
  )

  // Actions
  async function loadFeatures() {
    isLoading.value = true
    error.value = null
    
    try {
      const data = await getFeatures()
      features.value = data.features
    } catch (e: any) {
      error.value = e.message
      console.error('Failed to load features:', e)
    } finally {
      isLoading.value = false
    }
  }

  return {
    // State
    features,
    isLoading,
    error,
    // Getters
    enabledFeatures,
    // Actions
    loadFeatures
  }
})
```

### Using Stores in Components

```vue
<script setup lang="ts">
import { onMounted } from 'vue'
import { useMyFeatureStore } from '@/stores/myFeature'

const myFeatureStore = useMyFeatureStore()

onMounted(() => {
  myFeatureStore.loadFeatures()
})
</script>

<template>
  <div v-if="myFeatureStore.isLoading">Loading...</div>
  <div v-else-if="myFeatureStore.error">Error: {{ myFeatureStore.error }}</div>
  <div v-else>
    <div v-for="feature in myFeatureStore.enabledFeatures" :key="feature.id">
      {{ feature.name }}
    </div>
  </div>
</template>
```

---

## 🎯 Component Pattern Examples

### Example 1: Settings View (Reference Implementation)

See `frontend/src/views/SettingsView.vue` for a complete example of:
- ✅ Correct layout pattern (`min-h-screen bg-chat`)
- ✅ Using CSS variables and predefined classes
- ✅ i18n translations with `{{ $t('key') }}`
- ✅ Loading/Error/Success states
- ✅ Tab navigation
- ✅ Modular service integration

### Example 2: Modal Dialog Pattern

```vue
<!-- components/MyFeature/MyFeatureModal.vue -->
<template>
  <Teleport to="body">
    <div 
      v-if="isOpen"
      class="fixed inset-0 bg-black/50 flex items-center justify-center z-50"
      @click.self="emit('close')"
    >
      <div class="surface-card p-6 max-w-lg w-full mx-4">
        <!-- Header -->
        <div class="flex items-center justify-between mb-4">
          <h2 class="text-xl font-semibold txt-primary">
            {{ $t('myFeature.modal.title') }}
          </h2>
          <button @click="emit('close')" class="icon-ghost">
            <XMarkIcon class="w-5 h-5" />
          </button>
        </div>

        <!-- Content -->
        <div class="space-y-4">
          <slot />
        </div>

        <!-- Actions -->
        <div class="flex justify-end gap-2 mt-6">
          <button @click="emit('close')" class="px-4 py-2 rounded-lg hover-surface">
            {{ $t('common.cancel') }}
          </button>
          <button @click="emit('confirm')" class="btn-primary px-4 py-2 rounded-lg">
            {{ $t('common.save') }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script setup lang="ts">
import { XMarkIcon } from '@heroicons/vue/24/outline'

defineProps<{
  isOpen: boolean
}>()

const emit = defineEmits<{
  close: []
  confirm: []
}>()
</script>
```

### Example 3: List Item Component

```vue
<!-- components/MyFeature/MyFeatureItem.vue -->
<template>
  <div class="surface-card p-4 hover-surface transition-all">
    <div class="flex items-center justify-between">
      <div class="flex-1">
        <h3 class="font-semibold txt-primary">{{ item.name }}</h3>
        <p class="text-sm txt-secondary mt-1">{{ item.description }}</p>
      </div>
      
      <div class="flex items-center gap-2">
        <span 
          :class="[
            'px-3 py-1 rounded-full text-xs font-medium',
            item.enabled 
              ? 'bg-[var(--brand-alpha-light)] text-[var(--brand)]'
              : 'surface-chip txt-secondary'
          ]"
        >
          {{ item.enabled ? $t('common.active') : $t('common.inactive') }}
        </span>
        
        <button @click="emit('edit', item)" class="icon-ghost">
          <PencilIcon class="w-4 h-4" />
        </button>
        
        <button @click="emit('delete', item)" class="icon-ghost">
          <TrashIcon class="w-4 h-4" />
        </button>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { PencilIcon, TrashIcon } from '@heroicons/vue/24/outline'
import type { MyFeature } from '@/services/myFeatureService'

defineProps<{
  item: MyFeature
}>()

const emit = defineEmits<{
  edit: [item: MyFeature]
  delete: [item: MyFeature]
}>()
</script>
```

---

## 🚀 Backend Integration

### Controller Pattern (Symfony)

```php
// backend/src/Controller/MyFeatureController.php
namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use App\Entity\User;

#[Route('/api/v1/my-feature', name: 'my_feature_')]
class MyFeatureController extends AbstractController
{
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], 401);
        }

        // Your logic here
        $features = [
            ['id' => 1, 'name' => 'Feature 1', 'enabled' => true],
            ['id' => 2, 'name' => 'Feature 2', 'enabled' => false],
        ];

        return $this->json([
            'features' => $features,
            'total' => count($features)
        ]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(
        Request $request,
        #[CurrentUser] ?User $user
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], 401);
        }

        $data = json_decode($request->getContent(), true);
        
        // Validate and create feature
        
        return $this->json(['id' => 1, 'name' => $data['name'], 'enabled' => true]);
    }
}
```

---

## ✅ Code Quality Checklist

Before committing, verify:

- [ ] Used CSS variables instead of Tailwind colors
- [ ] Used predefined classes (`surface-card`, `txt-primary`, etc.)
- [ ] Followed standard layout pattern
- [ ] Added translations to BOTH `en.json` AND `de.json`
- [ ] Created modular components (not one giant file)
- [ ] Created dedicated service file for API calls
- [ ] Extracted `.data` from API responses
- [ ] Added proper TypeScript types/interfaces
- [ ] Handled loading/error/success states
- [ ] Used Composition API (not Options API)
- [ ] Tested in both light and dark mode

---

## 🎓 Quick Reference

### CSS Class Quick Lookup
```
Layout:       min-h-screen bg-chat scroll-thin
Container:    max-w-4xl mx-auto space-y-6
Cards:        surface-card surface-chip surface-elevated
Text:         txt-primary txt-secondary txt-brand
Buttons:      btn-primary hover-surface pill icon-ghost
Navigation:   nav-item nav-item--active
Dropdowns:    dropdown-panel dropdown-item dropdown-up
Chat:         bubble-ai bubble-user
```

### Translation Pattern
```vue
{{ $t('section.subsection.key') }}
{{ $t('common.cancel') }}
{{ $t('myFeature.actions.save') }}
```

### Service Response Pattern
```typescript
const response = await api.get<T>('/endpoint')
return response.data // Always extract .data!
```

### Component Communication
```vue
<!-- Parent -->
<MyComponent @event="handleEvent" :prop="value" />

<!-- Child -->
defineProps<{ prop: string }>()
const emit = defineEmits<{ event: [data: string] }>()
emit('event', 'data')
```

---

## 📚 Further Reading

- **Design System**: See `frontend/src/style.css` for all available classes
- **Example View**: See `frontend/src/views/SettingsView.vue` for reference implementation
- **Example Components**: See `frontend/src/components/` for reusable patterns
- **API Service**: See `frontend/src/services/apiService.ts` for HTTP client
- **Stores**: See `frontend/src/stores/` for state management examples

---

**Remember: Consistency is key. Follow these patterns for maintainable, scalable code.**

