<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useMarkdown } from '@/composables/useMarkdown'

const props = withDefaults(
  defineProps<{
    text: string
    prominent?: boolean
  }>(),
  { prominent: false }
)

const { t } = useI18n()
const { render } = useMarkdown()

const html = computed(() => render(props.text, { processFileMarkers: false }))
</script>

<template>
  <div>
    <!-- eslint-disable vue/no-v-html -- sanitized by useMarkdown -->
    <div
      class="prompt-transcript-md markdown-content txt-primary"
      :class="prominent ? 'text-base leading-relaxed' : 'text-sm leading-relaxed'"
      v-html="html"
    />
    <!-- eslint-enable vue/no-v-html -->
    <details class="what-was-sent-exact mt-3">
      <summary class="cursor-pointer list-none text-xs font-medium txt-secondary">
        {{ t('chatMessage.whatWasSentExact') }}
      </summary>
      <pre
        class="mt-2 overflow-x-auto whitespace-pre-wrap break-words rounded-xl border border-light-border/30 bg-chat px-3 py-2 font-mono text-xs leading-relaxed txt-primary dark:border-dark-border/20"
        data-testid="text-what-was-sent-exact"
        >{{ text }}</pre>
    </details>
  </div>
</template>

<style scoped>
.prompt-transcript-md :deep(> :first-child) {
  margin-top: 0;
}

.prompt-transcript-md :deep(h1) {
  font-size: 1.125rem;
  line-height: 1.4;
}

.prompt-transcript-md :deep(h2) {
  font-size: 1rem;
  line-height: 1.4;
}

.prompt-transcript-md :deep(h3),
.prompt-transcript-md :deep(h4),
.prompt-transcript-md :deep(h5),
.prompt-transcript-md :deep(h6) {
  font-size: 0.9375rem;
  line-height: 1.4;
}

.prompt-transcript-md :deep(h1),
.prompt-transcript-md :deep(h2),
.prompt-transcript-md :deep(h3),
.prompt-transcript-md :deep(h4),
.prompt-transcript-md :deep(h5),
.prompt-transcript-md :deep(h6) {
  margin-top: 0.9rem;
  margin-bottom: 0.4rem;
}

.prompt-transcript-md :deep(p),
.prompt-transcript-md :deep(li),
.prompt-transcript-md :deep(h1),
.prompt-transcript-md :deep(h2),
.prompt-transcript-md :deep(h3),
.prompt-transcript-md :deep(h4),
.prompt-transcript-md :deep(h5),
.prompt-transcript-md :deep(h6),
.prompt-transcript-md :deep(blockquote) {
  overflow-wrap: anywhere;
}

.prompt-transcript-md :deep(pre),
.prompt-transcript-md :deep(.code-block),
.prompt-transcript-md :deep(.table-scroll) {
  max-width: 100%;
}

.what-was-sent-exact summary::-webkit-details-marker {
  display: none;
}
</style>
