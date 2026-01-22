<template>
  <div
    ref="messageTextRef"
    class="prose prose-sm max-w-none txt-primary"
    data-testid="section-message-text"
  >
    <div class="whitespace-pre-wrap" data-testid="message-text" v-html="formattedContent"></div>
  </div>
</template>

<script setup lang="ts">
import { computed, ref, onMounted, onUnmounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { useMemoriesStore } from '@/stores/userMemories'
import { useConfigStore } from '@/stores/config'
import { useNotification } from '@/composables/useNotification'
import type { UserMemory } from '@/services/api/userMemoriesApi'

interface Props {
  content: string
  memories?: UserMemory[] | null | undefined
}

const props = defineProps<Props>()
const { t } = useI18n()
const router = useRouter()
const configStore = useConfigStore()
const { warning } = useNotification()
const messageTextRef = ref<HTMLElement | null>(null)
const memoriesStore = useMemoriesStore()

const isMemoryServiceAvailable = computed(() => configStore.features.memoryService)
const isMemoryServiceLoading = computed(() => configStore.features.memoryServiceLoading)

async function ensureMemoriesLoadedBestEffort(): Promise<void> {
  if (!props.content.includes('[Memory:')) return
  if (memoriesStore.memories.length > 0) return
  if (memoriesStore.loading) return

  // Best-effort: try to load memories so badges resolve without requiring a refresh.
  // Even if the service is down, fetchMemories() fails silently for common unavailability cases.
  await memoriesStore.fetchMemories().catch(() => {})
}

// Handle clicks on memory badges
const handleMemoryBadgeClick = (event: MouseEvent) => {
  const target = event.target as HTMLElement
  // Check if the clicked element or its parent is a memory badge
  const memoryBadge = target.closest('.memory-ref')
  if (memoryBadge) {
    event.preventDefault()

    // Check if this is a disabled badge
    if (memoryBadge.classList.contains('memory-ref--disabled')) {
      warning(t('memories.serviceDisabled.message'))
      return
    }

    const memoryId = parseInt(memoryBadge.getAttribute('data-memory-id') || '-1')
    if (memoryId > 0) {
      // Dispatch custom event for MessageMemories to highlight
      window.dispatchEvent(new CustomEvent('memory-ref-clicked', { detail: { memoryId } }))
      // Navigate to memories page
      router.push({ path: '/memories', query: { highlight: memoryId } })
    }
  }
}

// Handle tooltip positioning on hover
const handleTooltipPosition = (event: MouseEvent) => {
  const target = event.target as HTMLElement
  const badge = target.closest('.memory-badge-wrapper')
  if (badge) {
    const tooltip = badge.querySelector('.memory-tooltip') as HTMLElement
    if (tooltip) {
      const badgeRect = (badge as HTMLElement).getBoundingClientRect()
      // Position tooltip above the badge, centered
      tooltip.style.left = `${badgeRect.left + badgeRect.width / 2}px`
      tooltip.style.top = `${badgeRect.top - 10}px`
      tooltip.style.transform = 'translate(-50%, -100%)'
    }
  }
}

onMounted(() => {
  if (messageTextRef.value) {
    messageTextRef.value.addEventListener('click', handleMemoryBadgeClick)
    messageTextRef.value.addEventListener('mouseenter', handleTooltipPosition, true)
  }

  void ensureMemoriesLoadedBestEffort()
})

onUnmounted(() => {
  if (messageTextRef.value) {
    messageTextRef.value.removeEventListener('click', handleMemoryBadgeClick)
    messageTextRef.value.removeEventListener('mouseenter', handleTooltipPosition, true)
  }
})

// If config finishes loading and memory service becomes available, ensure we load memories so badges resolve.
watch(
  () => [isMemoryServiceAvailable.value, isMemoryServiceLoading.value],
  () => {
    void ensureMemoriesLoadedBestEffort()
  }
)

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

  // IMPORTANT: Replace "[Memory:ID]" references FIRST before any other markdown processing
  // NEW: AI uses [Memory:ID] format (e.g., [Memory:42]) so we can find the memory by ID
  // This works even after refresh because memories are loaded in the store
  // 🔥 REAKTIV: Access memoriesStore.memories to make computed reactive to changes!
  // This forces the computed to re-run whenever memories are loaded/updated
  const availableMemories = memoriesStore.memories
  const memoriesCount = availableMemories.length

  if (html.includes('[Memory')) {
    console.log('🔍 MessageText: Found [Memory] pattern, searching for IDs...', {
      memoriesAvailable: memoriesCount,
      memoriesLoading: memoriesStore.loading,
    })

    // Match [Memory:42] or [Memory: 42] format (with optional whitespace around colon)
    html = html.replace(/\[Memory\s*:\s*(\d+)\]/gi, (match, memoryId) => {
      const memoryIdNum = parseInt(memoryId)

      console.log('✅ REGEX MATCHED!', {
        match,
        memoryId: memoryIdNum,
        memoriesInStore: memoriesCount,
        serviceAvailable: isMemoryServiceAvailable.value,
      })

      // Find memory by ID from the reactive array
      const memory = availableMemories.find((m) => m.id === memoryIdNum)

      if (memory) {
        console.log('🎯 Replacing with memory badge for:', memory.key, 'ID:', memoryId)
        // Escape HTML in memory data for tooltip/display
        const escapedKey = escapeHtml(memory.key)
        const escapedValue = escapeHtml(memory.value)
        const escapedCategory = escapeHtml(memory.category)

        // Generate compact HTML (no extra whitespace to prevent layout issues with whitespace-pre-wrap)
        // Tooltip shows category, key and truncated value on hover
        return `<span class="memory-badge-wrapper inline relative group"><button class="memory-ref inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-[var(--brand-alpha-light)] text-[var(--brand)] text-xs font-medium hover:bg-[var(--brand)] hover:text-white transition-all border border-[var(--brand)]/20 hover:border-[var(--brand)] cursor-pointer align-middle" data-memory-id="${memoryId}" onclick="event.preventDefault()"><svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="currentColor" class="flex-shrink-0"><path d="M21.33 12.91c.09 1.55-.62 3.04-1.89 3.95l.77 1.49c.23.45.26.98.06 1.45c-.19.47-.58.84-1.06 1l-.79.25a1.69 1.69 0 0 1-1.86-.55L14.44 18c-.89-.15-1.73-.53-2.44-1.10c-.5.15-1 .23-1.5.23c-.88 0-1.76-.27-2.5-.79c-.53.16-1.07.23-1.62.22c-.79.01-1.57-.15-2.3-.45a4.1 4.1 0 0 1-2.43-3.61c-.08-.72.04-1.45.35-2.11c-.29-.75-.32-1.57-.07-2.33C2.3 7.11 3 6.32 3.87 5.82c.58-1.69 2.21-2.82 4-2.7c1.6-1.5 4.05-1.66 5.83-.37c.42-.11.86-.17 1.3-.17c1.36-.03 2.65.57 3.5 1.64c2.04.53 3.5 2.35 3.58 4.47c.05 1.11-.25 2.20-.86 3.13c.07.36.11.72.11 1.09m-5-1.41c.57.07 1.02.5 1.02 1.07a1 1 0 0 1-1 1h-.63c-.32.9-.88 1.69-1.62 2.29c.25.09.51.14.77.21c5.13-.07 4.53-3.2 4.53-3.25a2.59 2.59 0 0 0-2.69-2.49a1 1 0 0 1-1-1a1 1 0 0 1 1-1c1.23.03 2.41.49 3.33 1.30c.05-.29.08-.59.08-.89c-.06-1.24-.62-2.32-2.87-2.53c-1.25-2.96-4.4-1.32-4.4-.4c-.03.23.21.72.25.75a1 1 0 0 1 1 1c0 .55-.45 1-1 1c-.53-.02-1.03-.22-1.43-.56c-.48.31-1.03.5-1.6.56c-.57.05-1.04-.35-1.07-.90a.97.97 0 0 1 .88-1.10c.16-.02.94-.14.94-.77c0-.66.25-1.29.68-1.79c-.92-.25-1.91.08-2.91 1.29C6.75 5 6 5.25 5.45 7.2C4.5 7.67 4 8 3.78 9c1.08-.22 2.19-.13 3.22.25c.5.19.78.75.59 1.29c-.19.52-.77.78-1.29.59c-.73-.32-1.55-.34-2.30-.06c-.32.27-.32.83-.32 1.27c0 .74.37 1.43 1 1.83c.53.27 1.12.41 1.71.40q-.225-.39-.39-.81a1.038 1.038 0 0 1 1.96-.68c.4 1.14 1.42 1.92 2.62 2.05c1.37-.07 2.59-.88 3.19-2.13c.23-1.38 1.34-1.5 2.56-1.5m2 7.47l-.62-1.3l-.71.16l1 1.25zm-4.65-8.61a1 1 0 0 0-.91-1.03c-.71-.04-1.4.2-1.93.67c-.57.58-.87 1.38-.84 2.19a1 1 0 0 0 1 1c.57 0 1-.45 1-1c0-.27.07-.54.23-.76c.12-.10.27-.15.43-.15c.55.03 1.02-.38 1.02-.92"></path></svg><span class="font-medium max-w-[160px] truncate">${escapedKey}</span></button><div class="memory-tooltip fixed opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 pointer-events-none z-[9999] whitespace-nowrap"><div class="surface-elevated px-3 py-2 rounded-lg"><div class="flex items-center gap-2"><span class="pill text-[10px] px-1.5 py-0.5">${escapedCategory}</span><span class="text-xs font-medium txt-primary">${escapedKey}</span></div><div class="text-[11px] txt-secondary mt-1 max-w-[200px] truncate">${escapedValue}</div></div></div></span>`
      }

      // Memory not found:
      // - If we're still loading: show a short loading placeholder
      // - If we're NOT loading: show a "missing" badge (avoid infinite spinner)
      console.warn('⚠️ Memory not found in store:', {
        memoryId: memoryIdNum,
        totalMemories: memoriesCount,
        loading: memoriesStore.loading,
      })

      if (memoriesStore.loading || isMemoryServiceLoading.value) {
        return `<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-gray-100 dark:bg-gray-800 text-gray-500 dark:text-gray-400 text-xs font-medium border border-gray-200 dark:border-gray-700 align-middle"><svg class="animate-spin h-3 w-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg><span class="font-medium">[Memory:${memoryId}...]</span></span>`
      }

      // Only show service-disabled badge once we know the feature is not available.
      // This avoids false "service unavailable" on first render before config/memories are loaded.
      if (!isMemoryServiceAvailable.value) {
        console.warn('⚠️ Memory service disabled, showing disabled badge')
        return `<span class="memory-badge-wrapper inline relative group"><button class="memory-ref memory-ref--disabled inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-red-50 dark:bg-red-950/30 text-red-600 dark:text-red-400 text-xs font-medium hover:bg-red-100 dark:hover:bg-red-900/40 transition-all border border-red-200 dark:border-red-800/50 cursor-not-allowed align-middle" data-memory-id="${memoryId}" data-disabled="true"><svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="currentColor" class="flex-shrink-0"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg><span class="font-medium">[Memory:${memoryId}]</span></button><div class="memory-tooltip fixed opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 pointer-events-none z-[9999] whitespace-normal" style="max-width: 280px;"><div class="surface-elevated px-4 py-3 rounded-lg border border-red-200 dark:border-red-700 border-l-4 border-l-red-500"><div class="flex items-center gap-2 mb-2"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="currentColor" class="text-red-500 flex-shrink-0"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg><span class="text-xs font-bold txt-primary">${t('memories.serviceDisabled.badge')}</span></div><div class="text-xs txt-primary leading-relaxed">${t('memories.serviceDisabled.tooltip')}</div></div></div></span>`
      }

      return `<span class="memory-badge-wrapper inline relative group"><button class="memory-ref memory-ref--missing inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300 text-xs font-medium hover:bg-gray-200 dark:hover:bg-gray-700 transition-all border border-gray-200 dark:border-gray-700 cursor-pointer align-middle" data-memory-id="${memoryId}" onclick="event.preventDefault()"><svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="currentColor" class="flex-shrink-0"><path d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2m1 15h-2v-2h2v2m0-4h-2V7h2v6z"/></svg><span class="font-medium">[Memory:${memoryId}]</span></button><div class="memory-tooltip fixed opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 pointer-events-none z-[9999] whitespace-normal" style="max-width: 280px;"><div class="surface-elevated px-4 py-3 rounded-lg"><div class="text-xs font-bold txt-primary mb-1">${t('memories.referenceMissing.badge')}</div><div class="text-xs txt-primary leading-relaxed">${t('memories.referenceMissing.tooltip')}</div></div></div></span>`
    })
  }

  // Code blocks (``` ```)
  html = html.replace(/```(\w+)?\n([\s\S]*?)```/g, (_match: string, lang: string, code: string) => {
    const language = lang || 'text'
    return `<pre class="surface-chip p-4 overflow-x-auto my-3 rounded-lg"><code class="language-${language} text-sm">${escapeHtml(code.trim())}</code></pre>`
  })

  // Inline code (` `)
  html = html.replace(
    /`([^`]+)`/g,
    '<code class="surface-chip px-1.5 py-0.5 text-sm font-mono rounded">$1</code>'
  )

  // Headers (# ## ###)
  html = html.replace(/^### (.+)$/gm, '<h3 class="text-lg font-semibold mt-4 mb-2">$1</h3>')
  html = html.replace(/^## (.+)$/gm, '<h2 class="text-xl font-bold mt-5 mb-3">$1</h2>')
  html = html.replace(/^# (.+)$/gm, '<h1 class="text-2xl font-bold mt-6 mb-4">$1</h1>')

  // Blockquotes (> )
  html = html.replace(
    /^&gt; (.+)$/gm,
    '<blockquote class="border-l-4 pl-4 py-2 my-2 italic rounded-r" style="border-color: #6b7280; background-color: #f3f4f6; color: #1f2937;">$1</blockquote>'
  )
  html = html.replace(
    /^> (.+)$/gm,
    '<blockquote class="border-l-4 pl-4 py-2 my-2 italic rounded-r" style="border-color: #6b7280; background-color: #f3f4f6; color: #1f2937;">$1</blockquote>'
  )

  // Horizontal rule (---)
  html = html.replace(
    /^---$/gm,
    '<hr class="my-4 border-t border-gray-300 dark:border-gray-600" />'
  )

  // Links ([text](url))
  html = html.replace(
    /\[([^\]]+)\]\(([^)]+)\)/g,
    '<a href="$2" target="_blank" rel="noopener noreferrer" class="text-blue-600 dark:text-blue-400 hover:underline">$1</a>'
  )

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
