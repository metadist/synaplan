<template>
  <div
    class="my-3 surface-card border border-light-border/30 dark:border-dark-border/20 overflow-hidden"
    data-testid="comp-message-code"
  >
    <div
      class="flex items-center justify-between px-4 py-2.5 border-b border-light-border/30 dark:border-dark-border/20 bg-black/5 dark:bg-white/5"
    >
      <div class="flex items-center gap-2">
        <span v-if="language" class="text-xs font-semibold txt-primary uppercase tracking-wide">{{
          language
        }}</span>
        <span v-if="filename" class="text-xs txt-secondary">{{ filename }}</span>
      </div>
      <button
        class="text-xs px-3 py-1.5 rounded-lg hover-surface transition-all txt-secondary font-medium flex items-center gap-1.5"
        :aria-label="$t('commands.copyCode')"
        data-testid="btn-copy-code"
        @click="copyCode"
      >
        <svg v-if="!copied" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="2"
            d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"
          />
        </svg>
        <svg
          v-else
          class="w-4 h-4 text-green-500"
          fill="none"
          stroke="currentColor"
          viewBox="0 0 24 24"
        >
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="2"
            d="M5 13l4 4L19 7"
          />
        </svg>
        {{ copied ? $t('commands.copied') : $t('commands.copy') }}
      </button>
    </div>
    <pre
      class="p-4 overflow-x-auto text-sm scroll-thin"
    ><code ref="codeRef" class="hljs font-mono" v-html="highlightedCode"></code></pre>
  </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { ensureHighlighter, highlightCode, escapeHtml } from '@/composables/useHighlight'

interface Props {
  content: string
  language?: string
  filename?: string
}

const props = defineProps<Props>()

const copied = ref(false)
const codeRef = ref<HTMLElement | null>(null)
const hljsReady = ref(false)

// Load highlight.js and trigger re-render when ready
ensureHighlighter().then(() => {
  hljsReady.value = true
})

const highlightedCode = computed(() => {
  // Access hljsReady to re-compute when highlight.js finishes loading
  if (!hljsReady.value) {
    return escapeHtml(props.content)
  }
  return highlightCode(props.content, props.language || '')
})

const copyCode = async () => {
  try {
    await navigator.clipboard.writeText(props.content)
    copied.value = true
    setTimeout(() => {
      copied.value = false
    }, 2000)
  } catch (err) {
    console.error('Failed to copy:', err)
  }
}
</script>

<style scoped>
:deep(.hljs) {
  background: transparent !important;
  padding: 0;
}
</style>
