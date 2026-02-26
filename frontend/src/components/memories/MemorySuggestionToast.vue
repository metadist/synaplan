<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue'
import { useI18n } from 'vue-i18n'
import type { UserMemory } from '@/services/api/userMemoriesApi'
import { Pencil, Trash2, X } from 'lucide-vue-next'

interface Props {
  memory: UserMemory
  autoHide?: number // Auto-hide after X milliseconds (default: 8000)
}

const props = withDefaults(defineProps<Props>(), {
  autoHide: 8000,
})

const emit = defineEmits<{
  edit: [memory: UserMemory]
  delete: [memory: UserMemory]
  close: []
}>()

const { t } = useI18n()
const isVisible = ref(true)
let autoHideTimer: number | null = null

onMounted(() => {
  if (props.autoHide > 0) {
    autoHideTimer = window.setTimeout(() => {
      handleClose()
    }, props.autoHide)
  }
})

onUnmounted(() => {
  if (autoHideTimer) {
    clearTimeout(autoHideTimer)
  }
})

function handleEdit() {
  if (autoHideTimer) {
    clearTimeout(autoHideTimer)
  }
  emit('edit', props.memory)
  handleClose()
}

function handleDelete() {
  if (autoHideTimer) {
    clearTimeout(autoHideTimer)
  }
  emit('delete', props.memory)
  handleClose()
}

function handleClose() {
  isVisible.value = false
  setTimeout(() => {
    emit('close')
  }, 300)
}
</script>

<template>
  <Teleport to="#app">
    <Transition
      enter-active-class="transition-all duration-300"
      enter-from-class="opacity-0 translate-y-4"
      enter-to-class="opacity-100 translate-y-0"
      leave-active-class="transition-all duration-300"
      leave-from-class="opacity-100 translate-y-0"
      leave-to-class="opacity-0 translate-y-4"
    >
      <div v-if="isVisible" class="fixed bottom-8 right-8 z-50 w-full max-w-md">
        <div class="surface-elevated p-4 shadow-2xl">
          <!-- Header -->
          <div class="flex items-start justify-between gap-3 mb-3">
            <div class="flex-1">
              <div class="flex items-center gap-2 mb-1">
                <div class="w-2 h-2 rounded-full bg-brand animate-pulse" />
                <h3 class="text-sm font-bold txt-primary">
                  {{ t('memories.toast.title') }}
                </h3>
              </div>
              <p class="text-xs txt-secondary">
                {{ t('memories.categories.' + memory.category) }}
              </p>
            </div>
            <button class="icon-ghost" :aria-label="t('common.close')" @click="handleClose">
              <X :size="16" />
            </button>
          </div>

          <!-- Content -->
          <div class="mb-4">
            <p class="text-sm font-medium txt-primary mb-1">
              {{ memory.key }}
            </p>
            <p class="text-sm txt-secondary line-clamp-2">
              {{ memory.value }}
            </p>
          </div>

          <!-- Actions -->
          <div class="flex items-center gap-2">
            <button
              class="flex-1 btn-secondary px-3 py-2 rounded-lg text-sm font-medium flex items-center justify-center gap-2"
              @click="handleEdit"
            >
              <Pencil :size="14" />
              {{ t('memories.toast.actions.edit') }}
            </button>
            <button
              class="flex-1 btn-danger px-3 py-2 rounded-lg text-sm font-medium flex items-center justify-center gap-2"
              @click="handleDelete"
            >
              <Trash2 :size="14" />
              {{ t('memories.toast.actions.delete') }}
            </button>
          </div>

          <!-- Progress Bar -->
          <div class="mt-3 h-1 bg-overlay-light rounded-full overflow-hidden">
            <div
              class="h-full bg-brand transition-all"
              :style="{ width: '100%', animation: `shrink ${autoHide}ms linear` }"
            />
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
@keyframes shrink {
  from {
    width: 100%;
  }
  to {
    width: 0%;
  }
}
</style>
