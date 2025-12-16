<template>
  <Transition
    enter-active-class="transition-all duration-300 ease-out"
    enter-from-class="translate-y-full opacity-0"
    enter-to-class="translate-y-0 opacity-100"
    leave-active-class="transition-all duration-200 ease-in"
    leave-from-class="translate-y-0 opacity-100"
    leave-to-class="translate-y-full opacity-0"
  >
    <div
      v-if="show"
      class="fixed bottom-0 left-0 right-0 z-50 pointer-events-none"
      data-testid="section-unsaved-bar"
    >
      <div class="max-w-7xl mx-auto px-4 pb-4 md:px-8 md:pb-6">
        <div
          class="surface-card shadow-xl rounded-xl p-4 md:p-6 pointer-events-auto border-2 border-[var(--brand)]"
          data-testid="comp-unsaved-card"
        >
          <div class="flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
            <div class="flex items-start gap-3">
              <div
                class="flex-shrink-0 w-10 h-10 rounded-full bg-[var(--brand)]/10 flex items-center justify-center"
              >
                <ExclamationCircleIcon class="w-6 h-6" style="color: var(--brand)" />
              </div>
              <div>
                <p class="txt-primary font-semibold text-base md:text-lg">
                  {{ $t('unsavedChanges.title') }}
                </p>
                <p class="txt-secondary text-sm mt-0.5">
                  {{ $t('unsavedChanges.description') }}
                </p>
              </div>
            </div>

            <div
              class="flex items-center gap-3 w-full md:w-auto"
              data-testid="section-unsaved-actions"
            >
              <button
                :disabled="isSaving"
                class="flex-1 md:flex-none px-6 py-3 rounded-lg border-2 border-light-border/30 dark:border-dark-border/20 txt-primary hover:bg-black/5 dark:hover:bg-white/5 transition-colors font-medium text-base min-h-[48px] disabled:opacity-50 disabled:cursor-not-allowed focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--brand)] focus-visible:ring-offset-2"
                data-testid="btn-unsaved-discard"
                @click="handleDiscard"
              >
                {{ $t('unsavedChanges.discard') }}
              </button>
              <button
                v-if="showPreview"
                :disabled="isSaving"
                class="flex-1 md:flex-none px-6 py-3 rounded-lg border-2 border-[var(--brand)]/30 txt-primary hover:bg-[var(--brand)]/10 transition-colors font-medium text-base min-h-[48px] disabled:opacity-50 disabled:cursor-not-allowed focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--brand)] focus-visible:ring-offset-2"
                data-testid="btn-unsaved-preview"
                @click="handlePreview"
              >
                {{ $t('widget.previewWidget') }}
              </button>
              <button
                :disabled="isSaving"
                class="flex-1 md:flex-none btn-primary px-8 py-3 rounded-lg font-semibold text-base min-h-[48px] shadow-lg hover:shadow-xl transition-shadow disabled:opacity-70 disabled:cursor-not-allowed focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-[var(--brand)] flex items-center justify-center gap-2"
                data-testid="btn-unsaved-save"
                @click="handleSave"
              >
                <svg
                  v-if="isSaving"
                  class="animate-spin h-5 w-5"
                  xmlns="http://www.w3.org/2000/svg"
                  fill="none"
                  viewBox="0 0 24 24"
                >
                  <circle
                    class="opacity-25"
                    cx="12"
                    cy="12"
                    r="10"
                    stroke="currentColor"
                    stroke-width="4"
                  ></circle>
                  <path
                    class="opacity-75"
                    fill="currentColor"
                    d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
                  ></path>
                </svg>
                <span>{{
                  isSaving ? $t('unsavedChanges.saving') : $t('unsavedChanges.save')
                }}</span>
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </Transition>
</template>

<script setup lang="ts">
import { ref, watch, onMounted, onUnmounted } from 'vue'
import { ExclamationCircleIcon } from '@heroicons/vue/24/outline'

const props = defineProps<{
  show: boolean
  showPreview?: boolean
}>()

const emit = defineEmits<{
  save: []
  discard: []
  preview: []
}>()

const isSaving = ref(false)

const handleSave = async () => {
  if (isSaving.value) return
  isSaving.value = true
  emit('save')
}

const handleDiscard = () => {
  if (isSaving.value) return
  emit('discard')
}

const handlePreview = () => {
  if (isSaving.value) return
  emit('preview')
}

const handleKeydown = (e: KeyboardEvent) => {
  if (!props.show || isSaving.value) return

  // Cmd+S / Ctrl+S to save
  if ((e.metaKey || e.ctrlKey) && e.key === 's') {
    e.preventDefault()
    handleSave()
    return
  }

  // Escape to discard
  if (e.key === 'Escape') {
    e.preventDefault()
    handleDiscard()
  }
}

watch(
  () => props.show,
  (newVal) => {
    if (!newVal) {
      isSaving.value = false
    }
    // Don't auto-focus buttons - let user continue typing
  }
)

onMounted(() => {
  document.addEventListener('keydown', handleKeydown)
})

onUnmounted(() => {
  document.removeEventListener('keydown', handleKeydown)
})

defineExpose({
  resetSaving: () => {
    isSaving.value = false
  },
})
</script>
