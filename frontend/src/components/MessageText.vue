<template>
  <div class="prose prose-sm max-w-none txt-primary" data-testid="section-message-text">
    <div v-html="formattedContent" class="whitespace-pre-wrap"></div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'

interface Props {
  content: string
}

const props = defineProps<Props>()

const formattedContent = computed(() => {
  let html = props.content

  html = html.replace(/```(\w+)?\n([\s\S]*?)```/g, (_, lang, code) => {
    const language = lang || 'text'
    return `<pre class="surface-chip p-4 overflow-x-auto my-3"><code class="language-${language} text-sm">${escapeHtml(code.trim())}</code></pre>`
  })

  html = html.replace(/`([^`]+)`/g, '<code class="surface-chip px-1.5 py-0.5 text-sm font-mono">$1</code>')

  html = html.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
  html = html.replace(/\*([^*]+)\*/g, '<em>$1</em>')

  return html
})

function escapeHtml(text: string): string {
  const div = document.createElement('div')
  div.textContent = text
  return div.innerHTML
}
</script>

<style scoped>
.prose :deep(pre) {
  margin: 0.75rem 0;
}

.prose :deep(code) {
  font-family: 'Monaco', 'Menlo', 'Courier New', monospace;
}
</style>
