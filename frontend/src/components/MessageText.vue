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
import { ref, watch, nextTick, onMounted, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import { useMarkdown } from '@/composables/useMarkdown'
import { useTheme } from '@/composables/useTheme'
import { renderMermaidBlocks, hasMermaidBlocks } from '@/composables/useMarkdownMermaid'
import { hasMathFormulas } from '@/composables/useMarkdownKatex'

interface Props {
  content: string
  isStreaming?: boolean
}

const props = defineProps<Props>()
const { t } = useI18n()
const { render, renderAsync } = useMarkdown()
const { theme } = useTheme()
const containerRef = ref<HTMLElement | null>(null)
const renderedContent = ref('')

// Counter to prevent race conditions in async rendering
let renderVersion = 0

// Debounce timer for mermaid rendering
let mermaidDebounceTimer: ReturnType<typeof setTimeout> | null = null
const MERMAID_DEBOUNCE_MS = 500 // Wait 500ms after last content change before rendering

// Process content - sync for regular markdown, async for math formulas
async function processContent(content: string, version: number): Promise<string | null> {
  // Handle special file generation markers from backend with i18n
  if (content.startsWith('__FILE_GENERATED__:')) {
    const filename = content.replace('__FILE_GENERATED__:', '').trim()
    content = t('message.fileGenerated', { filename })
  } else if (content === '__FILE_GENERATION_FAILED__') {
    content = t('message.fileGenerationFailed')
  }

  // Use async rendering if content has math formulas, otherwise sync
  if (hasMathFormulas(content)) {
    const result = await renderAsync(content, { processFileMarkers: false, katex: true })
    // Check if this render is still current (prevents race conditions)
    if (version !== renderVersion) return null
    return result
  }
  return render(content, { processFileMarkers: false })
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

// Cleanup timer on unmount
onBeforeUnmount(() => {
  if (mermaidDebounceTimer) {
    clearTimeout(mermaidDebounceTimer)
  }
})

onMounted(scheduleMermaidProcessing)
watch(renderedContent, scheduleMermaidProcessing)
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
