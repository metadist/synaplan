<template>
  <!-- Fullscreen Modal Overlay -->
  <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm" data-testid="modal-embed-code">
    <div class="surface-card rounded-2xl max-w-4xl w-full max-h-[90vh] flex flex-col shadow-2xl" data-testid="section-embed-shell">
      <!-- Header -->
      <div class="flex-shrink-0 surface-card border-b border-light-border/30 dark:border-dark-border/20 px-6 py-4 flex items-center justify-between z-10" data-testid="section-header">
        <div data-testid="section-preview">
          <h2 class="text-xl font-semibold txt-primary flex items-center gap-2">
            <Icon icon="heroicons:code-bracket" class="w-6 h-6 text-[var(--brand)]" />
            {{ $t('widgets.embedCode') }}
          </h2>
          <p class="text-sm txt-secondary mt-1">{{ widget.name }}</p>
        </div>
        <button
          @click="$emit('close')"
          class="w-10 h-10 rounded-lg hover:bg-black/5 dark:hover:bg-white/5 transition-colors flex items-center justify-center"
          :aria-label="$t('common.close')"
          data-testid="btn-close"
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
              @click="copyToClipboard(embedCode, 'HTML')"
              class="px-4 py-2 rounded-lg bg-[var(--brand)]/10 text-[var(--brand)] hover:bg-[var(--brand)]/20 transition-colors text-sm font-medium flex items-center gap-2"
              data-testid="btn-copy-html"
            >
              <Icon :icon="copiedHTML ? 'heroicons:check' : 'heroicons:clipboard-document'" class="w-4 h-4" />
              {{ copiedHTML ? $t('common.copied') : $t('common.copy') }}
            </button>
          </div>
          <div class="relative">
            <pre class="surface-chip rounded-lg p-4 text-sm overflow-x-auto txt-primary font-mono border border-light-border/30 dark:border-dark-border/20"><code>{{ embedCode }}</code></pre>
          </div>
          <p class="text-xs txt-secondary mt-2">
            {{ $t('widgets.htmlCodeDescription') }}
          </p>
        </div>

        <!-- WordPress Shortcode -->
        <div data-testid="section-wordpress-code">
          <div class="flex items-center justify-between mb-3">
            <h3 class="font-semibold txt-primary flex items-center gap-2">
              <Icon icon="simple-icons:wordpress" class="w-5 h-5" />
              {{ $t('widgets.wordPressShortcode') }}
            </h3>
            <button
              @click="copyToClipboard(wordpressShortcode, 'WordPress')"
              class="px-4 py-2 rounded-lg bg-[var(--brand)]/10 text-[var(--brand)] hover:bg-[var(--brand)]/20 transition-colors text-sm font-medium flex items-center gap-2"
              data-testid="btn-copy-wp"
            >
              <Icon :icon="copiedWP ? 'heroicons:check' : 'heroicons:clipboard-document'" class="w-4 h-4" />
              {{ copiedWP ? $t('common.copied') : $t('common.copy') }}
            </button>
          </div>
          <div class="relative">
            <pre class="surface-chip rounded-lg p-4 text-sm overflow-x-auto txt-primary font-mono border border-light-border/30 dark:border-dark-border/20"><code>{{ wordpressShortcode }}</code></pre>
          </div>
          <p class="text-xs txt-secondary mt-2">
            {{ $t('widgets.wordPressDescription') }}
          </p>
        </div>

        <!-- Widget Preview -->
        <div>
          <h3 class="font-semibold txt-primary mb-3 flex items-center gap-2">
            <Icon icon="heroicons:eye" class="w-5 h-5" />
            {{ $t('widgets.preview') }}
          </h3>
          <div class="surface-chip rounded-lg border-2 border-dashed border-light-border/30 dark:border-dark-border/20 relative h-[600px] overflow-hidden">
            <!-- Background hint -->
            <div class="absolute inset-0 flex items-center justify-center txt-secondary text-sm pointer-events-none z-0">
              <div class="text-center">
                <Icon icon="heroicons:chat-bubble-left-right" class="w-12 h-12 mx-auto mb-2 opacity-20" />
                <p class="opacity-50">{{ $t('widgets.previewDescription') }}</p>
              </div>
            </div>
            
            <!-- Live Preview Widget (positioned absolute within this container) -->
            <div class="absolute inset-0 z-10">
              <ChatWidget
                :widget-id="widget.widgetId"
                :api-url="config.apiBaseUrl"
                :primary-color="widget.config.primaryColor || '#007bff'"
                :icon-color="widget.config.iconColor || '#ffffff'"
                :position="widget.config.position || 'bottom-right'"
                :auto-open="false"
                :auto-message="widget.config.autoMessage || 'Hello! How can I help you?'"
                :message-limit="widget.config.messageLimit || 50"
                :max-file-size="widget.config.maxFileSize || 10"
                :default-theme="widget.config.defaultTheme || 'light'"
                :is-preview="true"
              />
            </div>
          </div>
        </div>

        <!-- Installation Instructions -->
        <div class="surface-chip rounded-lg p-4 border border-blue-500/20 bg-blue-500/5">
          <div class="flex items-start gap-3">
            <Icon icon="heroicons:information-circle" class="w-5 h-5 text-blue-500 flex-shrink-0 mt-0.5" />
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
      <div class="flex-shrink-0 surface-card border-t border-light-border/30 dark:border-dark-border/20 px-6 py-4 flex items-center justify-end gap-3 z-10">
        <button
          @click="$emit('close')"
          class="px-6 py-2.5 rounded-lg hover:bg-black/5 dark:hover:bg-white/5 transition-colors txt-primary font-medium"
          data-testid="btn-close-footer"
        >
          {{ $t('common.close') }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { Icon } from '@iconify/vue'
import { useConfigStore } from '@/stores/config'
import ChatWidget from '@/components/widgets/ChatWidget.vue'
import type { Widget } from '@/services/api/widgetsApi'
import { useNotification } from '@/composables/useNotification'

interface Props {
  widget: Widget
  embedCode: string
  wordpressShortcode: string
}

defineProps<Props>()
defineEmits<{
  close: []
}>()

const config = useConfigStore()
const { success } = useNotification()

const copiedHTML = ref(false)
const copiedWP = ref(false)

/**
 * Copy to clipboard
 */
const copyToClipboard = async (text: string, type: string) => {
  try {
    await navigator.clipboard.writeText(text)
    
    if (type === 'HTML') {
      copiedHTML.value = true
      setTimeout(() => { copiedHTML.value = false }, 2000)
    } else {
      copiedWP.value = true
      setTimeout(() => { copiedWP.value = false }, 2000)
    }
    
    success(`${type} code copied to clipboard!`)
  } catch (error) {
    console.error('Failed to copy:', error)
  }
}
</script>
