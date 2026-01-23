<template>
  <div
    ref="containerRef"
    class="prose prose-sm max-w-none txt-primary markdown-content"
    data-testid="section-message-text"
  >
    <div data-testid="message-text" v-html="renderedContent"></div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, watch, nextTick, onMounted, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { useMarkdown } from '@/composables/useMarkdown'
import { useTheme } from '@/composables/useTheme'
import { renderMermaidBlocks, hasMermaidBlocks } from '@/composables/useMarkdownMermaid'
import { hasMathFormulas } from '@/composables/useMarkdownKatex'
import { useMemoriesStore } from '@/stores/userMemories'
import { useConfigStore } from '@/stores/config'
import { useNotification } from '@/composables/useNotification'
import type { UserMemory } from '@/services/api/userMemoriesApi'

interface Props {
  content: string
  isStreaming?: boolean
  memories?: UserMemory[] | null | undefined
}

const props = defineProps<Props>()
const { t } = useI18n()
const router = useRouter()
const { render, renderAsync } = useMarkdown()
const { theme } = useTheme()
const configStore = useConfigStore()
const { warning } = useNotification()
const containerRef = ref<HTMLElement | null>(null)
const renderedContent = ref('')
const memoriesStore = useMemoriesStore()

// Counter to prevent race conditions in async rendering
let renderVersion = 0

// Debounce timer for mermaid rendering
let mermaidDebounceTimer: ReturnType<typeof setTimeout> | null = null
const MERMAID_DEBOUNCE_MS = 500 // Wait 500ms after last content change before rendering

// Memory service availability
const isMemoryServiceAvailable = computed(() => configStore.features.memoryService)
const isMemoryServiceLoading = computed(() => configStore.features.memoryServiceLoading)

type MemoryAction = {
  action: string
  category?: string
  key?: string
  value?: string
}

function stripWholeBadgeOuterHtmlIfPresent(content: string): string {
  const trimmed = content.trim()
  if (
    trimmed.startsWith('<span class="memory-badge-wrapper') &&
    trimmed.includes('memory-tooltip') &&
    trimmed.endsWith('</span>')
  ) {
    return ''
  }
  return content
}

function extractTrailingMemoryActions(content: string): {
  cleanedContent: string
  actions: MemoryAction[]
} {
  const match = content.match(/\n\s*(\[\s*\{[\s\S]*\}\s*\])\s*$/)
  if (!match) return { cleanedContent: content, actions: [] }

  try {
    const parsed = JSON.parse(match[1] || 'null')
    if (
      Array.isArray(parsed) &&
      parsed.every(
        (item) =>
          item &&
          typeof item === 'object' &&
          typeof (item as { action?: unknown }).action === 'string'
      )
    ) {
      return {
        cleanedContent: content.slice(0, content.length - match[0].length).trimEnd(),
        actions: parsed as MemoryAction[],
      }
    }
  } catch {
    // ignore parse errors
  }

  return { cleanedContent: content, actions: [] }
}

function normalizeMemoryLine(value: string): string {
  return value
    .replace(/(?:\u200B|\u200C|\u200D|\uFEFF)/g, '')
    .replace(/[\s\u00a0\u202f\u2007\u2060]+/g, ' ')
    .trim()
}

function extractReferencedMemoryIds(content: string): number[] {
  const ids: number[] = []
  const regex = /\[Memory\s*:\s*(\d+)\]/gi
  let match: RegExpExecArray | null = null
  while ((match = regex.exec(content)) !== null) {
    const id = parseInt(match[1] || '', 10)
    if (Number.isFinite(id) && id > 0) ids.push(id)
  }
  return Array.from(new Set(ids))
}

const referencedMemoryIds = computed(() => extractReferencedMemoryIds(props.content))

const missingReferencedMemoryIds = computed(() => {
  if (referencedMemoryIds.value.length === 0) return []
  const available = new Set(memoriesStore.memories.map((m) => m.id))
  return referencedMemoryIds.value.filter((id) => !available.has(id))
})

const isMemoryServiceDefinitelyUnavailable = computed(() => {
  return !isMemoryServiceLoading.value && !isMemoryServiceAvailable.value
})

const isFetchProbablyUnreachable = computed(() => {
  const msg = (memoriesStore.error || '').toLowerCase()
  return msg.includes('timeout') || msg.includes('503') || msg.includes('unavailable')
})

const retryAttempt = ref(0)
const gaveUp = ref(false)
let retryTimer: number | null = null
const slowRetryAttempt = ref(0)
let slowRetryTimer: number | null = null

function clearRetryTimer() {
  if (retryTimer !== null && typeof window !== 'undefined') {
    window.clearTimeout(retryTimer)
  }
  retryTimer = null
}

function clearSlowRetryTimer() {
  if (slowRetryTimer !== null && typeof window !== 'undefined') {
    window.clearTimeout(slowRetryTimer)
  }
  slowRetryTimer = null
}

async function scheduleSlowRetry(): Promise<void> {
  if (slowRetryTimer !== null) return
  if (isMemoryServiceDefinitelyUnavailable.value) return
  if (missingReferencedMemoryIds.value.length === 0) return

  const delaysMs = [10000, 15000, 20000, 30000]
  const delay = delaysMs[Math.min(slowRetryAttempt.value, delaysMs.length - 1)]
  slowRetryAttempt.value += 1

  if (typeof window === 'undefined') return
  slowRetryTimer = window.setTimeout(async () => {
    slowRetryTimer = null
    if (missingReferencedMemoryIds.value.length === 0) return
    if (isMemoryServiceDefinitelyUnavailable.value) return

    await memoriesStore.fetchMemories(undefined, { timeoutMs: 8000, silent: true }).catch(() => {})

    if (missingReferencedMemoryIds.value.length === 0) {
      return
    }

    if (slowRetryAttempt.value >= delaysMs.length) {
      return
    }

    void scheduleSlowRetry()
  }, delay)
}

async function fetchMemoriesWithRetryBestEffort(): Promise<void> {
  if (referencedMemoryIds.value.length === 0) return
  if (missingReferencedMemoryIds.value.length === 0) return
  if (gaveUp.value) {
    void scheduleSlowRetry()
    return
  }
  if (isMemoryServiceDefinitelyUnavailable.value) return
  if (memoriesStore.loading) return

  const delaysMs = [0, 500, 1000, 2000, 3000, 5000, 8000, 12000]
  const delay = delaysMs[Math.min(retryAttempt.value, delaysMs.length - 1)]
  retryAttempt.value += 1

  clearRetryTimer()

  if (typeof window === 'undefined') return
  retryTimer = window.setTimeout(async () => {
    retryTimer = null
    if (missingReferencedMemoryIds.value.length === 0) return
    if (isMemoryServiceDefinitelyUnavailable.value) return

    await memoriesStore.fetchMemories(undefined, { timeoutMs: 8000, silent: true }).catch(() => {})

    if (missingReferencedMemoryIds.value.length === 0) {
      return
    }

    if (retryAttempt.value >= delaysMs.length) {
      gaveUp.value = true
      void scheduleSlowRetry()
      return
    }

    void fetchMemoriesWithRetryBestEffort()
  }, delay)
}

// Handle clicks on memory badges
const handleMemoryBadgeClick = (event: MouseEvent) => {
  const target = event.target as HTMLElement
  const memoryBadge = target.closest('.memory-ref')
  if (memoryBadge) {
    event.preventDefault()

    if (memoryBadge.classList.contains('memory-ref--disabled')) {
      warning(t('memories.serviceDisabled.message'))
      return
    }

    const memoryId = parseInt(memoryBadge.getAttribute('data-memory-id') || '-1')
    if (memoryId > 0) {
      window.dispatchEvent(new CustomEvent('memory-ref-clicked', { detail: { memoryId } }))
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
      tooltip.style.left = `${badgeRect.left + badgeRect.width / 2}px`
      tooltip.style.top = `${badgeRect.top - 10}px`
      tooltip.style.transform = 'translate(-50%, -100%)'
    }
  }
}

function escapeHtmlForBadge(text: string): string {
  const div = document.createElement('div')
  div.textContent = text
  return div.innerHTML
}

function buildMemoryBadgeHtml(memory: UserMemory, memoryId: string): string {
  const escapedKey = escapeHtmlForBadge(memory.key)
  const escapedValue = escapeHtmlForBadge(memory.value)
  const escapedCategory = escapeHtmlForBadge(memory.category)

  return `<span class="memory-badge-wrapper inline relative group"><button class="memory-ref inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-[var(--brand-alpha-light)] text-[var(--brand)] text-xs font-medium hover:bg-[var(--brand)] hover:text-white transition-all border border-[var(--brand)]/20 hover:border-[var(--brand)] cursor-pointer align-middle" data-memory-id="${memoryId}" onclick="event.preventDefault()"><svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="currentColor" class="flex-shrink-0"><path d="M21.33 12.91c.09 1.55-.62 3.04-1.89 3.95l.77 1.49c.23.45.26.98.06 1.45c-.19.47-.58.84-1.06 1l-.79.25a1.69 1.69 0 0 1-1.86-.55L14.44 18c-.89-.15-1.73-.53-2.44-1.10c-.5.15-1 .23-1.5.23c-.88 0-1.76-.27-2.5-.79c-.53.16-1.07.23-1.62.22c-.79.01-1.57-.15-2.3-.45a4.1 4.1 0 0 1-2.43-3.61c-.08-.72.04-1.45.35-2.11c-.29-.75-.32-1.57-.07-2.33C2.3 7.11 3 6.32 3.87 5.82c.58-1.69 2.21-2.82 4-2.7c1.6-1.5 4.05-1.66 5.83-.37c.42-.11.86-.17 1.3-.17c1.36-.03 2.65.57 3.5 1.64c2.04.53 3.5 2.35 3.58 4.47c.05 1.11-.25 2.20-.86 3.13c.07.36.11.72.11 1.09m-5-1.41c.57.07 1.02.5 1.02 1.07a1 1 0 0 1-1 1h-.63c-.32.9-.88 1.69-1.62 2.29c.25.09.51.14.77.21c5.13-.07 4.53-3.2 4.53-3.25a2.59 2.59 0 0 0-2.69-2.49a1 1 0 0 1-1-1a1 1 0 0 1 1-1c1.23.03 2.41.49 3.33 1.30c.05-.29.08-.59.08-.89c-.06-1.24-.62-2.32-2.87-2.53c-1.25-2.96-4.4-1.32-4.4-.4c-.03.23.21.72.25.75a1 1 0 0 1 1 1c0 .55-.45 1-1 1c-.53-.02-1.03-.22-1.43-.56c-.48.31-1.03.5-1.6.56c-.57.05-1.04-.35-1.07-.90a.97.97 0 0 1 .88-1.10c.16-.02.94-.14.94-.77c0-.66.25-1.29.68-1.79c-.92-.25-1.91.08-2.91 1.29C6.75 5 6 5.25 5.45 7.2C4.5 7.67 4 8 3.78 9c1.08-.22 2.19-.13 3.22.25c.5.19.78.75.59 1.29c-.19.52-.77.78-1.29.59c-.73-.32-1.55-.34-2.30-.06c-.32.27-.32.83-.32 1.27c0 .74.37 1.43 1 1.83c.53.27 1.12.41 1.71.40q-.225-.39-.39-.81a1.038 1.038 0 0 1 1.96-.68c.4 1.14 1.42 1.92 2.62 2.05c1.37-.07 2.59-.88 3.19-2.13c.23-1.38 1.34-1.5 2.56-1.5m2 7.47l-.62-1.3-.71.16l1 1.25zm-4.65-8.61a1 1 0 0 0-.91-1.03c-.71-.04-1.4.2-1.93.67c-.57.58-.87 1.38-.84 2.19a1 1 0 0 0 1 1c.57 0 1-.45 1-1c0-.27.07-.54.23-.76c.12-.10.27-.15.43-.15c.55.03 1.02-.38 1.02-.92"></path></svg><span class="font-medium max-w-[160px] truncate">${escapedKey}</span></button><div class="memory-tooltip fixed opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 pointer-events-none z-[9999] whitespace-nowrap"><div class="surface-elevated px-3 py-2 rounded-lg"><div class="flex items-center gap-2"><span class="pill text-[10px] px-1.5 py-0.5">${escapedCategory}</span><span class="text-xs font-medium txt-primary">${escapedKey}</span></div><div class="text-[11px] txt-secondary mt-1 max-w-[200px] truncate">${escapedValue}</div></div></div></span>`
}

// Process memory badges in the content
function processMemoryBadges(html: string): string {
  const { cleanedContent, actions } = extractTrailingMemoryActions(
    stripWholeBadgeOuterHtmlIfPresent(html)
  )
  let content = cleanedContent

  const availableMemories = memoriesStore.memories
  const resolvedMemories =
    props.memories && props.memories.length > 0 ? props.memories : availableMemories
  const missingIds = missingReferencedMemoryIds.value

  if (content.includes('[Memory')) {
    content = content.replace(/\[Memory\s*:\s*([^\]]+)\]/gi, (_match, memoryToken) => {
      const token = String(memoryToken).trim()
      const numericMatch = token.match(/^(\d+)/)
      const isNumeric = numericMatch !== null

      if (!isNumeric) {
        const action = actions
          .slice()
          .reverse()
          .find((a) => a && (a.action === 'create' || a.action === 'update') && !!a.key)

        if (action?.key) {
          const resolved = availableMemories.find(
            (m) =>
              m.key === action.key &&
              (action.category ? m.category === action.category : true) &&
              (action.value ? m.value === action.value : true)
          )
          if (resolved) {
            return buildMemoryBadgeHtml(resolved, String(resolved.id))
          }
        }

        return `<span class="memory-badge-wrapper inline relative group"><button class="memory-ref memory-ref--disabled inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300 text-xs font-medium border border-gray-200 dark:border-gray-700 cursor-not-allowed align-middle" data-disabled="true"><svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="currentColor" class="flex-shrink-0"><path d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2m1 15h-2v-2h2v2m0-4h-2V7h2v6z"/></svg><span class="font-medium">${escapeHtmlForBadge(t('memories.referencePending.badge'))}</span></button><div class="memory-tooltip fixed opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 pointer-events-none z-[9999] whitespace-normal" style="max-width: 280px;"><div class="surface-elevated px-4 py-3 rounded-lg"><div class="text-xs font-bold txt-primary mb-1">${escapeHtmlForBadge(t('memories.referencePending.badge'))}</div><div class="text-xs txt-primary leading-relaxed">${escapeHtmlForBadge(t('memories.referencePending.tooltip'))}</div></div></div></span>`
      }

      const memoryIdNum = numericMatch ? parseInt(numericMatch[1] || '0', 10) : -1
      const memoryId = numericMatch ? numericMatch[1] || token : token

      const memory = availableMemories.find((m) => m.id === memoryIdNum)

      if (memory) {
        return buildMemoryBadgeHtml(memory, memoryId)
      }

      if (
        memoriesStore.loading ||
        isMemoryServiceLoading.value ||
        (!gaveUp.value && missingIds.includes(memoryIdNum))
      ) {
        return `<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-gray-100 dark:bg-gray-800 text-gray-500 dark:text-gray-400 text-xs font-medium border border-gray-200 dark:border-gray-700 align-middle"><svg class="animate-spin h-3 w-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg><span class="font-medium">[Memory:${memoryId}...]</span></span>`
      }

      if (
        isMemoryServiceDefinitelyUnavailable.value ||
        (gaveUp.value && isFetchProbablyUnreachable.value)
      ) {
        return `<span class="memory-badge-wrapper inline relative group"><button class="memory-ref memory-ref--disabled inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-red-50 dark:bg-red-950/30 text-red-600 dark:text-red-400 text-xs font-medium hover:bg-red-100 dark:hover:bg-red-900/40 transition-all border border-red-200 dark:border-red-800/50 cursor-not-allowed align-middle" data-memory-id="${memoryId}" data-disabled="true"><svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="currentColor" class="flex-shrink-0"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg><span class="font-medium">[Memory:${memoryId}]</span></button><div class="memory-tooltip fixed opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 pointer-events-none z-[9999] whitespace-normal" style="max-width: 280px;"><div class="surface-elevated px-4 py-3 rounded-lg border border-red-200 dark:border-red-700 border-l-4 border-l-red-500"><div class="flex items-center gap-2 mb-2"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="currentColor" class="text-red-500 flex-shrink-0"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg><span class="text-xs font-bold txt-primary">${t('memories.serviceDisabled.badge')}</span></div><div class="text-xs txt-primary leading-relaxed">${t('memories.serviceDisabled.tooltip')}</div></div></div></span>`
      }

      return `<span class="memory-badge-wrapper inline relative group"><button class="memory-ref memory-ref--missing inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300 text-xs font-medium hover:bg-gray-200 dark:hover:bg-gray-700 transition-all border border-gray-200 dark:border-gray-700 cursor-pointer align-middle" data-memory-id="${memoryId}" onclick="event.preventDefault()"><svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="currentColor" class="flex-shrink-0"><path d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2m1 15h-2v-2h2v2m0-4h-2V7h2v6z"/></svg><span class="font-medium">[Memory:${memoryId}]</span></button><div class="memory-tooltip fixed opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 pointer-events-none z-[9999] whitespace-normal" style="max-width: 280px;"><div class="surface-elevated px-4 py-3 rounded-lg"><div class="text-xs font-bold txt-primary mb-1">${t('memories.referenceMissing.badge')}</div><div class="text-xs txt-primary leading-relaxed">${t('memories.referenceMissing.tooltip')}</div></div></div></span>`
    })
  }

  if (resolvedMemories.length > 0) {
    const keyMap = new Map<string, UserMemory>()
    for (const memory of resolvedMemories) {
      if (!keyMap.has(memory.key)) {
        keyMap.set(memory.key, memory)
      }
    }

    const lines = content.split(/\r?\n/)
    content = lines
      .map((line) => {
        if (line.includes('memory-badge-wrapper')) return line
        const normalized = normalizeMemoryLine(line)
        const normalizedKey = normalized.replace(/[·•.:,;!?]+$/g, '').trim()
        const memory = keyMap.get(normalizedKey)
        if (!memory) return line
        return buildMemoryBadgeHtml(memory, String(memory.id))
      })
      .join('\n')
  }

  return content
}

// Process content - sync for regular markdown, async for math formulas
async function processContent(content: string, version: number): Promise<string | null> {
  // Handle special file generation markers from backend with i18n
  if (content.startsWith('__FILE_GENERATED__:')) {
    const filename = content.replace('__FILE_GENERATED__:', '').trim()
    content = t('message.fileGenerated', { filename })
  } else if (content === '__FILE_GENERATION_FAILED__') {
    content = t('message.fileGenerationFailed')
  }

  let html: string

  // Use async rendering if content has math formulas, otherwise sync
  if (hasMathFormulas(content)) {
    html = await renderAsync(content, { processFileMarkers: false, katex: true })
    // Check if this render is still current (prevents race conditions)
    if (version !== renderVersion) return null
  } else {
    html = render(content, { processFileMarkers: false })
  }

  // Process memory badges after markdown rendering
  return processMemoryBadges(html)
}

// Update rendered content when props change
watch(
  () => props.content,
  async (newContent) => {
    // Increment version to invalidate any pending async renders
    const currentVersion = ++renderVersion
    const result = await processContent(newContent, currentVersion)
    // Only update if this is still the current render
    if (result !== null) {
      renderedContent.value = result
    }
  },
  { immediate: true }
)

// Resolve actual theme (system -> light/dark based on preference)
function getActualTheme(): 'light' | 'dark' {
  if (theme.value === 'system') {
    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'
  }
  return theme.value
}

// Render mermaid diagrams after content is mounted/updated (debounced)
async function processMermaidBlocks(): Promise<void> {
  await nextTick()
  if (containerRef.value && hasMermaidBlocks(containerRef.value)) {
    await renderMermaidBlocks(containerRef.value, getActualTheme())
  }
}

// Mermaid processing - debounced during streaming, immediate otherwise
function scheduleMermaidProcessing(): void {
  // Clear any pending timer
  if (mermaidDebounceTimer) {
    clearTimeout(mermaidDebounceTimer)
    mermaidDebounceTimer = null
  }

  // If streaming, debounce to avoid rendering incomplete diagrams
  if (props.isStreaming) {
    mermaidDebounceTimer = setTimeout(() => {
      processMermaidBlocks()
    }, MERMAID_DEBOUNCE_MS)
  } else {
    // Not streaming - render immediately
    processMermaidBlocks()
  }
}

// Cleanup timers on unmount
onBeforeUnmount(() => {
  if (mermaidDebounceTimer) {
    clearTimeout(mermaidDebounceTimer)
  }
  clearRetryTimer()
  clearSlowRetryTimer()
})

onMounted(() => {
  // Setup memory badge event listeners
  if (containerRef.value) {
    containerRef.value.addEventListener('click', handleMemoryBadgeClick)
    containerRef.value.addEventListener('mouseenter', handleTooltipPosition, true)
  }

  // Fetch memories if needed
  void fetchMemoriesWithRetryBestEffort()

  // Schedule mermaid processing
  scheduleMermaidProcessing()
})

watch(renderedContent, scheduleMermaidProcessing)

// If config finishes loading and memory service becomes available, ensure we load memories
watch(
  () => [isMemoryServiceAvailable.value, isMemoryServiceLoading.value],
  () => {
    void fetchMemoriesWithRetryBestEffort()
  }
)

// When message content changes, reset retry state and try again
watch(
  () => props.content,
  () => {
    clearRetryTimer()
    clearSlowRetryTimer()
    retryAttempt.value = 0
    slowRetryAttempt.value = 0
    gaveUp.value = false
    void fetchMemoriesWithRetryBestEffort()
  }
)

// When store updates, if some referenced IDs are still missing, keep trying
watch(
  () => missingReferencedMemoryIds.value,
  (missing) => {
    if (missing.length === 0) {
      clearRetryTimer()
      clearSlowRetryTimer()
      retryAttempt.value = 0
      slowRetryAttempt.value = 0
      gaveUp.value = false
      return
    }
    void fetchMemoriesWithRetryBestEffort()
  }
)
</script>

<style scoped>
/* Code blocks */
.markdown-content :deep(.code-block) {
  padding: 1rem;
  overflow-x: auto;
  margin-top: 0.75rem;
  margin-bottom: 0.75rem;
  border-radius: 0.5rem;
  background: var(--bg-chip);
  box-shadow: inset 0 0 0 1px var(--border-light);
}

.markdown-content :deep(.code-block code) {
  font-size: 0.875rem;
  font-family: 'Monaco', 'Menlo', 'Courier New', monospace;
}

/* Inline code */
.markdown-content :deep(.inline-code) {
  padding: 0.125rem 0.375rem;
  font-size: 0.875rem;
  font-family: ui-monospace, SFMono-Regular, monospace;
  border-radius: 0.25rem;
  background: var(--bg-chip);
  box-shadow: inset 0 0 0 1px var(--border-light);
}

/* Headers */
.markdown-content :deep(h1) {
  font-size: 1.5rem;
  font-weight: 700;
  margin-top: 1.5rem;
  margin-bottom: 1rem;
}

.markdown-content :deep(h2) {
  font-size: 1.25rem;
  font-weight: 700;
  margin-top: 1.25rem;
  margin-bottom: 0.75rem;
}

.markdown-content :deep(h3) {
  font-size: 1.125rem;
  font-weight: 600;
  margin-top: 1rem;
  margin-bottom: 0.5rem;
}

.markdown-content :deep(h4),
.markdown-content :deep(h5),
.markdown-content :deep(h6) {
  font-size: 1rem;
  font-weight: 600;
  margin-top: 0.75rem;
  margin-bottom: 0.5rem;
}

/* Lists */
.markdown-content :deep(ul) {
  margin-top: 0.5rem;
  margin-bottom: 0.5rem;
  margin-left: 1.5rem;
  list-style-type: disc;
}

.markdown-content :deep(ol) {
  margin-top: 0.5rem;
  margin-bottom: 0.5rem;
  margin-left: 1.5rem;
  list-style-type: decimal;
}

.markdown-content :deep(li) {
  margin-top: 0.25rem;
  margin-bottom: 0.25rem;
}

/* Task lists (GFM) */
.markdown-content :deep(li > input[type='checkbox']) {
  margin-right: 0.5rem;
}

/* Blockquotes */
.markdown-content :deep(.markdown-blockquote) {
  border-left-width: 4px;
  padding-left: 1rem;
  padding-top: 0.5rem;
  padding-bottom: 0.5rem;
  margin-top: 0.5rem;
  margin-bottom: 0.5rem;
  font-style: italic;
  border-top-right-radius: 0.25rem;
  border-bottom-right-radius: 0.25rem;
  border-color: var(--border-light, #6b7280);
  background-color: var(--bg-chip, #f3f4f6);
}

/* Tables */
.markdown-content :deep(.markdown-table) {
  width: 100%;
  border-collapse: collapse;
  margin-top: 1rem;
  margin-bottom: 1rem;
}

.markdown-content :deep(.markdown-table th),
.markdown-content :deep(.markdown-table td) {
  border-width: 1px;
  border-style: solid;
  padding: 0.5rem 0.75rem;
  border-color: var(--border-light, rgba(0, 0, 0, 0.1));
}

.markdown-content :deep(.markdown-table th) {
  font-weight: 600;
  background-color: var(--bg-chip, rgba(0, 0, 0, 0.05));
}

/* Links */
.markdown-content :deep(a) {
  color: #2563eb;
}

.markdown-content :deep(a:hover) {
  text-decoration: underline;
}

.dark .markdown-content :deep(a) {
  color: #60a5fa;
}

/* Horizontal rule */
.markdown-content :deep(hr) {
  margin-top: 1rem;
  margin-bottom: 1rem;
  border-top-width: 1px;
  border-color: var(--border-light, #d1d5db);
}

/* Strikethrough (GFM) */
.markdown-content :deep(del) {
  text-decoration: line-through;
  opacity: 0.6;
}

/* Paragraphs */
.markdown-content :deep(p) {
  margin-top: 0.5rem;
  margin-bottom: 0.5rem;
}

.markdown-content :deep(p:first-child) {
  margin-top: 0;
}

.markdown-content :deep(p:last-child) {
  margin-bottom: 0;
}

/* Strong/Bold */
.markdown-content :deep(strong) {
  font-weight: 600;
}

/* Emphasis/Italic */
.markdown-content :deep(em) {
  font-style: italic;
}

/* Highlighted/Marked text (==text==) */
.markdown-content :deep(.markdown-mark),
.markdown-content :deep(mark) {
  background-color: #fef08a;
  padding: 0.125rem 0.25rem;
  border-radius: 0.125rem;
}

.dark .markdown-content :deep(.markdown-mark),
.dark .markdown-content :deep(mark) {
  background-color: #854d0e;
  color: #fef9c3;
}

/* Collapsible sections (details/summary) */
.markdown-content :deep(details) {
  margin-top: 0.5rem;
  margin-bottom: 0.5rem;
  padding: 0.5rem;
  border-radius: 0.375rem;
  background-color: var(--bg-chip, rgba(0, 0, 0, 0.03));
  border: 1px solid var(--border-light, rgba(0, 0, 0, 0.1));
}

.markdown-content :deep(summary) {
  cursor: pointer;
  font-weight: 500;
  padding: 0.25rem 0;
  list-style: revert;
}

.markdown-content :deep(summary:hover) {
  color: var(--brand, #3b82f6);
}

.markdown-content :deep(details[open]) {
  padding-bottom: 0.75rem;
}

.markdown-content :deep(details[open] > summary) {
  margin-bottom: 0.5rem;
  border-bottom: 1px solid var(--border-light, rgba(0, 0, 0, 0.1));
  padding-bottom: 0.5rem;
}

/* Footnotes */
.markdown-content :deep(.footnotes-sep) {
  margin-top: 1.5rem;
  margin-bottom: 1rem;
}

.markdown-content :deep(.footnotes) {
  font-size: 0.875rem;
  color: var(--txt-secondary, #6b7280);
}

.markdown-content :deep(.footnotes-list) {
  padding-left: 1.5rem;
  margin: 0;
}

.markdown-content :deep(.footnote-item) {
  margin-bottom: 0.5rem;
}

.markdown-content :deep(.footnote-ref a) {
  color: var(--brand, #3b82f6);
  text-decoration: none;
  font-weight: 500;
}

.markdown-content :deep(.footnote-ref a:hover) {
  text-decoration: underline;
}

.markdown-content :deep(.footnote-backref) {
  margin-left: 0.25rem;
  color: var(--brand, #3b82f6);
  text-decoration: none;
}

.markdown-content :deep(.footnote-backref:hover) {
  text-decoration: underline;
}

/* Definition Lists */
.markdown-content :deep(.definition-list) {
  margin-top: 0.5rem;
  margin-bottom: 0.5rem;
}

.markdown-content :deep(.definition-list dt) {
  font-weight: 600;
  margin-top: 0.5rem;
}

.markdown-content :deep(.definition-list dd) {
  margin-left: 1.5rem;
  margin-bottom: 0.25rem;
  color: var(--txt-secondary, #6b7280);
}

/* Mermaid diagrams */
.markdown-content :deep(.mermaid-diagram) {
  margin-top: 1rem;
  margin-bottom: 1rem;
  overflow-x: auto;
}

.markdown-content :deep(.mermaid-diagram svg) {
  max-width: 100%;
  height: auto;
}

.markdown-content :deep(.mermaid-block) {
  padding: 1rem;
  overflow-x: auto;
  margin-top: 0.75rem;
  margin-bottom: 0.75rem;
  border-radius: 0.5rem;
  background: var(--bg-chip);
  box-shadow: inset 0 0 0 1px var(--border-light);
}

.markdown-content :deep(.mermaid-error) {
  border-width: 2px;
  border-style: solid;
  border-color: #fca5a5;
}

/* KaTeX math formulas */
.markdown-content :deep(.katex-block) {
  margin-top: 1rem;
  margin-bottom: 1rem;
  overflow-x: auto;
  text-align: center;
}

.markdown-content :deep(.katex-inline) {
  display: inline;
}

.markdown-content :deep(.katex-error) {
  color: #ef4444;
  font-family: ui-monospace, SFMono-Regular, monospace;
  font-size: 0.875rem;
}

.markdown-content :deep(.katex) {
  font-size: 1.1em;
}
</style>
