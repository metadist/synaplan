<template>
  <!-- Fullscreen Modal Overlay -->
  <div
    class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm"
    data-testid="modal-embed-code"
    @click.self="$emit('close')"
  >
    <div
      class="surface-card rounded-2xl max-w-4xl w-full max-h-[90vh] flex flex-col shadow-2xl"
      data-testid="section-embed-shell"
    >
      <!-- Header -->
      <div
        class="flex-shrink-0 surface-card border-b border-light-border/30 dark:border-dark-border/20 px-6 py-4 flex items-center justify-between z-10"
        data-testid="section-header"
      >
        <div data-testid="section-preview">
          <h2 class="text-xl font-semibold txt-primary flex items-center gap-2">
            <Icon icon="heroicons:code-bracket" class="w-6 h-6 text-[var(--brand)]" />
            {{ $t('widgets.embedCode') }}
          </h2>
          <p class="text-sm txt-secondary mt-1">{{ widget.name }}</p>
        </div>
        <button
          class="w-10 h-10 rounded-lg hover:bg-black/5 dark:hover:bg-white/5 transition-colors flex items-center justify-center"
          :aria-label="$t('common.close')"
          data-testid="btn-close"
          @click="$emit('close')"
        >
          <Icon icon="heroicons:x-mark" class="w-6 h-6 txt-secondary" />
        </button>
      </div>

      <!-- Content (scrollable) -->
      <div class="flex-1 overflow-y-auto p-6 space-y-6" data-testid="section-content">
        <!-- HTML Embed Code -->
        <div data-testid="section-html-code">
          <div class="flex items-center justify-between mb-3">
            <h3 class="font-semibold txt-primary flex items-center gap-2">
              <Icon icon="heroicons:globe-alt" class="w-5 h-5" />
              {{ $t('widgets.htmlCode') }}
            </h3>
            <button
              class="px-4 py-2 rounded-lg bg-[var(--brand)]/10 text-[var(--brand)] hover:bg-[var(--brand)]/20 transition-colors text-sm font-medium flex items-center gap-2"
              data-testid="btn-copy-html"
              @click="copyToClipboard(embedCode, 'HTML')"
            >
              <Icon
                :icon="copiedHTML ? 'heroicons:check' : 'heroicons:clipboard-document'"
                class="w-4 h-4"
              />
              {{ copiedHTML ? $t('common.copied') : $t('common.copy') }}
            </button>
          </div>
          <div class="relative">
            <pre
              class="surface-chip rounded-lg p-4 text-sm overflow-x-auto txt-primary font-mono border border-light-border/30 dark:border-dark-border/20"
            ><code>{{ embedCode }}</code></pre>
          </div>
          <p class="text-xs txt-secondary mt-2">
            {{ $t('widgets.htmlCodeDescription') }}
          </p>
        </div>

        <!-- Installation Instructions -->
        <div class="surface-chip rounded-lg p-4 border border-blue-500/20 bg-blue-500/5">
          <div class="flex items-start gap-3">
            <Icon
              icon="heroicons:information-circle"
              class="w-5 h-5 text-blue-500 flex-shrink-0 mt-0.5"
            />
            <div class="flex-1">
              <h4 class="font-medium txt-primary mb-2">{{ $t('widgets.installationTitle') }}</h4>
              <ul class="text-sm txt-secondary space-y-1 list-disc list-inside">
                <li>{{ $t('widgets.installationStep1') }}</li>
                <li>{{ $t('widgets.installationStep2') }}</li>
                <li>{{ $t('widgets.installationStep3') }}</li>
              </ul>
            </div>
          </div>
        </div>
      </div>

      <!-- Footer -->
      <div
        class="flex-shrink-0 surface-card border-t border-light-border/30 dark:border-dark-border/20 px-6 py-4 flex items-center justify-end gap-3 z-10"
      >
        <button
          class="px-6 py-2.5 rounded-lg hover:bg-black/5 dark:hover:bg-white/5 transition-colors txt-primary font-medium"
          data-testid="btn-close-footer"
          @click="$emit('close')"
        >
          {{ $t('common.close') }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue'
import { Icon } from '@iconify/vue'
import type { Widget } from '@/services/api/widgetsApi'
import { useNotification } from '@/composables/useNotification'

interface Props {
  widget: Widget
  embedCode: string
  legacyEmbedCode?: string
  wordpressShortcode: string
}

defineProps<Props>()
const emit = defineEmits<{
  close: []
}>()

const { success } = useNotification()

const copiedHTML = ref(false)

/**
 * Copy to clipboard
 */
const copyToClipboard = async (text: string, type: string) => {
  try {
    await navigator.clipboard.writeText(text)

    copiedHTML.value = true
    setTimeout(() => {
      copiedHTML.value = false
    }, 2000)

    success(`${type} code copied to clipboard!`)
  } catch (error) {
    console.error('Failed to copy:', error)
  }
}

/**
 * Handle ESC key press
 */
const handleEscape = (event: KeyboardEvent) => {
  if (event.key === 'Escape') {
    emit('close')
  }
}

onMounted(() => {
  // Add ESC key listener
  window.addEventListener('keydown', handleEscape)
})

onUnmounted(() => {
  // Remove ESC key listener
  window.removeEventListener('keydown', handleEscape)
})
</script>
