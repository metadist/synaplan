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
    <pre class="p-4 overflow-x-auto text-sm scroll-thin">
      <!-- eslint-disable-next-line vue/no-v-html -- trusted HTML from syntax highlighter -->
      <code class="hljs font-mono" v-html="highlightedCode"></code>
    </pre>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { ensureHighlighter, highlightCode, escapeHtml } from '@/composables/useHighlight'

interface Props {
  content: string
  language?: string
  filename?: string
  /**
   * Phase 3c: while the parent message is still streaming, skip the
   * highlight.js call and just show escaped text. highlight.js parsing on
   * a half-written code block costs 10-50 ms per chunk and produces
   * visibly wrong colours that flip every time a new keyword/string token
   * arrives. We re-run the highlighter exactly once when streaming ends.
   */
  isStreaming?: boolean
}

const props = defineProps<Props>()

const copied = ref(false)
const hljsReady = ref(false)

// Load highlight.js and trigger re-render when ready (only when not streaming —
// no point loading the lib at the start of a stream that's about to ignore it).
if (!props.isStreaming) {
  ensureHighlighter().then(() => {
    hljsReady.value = true
  })
}

const highlightedCode = computed(() => {
  // While streaming, return escaped text with no syntax highlighting. This
  // prevents the "rainbow flash" where partial code re-tokenises every time
  // a new chunk arrives. The full highlight runs once when isStreaming flips
  // false (the watch below loads the lib lazily at that point).
  if (props.isStreaming) {
    return escapeHtml(props.content)
  }
  // Access hljsReady to re-compute when highlight.js finishes loading
  if (!hljsReady.value) {
    return escapeHtml(props.content)
  }
  return highlightCode(props.content, props.language || '')
})

// When streaming flips to false (or the part is mounted post-stream),
// ensure the highlighter is loaded so the final render shows colours.
watch(
  () => props.isStreaming,
  (streaming) => {
    if (!streaming && !hljsReady.value) {
      ensureHighlighter().then(() => {
        hljsReady.value = true
      })
    }
  },
  { immediate: true }
)

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
