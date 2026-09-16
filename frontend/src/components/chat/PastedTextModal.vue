<template>
  <Teleport to="#app">
    <Transition name="modal">
      <div
        v-if="visible"
        class="modal-overlay fixed inset-0 z-50 flex items-end sm:items-center justify-center sm:p-4 bg-black/50"
        data-testid="modal-pasted-text-root"
        @click.self="emit('close')"
      >
        <div
          class="modal-panel pasted-text-modal surface-card w-full sm:max-w-3xl overflow-hidden flex flex-col rounded-t-2xl sm:rounded-xl"
          data-testid="modal-pasted-text"
          role="dialog"
          aria-modal="true"
          :aria-labelledby="titleId"
        >
          <div
            class="flex items-start justify-between gap-3 px-4 py-3 sm:p-6 border-b border-[var(--border-light)]"
          >
            <div class="min-w-0">
              <h2 :id="titleId" class="text-base sm:text-xl font-semibold txt-primary">
                {{ t('chatInput.pastedText.modalTitle') }}
              </h2>
              <p class="mt-1 text-xs sm:text-sm txt-secondary">
                {{
                  t('chatInput.pastedText.meta', {
                    lines: lineCount,
                    chars: charCount,
                  })
                }}
              </p>
            </div>
            <button
              type="button"
              class="icon-ghost p-2 flex-shrink-0"
              :aria-label="t('common.close')"
              data-testid="btn-pasted-text-modal-close"
              @click="emit('close')"
            >
              <XMarkIcon class="w-5 h-5" />
            </button>
          </div>

          <div class="flex-1 min-h-0 px-4 py-3 sm:px-6 sm:py-4">
            <textarea
              ref="textareaRef"
              v-model="draft"
              class="pasted-text-modal__editor"
              :readonly="readonly"
              :aria-label="t('chatInput.pastedText.title')"
              data-testid="input-pasted-text-editor"
              @keydown.esc.stop="emit('close')"
            />
          </div>

          <div
            v-if="!readonly"
            class="flex items-center justify-end gap-2 px-4 py-3 sm:px-6 sm:py-4 border-t border-[var(--border-light)]"
          >
            <button
              type="button"
              class="btn-secondary px-3 py-2 sm:px-4 rounded-lg text-sm font-medium"
              data-testid="btn-pasted-text-cancel"
              @click="emit('close')"
            >
              {{ t('chatInput.pastedText.cancel') }}
            </button>
            <button
              type="button"
              class="btn-primary px-3 py-2 sm:px-4 rounded-lg text-sm font-medium"
              data-testid="btn-pasted-text-save"
              @click="save"
            >
              {{ t('chatInput.pastedText.save') }}
            </button>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { XMarkIcon } from '@heroicons/vue/24/outline'
import { countLines } from '@/utils/pastedContent'

const props = withDefaults(
  defineProps<{
    visible: boolean
    content: string
    readonly?: boolean
  }>(),
  {
    readonly: false,
  }
)

const emit = defineEmits<{
  close: []
  save: [content: string]
}>()

const { t } = useI18n()
const textareaRef = ref<HTMLTextAreaElement | null>(null)
const draft = ref(props.content)
const titleId = 'pasted-text-modal-title'

const lineCount = computed(() => countLines(draft.value))
const charCount = computed(() => draft.value.length)

const isDesktopViewport = () =>
  typeof window !== 'undefined' && window.matchMedia('(min-width: 640px)').matches

watch(
  () => props.visible,
  async (open) => {
    if (!open) {
      return
    }
    draft.value = props.content
    await nextTick()
    if (!props.readonly && isDesktopViewport()) {
      textareaRef.value?.focus()
    }
  }
)

watch(
  () => props.content,
  (value) => {
    if (props.visible) {
      draft.value = value
    }
  }
)

const handleEscape = (event: KeyboardEvent) => {
  if (!props.visible || event.key !== 'Escape') {
    return
  }
  event.preventDefault()
  emit('close')
}

watch(
  () => props.visible,
  (open) => {
    if (open) {
      document.addEventListener('keydown', handleEscape)
      return
    }
    document.removeEventListener('keydown', handleEscape)
  }
)

onBeforeUnmount(() => {
  document.removeEventListener('keydown', handleEscape)
})

const save = () => {
  emit('save', draft.value)
}
</script>

<style scoped>
.modal-enter-active,
.modal-leave-active {
  transition: opacity 0.3s ease;
}

.modal-enter-from,
.modal-leave-to {
  opacity: 0;
}

.pasted-text-modal {
  max-height: inherit;
}

.pasted-text-modal__editor {
  display: block;
  width: 100%;
  min-height: 12rem;
  height: min(50dvh, 28rem);
  resize: none;
  border-radius: 0.75rem;
  border: 1px solid var(--border-light);
  background: var(--bg-chip);
  color: var(--txt-primary);
  font-family:
    ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New',
    monospace;
  overflow-y: auto;
  font-size: 1rem;
  line-height: 1.5;
  padding: 0.75rem 1rem;
}

.pasted-text-modal__editor:focus {
  outline: 2px solid var(--brand);
  outline-offset: 1px;
}

.pasted-text-modal__editor[readonly] {
  cursor: default;
}

@media (min-width: 640px) {
  .pasted-text-modal__editor {
    font-size: 0.875rem;
  }
}
</style>
