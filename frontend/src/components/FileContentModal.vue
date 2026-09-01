<template>
  <Teleport to="#app">
    <Transition name="modal">
      <div
        v-if="isOpen"
        class="modal-overlay fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm"
        data-testid="modal-file-content-root"
        @click.self="close"
      >
        <div
          class="modal-panel surface-card max-w-6xl w-full flex flex-col rounded-xl shadow-2xl overflow-hidden"
          data-testid="modal-file-content"
          @click.stop
        >
          <!-- Header -->
          <div
            class="flex items-center justify-between p-6 border-b border-light-border/10 dark:border-dark-border/10"
          >
            <div class="flex-1 min-w-0">
              <h2 class="text-2xl font-semibold txt-primary truncate">
                {{ fileData?.filename || $t('files.content.fallbackTitle') }}
              </h2>
              <div class="flex items-center gap-3 mt-2 text-sm txt-secondary">
                <span class="pill px-2 py-0.5">{{
                  fileData?.file_type?.toUpperCase() || 'N/A'
                }}</span>
                <span class="pill px-2 py-0.5">{{
                  fileData?.status ? $t(`files.status_${fileData.status}`) : ''
                }}</span>
                <span>{{ fileData?.uploaded_date }}</span>
              </div>
            </div>
            <button
              class="ml-4 p-2 rounded-lg hover:bg-black/5 dark:hover:bg-white/5 transition-colors txt-secondary hover:txt-primary"
              :aria-label="$t('files.content.close')"
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
                <p class="txt-secondary">{{ $t('files.content.loading') }}</p>
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

            <div v-else-if="fileData" class="space-y-6">
              <!-- Media preview: show the actual file, not its extracted text -->
              <div v-if="isImage" class="flex justify-center">
                <img
                  :src="imageSrc"
                  :alt="$t('files.content.imageAlt', { name: fileData.filename })"
                  class="max-h-[60vh] w-auto max-w-full rounded-lg object-contain surface-elevated"
                  data-testid="file-content-image"
                />
              </div>
              <div v-else-if="isAudio" class="surface-elevated rounded-lg p-4">
                <MessageAudio
                  :url="rawDownloadUrl"
                  class="!my-0 w-full"
                  data-testid="file-content-audio"
                />
              </div>
              <div v-else-if="isVideo" class="surface-elevated rounded-lg overflow-hidden">
                <MessageVideo
                  :url="rawDownloadUrl"
                  class="!my-0 w-full"
                  data-testid="file-content-video"
                />
              </div>

              <!-- Extracted text for real documents: primary content with stats -->
              <div v-if="hasText && !isMedia" class="space-y-4">
                <div class="grid grid-cols-3 gap-4 p-4 surface-elevated rounded-lg">
                  <div class="text-center">
                    <p class="text-2xl font-bold txt-primary">
                      {{ characterCount.toLocaleString() }}
                    </p>
                    <p class="text-sm txt-secondary">{{ $t('files.content.statsCharacters') }}</p>
                  </div>
                  <div class="text-center">
                    <p class="text-2xl font-bold txt-primary">
                      {{ wordCount.toLocaleString() }}
                    </p>
                    <p class="text-sm txt-secondary">{{ $t('files.content.statsWords') }}</p>
                  </div>
                  <div class="text-center">
                    <p class="text-2xl font-bold txt-primary">{{ lineCount.toLocaleString() }}</p>
                    <p class="text-sm txt-secondary">{{ $t('files.content.statsLines') }}</p>
                  </div>
                </div>

                <div class="surface-elevated rounded-lg p-6">
                  <p class="text-sm font-medium txt-secondary mb-3">
                    {{ $t('files.content.extractedText') }}
                  </p>
                  <pre class="whitespace-pre-wrap font-mono text-sm txt-primary leading-relaxed">{{
                    fileData.extracted_text
                  }}</pre>
                </div>
              </div>

              <!-- AI description for media: secondary, explained, collapsed by default -->
              <div
                v-else-if="hasText && isMedia"
                class="surface-elevated rounded-lg overflow-hidden"
              >
                <button
                  type="button"
                  class="w-full flex items-center justify-between gap-3 p-4 text-left hover:bg-black/5 dark:hover:bg-white/5 transition-colors"
                  :aria-expanded="descriptionOpen"
                  data-testid="btn-file-content-ai-desc"
                  @click="descriptionOpen = !descriptionOpen"
                >
                  <span class="flex items-center gap-3 min-w-0">
                    <Icon icon="mdi:auto-fix" class="w-5 h-5 text-[var(--brand)] shrink-0" />
                    <span class="min-w-0">
                      <span class="block text-sm font-medium txt-primary">
                        {{ $t('files.content.aiDescription.title') }}
                      </span>
                      <span class="block text-xs txt-secondary">
                        {{ $t('files.content.aiDescription.hint') }}
                      </span>
                    </span>
                  </span>
                  <Icon
                    :icon="descriptionOpen ? 'mdi:chevron-up' : 'mdi:chevron-down'"
                    class="w-5 h-5 txt-secondary shrink-0"
                  />
                </button>
                <div v-if="descriptionOpen" class="px-4 pb-4">
                  <pre
                    class="whitespace-pre-wrap font-mono text-xs txt-secondary leading-relaxed"
                    >{{ fileData.extracted_text }}</pre>
                </div>
              </div>

              <!-- No media rendered and no text extracted -->
              <div v-else-if="!isMedia" class="flex items-center justify-center py-20">
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
                  <p class="txt-secondary">{{ $t('files.content.noText') }}</p>
                </div>
              </div>
            </div>
          </div>

          <!-- Footer -->
          <div
            class="flex items-center justify-end gap-3 p-6 border-t border-light-border/10 dark:border-dark-border/10"
          >
            <button
              v-if="fileData"
              class="px-4 py-2 rounded-lg flex items-center gap-2 transition-colors border border-light-border/30 dark:border-dark-border/20 txt-primary hover:bg-black/5 dark:hover:bg-white/5"
              data-testid="btn-file-content-download"
              @click="download"
            >
              <Icon icon="mdi:download" class="w-5 h-5" />
              {{ $t('files.content.download') }}
            </button>
            <button
              :disabled="!hasText"
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
              {{ $t('files.content.copyText') }}
            </button>
            <button
              class="btn-primary px-6 py-2 rounded-lg"
              data-testid="btn-file-content-dismiss"
              @click="close"
            >
              {{ $t('files.content.close') }}
            </button>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup lang="ts">
import { getErrorMessage } from '@/utils/errorMessage'
import { ref, computed, watch, toRef } from 'vue'
import { useI18n } from 'vue-i18n'
import { Icon } from '@iconify/vue'
import MessageAudio from '@/components/MessageAudio.vue'
import MessageVideo from '@/components/MessageVideo.vue'
import { getFileContent, downloadFile } from '@/services/filesService'
import { extensionOf, kindFromExtension, type PreviewKind } from '@/services/filePreview'
import { getApiBaseUrl } from '@/services/api/httpClient'
import { useMediaSrc } from '@/services/api/mediaAuth'
import { useEscapeKey } from '@/composables/useEscapeKey'
import { useNotification } from '@/composables/useNotification'

interface Props {
  isOpen: boolean
  fileId: number | null
}

const props = defineProps<Props>()
const emit = defineEmits<{
  close: []
}>()

const { t } = useI18n()
const { success, error: showError } = useNotification()
// On web `mediaSrc` is a transparent no-op; on native it appends a read-only
// media token so a bare <img> (which cannot send auth headers) still loads.
const { mediaSrc } = useMediaSrc()

const loading = ref(false)
const error = ref<string | null>(null)
const descriptionOpen = ref(false)
const fileData = ref<{
  id: number
  filename: string
  file_path: string
  file_type: string
  file_size?: number
  mime?: string
  extracted_text: string
  status: string
  uploaded_at: number
  uploaded_date: string
} | null>(null)

// Resolve the preview kind from the filename first (most specific), then the
// coarse file_type, then the MIME type as a last resort.
const previewKind = computed<PreviewKind>(() => {
  if (!fileData.value) return 'unknown'
  const byExt = kindFromExtension(extensionOf(fileData.value.filename))
  if ('unknown' !== byExt) return byExt
  const byType = kindFromExtension((fileData.value.file_type || '').toLowerCase())
  if ('unknown' !== byType) return byType
  const mime = fileData.value.mime || ''
  if (mime.startsWith('image/')) return 'image'
  if (mime.startsWith('audio/')) return 'audio'
  if (mime.startsWith('video/')) return 'video'
  if ('application/pdf' === mime) return 'pdf'
  return 'unknown'
})

const isImage = computed(() => 'image' === previewKind.value)
const isAudio = computed(() => 'audio' === previewKind.value)
const isVideo = computed(() => 'video' === previewKind.value)
const isMedia = computed(() => isImage.value || isAudio.value || isVideo.value)
const hasText = computed(() => Boolean(fileData.value?.extracted_text))

const rawDownloadUrl = computed(() =>
  fileData.value ? `${getApiBaseUrl()}/api/v1/files/${fileData.value.id}/download` : ''
)
const imageSrc = computed(() => mediaSrc(rawDownloadUrl.value))

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
    // Media files lead with the media itself, so the AI description starts
    // collapsed; text documents show their text expanded.
    descriptionOpen.value = !isMedia.value
  } catch (err: unknown) {
    error.value = getErrorMessage(err) || t('files.content.loadError')
    showError(t('files.content.loadError'))
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
    descriptionOpen.value = false
  }, 300)
}

useEscapeKey(close, toRef(props, 'isOpen'))

const copyToClipboard = async () => {
  if (!fileData.value?.extracted_text) return

  try {
    await navigator.clipboard.writeText(fileData.value.extracted_text)
    success(t('files.content.copied'))
  } catch {
    showError(t('files.content.copyFailed'))
  }
}

const download = async () => {
  if (!fileData.value) return
  await downloadFile(fileData.value.id, fileData.value.filename)
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
