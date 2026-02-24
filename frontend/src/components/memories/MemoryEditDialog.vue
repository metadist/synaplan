<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useMemoriesStore } from '@/stores/userMemories'
import type { UserMemory } from '@/services/api/userMemoriesApi'
import { X } from 'lucide-vue-next'

interface Props {
  memory: UserMemory | null
}

const props = defineProps<Props>()

const emit = defineEmits<{
  close: []
}>()

const { t } = useI18n()
const memoriesStore = useMemoriesStore()

const isEdit = computed(() => props.memory !== null)
const dialogTitle = computed(() =>
  isEdit.value ? t('memories.edit.title') : t('memories.create.title')
)

// Form state
const category = ref('')
const key = ref('')
const value = ref('')
const loading = ref(false)
const error = ref<string | null>(null)

// Initialize form with memory data if editing
watch(
  () => props.memory,
  (memory) => {
    if (memory) {
      category.value = memory.category
      key.value = memory.key
      value.value = memory.value
    } else {
      // Reset for create
      category.value = 'preferences'
      key.value = ''
      value.value = ''
    }
  },
  { immediate: true }
)

const categories = [
  { value: 'preferences', label: t('memories.categories.preferences') },
  { value: 'personal', label: t('memories.categories.personal') },
  { value: 'work', label: t('memories.categories.work') },
  { value: 'projects', label: t('memories.categories.projects') },
]

const isValid = computed(() => {
  return category.value && key.value.length >= 3 && value.value.length >= 5
})

async function handleSubmit() {
  if (!isValid.value) return

  loading.value = true
  error.value = null

  try {
    if (isEdit.value && props.memory) {
      // Update existing memory
      await memoriesStore.editMemory(props.memory.id, { value: value.value })
    } else {
      // Create new memory
      await memoriesStore.addMemory({
        category: category.value,
        key: key.value,
        value: value.value,
      })
    }

    emit('close')
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Failed to save memory'
  } finally {
    loading.value = false
  }
}

function handleCancel() {
  emit('close')
}

function handleBackdropClick(event: MouseEvent) {
  if (event.target === event.currentTarget) {
    emit('close')
  }
}
</script>

<template>
  <Teleport to="#app">
    <div
      class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50"
      @click="handleBackdropClick"
    >
      <div class="surface-elevated w-full max-w-md p-6 max-h-[90vh] overflow-y-auto scroll-thin">
        <!-- Header -->
        <div class="flex items-center justify-between mb-6">
          <h2 class="text-xl font-bold txt-primary">
            {{ dialogTitle }}
          </h2>
          <button class="icon-ghost" :aria-label="t('common.close')" @click="handleCancel">
            <X :size="20" />
          </button>
        </div>

        <!-- Form -->
        <form @submit.prevent="handleSubmit">
          <!-- Category -->
          <div class="mb-4">
            <label class="block text-sm font-medium txt-primary mb-2">
              {{ t('memories.create.category') }}
            </label>
            <select
              v-model="category"
              class="w-full surface-card px-4 py-2 rounded-lg txt-primary focus:outline-none focus:ring-2 focus:ring-brand"
              :disabled="isEdit"
            >
              <option v-for="cat in categories" :key="cat.value" :value="cat.value">
                {{ cat.label }}
              </option>
            </select>
          </div>

          <!-- Key -->
          <div class="mb-4">
            <label class="block text-sm font-medium txt-primary mb-2">
              {{ t('memories.create.key') }}
            </label>
            <input
              v-model="key"
              type="text"
              class="w-full surface-card px-4 py-2 rounded-lg txt-primary placeholder:txt-secondary focus:outline-none focus:ring-2 focus:ring-brand"
              :placeholder="t('memories.create.keyPlaceholder')"
              :disabled="isEdit"
              required
              minlength="3"
            />
          </div>

          <!-- Value -->
          <div class="mb-6">
            <label class="block text-sm font-medium txt-primary mb-2">
              {{ t('memories.create.value') }}
            </label>
            <textarea
              v-model="value"
              rows="4"
              class="w-full surface-card px-4 py-2 rounded-lg txt-primary placeholder:txt-secondary focus:outline-none focus:ring-2 focus:ring-brand resize-none"
              :placeholder="t('memories.create.valuePlaceholder')"
              required
              minlength="5"
            />
          </div>

          <!-- Error -->
          <div v-if="error" class="alert-error mb-4">
            <p class="alert-error-text text-sm">{{ error }}</p>
          </div>

          <!-- Actions -->
          <div class="flex items-center justify-end gap-3">
            <button
              type="button"
              class="btn-secondary px-4 py-2 rounded-lg font-medium"
              @click="handleCancel"
            >
              {{ t('memories.actions.cancel') }}
            </button>
            <button
              type="submit"
              class="btn-primary px-4 py-2 rounded-lg font-medium"
              :disabled="!isValid || loading"
            >
              {{ loading ? t('common.saving') : t('memories.actions.save') }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </Teleport>
</template>
