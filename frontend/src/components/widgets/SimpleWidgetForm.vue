<template>
  <Teleport to="body">
    <div
      class="fixed inset-0 z-50 bg-black/50 backdrop-blur-sm flex items-center justify-center p-4"
      data-testid="modal-simple-widget-form"
      @click.self="handleClose"
    >
      <div
        class="surface-card rounded-2xl w-full max-w-lg lg:max-w-6xl max-h-[90vh] overflow-hidden shadow-2xl flex flex-col"
        data-testid="section-form-container"
      >
        <!-- Header -->
        <div
          class="px-6 py-4 border-b border-light-border/30 dark:border-dark-border/20 flex items-center justify-between"
        >
          <div>
            <h2 class="text-xl font-semibold txt-primary flex items-center gap-2">
              <Icon icon="heroicons:sparkles" class="w-6 h-6 txt-brand" />
              {{ $t('widgets.simpleSetup.title') }}
            </h2>
            <p class="text-sm txt-secondary mt-1">
              {{ $t('widgets.simpleSetup.subtitle') }}
            </p>
          </div>
          <button
            class="w-10 h-10 rounded-lg hover-surface transition-colors flex items-center justify-center"
            :aria-label="$t('common.close')"
            data-testid="btn-close"
            @click="handleClose"
          >
            <Icon icon="heroicons:x-mark" class="w-6 h-6 txt-secondary" />
          </button>
        </div>

        <!-- Form Content -->
        <form class="p-6 overflow-y-auto flex-1 min-h-0" @submit.prevent="handleSubmit">
          <div class="flex flex-col lg:flex-row gap-8">
            <!-- Left: Form Fields -->
            <div class="w-full lg:w-2/5 space-y-5">
              <!-- Widget Name -->
              <div>
                <label class="block text-sm font-medium txt-primary mb-2">
                  {{ $t('widgets.simpleSetup.nameLabel') }} *
                </label>
                <input
                  v-model="formData.name"
                  type="text"
                  :placeholder="$t('widgets.simpleSetup.namePlaceholder')"
                  class="w-full px-4 py-3 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)] transition-all"
                  data-testid="input-widget-name"
                  required
                />
              </div>

              <!-- Website URL -->
              <div>
                <label class="block text-sm font-medium txt-primary mb-2">
                  {{ $t('widgets.simpleSetup.websiteLabel') }} *
                </label>
                <div class="relative">
                  <Icon
                    icon="heroicons:globe-alt"
                    class="absolute left-4 top-1/2 -translate-y-1/2 w-5 h-5 txt-secondary"
                  />
                  <input
                    v-model="formData.websiteUrl"
                    type="text"
                    :placeholder="$t('widgets.simpleSetup.websitePlaceholder')"
                    class="w-full pl-12 pr-4 py-3 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)] transition-all"
                    data-testid="input-website-url"
                    required
                  />
                </div>
                <p class="text-xs txt-secondary mt-1.5 flex items-start gap-1">
                  <Icon icon="heroicons:information-circle" class="w-4 h-4 flex-shrink-0 mt-0.5" />
                  <span>{{ $t('widgets.simpleSetup.websiteHelp') }}</span>
                </p>
              </div>

              <!-- Info Box -->
              <div
                class="p-4 rounded-lg bg-[var(--brand-alpha-light)] border border-[var(--brand)]/20"
              >
                <div class="flex items-start gap-3">
                  <Icon
                    icon="heroicons:light-bulb"
                    class="w-5 h-5 txt-brand flex-shrink-0 mt-0.5"
                  />
                  <div class="text-sm">
                    <p class="txt-primary font-medium mb-1">
                      {{ $t('widgets.simpleSetup.infoTitle') }}
                    </p>
                    <p class="txt-secondary">
                      {{ $t('widgets.simpleSetup.infoDescription') }}
                    </p>
                  </div>
                </div>
              </div>

              <!-- Error Message -->
              <div
                v-if="errorMessage"
                class="p-4 rounded-lg bg-red-500/10 border border-red-500/20 text-red-600 dark:text-red-400 text-sm flex items-start gap-2"
              >
                <Icon icon="heroicons:exclamation-circle" class="w-5 h-5 flex-shrink-0 mt-0.5" />
                <span>{{ errorMessage }}</span>
              </div>
            </div>

            <!-- Right: Website Preview (hidden on mobile) -->
            <div class="hidden lg:block lg:w-3/5 space-y-2">
              <label class="block text-sm font-medium txt-primary flex items-center gap-2">
                <Icon icon="heroicons:eye" class="w-4 h-4 txt-brand" />
                {{ $t('widgets.simpleSetup.previewLabel') }}
              </label>
              <div
                class="relative rounded-lg overflow-hidden border border-light-border/30 dark:border-dark-border/20 shadow-lg"
              >
                <!-- Mock Browser Chrome -->
                <div
                  class="flex items-center gap-2 px-3 py-2 bg-gray-100 dark:bg-gray-800 border-b border-light-border/30 dark:border-dark-border/20"
                >
                  <div class="flex gap-1.5">
                    <div class="w-2.5 h-2.5 rounded-full bg-red-400"></div>
                    <div class="w-2.5 h-2.5 rounded-full bg-yellow-400"></div>
                    <div class="w-2.5 h-2.5 rounded-full bg-green-400"></div>
                  </div>
                  <div
                    class="flex-1 px-3 py-1 rounded bg-white dark:bg-gray-700 text-xs txt-secondary truncate"
                  >
                    {{ displayUrl }}
                  </div>
                </div>

                <!-- Preview Container -->
                <div class="relative h-[450px] bg-white dark:bg-gray-900 overflow-hidden">
                  <!-- Loading Indicator -->
                  <div
                    v-if="iframeLoading && debouncedUrl"
                    class="absolute inset-0 flex items-center justify-center bg-white dark:bg-gray-900 z-20"
                  >
                    <div class="flex flex-col items-center gap-3">
                      <Icon icon="heroicons:arrow-path" class="w-8 h-8 txt-brand animate-spin" />
                      <p class="text-sm txt-secondary">{{ $t('common.loading') }}...</p>
                    </div>
                  </div>

                  <!-- Actual Website in iframe (when valid URL entered and no error) -->
                  <iframe
                    v-if="showIframe"
                    :key="normalizedUrl"
                    :src="normalizedUrl"
                    class="w-full h-full border-0"
                    sandbox="allow-scripts allow-same-origin allow-forms"
                    referrerpolicy="no-referrer"
                    @load="handleIframeLoad"
                    @error="handleIframeError"
                  ></iframe>

                  <!-- Mock Website Content (when no URL or error) -->
                  <div v-if="!showIframe && !iframeLoading" class="absolute inset-0 p-6">
                    <!-- Mock Header -->
                    <div class="flex items-center justify-between mb-6">
                      <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-lg bg-gray-200 dark:bg-gray-700"></div>
                        <div class="w-28 h-5 rounded bg-gray-200 dark:bg-gray-700"></div>
                      </div>
                      <div class="flex gap-4">
                        <div class="w-20 h-4 rounded bg-gray-200 dark:bg-gray-700"></div>
                        <div class="w-20 h-4 rounded bg-gray-200 dark:bg-gray-700"></div>
                        <div class="w-20 h-4 rounded bg-gray-200 dark:bg-gray-700"></div>
                      </div>
                    </div>
                    <!-- Mock Hero Section -->
                    <div class="mb-6">
                      <div class="w-3/4 h-8 rounded bg-gray-200 dark:bg-gray-700 mb-3"></div>
                      <div class="w-1/2 h-5 rounded bg-gray-100 dark:bg-gray-800"></div>
                    </div>
                    <!-- Mock Content -->
                    <div class="grid grid-cols-3 gap-4 mb-6">
                      <div class="h-24 rounded-lg bg-gray-100 dark:bg-gray-800"></div>
                      <div class="h-24 rounded-lg bg-gray-100 dark:bg-gray-800"></div>
                      <div class="h-24 rounded-lg bg-gray-100 dark:bg-gray-800"></div>
                    </div>
                    <div class="space-y-3">
                      <div class="w-full h-4 rounded bg-gray-100 dark:bg-gray-800"></div>
                      <div class="w-5/6 h-4 rounded bg-gray-100 dark:bg-gray-800"></div>
                      <div class="w-4/6 h-4 rounded bg-gray-100 dark:bg-gray-800"></div>
                    </div>
                    <div class="mt-6 grid grid-cols-2 gap-4">
                      <div class="h-20 rounded-lg bg-gray-100 dark:bg-gray-800"></div>
                      <div class="h-20 rounded-lg bg-gray-100 dark:bg-gray-800"></div>
                    </div>
                  </div>

                  <!-- Chat Widget -->
                  <div class="absolute bottom-4 right-4 z-10">
                    <!-- Chat Window (when open) -->
                    <transition name="chat-slide">
                      <div
                        v-if="chatOpen"
                        class="absolute bottom-16 right-0 w-80 rounded-xl shadow-2xl overflow-hidden"
                        style="background-color: #ffffff"
                      >
                        <!-- Chat Header -->
                        <div
                          class="px-4 py-3 flex items-center justify-between"
                          style="background-color: #007bff"
                        >
                          <div class="flex items-center gap-2">
                            <Icon
                              icon="heroicons:chat-bubble-left-right"
                              class="w-5 h-5 text-white"
                            />
                            <span class="text-white font-medium text-sm">Chat Assistant</span>
                          </div>
                          <button
                            type="button"
                            class="text-white/80 hover:text-white transition-colors"
                            @click.stop="chatOpen = false"
                          >
                            <Icon icon="heroicons:x-mark" class="w-5 h-5" />
                          </button>
                        </div>
                        <!-- Chat Messages -->
                        <div class="p-4 h-48 overflow-y-auto bg-gray-50">
                          <div class="flex gap-3 mb-3">
                            <div
                              class="w-8 h-8 rounded-full bg-gray-300 flex-shrink-0 flex items-center justify-center"
                            >
                              <Icon icon="heroicons:user" class="w-4 h-4 text-gray-500" />
                            </div>
                            <div
                              class="bg-white rounded-lg px-4 py-2.5 text-sm text-gray-700 shadow-sm max-w-[85%]"
                            >
                              {{ $t('widgets.simpleSetup.previewChatGreeting') }}
                            </div>
                          </div>
                        </div>
                        <!-- Chat Input -->
                        <div class="p-3 border-t border-gray-200 bg-white">
                          <div class="flex gap-2">
                            <input
                              type="text"
                              :placeholder="$t('widgets.simpleSetup.previewChatPlaceholder')"
                              class="flex-1 px-4 py-2.5 text-sm rounded-lg border border-gray-200 bg-gray-50"
                              disabled
                            />
                            <button
                              type="button"
                              class="w-10 h-10 rounded-lg flex items-center justify-center"
                              style="background-color: #007bff"
                              disabled
                            >
                              <Icon icon="heroicons:paper-airplane" class="w-5 h-5 text-white" />
                            </button>
                          </div>
                        </div>
                      </div>
                    </transition>

                    <!-- Chat Button -->
                    <button
                      type="button"
                      class="w-14 h-14 rounded-full shadow-2xl flex items-center justify-center cursor-pointer hover:scale-110 transition-transform"
                      style="background-color: #007bff"
                      @click.stop="chatOpen = !chatOpen"
                    >
                      <Icon
                        :icon="chatOpen ? 'heroicons:x-mark' : 'heroicons:chat-bubble-left-right'"
                        class="w-7 h-7 text-white"
                      />
                    </button>
                  </div>
                </div>
              </div>
              <p class="text-xs txt-secondary flex items-center gap-1">
                <Icon icon="heroicons:cursor-arrow-rays" class="w-3.5 h-3.5" />
                {{ $t('widgets.simpleSetup.previewClickHint') }}
              </p>
            </div>
          </div>

          <!-- Actions -->
          <div
            class="flex items-center justify-end gap-3 pt-5 mt-5 border-t border-light-border/30 dark:border-dark-border/20"
          >
            <button
              type="button"
              class="px-5 py-2.5 rounded-lg hover-surface transition-colors txt-secondary font-medium"
              data-testid="btn-cancel"
              @click="handleClose"
            >
              {{ $t('common.cancel') }}
            </button>
            <button
              type="submit"
              :disabled="!isValid || creating"
              class="btn-primary px-6 py-2.5 rounded-lg transition-colors font-medium disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-2"
              data-testid="btn-create"
            >
              <Icon v-if="creating" icon="heroicons:arrow-path" class="w-5 h-5 animate-spin" />
              <Icon v-else icon="heroicons:rocket-launch" class="w-5 h-5" />
              {{ creating ? $t('common.creating') : $t('widgets.simpleSetup.createButton') }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </Teleport>
</template>

<script setup lang="ts">
import { ref, computed, watch, onUnmounted } from 'vue'
import { Icon } from '@iconify/vue'
import * as widgetsApi from '@/services/api/widgetsApi'
import { useNotification } from '@/composables/useNotification'
import { useI18n } from 'vue-i18n'

// Disable attribute inheritance since we use Teleport as root
defineOptions({
  inheritAttrs: false,
})

const emit = defineEmits<{
  close: []
  created: [widget: widgetsApi.Widget]
}>()

const { error: showError } = useNotification()
const { t } = useI18n()

const formData = ref({
  name: '',
  websiteUrl: '',
})

const creating = ref(false)
const errorMessage = ref<string | null>(null)
const chatOpen = ref(false)

// Debounced URL for iframe (300ms delay)
const debouncedUrl = ref('')
const iframeLoading = ref(false)
const iframeError = ref(false)
const iframeLoadedSuccessfully = ref(false)
let debounceTimer: ReturnType<typeof setTimeout> | null = null
let loadTimeoutTimer: ReturnType<typeof setTimeout> | null = null

// Watch for URL changes and debounce
watch(
  () => formData.value.websiteUrl,
  (newUrl) => {
    // Reset states when URL changes
    iframeError.value = false
    iframeLoadedSuccessfully.value = false
    iframeLoading.value = true

    // Clear previous timers
    if (debounceTimer) {
      clearTimeout(debounceTimer)
    }
    if (loadTimeoutTimer) {
      clearTimeout(loadTimeoutTimer)
    }

    // Set new debounce timer
    debounceTimer = setTimeout(() => {
      debouncedUrl.value = newUrl.trim()
      // If URL is empty, stop loading
      if (!newUrl.trim()) {
        iframeLoading.value = false
      } else {
        // Set a timeout - if iframe doesn't load in 8 seconds, show mock
        loadTimeoutTimer = setTimeout(() => {
          if (iframeLoading.value && !iframeLoadedSuccessfully.value) {
            iframeError.value = true
            iframeLoading.value = false
          }
        }, 8000)
      }
    }, 300)
  }
)

// Cleanup timers on unmount
onUnmounted(() => {
  if (debounceTimer) {
    clearTimeout(debounceTimer)
  }
  if (loadTimeoutTimer) {
    clearTimeout(loadTimeoutTimer)
  }
})

// Normalize URL (add https:// if missing)
const normalizedUrl = computed(() => {
  const url = debouncedUrl.value
  if (!url) return ''
  if (url.startsWith('http://') || url.startsWith('https://')) {
    return url
  }
  return `https://${url}`
})

// Check if we have a valid URL for the iframe
const hasValidUrl = computed(() => {
  if (!debouncedUrl.value) return false
  if (iframeError.value) return false
  try {
    new URL(normalizedUrl.value)
    return true
  } catch {
    return false
  }
})

// Show iframe when we have valid URL and no error
const showIframe = computed(() => hasValidUrl.value && !iframeError.value)

// Display URL in browser bar (show current input, not debounced)
const displayUrl = computed(() => {
  if (!formData.value.websiteUrl.trim()) return 'https://your-website.com'
  const url = formData.value.websiteUrl.trim()
  if (url.startsWith('http://') || url.startsWith('https://')) {
    return url
  }
  return `https://${url}`
})

const isValid = computed(() => {
  return formData.value.name.trim().length >= 2 && formData.value.websiteUrl.trim().length > 0
})

// Handle iframe load success
const handleIframeLoad = () => {
  iframeLoading.value = false
  iframeLoadedSuccessfully.value = true
  // Clear timeout timer since load was successful
  if (loadTimeoutTimer) {
    clearTimeout(loadTimeoutTimer)
  }
}

// Handle iframe error
const handleIframeError = () => {
  iframeLoading.value = false
  iframeError.value = true
  iframeLoadedSuccessfully.value = false
  // Clear timeout timer
  if (loadTimeoutTimer) {
    clearTimeout(loadTimeoutTimer)
  }
}

const handleClose = () => {
  emit('close')
}

const handleSubmit = async () => {
  if (!isValid.value || creating.value) return

  creating.value = true
  errorMessage.value = null

  try {
    const widget = await widgetsApi.quickCreateWidget({
      name: formData.value.name.trim(),
      websiteUrl: formData.value.websiteUrl.trim(),
    })

    emit('created', widget)
  } catch (err: unknown) {
    const message = err instanceof Error ? err.message : t('widgets.simpleSetup.createError')
    console.error('Failed to create widget:', err)
    errorMessage.value = message
    showError(message)
  } finally {
    creating.value = false
  }
}
</script>

<style scoped>
.chat-slide-enter-active,
.chat-slide-leave-active {
  transition: all 0.2s ease-out;
}

.chat-slide-enter-from,
.chat-slide-leave-to {
  opacity: 0;
  transform: translateY(10px) scale(0.95);
}
</style>
