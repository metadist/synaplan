<script setup lang="ts">
import { computed } from 'vue'
import { useMarkdown } from '@/composables/useMarkdown'

const props = withDefaults(
  defineProps<{
    text: string
    prominent?: boolean
  }>(),
  { prominent: false }
)

const { render } = useMarkdown()

const html = computed(() => render(props.text, { processFileMarkers: false }))
</script>

<template>
  <!-- eslint-disable vue/no-v-html -- sanitized by useMarkdown -->
  <div
    class="prompt-transcript-md markdown-content txt-primary"
    :class="prominent ? 'text-base leading-relaxed' : 'text-sm leading-relaxed'"
    v-html="html"
  />
  <!-- eslint-enable vue/no-v-html -->
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
</style>
