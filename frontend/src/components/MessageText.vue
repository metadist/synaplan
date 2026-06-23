<template>
  <div
    ref="containerRef"
    class="prose prose-sm max-w-none txt-primary markdown-content"
    data-testid="section-message-text"
  >
    <!--
      Rendered markdown is applied to this container imperatively via morphdom
      (see `applyHtml`), NOT through v-html. v-html sets innerHTML wholesale,
      which tears down and rebuilds the entire subtree on every SSE chunk —
      the cause of the streaming flash. morphdom diffs the existing DOM against
      the new HTML and only touches the nodes that actually changed, so
      finished paragraphs keep their DOM nodes and never flicker. Vue does not
      manage this element's children (the template leaves it empty), so our
      imperative writes are safe and persistent.
    -->
    <div ref="contentRef" data-testid="message-text"></div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, watch, nextTick, onMounted, onBeforeUnmount } from 'vue'
import morphdom from 'morphdom'
import { useI18n } from 'vue-i18n'
import { useMarkdown } from '@/composables/useMarkdown'
import { useTheme } from '@/composables/useTheme'
import { renderMermaidBlocks, hasMermaidBlocks } from '@/composables/useMarkdownMermaid'
import { hasMathFormulas } from '@/composables/useMarkdownKatex'
import { useMemoriesStore } from '@/stores/userMemories'
import { useFeedbackStore } from '@/stores/userFeedback'
import { useConfigStore } from '@/stores/config'
import { useNotification } from '@/composables/useNotification'
import { findStableMarkdownBoundary } from '@/utils/streamingBoundary'
import { completeInlineMarkdown } from '@/utils/partialMarkdown'
import type { UserMemory } from '@/services/api/userMemoriesApi'

interface Props {
  content: string
  isStreaming?: boolean
  memories?: UserMemory[] | null | undefined
  /** When true, disables memory fetching (for shared/anonymous views) */
  readonly?: boolean
}

const props = defineProps<Props>()
const { t } = useI18n()
const { render, renderAsync } = useMarkdown()
const { theme } = useTheme()
const configStore = useConfigStore()
const { warning } = useNotification()
const containerRef = ref<HTMLElement | null>(null)
// The element that holds the rendered markdown. Written imperatively via
// `applyHtml` (morphdom), never via v-html — see the template comment.
const contentRef = ref<HTMLElement | null>(null)
// Last HTML string applied to the DOM. Tracked so post-render passes
// (mermaid) can react and so a render that happens before mount can be
// flushed in onMounted.
const lastRenderedHtml = ref('')

// Apply a fully-rendered (sanitized) HTML string to the content element by
// MORPHING the existing DOM toward it instead of replacing innerHTML. This is
// the core anti-flash mechanism: morphdom keeps untouched nodes (finished
// paragraphs, already-highlighted code, rendered mermaid) physically identical
// and only patches the parts that actually changed.
function applyHtml(html: string): void {
  lastRenderedHtml.value = html
  const el = contentRef.value
  if (!el) return

  morphdom(el, `<div>${html}</div>`, {
    childrenOnly: true,
    // Keep rendered mermaid diagrams: their live DOM (SVG inside the <pre>)
    // diverges from the source `<pre class="mermaid-block">` in the new HTML,
    // so without this morphdom would revert them on the next patch.
    skipFromChildren(fromEl) {
      return fromEl.classList?.contains('mermaid-rendered') === true
    },
    onBeforeElUpdated(fromEl, toEl) {
      if (fromEl.classList?.contains('mermaid-rendered')) return false
      // Skip deep work on identical subtrees (finished paragraphs / code).
      if (fromEl.isEqualNode(toEl)) return false
      return true
    },
    onBeforeNodeDiscarded(node) {
      return !(node instanceof Element && node.classList.contains('mermaid-rendered'))
    },
  })
}
const memoriesStore = useMemoriesStore()
const feedbackStore = useFeedbackStore()

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
  // Match [Memory:123] or [Memory:123...] (AI sometimes adds trailing dots)
  const regex = /\[Memory\s*:\s*(\d+)\.{0,3}\]/gi
  let match
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
  // Skip in readonly mode (shared views)
  if (props.readonly) return
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
  // Skip memory fetching in readonly mode (shared views)
  if (props.readonly) return
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

    // First try bulk fetch
    await memoriesStore.fetchMemories(undefined, { timeoutMs: 8000, silent: true }).catch(() => {})

    // If still missing, try to fetch individual memories by ID
    const stillMissing = missingReferencedMemoryIds.value
    if (stillMissing.length > 0) {
      // Fetch each missing memory individually (in parallel, max 5 at a time)
      const toFetch = stillMissing.slice(0, 5)
      await Promise.all(toFetch.map((id) => memoriesStore.fetchMemoryById(id))).catch(() => {})
    }

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
      // Find the memory from props or store
      const memory =
        props.memories?.find((m) => m.id === memoryId) ||
        memoriesStore.memories.find((m) => m.id === memoryId)

      // Dispatch window event to open MemoriesDialog in ChatView (stay in chat!)
      if (memory) {
        window.dispatchEvent(new CustomEvent('open-memory-dialog', { detail: { memory } }))
      }

      // Also dispatch event for MessageMemories component to highlight
      window.dispatchEvent(new CustomEvent('memory-ref-clicked', { detail: { memoryId } }))
    }
  }
}

// Handle feedback badge clicks (feedback badges use .memory-ref class but have data-feedback-id attribute)
const handleFeedbackBadgeClick = (event: MouseEvent) => {
  const target = event.target as HTMLElement
  // Look for .memory-ref that has data-feedback-id (not data-memory-id)
  const feedbackBadge = target.closest('.memory-ref[data-feedback-id]')
  if (feedbackBadge) {
    event.preventDefault()

    if (feedbackBadge.classList.contains('memory-ref--disabled')) {
      warning(t('feedback.list.loadError'))
      return
    }

    const feedbackId = parseInt(feedbackBadge.getAttribute('data-feedback-id') || '-1')
    if (feedbackId > 0) {
      // Navigate to feedback page with highlight
      window.dispatchEvent(new CustomEvent('open-feedback-dialog', { detail: { feedbackId } }))
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

  const brainIcon =
    '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="currentColor" class="flex-shrink-0"><path d="M21.33 12.91c.09 1.55-.62 3.04-1.89 3.95l.77 1.49c.23.45.26.98.06 1.45c-.19.47-.58.84-1.06 1l-.79.25a1.69 1.69 0 0 1-1.86-.55L14.44 18c-.89-.15-1.73-.53-2.44-1.10c-.5.15-1 .23-1.5.23c-.88 0-1.76-.27-2.5-.79c-.53.16-1.07.23-1.62.22c-.79.01-1.57-.15-2.3-.45a4.1 4.1 0 0 1-2.43-3.61c-.08-.72.04-1.45.35-2.11c-.29-.75-.32-1.57-.07-2.33C2.3 7.11 3 6.32 3.87 5.82c.58-1.69 2.21-2.82 4-2.7c1.6-1.5 4.05-1.66 5.83-.37c.42-.11.86-.17 1.3-.17c1.36-.03 2.65.57 3.5 1.64c2.04.53 3.5 2.35 3.58 4.47c.05 1.11-.25 2.20-.86 3.13c.07.36.11.72.11 1.09m-5-1.41c.57.07 1.02.5 1.02 1.07a1 1 0 0 1-1 1h-.63c-.32.9-.88 1.69-1.62 2.29c.25.09.51.14.77.21c5.13-.07 4.53-3.2 4.53-3.25a2.59 2.59 0 0 0-2.69-2.49a1 1 0 0 1-1-1a1 1 0 0 1 1-1c1.23.03 2.41.49 3.33 1.30c.05-.29.08-.59.08-.89c-.06-1.24-.62-2.32-2.87-2.53c-1.25-2.96-4.4-1.32-4.4-.4c-.03.23.21.72.25.75a1 1 0 0 1 1 1c0 .55-.45 1-1 1c-.53-.02-1.03-.22-1.43-.56c-.48.31-1.03.5-1.6.56c-.57.05-1.04-.35-1.07-.90a.97.97 0 0 1 .88-1.10c.16-.02.94-.14.94-.77c0-.66.25-1.29.68-1.79c-.92-.25-1.91.08-2.91 1.29C6.75 5 6 5.25 5.45 7.2C4.5 7.67 4 8 3.78 9c1.08-.22 2.19-.13 3.22.25c.5.19.78.75.59 1.29c-.19.52-.77.78-1.29.59c-.73-.32-1.55-.34-2.30-.06c-.32.27-.32.83-.32 1.27c0 .74.37 1.43 1 1.83c.53.27 1.12.41 1.71.40q-.225-.39-.39-.81a1.038 1.038 0 0 1 1.96-.68c.4 1.14 1.42 1.92 2.62 2.05c1.37-.07 2.59-.88 3.19-2.13c.23-1.38 1.34-1.5 2.56-1.5m2 7.47l-.62-1.3-.71.16l1 1.25zm-4.65-8.61a1 1 0 0 0-.91-1.03c-.71-.04-1.4.2-1.93.67c-.57.58-.87 1.38-.84 2.19a1 1 0 0 0 1 1c.57 0 1-.45 1-1c0-.27.07-.54.23-.76c.12-.10.27-.15.43-.15c.55.03 1.02-.38 1.02-.92"></path></svg>'

  return `<span class="memory-badge-wrapper inline relative group"><button class="memory-ref inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-[var(--brand-alpha-light)] text-[var(--brand)] text-xs font-medium hover:bg-[var(--brand)] hover:text-white transition-all border border-[var(--brand)]/20 hover:border-[var(--brand)] cursor-pointer align-middle" data-memory-id="${memoryId}" onclick="event.preventDefault()">${brainIcon}<span class="font-medium max-w-[160px] truncate">${escapedKey}</span></button><span class="memory-tooltip absolute bottom-full left-1/2 -translate-x-1/2 mb-2 opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 pointer-events-none z-[9999] whitespace-nowrap" style="display:block"><span class="surface-elevated px-3 py-2 rounded-lg" style="display:block"><span class="flex items-center gap-2"><span class="pill text-[10px] px-1.5 py-0.5">${escapedCategory}</span><span class="text-xs font-medium txt-primary">${escapedKey}</span></span><span class="text-[11px] txt-secondary mt-1 max-w-[200px] truncate" style="display:block">${escapedValue}</span></span></span></span>`
}

// Build a readonly memory badge for shared views (links to login)
function buildReadonlyMemoryBadgeHtml(): string {
  const loginUrl = '/login'
  const brainIcon =
    '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="currentColor" class="flex-shrink-0"><path d="M21.33 12.91c.09 1.55-.62 3.04-1.89 3.95l.77 1.49c.23.45.26.98.06 1.45c-.19.47-.58.84-1.06 1l-.79.25a1.69 1.69 0 0 1-1.86-.55L14.44 18c-.89-.15-1.73-.53-2.44-1.10c-.5.15-1 .23-1.5.23c-.88 0-1.76-.27-2.5-.79c-.53.16-1.07.23-1.62.22c-.79.01-1.57-.15-2.3-.45a4.1 4.1 0 0 1-2.43-3.61c-.08-.72.04-1.45.35-2.11c-.29-.75-.32-1.57-.07-2.33C2.3 7.11 3 6.32 3.87 5.82c.58-1.69 2.21-2.82 4-2.7c1.6-1.5 4.05-1.66 5.83-.37c.42-.11.86-.17 1.3-.17c1.36-.03 2.65.57 3.5 1.64c2.04.53 3.5 2.35 3.58 4.47c.05 1.11-.25 2.20-.86 3.13c.07.36.11.72.11 1.09m-5-1.41c.57.07 1.02.5 1.02 1.07a1 1 0 0 1-1 1h-.63c-.32.9-.88 1.69-1.62 2.29c.25.09.51.14.77.21c5.13-.07 4.53-3.2 4.53-3.25a2.59 2.59 0 0 0-2.69-2.49a1 1 0 0 1-1-1a1 1 0 0 1 1-1c1.23.03 2.41.49 3.33 1.30c.05-.29.08-.59.08-.89c-.06-1.24-.62-2.32-2.87-2.53c-1.25-2.96-4.4-1.32-4.4-.4c-.03.23.21.72.25.75a1 1 0 0 1 1 1c0 .55-.45 1-1 1c-.53-.02-1.03-.22-1.43-.56c-.48.31-1.03.5-1.6.56c-.57.05-1.04-.35-1.07-.90a.97.97 0 0 1 .88-1.10c.16-.02.94-.14.94-.77c0-.66.25-1.29.68-1.79c-.92-.25-1.91.08-2.91 1.29C6.75 5 6 5.25 5.45 7.2C4.5 7.67 4 8 3.78 9c1.08-.22 2.19-.13 3.22.25c.5.19.78.75.59 1.29c-.19.52-.77.78-1.29.59c-.73-.32-1.55-.34-2.30-.06c-.32.27-.32.83-.32 1.27c0 .74.37 1.43 1 1.83c.53.27 1.12.41 1.71.40q-.225-.39-.39-.81a1.038 1.038 0 0 1 1.96-.68c.4 1.14 1.42 1.92 2.62 2.05c1.37-.07 2.59-.88 3.19-2.13c.23-1.38 1.34-1.5 2.56-1.5m2 7.47l-.62-1.3-.71.16l1 1.25zm-4.65-8.61a1 1 0 0 0-.91-1.03c-.71-.04-1.4.20-1.93.67c-.57.58-.87 1.38-.84 2.19a1 1 0 0 0 1 1c.57 0 1-.45 1-1c0-.27.07-.54.23-.76c.12-.10.27-.15.43-.15c.55.03 1.02-.38 1.02-.92"></path></svg>'
  return `<span class="memory-badge-wrapper inline relative group"><a href="${loginUrl}" class="memory-ref memory-ref--readonly inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-[var(--brand-alpha-light)] text-[var(--brand)] text-xs font-medium hover:bg-[var(--brand)] hover:text-white transition-all border border-[var(--brand)]/20 hover:border-[var(--brand)] cursor-pointer align-middle no-underline" title="${escapeHtmlForBadge(t('memories.loginToView'))}">${brainIcon}<span class="font-medium">${escapeHtmlForBadge(t('memories.memoryUsed'))}</span></a></span>`
}

// Process memory badges in the content
function processMemoryBadges(html: string): string {
  const { cleanedContent, actions } = extractTrailingMemoryActions(
    stripWholeBadgeOuterHtmlIfPresent(html)
  )
  let content = cleanedContent

  // In readonly mode, render simplified badges that link to login
  if (props.readonly) {
    if (content.includes('[Memory')) {
      content = content.replace(/\[Memory\s*:\s*([^\]]+)\]/gi, () => {
        return buildReadonlyMemoryBadgeHtml()
      })
    }
    return content
  }

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

        return `<span class="memory-badge-wrapper inline relative group"><button class="memory-ref memory-ref--disabled inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300 text-xs font-medium border border-gray-200 dark:border-gray-700 cursor-not-allowed align-middle" data-disabled="true"><svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="currentColor" class="flex-shrink-0"><path d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2m1 15h-2v-2h2v2m0-4h-2V7h2v6z"/></svg><span class="font-medium">${escapeHtmlForBadge(t('memories.referencePending.badge'))}</span></button><span class="memory-tooltip absolute bottom-full left-1/2 -translate-x-1/2 mb-2 opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 pointer-events-none z-[9999] whitespace-normal" style="display:block;max-width:280px"><span class="surface-elevated px-4 py-3 rounded-lg" style="display:block"><span class="text-xs font-bold txt-primary mb-1" style="display:block">${escapeHtmlForBadge(t('memories.referencePending.badge'))}</span><span class="text-xs txt-primary leading-relaxed" style="display:block">${escapeHtmlForBadge(t('memories.referencePending.tooltip'))}</span></span></span></span>`
      }

      const memoryIdNum = numericMatch ? parseInt(numericMatch[1] || '0', 10) : -1
      const memoryId = numericMatch ? numericMatch[1] || token : token

      // Exact match first
      let memory = availableMemories.find((m) => m.id === memoryIdNum)

      // If no exact match, try prefix matching (AI sometimes truncates long IDs)
      if (!memory && memoryId.length >= 10) {
        // Only match if the provided ID is a prefix of a real ID (truncated by AI)
        // Do NOT match if a real ID is a prefix of the provided ID — that causes false matches
        memory = availableMemories.find((m) => {
          const memIdStr = String(m.id)
          return memIdStr.startsWith(memoryId)
        })
      }

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
        return `<span class="memory-badge-wrapper inline relative group"><button class="memory-ref memory-ref--disabled inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-red-50 dark:bg-red-950/30 text-red-600 dark:text-red-400 text-xs font-medium hover:bg-red-100 dark:hover:bg-red-900/40 transition-all border border-red-200 dark:border-red-800/50 cursor-not-allowed align-middle" data-memory-id="${memoryId}" data-disabled="true"><svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="currentColor" class="flex-shrink-0"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg><span class="font-medium">[Memory:${memoryId}]</span></button><span class="memory-tooltip absolute bottom-full left-1/2 -translate-x-1/2 mb-2 opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 pointer-events-none z-[9999] whitespace-normal" style="display:block;max-width:280px"><span class="surface-elevated px-4 py-3 rounded-lg border border-red-200 dark:border-red-700 border-l-4 border-l-red-500" style="display:block"><span class="flex items-center gap-2 mb-2"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="currentColor" class="text-red-500 flex-shrink-0"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg><span class="text-xs font-bold txt-primary">${t('memories.serviceDisabled.badge')}</span></span><span class="text-xs txt-primary leading-relaxed" style="display:block">${t('memories.serviceDisabled.tooltip')}</span></span></span></span>`
      }

      return `<span class="memory-badge-wrapper inline relative group"><button class="memory-ref memory-ref--missing inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300 text-xs font-medium hover:bg-gray-200 dark:hover:bg-gray-700 transition-all border border-gray-200 dark:border-gray-700 cursor-pointer align-middle" data-memory-id="${memoryId}" onclick="event.preventDefault()"><svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="currentColor" class="flex-shrink-0"><path d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2m1 15h-2v-2h2v2m0-4h-2V7h2v6z"/></svg><span class="font-medium">[Memory:${memoryId}]</span></button><span class="memory-tooltip absolute bottom-full left-1/2 -translate-x-1/2 mb-2 opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 pointer-events-none z-[9999] whitespace-normal" style="display:block;max-width:280px"><span class="surface-elevated px-4 py-3 rounded-lg" style="display:block"><span class="text-xs font-bold txt-primary mb-1" style="display:block">${t('memories.referenceMissing.badge')}</span><span class="text-xs txt-primary leading-relaxed" style="display:block">${t('memories.referenceMissing.tooltip')}</span></span></span></span>`
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
    const result: string[] = []
    for (let i = 0; i < lines.length; i++) {
      const line = lines[i]
      if (line.includes('memory-badge-wrapper')) {
        result.push(line)
        continue
      }
      const normalized = normalizeMemoryLine(line)
      const normalizedKey = normalized.replace(/[·•.:,;!?]+$/g, '').trim()
      const memory = keyMap.get(normalizedKey)
      if (!memory) {
        result.push(line)
        continue
      }
      const badge = buildMemoryBadgeHtml(memory, String(memory.id))
      if (result.length > 0 && result[result.length - 1].trimEnd().endsWith('</p>')) {
        result[result.length - 1] = result[result.length - 1].replace(/<\/p>\s*$/, ` ${badge}</p>`)
      } else {
        result.push(badge)
      }
    }
    content = result.join('\n')
  }

  return content
}

// Build feedback badge HTML - using EXACT same style as memory badges for consistency
function buildFeedbackBadgeHtml(
  feedback: { id: number; type: string; value: string },
  feedbackId: string
): string {
  const isPositive = feedback.type === 'positive'
  // Use checkmark for positive, X for negative
  const icon = isPositive
    ? '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="currentColor" class="flex-shrink-0"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/></svg>'
    : '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="currentColor" class="flex-shrink-0"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12 19 6.41z"/></svg>'

  // Truncate value for badge display (same as memory badges with key)
  const shortLabel =
    feedback.value.length > 40 ? feedback.value.substring(0, 40) + '...' : feedback.value
  const escapedShortLabel = escapeHtmlForBadge(shortLabel)
  const escapedFullValue = escapeHtmlForBadge(feedback.value)
  const typeLabel = isPositive ? t('feedback.type.positive') : t('feedback.type.falsePositive')

  // Tooltip structure: surface-elevated > category pill + key > value
  return `<span class="memory-badge-wrapper inline relative group"><button class="memory-ref inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-[var(--brand-alpha-light)] text-[var(--brand)] text-xs font-medium hover:bg-[var(--brand)] hover:text-white transition-all border border-[var(--brand)]/20 hover:border-[var(--brand)] cursor-pointer align-middle" data-feedback-id="${feedbackId}" onclick="event.preventDefault()">${icon}<span class="font-medium max-w-[160px] truncate">${escapedShortLabel}</span></button><span class="memory-tooltip absolute bottom-full left-1/2 -translate-x-1/2 mb-2 opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 pointer-events-none z-[9999] whitespace-nowrap" style="display:block"><span class="surface-elevated px-3 py-2 rounded-lg" style="display:block"><span class="flex items-center gap-2"><span class="pill text-[10px] px-1.5 py-0.5">${typeLabel}</span><span class="text-xs font-medium txt-primary">${escapedShortLabel}</span></span><span class="text-[11px] txt-secondary mt-1 max-w-[200px] truncate" style="display:block">${escapedFullValue}</span></span></span></span>`
}

// Process feedback badges in the content [Feedback:ID]
function processFeedbackBadges(html: string): string {
  // Skip if no feedback references
  if (!html.includes('[Feedback:') && !html.includes('[feedback:')) {
    return html
  }

  // Match [Feedback:123] or [Feedback:123...] pattern (AI sometimes adds trailing dots)
  const feedbackRefPattern = /\[Feedback\s*:\s*(\d+)\.{0,3}\]/gi

  return html.replace(feedbackRefPattern, (_match, id) => {
    const feedbackId = parseInt(id, 10)
    const feedback = feedbackStore.getFeedbackById(feedbackId)

    if (feedback) {
      return buildFeedbackBadgeHtml(feedback, String(feedbackId))
    }

    // Show placeholder for unresolved feedbacks - same style as missing memory badges
    return `<span class="memory-badge-wrapper inline relative group"><button class="memory-ref memory-ref--missing inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300 text-xs font-medium hover:bg-gray-200 dark:hover:bg-gray-700 transition-all border border-gray-200 dark:border-gray-700 cursor-pointer align-middle" data-feedback-id="${feedbackId}" onclick="event.preventDefault()"><svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="currentColor" class="flex-shrink-0"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg><span class="font-medium">${escapeHtmlForBadge(t('feedback.badge.loading'))}</span></button></span>`
  })
}

// Normalize reference markers so they stay inline (prevent markdown paragraph breaks)
function normalizeInlineReferences(text: string): string {
  // Remove newlines directly before/after [Feedback:ID] and [Memory:ID] so markdown
  // does not wrap them in separate <p> blocks.
  // Handles both numeric IDs ([Memory:12345]) and named keys ([Memory:hobby]).
  return text
    .replace(/\n+(\[(?:Feedback|Memory)\s*:\s*[\w.-]+\])/gi, ' $1')
    .replace(/(\[(?:Feedback|Memory)\s*:\s*[\w.-]+\])\n+/gi, '$1 ')
}

// Normalize special file markers + reference markers + apply badge / table
// post-processing. Shared between the sync and async render paths so the
// post-render HTML shape is identical regardless of which path produced it.
function normalizeContentForRender(input: string): string {
  if (input.startsWith('__FILE_GENERATED__:')) {
    const filename = input.replace('__FILE_GENERATED__:', '').trim()
    return t('message.fileGenerated', { filename })
  }
  if (input === '__FILE_GENERATION_FAILED__') {
    return t('message.fileGenerationFailed')
  }
  if (input === '__AUDIO_GENERATED__') {
    return t('message.audioGenerated')
  }
  return input
}

function postProcessHtml(html: string): string {
  html = processFeedbackBadges(processMemoryBadges(html))
  html = html.replace(/<table(\s|>)/g, '<div class="table-scroll"><table$1')
  html = html.replace(/<\/table>/g, '</table></div>')
  return html
}

/**
 * Synchronous full-pipeline render. Used for the post-stream final render of
 * non-math content. Critical for E2E tests that read `innerText` immediately
 * after `data-testid="message-done"` becomes visible — anything async here
 * would push the final `applyHtml` call onto the next microtask, after Vue has
 * already committed `message-done` to the DOM. Tests would then read a stale
 * half-rendered bubble (only the cheap streaming HTML).
 */
function processContentSync(content: string): string {
  const normalized = normalizeInlineReferences(normalizeContentForRender(content))
  const html = render(normalized, { processFileMarkers: false })
  return postProcessHtml(html)
}

/**
 * Async full-pipeline render. Only used when math formulas need the KaTeX
 * pipeline (which is genuinely async). Returns null if a newer render
 * superseded this one mid-flight.
 */
async function processContentAsync(content: string, version: number): Promise<string | null> {
  const normalized = normalizeInlineReferences(normalizeContentForRender(content))
  const html = await renderAsync(normalized, { processFileMarkers: false, katex: true })
  if (version !== renderVersion) return null
  return postProcessHtml(html)
}

/**
 * Legacy entry point retained so any other caller still gets the right
 * behaviour without a duplicated branch. Routes to sync for plain content,
 * async for math.
 */
async function processContent(content: string, version: number): Promise<string | null> {
  if (hasMathFormulas(content)) {
    return processContentAsync(content, version)
  }
  return processContentSync(content)
}

// Streaming render strategy (issue #903):
//
// We split the in-progress content into a STABLE PREFIX (everything up
// to the last paragraph break / closed code fence) and a TRAILING TAIL
// (the in-progress paragraph). The prefix runs through the full
// markdown + DOMPurify + highlight.js pipeline ONCE per paragraph and
// is cached, so already-rendered headings, tables, and code blocks stay
// byte-identical between chunks — no flicker. The tail uses the cheap
// escape + <br> path because it would be rewritten by every chunk
// anyway. Memory / feedback badges still resolve in the tail in real
// time so [Memory:ID] tokens turn into pills as they arrive.
//
// Math content keeps the legacy debounced-async path because KaTeX is
// genuinely async and an in-progress formula must not be partially
// rendered.
let renderDebounceTimer: ReturnType<typeof setTimeout> | null = null
const RENDER_DEBOUNCE_STREAMING_MS = 250

// Cache of the most recently rendered stable prefix during streaming.
// Cleared whenever streaming ends, the message is replaced, or the
// memory / feedback stores update (so freshly-resolved badges replace
// any stale loading pills in the cached HTML).
let streamingStableCache: { rawPrefix: string; html: string } | null = null

function invalidateStreamingCache(): void {
  streamingStableCache = null
}

function renderStreamingIncremental(content: string): string {
  const boundary = findStableMarkdownBoundary(content)
  const stablePrefix = boundary > 0 ? content.slice(0, boundary) : ''
  const trailingTail = boundary < content.length ? content.slice(boundary) : ''

  let stableHtml = ''
  if (stablePrefix.length > 0) {
    if (streamingStableCache && streamingStableCache.rawPrefix === stablePrefix) {
      stableHtml = streamingStableCache.html
    } else {
      stableHtml = processContentSync(stablePrefix)
      streamingStableCache = { rawPrefix: stablePrefix, html: stableHtml }
    }
  }

  let tailHtml = ''
  if (trailingTail.length > 0) {
    tailHtml = renderStreamingTail(trailingTail)
    tailHtml = processFeedbackBadges(processMemoryBadges(tailHtml))
  }

  // Concatenation is fine now: morphdom diffs the combined HTML against the
  // live DOM, so the unchanged stable prefix produces zero DOM mutations while
  // only the growing tail is patched. The cache still avoids recomputing the
  // prefix HTML on every chunk (CPU win).
  return stableHtml + tailHtml
}

/**
 * Renders the in-progress trailing paragraph through the FULL markdown
 * pipeline (marked + DOMPurify) so already-typed inline formatting
 * (`**bold**`, `*italic*`, `~~strike~~`, inline code, links) stays rendered
 * while the user is still typing the rest of the line. Open inline markers are
 * temporarily balanced via `completeInlineMarkdown` so the token currently
 * being streamed also shows formatted instead of leaking raw `**`.
 *
 * Code fences in the tail keep the cheap, un-highlighted treatment: running
 * highlight.js on every chunk is expensive and produces a rainbow flash, and
 * the final post-stream render highlights them properly anyway.
 */
function renderStreamingTail(content: string): string {
  const parts: Array<{ kind: 'prose' | 'code'; text: string; lang?: string }> = []
  const fenceRe = /```([a-zA-Z0-9_+-]*)\n([\s\S]*?)(?:```|$)/g
  let lastIdx = 0
  let m: RegExpExecArray | null
  while ((m = fenceRe.exec(content)) !== null) {
    if (m.index > lastIdx) {
      parts.push({ kind: 'prose', text: content.slice(lastIdx, m.index) })
    }
    parts.push({ kind: 'code', text: m[2] ?? '', lang: m[1] || 'text' })
    lastIdx = fenceRe.lastIndex
  }
  if (lastIdx < content.length) {
    parts.push({ kind: 'prose', text: content.slice(lastIdx) })
  }

  return parts
    .map((p) => {
      if (p.kind === 'code') {
        return `<pre class="code-block streaming-code-block"><code class="hljs language-${escapeHtml(p.lang ?? 'text')}">${escapeHtml(p.text)}</code></pre>`
      }
      const balanced = completeInlineMarkdown(p.text)
      const normalized = normalizeInlineReferences(balanced)
      return render(normalized, { processFileMarkers: false })
    })
    .join('')
}

function escapeHtml(input: string): string {
  return input
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;')
}

// Update rendered content when props change.
//
// IMPORTANT: this watcher MUST be synchronous when transitioning out of
// streaming mode for plain (non-math) content. The post-stream final render
// happens in the same Vue tick as the `isStreaming = false` flip that makes
// `data-testid="message-done"` visible in the DOM. If we awaited even one
// microtask here, Vue would commit `message-done` first and Playwright's
// `waitForAnswer` would read stale streaming HTML. Math content still routes
// through the async path (KaTeX is genuinely async); for that case the
// cheap render stays on screen until the math render completes — better
// than blocking the paint.
//
// During streaming, plain content uses `renderStreamingIncremental` which
// keeps the stable prefix (everything up to the last `\n\n` outside a code
// fence) byte-identical between chunks — no flicker (issue #903). Only the
// trailing in-progress paragraph is re-rendered with each chunk.
watch(
  () => props.content,
  (newContent) => {
    const currentVersion = ++renderVersion

    if (props.isStreaming) {
      // Issue #903: ALWAYS paint formatted markdown immediately (via the
      // incremental renderer) so inline formatting — **bold**, *italic*,
      // lists, inline code — never flashes as raw text between chunks.
      //
      // Math must NOT fall back to a raw escape pass: a single `$` (prices
      // like "19 $", shell vars, regexes, …) used to route the WHOLE message
      // through an un-formatted renderer, which is exactly what made
      // already-rendered bold briefly revert to literal `**bold**` on every
      // chunk. Instead we render markdown now and, only for genuine math
      // content, upgrade the formulas to KaTeX on a debounce (KaTeX is async
      // and we must not render half-typed formulas). morphdom keeps the
      // unchanged markdown nodes identical, so only the formula spans update.
      applyHtml(renderStreamingIncremental(newContent))

      if (hasMathFormulas(newContent)) {
        if (renderDebounceTimer !== null) {
          clearTimeout(renderDebounceTimer)
        }
        renderDebounceTimer = setTimeout(() => {
          renderDebounceTimer = null
          if (currentVersion !== renderVersion) return
          if (!props.isStreaming) return
          void processContentAsync(newContent, currentVersion).then((result) => {
            if (result !== null) {
              applyHtml(result)
            }
          })
        }, RENDER_DEBOUNCE_STREAMING_MS)
      }
      return
    }

    // Not streaming — full markdown pipeline. Sync for non-math (the common
    // case) so `message-done` and the final bubble HTML land in the same
    // Vue render commit; async only when KaTeX needs it.
    invalidateStreamingCache()
    if (hasMathFormulas(newContent)) {
      void processContentAsync(newContent, currentVersion).then((result) => {
        if (result !== null) {
          applyHtml(result)
        }
      })
    } else {
      applyHtml(processContentSync(newContent))
    }
  },
  { immediate: true }
)

// When streaming ends, run the full pipeline exactly once so the final
// rendered HTML matches what stored messages look like (markdown, code
// highlight, math, mermaid). For non-math content this MUST be synchronous
// — see the matching note on the content watcher above. The two watchers
// can both fire on the streaming → not-streaming transition (content tends
// to change too); since `processContentSync` is idempotent and cheap, the
// duplicate work is harmless and the synchronicity is what matters.
watch(
  () => props.isStreaming,
  (streaming) => {
    if (streaming) return
    if (renderDebounceTimer !== null) {
      clearTimeout(renderDebounceTimer)
      renderDebounceTimer = null
    }
    invalidateStreamingCache()

    const currentVersion = ++renderVersion
    if (hasMathFormulas(props.content)) {
      void processContentAsync(props.content, currentVersion).then((result) => {
        if (result !== null) {
          applyHtml(result)
        }
      })
    } else {
      applyHtml(processContentSync(props.content))
    }
  }
)

// Resolve actual theme (system -> light/dark based on preference)
function getActualTheme(): 'light' | 'dark' {
  if (theme.value === 'system') {
    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'
  }
  return theme.value
}

// Render mermaid diagrams after content is mounted/updated (debounced).
// `inPlace: true` keeps the <pre> element identity so the next morphdom patch
// does not revert the rendered diagram (skipFromChildren in applyHtml).
async function processMermaidBlocks(): Promise<void> {
  await nextTick()
  if (containerRef.value && hasMermaidBlocks(containerRef.value)) {
    await renderMermaidBlocks(containerRef.value, getActualTheme(), true)
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
      processMermaidBlocks().catch((error) => {
        console.error('Error processing mermaid blocks:', error)
      })
    }, MERMAID_DEBOUNCE_MS)
  } else {
    // Not streaming - render immediately
    processMermaidBlocks().catch((error) => {
      console.error('Error processing mermaid blocks:', error)
    })
  }
}

// Cleanup timers and event listeners on unmount
onBeforeUnmount(() => {
  if (renderDebounceTimer) {
    clearTimeout(renderDebounceTimer)
  }
  if (mermaidDebounceTimer) {
    clearTimeout(mermaidDebounceTimer)
  }
  clearRetryTimer()
  clearSlowRetryTimer()

  // Remove event listeners to prevent memory leaks
  if (containerRef.value) {
    containerRef.value.removeEventListener('click', handleMemoryBadgeClick)
    containerRef.value.removeEventListener('click', handleFeedbackBadgeClick)
  }
})

onMounted(() => {
  // Flush the initial render: the content watcher (immediate) ran during setup
  // before contentRef existed, so the first HTML was computed but not yet
  // written to the DOM.
  if (lastRenderedHtml.value) {
    applyHtml(lastRenderedHtml.value)
  }

  // Setup memory and feedback badge event listeners
  if (containerRef.value) {
    containerRef.value.addEventListener('click', handleMemoryBadgeClick)
    containerRef.value.addEventListener('click', handleFeedbackBadgeClick)
  }

  // Fetch memories if needed
  void fetchMemoriesWithRetryBestEffort()

  // Schedule mermaid processing
  scheduleMermaidProcessing()
})

watch(lastRenderedHtml, scheduleMermaidProcessing)

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

// Re-render content when feedback store changes (so badges resolve after SSE load)
watch(
  () => feedbackStore.feedbacks,
  async () => {
    if (props.content.includes('[Feedback:') || props.content.includes('[feedback:')) {
      // Drop the cached stable prefix so the next streaming chunk picks
      // up freshly-resolved feedback badges instead of stale loading pills.
      invalidateStreamingCache()
      const currentVersion = ++renderVersion
      const result = await processContent(props.content, currentVersion)
      if (result !== null) {
        applyHtml(result)
      }
    }
  },
  { deep: true }
)

// Re-render content when memory store changes (so badges resolve after SSE load)
watch(
  () => memoriesStore.memories,
  async () => {
    if (props.content.includes('[Memory:') || props.content.includes('[memory:')) {
      invalidateStreamingCache()
      const currentVersion = ++renderVersion
      const result = await processContent(props.content, currentVersion)
      if (result !== null) {
        applyHtml(result)
      }
    }
  },
  { deep: true }
)
</script>

<style scoped>
/*
 * Markdown styles are now defined globally in src/assets/markdown.css
 * Only component-specific overrides should go here
 */

/* Add subtle inset shadow for code blocks in main chat */
.markdown-content :deep(.code-block),
.markdown-content :deep(.mermaid-block) {
  box-shadow: inset 0 0 0 1px var(--border-light);
}

.markdown-content :deep(.inline-code) {
  box-shadow: inset 0 0 0 1px var(--border-light);
}

/* Table scroll wrapper — must override parent overflow-hidden */
.markdown-content :deep(.table-scroll) {
  overflow-x: auto;
  overflow-y: hidden;
  -webkit-overflow-scrolling: touch;
}
</style>
