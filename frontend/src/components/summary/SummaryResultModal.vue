<template>
  <Teleport to="body">
    <Transition name="modal">
      <div
        v-if="isOpen"
        class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm"
        @click.self="close"
      >
        <div
          class="surface-card max-w-5xl w-full max-h-[90vh] flex flex-col rounded-xl shadow-2xl overflow-hidden"
          @click.stop
        >
          <!-- Header -->
          <div
            class="flex items-center justify-between p-6 border-b border-light-border/10 dark:border-dark-border/10"
          >
            <div class="flex-1 min-w-0">
              <h2 class="text-2xl font-semibold txt-primary flex items-center gap-2">
                <SparklesIcon class="w-6 h-6 text-[var(--brand)]" />
                Document Summary
              </h2>
              <div class="flex items-center gap-3 mt-2 text-sm txt-secondary">
                <span class="pill px-2 py-0.5">{{ config?.summaryType }}</span>
                <span class="pill px-2 py-0.5">{{ config?.length }}</span>
                <span class="pill px-2 py-0.5">{{ config?.outputLanguage }}</span>
              </div>
            </div>
            <button
              class="ml-4 p-2 rounded-lg hover:bg-black/5 dark:hover:bg-white/5 transition-colors txt-secondary hover:txt-primary"
              aria-label="Close"
              @click="close"
            >
              <XMarkIcon class="w-6 h-6" />
            </button>
          </div>

          <!-- Content -->
          <div class="flex-1 overflow-y-auto p-6 scroll-thin">
            <div v-if="summary" class="space-y-6">
              <!-- Thinking Block (Optional) -->
              <div
                v-if="parsedContent.thinking"
                class="surface-card rounded-lg border-2 border-light-border/20 dark:border-dark-border/10"
              >
                <button
                  class="w-full flex items-center justify-between p-4 text-left hover:bg-black/5 dark:hover:bg-white/5 transition-colors rounded-lg"
                  @click="showThinking = !showThinking"
                >
                  <div class="flex items-center gap-2">
                    <svg
                      class="w-5 h-5 txt-secondary"
                      fill="none"
                      stroke="currentColor"
                      viewBox="0 0 24 24"
                    >
                      <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"
                      />
                    </svg>
                    <span class="font-medium txt-primary">Thinking Process</span>
                    <span class="text-xs txt-secondary">(AI reasoning - not included in copy)</span>
                  </div>
                  <component
                    :is="showThinking ? ChevronUpIcon : ChevronDownIcon"
                    class="w-5 h-5 txt-secondary"
                  />
                </button>
                <Transition
                  enter-active-class="transition-all duration-300 ease-out"
                  leave-active-class="transition-all duration-200 ease-in"
                  enter-from-class="opacity-0 max-h-0"
                  enter-to-class="opacity-100 max-h-[500px]"
                  leave-from-class="opacity-100 max-h-[500px]"
                  leave-to-class="opacity-0 max-h-0"
                >
                  <div v-if="showThinking" class="px-4 pb-4 overflow-hidden">
                    <div
                      class="surface-elevated rounded-lg p-4 text-sm txt-secondary font-mono whitespace-pre-wrap leading-relaxed"
                    >
                      {{ parsedContent.thinking }}
                    </div>
                  </div>
                </Transition>
              </div>

              <!-- Summary Text (Markdown formatted) -->
              <div class="surface-elevated rounded-lg p-6">
                <div
                  class="prose prose-sm max-w-none txt-primary leading-relaxed"
                  v-html="formattedSummary"
                ></div>
              </div>

              <!-- Statistics -->
              <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div
                  class="text-center p-4 surface-card rounded-lg border border-light-border/10 dark:border-dark-border/10"
                >
                  <p class="text-xs txt-secondary mb-2">Original</p>
                  <p class="text-2xl font-bold txt-primary">{{ metadata?.original_length || 0 }}</p>
                  <p class="text-xs txt-secondary mt-1">words</p>
                </div>
                <div
                  class="text-center p-4 surface-card rounded-lg border border-light-border/10 dark:border-dark-border/10"
                >
                  <p class="text-xs txt-secondary mb-2">Summary</p>
                  <p class="text-2xl font-bold txt-primary">{{ metadata?.summary_length || 0 }}</p>
                  <p class="text-xs txt-secondary mt-1">words</p>
                </div>
                <div
                  class="text-center p-4 surface-card rounded-lg border border-light-border/10 dark:border-dark-border/10"
                >
                  <p class="text-xs txt-secondary mb-2">Compression</p>
                  <p class="text-2xl font-bold text-[var(--brand)]">
                    {{ ((metadata?.compression_ratio || 0) * 100).toFixed(1) }}%
                  </p>
                  <p class="text-xs txt-secondary mt-1">ratio</p>
                </div>
                <div
                  class="text-center p-4 surface-card rounded-lg border border-light-border/10 dark:border-dark-border/10"
                >
                  <p class="text-xs txt-secondary mb-2">Processing Time</p>
                  <p class="text-2xl font-bold txt-primary">
                    {{ ((metadata?.processing_time_ms || 0) / 1000).toFixed(1) }}s
                  </p>
                  <p class="text-xs txt-secondary mt-1">{{ metadata?.model || 'N/A' }}</p>
                </div>
              </div>

              <!-- Configuration Details -->
              <div class="surface-elevated rounded-lg p-4">
                <h3 class="text-sm font-semibold txt-primary mb-3">Configuration Used</h3>
                <div class="grid grid-cols-2 gap-3 text-sm">
                  <div>
                    <span class="txt-secondary">Type:</span>
                    <span class="txt-primary ml-2 font-medium">{{ config?.summaryType }}</span>
                  </div>
                  <div>
                    <span class="txt-secondary">Length:</span>
                    <span class="txt-primary ml-2 font-medium">{{ config?.length }}</span>
                  </div>
                  <div>
                    <span class="txt-secondary">Language:</span>
                    <span class="txt-primary ml-2 font-medium">{{ config?.outputLanguage }}</span>
                  </div>
                  <div>
                    <span class="txt-secondary">Focus:</span>
                    <span class="txt-primary ml-2 font-medium">{{
                      config?.focusAreas?.join(', ')
                    }}</span>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Footer -->
          <div
            class="flex items-center justify-between gap-3 p-6 border-t border-light-border/10 dark:border-dark-border/10"
          >
            <button
              class="px-4 py-2 rounded-lg flex items-center gap-2 transition-colors border border-light-border/30 dark:border-dark-border/20 txt-primary hover:bg-black/5 dark:hover:bg-white/5"
              @click="copyToClipboard"
            >
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"
                />
              </svg>
              Copy Summary
            </button>
            <button class="btn-primary px-6 py-2 rounded-lg" @click="close">Close</button>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { SparklesIcon, XMarkIcon, ChevronDownIcon, ChevronUpIcon } from '@heroicons/vue/24/outline'
import { useNotification } from '@/composables/useNotification'
import { useMarkdown } from '@/composables/useMarkdown'
import type { SummaryConfig } from '@/mocks/summaries'
import type { SummaryMetadata } from '@/services/summaryService'

interface Props {
  isOpen: boolean
  summary: string | null
  metadata: SummaryMetadata | null
  config: SummaryConfig | null
}

const props = defineProps<Props>()
const emit = defineEmits<{
  close: []
}>()

const { success, error: showError } = useNotification()
const { render: renderMarkdown } = useMarkdown()

const showThinking = ref(false)

// Parse thinking block from summary
const parsedContent = computed(() => {
  if (!props.summary) return { thinking: null, content: '' }

  // Extract <think>...</think> block
  const thinkMatch = props.summary.match(/<think>([\s\S]*?)<\/think>/i)

  if (thinkMatch) {
    const thinking = thinkMatch[1].trim()
    const content = props.summary.replace(/<think>[\s\S]*?<\/think>/i, '').trim()
    return { thinking, content }
  }

  return { thinking: null, content: props.summary }
})

/**
 * Format summary content using the shared markdown renderer.
 * This ensures consistent rendering with the main chat interface.
 */
const formattedSummary = computed(() => {
  const content = parsedContent.value.content
  if (!content) return ''
  return renderMarkdown(content)
})

const close = () => {
  emit('close')
}

const copyToClipboard = async () => {
  // Copy only the summary content (without thinking)
  const contentToCopy = parsedContent.value.content
  if (!contentToCopy) return

  try {
    await navigator.clipboard.writeText(contentToCopy)
    success('Summary copied to clipboard!')
  } catch (err) {
    showError('Failed to copy summary')
  }
}
</script>

<style scoped>
.modal-enter-active,
.modal-leave-active {
  transition: opacity 0.3s ease;
}

.modal-enter-from,
.modal-leave-to {
  opacity: 0;
}

.modal-enter-active .surface-card,
.modal-leave-active .surface-card {
  transition: transform 0.3s ease;
}

.modal-enter-from .surface-card,
.modal-leave-to .surface-card {
  transform: scale(0.95);
}
</style>
