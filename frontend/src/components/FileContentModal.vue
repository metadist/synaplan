<template>
  <Teleport to="body">
    <Transition name="modal">
      <div
        v-if="isOpen"
        class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm"
        data-testid="modal-file-content-root"
        @click.self="close"
      >
        <div
          class="surface-card max-w-6xl w-full max-h-[90vh] flex flex-col rounded-xl shadow-2xl overflow-hidden"
          data-testid="modal-file-content"
          @click.stop
        >
          <!-- Header -->
          <div
            class="flex items-center justify-between p-6 border-b border-light-border/10 dark:border-dark-border/10"
          >
            <div class="flex-1 min-w-0">
              <h2 class="text-2xl font-semibold txt-primary truncate">
                {{ fileData?.filename || 'File Content' }}
              </h2>
              <div class="flex items-center gap-3 mt-2 text-sm txt-secondary">
                <span class="pill px-2 py-0.5">{{
                  fileData?.file_type?.toUpperCase() || 'N/A'
                }}</span>
                <span class="pill px-2 py-0.5">{{ fileData?.status }}</span>
                <span>{{ fileData?.uploaded_date }}</span>
              </div>
            </div>
            <button
              class="ml-4 p-2 rounded-lg hover:bg-black/5 dark:hover:bg-white/5 transition-colors txt-secondary hover:txt-primary"
              aria-label="Close"
              data-testid="btn-file-content-close"
              @click="close"
            >
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M6 18L18 6M6 6l12 12"
                />
              </svg>
            </button>
          </div>

          <!-- Content -->
          <div class="flex-1 overflow-y-auto p-6 scroll-thin">
            <div v-if="loading" class="flex items-center justify-center py-20">
              <div class="flex flex-col items-center gap-4">
                <div
                  class="animate-spin h-12 w-12 border-4 border-[var(--brand)] border-t-transparent rounded-full"
                ></div>
                <p class="txt-secondary">Loading content...</p>
              </div>
            </div>

            <div v-else-if="error" class="flex items-center justify-center py-20">
              <div class="flex flex-col items-center gap-4 text-center">
                <svg
                  class="w-16 h-16 text-red-500"
                  fill="none"
                  stroke="currentColor"
                  viewBox="0 0 24 24"
                >
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
                  />
                </svg>
                <p class="text-red-500 font-medium">{{ error }}</p>
              </div>
            </div>

            <div v-else-if="fileData?.extracted_text" class="space-y-4">
              <!-- Statistics -->
              <div class="grid grid-cols-3 gap-4 p-4 surface-elevated rounded-lg">
                <div class="text-center">
                  <p class="text-2xl font-bold txt-primary">
                    {{ characterCount.toLocaleString() }}
                  </p>
                  <p class="text-sm txt-secondary">Characters</p>
                </div>
                <div class="text-center">
                  <p class="text-2xl font-bold txt-primary">{{ wordCount.toLocaleString() }}</p>
                  <p class="text-sm txt-secondary">Words</p>
                </div>
                <div class="text-center">
                  <p class="text-2xl font-bold txt-primary">{{ lineCount.toLocaleString() }}</p>
                  <p class="text-sm txt-secondary">Lines</p>
                </div>
              </div>

              <!-- Text Content -->
              <div class="surface-elevated rounded-lg p-6">
                <pre class="whitespace-pre-wrap font-mono text-sm txt-primary leading-relaxed">{{
                  fileData.extracted_text
                }}</pre>
              </div>
            </div>

            <div v-else class="flex items-center justify-center py-20">
              <div class="flex flex-col items-center gap-4 text-center">
                <svg
                  class="w-16 h-16 txt-secondary"
                  fill="none"
                  stroke="currentColor"
                  viewBox="0 0 24 24"
                >
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
                  />
                </svg>
                <p class="txt-secondary">No extracted text available</p>
              </div>
            </div>
          </div>

          <!-- Footer -->
          <div
            class="flex items-center justify-end gap-3 p-6 border-t border-light-border/10 dark:border-dark-border/10"
          >
            <button
              :disabled="!fileData?.extracted_text"
              class="px-4 py-2 rounded-lg flex items-center gap-2 transition-colors disabled:opacity-50 disabled:cursor-not-allowed border border-light-border/30 dark:border-dark-border/20 txt-primary hover:bg-black/5 dark:hover:bg-white/5"
              data-testid="btn-file-content-copy"
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
              Copy Text
            </button>
            <button
              class="btn-primary px-6 py-2 rounded-lg"
              data-testid="btn-file-content-dismiss"
              @click="close"
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
import { ref, computed, watch } from 'vue'
import { getFileContent } from '@/services/filesService'
import { useNotification } from '@/composables/useNotification'

interface Props {
  isOpen: boolean
  fileId: number | null
}

const props = defineProps<Props>()
const emit = defineEmits<{
  close: []
}>()

const { success, error: showError } = useNotification()

const loading = ref(false)
const error = ref<string | null>(null)
const fileData = ref<{
  id: number
  filename: string
  file_path: string
  file_type: string
  extracted_text: string
  status: string
  uploaded_at: number
  uploaded_date: string
} | null>(null)

// Computed statistics
const characterCount = computed(() => fileData.value?.extracted_text?.length || 0)
const wordCount = computed(() => {
  if (!fileData.value?.extracted_text) return 0
  return fileData.value.extracted_text
    .trim()
    .split(/\s+/)
    .filter((w) => w.length > 0).length
})
const lineCount = computed(() => {
  if (!fileData.value?.extracted_text) return 0
  return fileData.value.extracted_text.split('\n').length
})

// Load content when modal opens
watch(
  () => [props.isOpen, props.fileId],
  async ([isOpen, fileId]) => {
    if (isOpen && fileId) {
      await loadContent(fileId as number)
    }
  },
  { immediate: true }
)

const loadContent = async (fileId: number) => {
  loading.value = true
  error.value = null
  fileData.value = null

  try {
    fileData.value = await getFileContent(fileId)
  } catch (err: any) {
    error.value = err.message || 'Failed to load file content'
    showError('Failed to load file content')
  } finally {
    loading.value = false
  }
}

const close = () => {
  emit('close')
  // Reset state after animation
  setTimeout(() => {
    fileData.value = null
    error.value = null
  }, 300)
}

const copyToClipboard = async () => {
  if (!fileData.value?.extracted_text) return

  try {
    await navigator.clipboard.writeText(fileData.value.extracted_text)
    success('Text copied to clipboard')
  } catch (err) {
    showError('Failed to copy text')
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
  transform: scale(0.9);
}
</style>
