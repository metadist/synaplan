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
          <div class="flex items-center justify-between p-6 border-b border-light-border/10 dark:border-dark-border/10">
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
              @click="close"
              class="ml-4 p-2 rounded-lg hover:bg-black/5 dark:hover:bg-white/5 transition-colors txt-secondary hover:txt-primary"
              aria-label="Close"
            >
              <XMarkIcon class="w-6 h-6" />
            </button>
          </div>

          <!-- Content -->
          <div class="flex-1 overflow-y-auto p-6 scroll-thin">
            <div v-if="summary" class="space-y-6">
              <!-- Summary Text -->
              <div class="surface-elevated rounded-lg p-6">
                <pre class="whitespace-pre-wrap font-sans text-base txt-primary leading-relaxed">{{ summary }}</pre>
              </div>

              <!-- Statistics -->
              <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="text-center p-4 surface-card rounded-lg border border-light-border/10 dark:border-dark-border/10">
                  <p class="text-xs txt-secondary mb-2">Original</p>
                  <p class="text-2xl font-bold txt-primary">{{ metadata?.original_length || 0 }}</p>
                  <p class="text-xs txt-secondary mt-1">words</p>
                </div>
                <div class="text-center p-4 surface-card rounded-lg border border-light-border/10 dark:border-dark-border/10">
                  <p class="text-xs txt-secondary mb-2">Summary</p>
                  <p class="text-2xl font-bold txt-primary">{{ metadata?.summary_length || 0 }}</p>
                  <p class="text-xs txt-secondary mt-1">words</p>
                </div>
                <div class="text-center p-4 surface-card rounded-lg border border-light-border/10 dark:border-dark-border/10">
                  <p class="text-xs txt-secondary mb-2">Compression</p>
                  <p class="text-2xl font-bold text-[var(--brand)]">{{ ((metadata?.compression_ratio || 0) * 100).toFixed(1) }}%</p>
                  <p class="text-xs txt-secondary mt-1">ratio</p>
                </div>
                <div class="text-center p-4 surface-card rounded-lg border border-light-border/10 dark:border-dark-border/10">
                  <p class="text-xs txt-secondary mb-2">Processing Time</p>
                  <p class="text-2xl font-bold txt-primary">{{ ((metadata?.processing_time_ms || 0) / 1000).toFixed(1) }}s</p>
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
                    <span class="txt-primary ml-2 font-medium">{{ config?.focusAreas?.join(', ') }}</span>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Footer -->
          <div class="flex items-center justify-between gap-3 p-6 border-t border-light-border/10 dark:border-dark-border/10">
            <button
              @click="copyToClipboard"
              class="px-4 py-2 rounded-lg flex items-center gap-2 transition-colors border border-light-border/30 dark:border-dark-border/20 txt-primary hover:bg-black/5 dark:hover:bg-white/5"
            >
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
              </svg>
              Copy Summary
            </button>
            <button
              @click="close"
              class="btn-primary px-6 py-2 rounded-lg"
            >
              Close
            </button>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup lang="ts">
import { SparklesIcon, XMarkIcon } from '@heroicons/vue/24/outline'
import { useNotification } from '@/composables/useNotification'
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

const close = () => {
  emit('close')
}

const copyToClipboard = async () => {
  if (!props.summary) return

  try {
    await navigator.clipboard.writeText(props.summary)
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

