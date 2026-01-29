<template>
  <Teleport to="body">
    <div
      class="fixed inset-0 z-50 bg-black/50 backdrop-blur-sm flex items-center justify-center p-4"
      data-testid="modal-widget-success"
    >
      <div
        class="surface-card rounded-2xl w-full max-w-2xl overflow-hidden shadow-2xl"
        data-testid="section-success-container"
      >
        <!-- Header -->
        <div
          class="px-6 py-5 border-b border-light-border/30 dark:border-dark-border/20 bg-gradient-to-r from-[var(--brand-alpha-light)] to-transparent"
        >
          <div class="flex items-center gap-3">
            <div class="w-12 h-12 rounded-full bg-green-500/20 flex items-center justify-center">
              <Icon icon="heroicons:check-circle" class="w-7 h-7 text-green-500" />
            </div>
            <div>
              <h2 class="text-xl font-semibold txt-primary">
                {{ $t('widgets.success.title') }}
              </h2>
              <p class="text-sm txt-secondary mt-0.5">
                {{ widget.name }}
              </p>
            </div>
          </div>
        </div>

        <!-- Content -->
        <div class="p-6 space-y-6">
          <!-- Status Badge -->
          <div class="flex items-center gap-2">
            <span
              class="px-3 py-1 rounded-full bg-green-500/10 text-green-600 dark:text-green-400 text-sm font-medium flex items-center gap-1.5"
            >
              <Icon icon="heroicons:check" class="w-4 h-4" />
              {{ $t('widgets.success.statusActive') }}
            </span>
            <span class="txt-secondary text-sm">
              {{ $t('widgets.success.readyToUse') }}
            </span>
          </div>

          <!-- Embed Code Section -->
          <div class="space-y-3">
            <label class="block text-sm font-medium txt-primary">
              {{ $t('widgets.success.embedCodeLabel') }}
            </label>
            <div class="relative">
              <pre
                class="surface-chip p-4 rounded-lg overflow-x-auto text-sm font-mono txt-primary border border-light-border/30 dark:border-dark-border/20"
              ><code>{{ embedCode }}</code></pre>
              <button
                class="absolute top-2 right-2 px-3 py-1.5 rounded-lg bg-[var(--brand)] text-white text-xs font-medium hover:bg-[var(--brand-hover)] transition-colors flex items-center gap-1.5"
                data-testid="btn-copy-code"
                @click="copyCode"
              >
                <Icon :icon="copied ? 'heroicons:check' : 'heroicons:clipboard'" class="w-4 h-4" />
                {{ copied ? $t('common.copied') : $t('common.copy') }}
              </button>
            </div>
          </div>

          <!-- Next Steps -->
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <!-- AI Setup -->
            <button
              class="p-4 rounded-xl border-2 border-[var(--brand)]/30 bg-[var(--brand-alpha-light)] hover:border-[var(--brand)] transition-all text-left group"
              data-testid="btn-ai-setup"
              @click="$emit('startAiSetup')"
            >
              <div class="flex items-start gap-3">
                <div
                  class="w-10 h-10 rounded-lg bg-[var(--brand)]/20 flex items-center justify-center group-hover:bg-[var(--brand)]/30 transition-colors"
                >
                  <Icon icon="heroicons:sparkles" class="w-5 h-5 txt-brand" />
                </div>
                <div class="flex-1 min-w-0">
                  <h3 class="font-semibold txt-primary group-hover:txt-brand transition-colors">
                    {{ $t('widgets.success.aiSetupTitle') }}
                  </h3>
                  <p class="text-xs txt-secondary mt-1 line-clamp-2">
                    {{ $t('widgets.success.aiSetupDescription') }}
                  </p>
                </div>
              </div>
            </button>

            <!-- Advanced Config -->
            <button
              class="p-4 rounded-xl border-2 border-light-border/30 dark:border-dark-border/20 hover:border-[var(--brand)]/50 transition-all text-left group"
              data-testid="btn-advanced-config"
              @click="$emit('openAdvanced')"
            >
              <div class="flex items-start gap-3">
                <div
                  class="w-10 h-10 rounded-lg surface-chip flex items-center justify-center group-hover:bg-[var(--brand-alpha-light)] transition-colors"
                >
                  <Icon
                    icon="heroicons:cog-6-tooth"
                    class="w-5 h-5 txt-secondary group-hover:txt-brand transition-colors"
                  />
                </div>
                <div class="flex-1 min-w-0">
                  <h3 class="font-semibold txt-primary group-hover:txt-brand transition-colors">
                    {{ $t('widgets.success.advancedTitle') }}
                  </h3>
                  <p class="text-xs txt-secondary mt-1 line-clamp-2">
                    {{ $t('widgets.success.advancedDescription') }}
                  </p>
                </div>
              </div>
            </button>
          </div>

          <!-- Test Widget Button -->
          <div class="pt-2">
            <button
              class="w-full py-3 rounded-lg border-2 border-dashed border-light-border/50 dark:border-dark-border/30 hover:border-green-500/50 hover:bg-green-500/5 transition-all flex items-center justify-center gap-2 txt-secondary hover:text-green-600 dark:hover:text-green-400"
              data-testid="btn-test-widget"
              @click="$emit('testWidget')"
            >
              <Icon icon="heroicons:play" class="w-5 h-5" />
              {{ $t('widgets.success.testNow') }}
            </button>
          </div>
        </div>

        <!-- Footer -->
        <div
          class="px-6 py-4 border-t border-light-border/30 dark:border-dark-border/20 flex justify-end"
        >
          <button
            class="px-5 py-2.5 rounded-lg hover-surface transition-colors txt-primary font-medium"
            data-testid="btn-close"
            @click="$emit('close')"
          >
            {{ $t('common.done') }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { Icon } from '@iconify/vue'
import * as widgetsApi from '@/services/api/widgetsApi'
import { useI18n } from 'vue-i18n'

// Disable attribute inheritance since we use Teleport as root
defineOptions({
  inheritAttrs: false,
})

const props = defineProps<{
  widget: widgetsApi.Widget
}>()

defineEmits<{
  close: []
  startAiSetup: []
  openAdvanced: []
  testWidget: []
}>()

const { t } = useI18n()

const embedCode = ref('')
const copied = ref(false)

const loadEmbedCode = async () => {
  try {
    const data = await widgetsApi.getEmbedCode(props.widget.widgetId)
    embedCode.value = data.embedCode
  } catch (err) {
    console.error('Failed to load embed code:', err)
    embedCode.value = t('widgets.success.embedCodeError')
  }
}

const copyCode = async () => {
  try {
    await navigator.clipboard.writeText(embedCode.value)
    copied.value = true
    setTimeout(() => {
      copied.value = false
    }, 2000)
  } catch (err) {
    console.error('Failed to copy:', err)
  }
}

onMounted(() => {
  loadEmbedCode()
})
</script>
