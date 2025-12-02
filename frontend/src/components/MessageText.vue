<template>
  <div class="prose prose-sm max-w-none txt-primary" data-testid="section-message-text">
    <div v-html="formattedContent" class="whitespace-pre-wrap" data-testid="message-text"></div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

interface Props {
  content: string
}

const props = defineProps<Props>()
const { t } = useI18n()

const formattedContent = computed(() => {
  let content = props.content
  
  // Handle special file generation markers from backend
  if (content.startsWith('__FILE_GENERATED__:')) {
    const filename = content.replace('__FILE_GENERATED__:', '').trim()
    content = t('message.fileGenerated', { filename })
  } else if (content === '__FILE_GENERATION_FAILED__') {
    content = t('message.fileGenerationFailed')
  }
  
  let html = content

  // Code blocks (``` ```)
  html = html.replace(/```(\w+)?\n([\s\S]*?)```/g, (_match: string, lang: string, code: string) => {
    const language = lang || 'text'
    return `<pre class="surface-chip p-4 overflow-x-auto my-3 rounded-lg"><code class="language-${language} text-sm">${escapeHtml(code.trim())}</code></pre>`
  })

  // Inline code (` `)
  html = html.replace(/`([^`]+)`/g, '<code class="surface-chip px-1.5 py-0.5 text-sm font-mono rounded">$1</code>')

  // Headers (# ## ###)
  html = html.replace(/^### (.+)$/gm, '<h3 class="text-lg font-semibold mt-4 mb-2">$1</h3>')
  html = html.replace(/^## (.+)$/gm, '<h2 class="text-xl font-bold mt-5 mb-3">$1</h2>')
  html = html.replace(/^# (.+)$/gm, '<h1 class="text-2xl font-bold mt-6 mb-4">$1</h1>')

  // Blockquotes (> )
  html = html.replace(/^&gt; (.+)$/gm, '<blockquote class="border-l-4 pl-4 py-2 my-2 italic rounded-r" style="border-color: #6b7280; background-color: #f3f4f6; color: #1f2937;">$1</blockquote>')
  html = html.replace(/^> (.+)$/gm, '<blockquote class="border-l-4 pl-4 py-2 my-2 italic rounded-r" style="border-color: #6b7280; background-color: #f3f4f6; color: #1f2937;">$1</blockquote>')

  // Horizontal rule (---)
  html = html.replace(/^---$/gm, '<hr class="my-4 border-t border-gray-300 dark:border-gray-600" />')

  // Links ([text](url))
  html = html.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer" class="text-blue-600 dark:text-blue-400 hover:underline">$1</a>')

  // Bold (**text**)
  html = html.replace(/\*\*([^*]+)\*\*/g, '<strong class="font-semibold">$1</strong>')
  
  // Italic (*text*)
  html = html.replace(/\*([^*]+)\*/g, '<em class="italic">$1</em>')

  // Unordered lists (- item)
  html = html.replace(/^- (.+)$/gm, (_match: string, item: string) => {
    return `<li class="ml-6 list-disc">${item}</li>`
  })
  
  // Ordered lists (1. item)
  html = html.replace(/^\d+\. (.+)$/gm, (_match: string, item: string) => {
    return `<li class="ml-6 list-decimal">${item}</li>`
  })

  // Wrap consecutive list items in ul/ol tags
  html = html.replace(/(<li class="ml-6 list-disc">.*?<\/li>\n?)+/g, (match: string) => {
    return `<ul class="my-2">${match}</ul>`
  })
  html = html.replace(/(<li class="ml-6 list-decimal">.*?<\/li>\n?)+/g, (match: string) => {
    return `<ol class="my-2">${match}</ol>`
  })

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
