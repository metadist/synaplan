<template>
  <div
    class="surface-card mb-2 overflow-hidden transition-opacity"
    :class="isStreaming ? 'opacity-90' : 'opacity-50 hover:opacity-70'"
    data-testid="section-message-thinking"
  >
    <button
      class="w-full px-3.5 py-2.5 flex items-center justify-between gap-2 hover-surface transition-colors"
      type="button"
      :aria-expanded="isExpanded"
      data-testid="btn-thinking-toggle"
      @click="toggle"
    >
      <span class="flex items-center gap-2 min-w-0">
        <span
          v-if="isStreaming"
          class="w-1.5 h-1.5 rounded-full bg-[var(--brand)] animate-pulse flex-shrink-0"
          aria-hidden="true"
        />
        <span class="text-sm font-medium txt-secondary truncate">
          {{ headerLabel }}
        </span>
      </span>
      <svg
        class="w-4 h-4 txt-tertiary transition-transform flex-shrink-0"
        :class="{ 'rotate-180': isExpanded }"
        viewBox="0 0 16 16"
        fill="none"
        xmlns="http://www.w3.org/2000/svg"
      >
        <path
          d="M4 6L8 10L12 6"
          stroke="currentColor"
          stroke-width="1.5"
          stroke-linecap="round"
          stroke-linejoin="round"
        />
      </svg>
    </button>

    <Transition
      enter-active-class="transition-all duration-200 ease-out"
      enter-from-class="opacity-0 max-h-0"
      enter-to-class="opacity-100"
      leave-active-class="transition-all duration-150 ease-in"
      leave-from-class="opacity-100"
      leave-to-class="opacity-0 max-h-0"
    >
      <div
        v-if="isExpanded"
        class="border-t border-light-border dark:border-dark-border px-3.5 py-3 flex gap-3 processing-enter"
        data-testid="section-thinking-content"
      >
        <div class="flex-shrink-0 w-3.5 h-3.5 mt-0.5">
          <!-- Brain/CPU icon from Heroicons -->
          <svg
            xmlns="http://www.w3.org/2000/svg"
            fill="none"
            viewBox="0 0 24 24"
            stroke-width="1.5"
            stroke="currentColor"
            class="w-full h-full txt-tertiary"
          >
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              d="M8.25 3v1.5M4.5 8.25H3m18 0h-1.5M4.5 12H3m18 0h-1.5m-15 3.75H3m18 0h-1.5M8.25 19.5V21M12 3v1.5m0 15V21m3.75-18v1.5m0 15V21m-9-1.5h10.5a2.25 2.25 0 002.25-2.25V6.75a2.25 2.25 0 00-2.25-2.25H6.75A2.25 2.25 0 004.5 6.75v10.5a2.25 2.25 0 002.25 2.25zm.75-12h9v9h-9v-9z"
            />
          </svg>
        </div>
        <!--
          While streaming the box is capped and follows the newest line, like a
          console — the point is to show the model working, not to read every
          word. Finished thinking is shown in full.
        -->
        <div
          ref="contentEl"
          class="flex-1 text-[13px] leading-relaxed txt-secondary whitespace-pre-wrap break-words"
          :class="{ 'max-h-40 overflow-y-auto': isStreaming }"
        >
          {{ content }}
        </div>
      </div>
    </Transition>
  </div>
</template>

<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

interface Props {
  content: string
  thinkingTime?: number
  isStreaming?: boolean
}

const props = defineProps<Props>()
const { t } = useI18n()

// Open while the model thinks so the user sees it working; folds away on its
// own when the answer starts. A manual toggle wins over both automatics.
const isExpanded = ref(!!props.isStreaming)
const userToggled = ref(false)
const contentEl = ref<HTMLElement | null>(null)

const toggle = () => {
  userToggled.value = true
  isExpanded.value = !isExpanded.value
}

watch(
  () => props.isStreaming,
  (streaming, wasStreaming) => {
    if (userToggled.value) return
    if (streaming) {
      isExpanded.value = true
    } else if (wasStreaming) {
      isExpanded.value = false
    }
  }
)

watch(
  () => props.content,
  async () => {
    if (!props.isStreaming || !isExpanded.value) return
    await nextTick()
    const el = contentEl.value
    if (el) el.scrollTop = el.scrollHeight
  }
)

// #1058: never invent a fake "8 seconds". While streaming show a live label;
// when finished, show the measured duration if we have one.
const headerLabel = computed(() => {
  if (props.isStreaming) {
    return t('message.thinkingInProgress')
  }
  if (typeof props.thinkingTime === 'number' && props.thinkingTime > 0) {
    return t('message.thoughtFor', { n: props.thinkingTime })
  }
  return t('message.thinking')
})
</script>
