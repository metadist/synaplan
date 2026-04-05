<template>
  <Teleport to="#app">
    <Transition name="modal">
      <div
        v-if="visible"
        class="fixed inset-0 z-50 flex items-end sm:items-center justify-center sm:p-4 bg-black/50"
        data-testid="modal-file-selection-root"
        @click.self="emit('close')"
      >
        <div
          class="w-full sm:max-w-4xl max-h-[95dvh] sm:max-h-[80vh] overflow-hidden flex flex-col rounded-t-2xl sm:rounded-xl shadow-2xl bg-white dark:bg-[#0f1729] border border-light-border/20 dark:border-dark-border/20"
          data-testid="modal-file-selection"
        >
          <!-- Header -->
          <div
            class="flex items-center justify-between px-4 py-3 sm:p-6 border-b border-light-border/20 dark:border-dark-border/15"
          >
            <h2 class="text-base sm:text-xl font-semibold txt-primary">
              {{ $t('fileSelection.title') }}
            </h2>
            <button
              class="icon-ghost p-2"
              :aria-label="$t('common.close')"
              data-testid="btn-file-selection-close"
              @click="emit('close')"
            >
              <XMarkIcon class="w-5 h-5" />
            </button>
          </div>

          <!-- Upload Area -->
          <div
            class="px-3 py-2.5 sm:p-4 border-b border-light-border/20 dark:border-dark-border/15 transition-colors"
            :class="isDragging ? 'bg-brand-alpha-light' : 'bg-gray-50 dark:bg-white/[0.03]'"
            data-testid="section-file-dropzone"
            @dragover.prevent="handleDragOver"
            @dragleave.prevent="handleDragLeave"
            @drop.prevent="handleDrop"
          >
            <div class="flex items-center gap-2 sm:gap-3 flex-wrap">
              <button
                :disabled="isUploading"
                class="btn-primary px-3 py-1.5 sm:px-4 sm:py-2 rounded-lg flex items-center gap-1.5 sm:gap-2 text-sm sm:text-base disabled:opacity-50 disabled:cursor-not-allowed"
                data-testid="btn-file-selection-upload"
                @click="triggerFileUpload"
              >
                <Icon
                  v-if="isUploading || isBackgroundProcessing"
                  icon="mdi:loading"
                  class="w-4 h-4 sm:w-5 sm:h-5 animate-spin"
                />
                <Icon v-else icon="mdi:cloud-upload" class="w-4 h-4 sm:w-5 sm:h-5" />
                <span class="hidden sm:inline">{{ $t('fileSelection.uploadNew') }}</span>
                <span class="sm:hidden">{{ $t('files.selectAndUpload') }}</span>
              </button>
              <input
                ref="fileInputRef"
                type="file"
                multiple
                class="hidden"
                accept="image/*,video/*,audio/*,.pdf,.doc,.docx,.txt,.xlsx,.xls,.pptx,.ppt"
                data-testid="input-file-selection-upload"
                @change="handleFileUpload"
              />
              <span
                v-if="isUploading && uploadProgress"
                class="text-xs sm:text-sm txt-secondary flex items-center gap-1.5 min-w-0"
              >
                <template v-if="uploadBytePercent !== null && uploadBytePercent < 100">
                  {{
                    $t('fileSelection.uploadingFilePercent', {
                      current: uploadProgress.current,
                      total: uploadProgress.total,
                      percent: uploadBytePercent,
                    })
                  }}
                </template>
                <template v-else-if="isFinishingUpload">
                  {{ $t('fileSelection.storingFile') }}
                </template>
                <template v-else>
                  {{
                    $t('fileSelection.uploading', {
                      current: uploadProgress.current,
                      total: uploadProgress.total,
                    })
                  }}
                </template>
              </span>
              <span
                v-else-if="isBackgroundProcessing"
                class="text-xs sm:text-sm txt-secondary inline-flex items-center gap-1.5 min-w-0"
              >
                <Icon icon="mdi:loading" class="w-3.5 h-3.5 flex-shrink-0 animate-spin" />
                {{
                  $t('fileSelection.processingOnServer', {
                    count: pendingProcessingIds.size,
                  })
                }}
              </span>
              <span v-else-if="isDragging" class="text-xs sm:text-sm txt-brand font-medium">
                {{ $t('fileSelection.dropHere') }}
              </span>
              <span v-else class="text-xs sm:text-sm txt-secondary hidden sm:inline">
                {{ $t('fileSelection.orDragDrop') }}
              </span>
            </div>
          </div>

          <!-- Search and Filter -->
          <div class="px-3 py-2 sm:p-4 border-b border-light-border/20 dark:border-dark-border/15">
            <div class="flex gap-2 sm:gap-3">
              <input
                v-model="searchQuery"
                type="text"
                :placeholder="$t('fileSelection.searchPlaceholder')"
                class="flex-1 px-3 py-1.5 sm:px-4 sm:py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
                data-testid="input-file-selection-search"
              />
              <select
                v-model="filterStatus"
                class="px-2 py-1.5 sm:px-4 sm:py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
                data-testid="select-file-selection-status"
              >
                <option value="all">{{ $t('fileSelection.allStatuses') }}</option>
                <option value="vectorized">{{ $t('files.status_vectorized') }}</option>
                <option value="extracted">{{ $t('files.status_extracted') }}</option>
                <option value="uploaded">{{ $t('files.status_uploaded') }}</option>
              </select>
            </div>
          </div>

          <!-- Files List -->
          <div class="flex-1 overflow-y-auto px-2 py-1.5 sm:p-4">
            <div v-if="isLoading" class="flex items-center justify-center py-12">
              <Icon icon="mdi:loading" class="w-8 h-8 animate-spin txt-secondary" />
            </div>

            <div v-else-if="filteredFiles.length === 0" class="text-center py-12 txt-secondary">
              {{ $t('files.noFiles') }}
            </div>

            <div v-else class="space-y-1 sm:space-y-2">
              <div
                v-for="file in filteredFiles"
                :key="file.id"
                class="group flex items-center gap-2 sm:gap-4 px-2 py-2 sm:p-4 rounded-lg sm:rounded-xl border cursor-pointer transition-all"
                :class="
                  isSelected(file.id)
                    ? 'border-[var(--brand)]/40 bg-[var(--brand)]/[0.04] ring-1 ring-[var(--brand)]/30'
                    : 'border-gray-200 dark:border-white/[0.08] hover:border-gray-300 dark:hover:border-white/[0.15] hover:bg-gray-50/50 dark:hover:bg-white/[0.02]'
                "
                @click="toggleFileSelection(file)"
              >
                <input
                  type="checkbox"
                  :checked="isSelected(file.id)"
                  class="w-4 h-4 sm:w-5 sm:h-5 rounded border-light-border/30 dark:border-dark-border/20 text-[var(--brand)] flex-shrink-0"
                  @click.stop
                  @change="toggleFileSelection(file)"
                />

                <!-- Thumbnail / Icon -->
                <div
                  class="w-10 h-10 sm:w-12 sm:h-12 flex-shrink-0 rounded-lg overflow-hidden bg-gray-100 dark:bg-white/[0.06] flex items-center justify-center"
                >
                  <img
                    v-if="isImageFile(file.file_type)"
                    :src="getDownloadUrl(file.id)"
                    :alt="file.filename"
                    class="w-full h-full object-cover"
                    loading="lazy"
                    @error="
                      ;($event.target as HTMLImageElement).style.display = 'none'
                      ;($event.target as HTMLImageElement).nextElementSibling?.classList.remove(
                        'hidden'
                      )
                    "
                  />
                  <Icon
                    :icon="getFileIcon(file.file_type)"
                    :class="['w-6 h-6 txt-secondary', isImageFile(file.file_type) ? 'hidden' : '']"
                  />
                </div>

                <div class="flex-1 min-w-0">
                  <div class="text-sm sm:text-base font-medium txt-primary truncate">
                    {{ file.filename }}
                  </div>
                  <div class="text-xs sm:text-sm txt-secondary flex items-center gap-1.5 mt-0.5">
                    <span>{{ formatFileSize(file.file_size) }}</span>
                    <span>·</span>
                    <span
                      class="inline-flex items-center gap-0.5"
                      :class="{
                        'text-green-600 dark:text-green-400':
                          file.status === 'vectorized' || file.status === 'processed',
                        'text-yellow-600 dark:text-yellow-400': file.status === 'extracted',
                        'text-blue-500 dark:text-blue-400':
                          file.status === 'extracting' || file.status === 'vectorizing',
                        'text-gray-600 dark:text-gray-400': file.status === 'uploaded',
                        'text-red-600 dark:text-red-400': file.status === 'error',
                      }"
                    >
                      <Icon
                        v-if="file.status === 'extracting' || file.status === 'vectorizing'"
                        icon="mdi:loading"
                        class="w-3 h-3 animate-spin"
                      />
                      {{ $t(`files.status_${file.status}`) }}
                    </span>
                  </div>
                </div>

                <!-- Per-file action buttons (desktop: hover, mobile: always visible but compact) -->
                <div
                  class="flex items-center gap-0.5 sm:opacity-0 sm:group-hover:opacity-100 transition-opacity flex-shrink-0"
                  @click.stop
                >
                  <button
                    v-if="!['vectorized', 'extracting', 'vectorizing'].includes(file.status)"
                    class="p-1 sm:p-1.5 rounded hover:bg-purple-500/10 text-purple-600 dark:text-purple-400 transition-colors"
                    :title="$t('fileSelection.reVectorize')"
                    data-testid="btn-file-revectorize"
                    @click="handleReVectorize(file.id)"
                  >
                    <Icon icon="heroicons:arrow-path" class="w-3.5 h-3.5 sm:w-4 sm:h-4" />
                  </button>
                  <button
                    class="p-1 sm:p-1.5 rounded hover:bg-[var(--brand)]/10 text-[var(--brand)] transition-colors"
                    :title="$t('fileSelection.viewContent')"
                    data-testid="btn-file-view"
                    @click="openContentModal(file.id)"
                  >
                    <Icon icon="heroicons:eye" class="w-3.5 h-3.5 sm:w-4 sm:h-4" />
                  </button>
                  <button
                    class="hidden sm:block p-1.5 rounded hover:bg-blue-500/10 text-blue-400 transition-colors"
                    :title="$t('fileSelection.download')"
                    data-testid="btn-file-download"
                    @click="handleDownload(file.id, file.filename)"
                  >
                    <ArrowDownTrayIcon class="w-4 h-4" />
                  </button>
                  <button
                    class="p-1 sm:p-1.5 rounded hover:bg-red-500/10 text-red-400 transition-colors"
                    :title="$t('fileSelection.deleteFile')"
                    data-testid="btn-file-delete"
                    @click="confirmDeleteFile(file.id)"
                  >
                    <TrashIcon class="w-3.5 h-3.5 sm:w-4 sm:h-4" />
                  </button>
                </div>
              </div>
            </div>
          </div>

          <!-- Footer with actions — stacked on mobile -->
          <div
            class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between px-3 py-3 sm:p-6 border-t border-light-border/20 dark:border-dark-border/15"
          >
            <span class="txt-secondary text-xs sm:text-sm text-center sm:text-left">
              {{ $t('fileSelection.selectedCount', { count: selectedFiles.length }) }}
            </span>
            <div class="flex gap-2 sm:gap-3">
              <button
                v-if="selectedFiles.length > 0"
                class="flex-1 sm:flex-none px-3 py-2 sm:py-1.5 rounded-lg bg-red-500/10 text-red-500 hover:bg-red-500/20 transition-colors text-sm flex items-center justify-center gap-1.5"
                data-testid="btn-file-selection-delete-selected"
                @click="confirmDeleteSelected"
              >
                <TrashIcon class="w-3.5 h-3.5" />
                <span class="hidden sm:inline">{{ $t('fileSelection.deleteSelected') }}</span>
              </button>
              <button
                class="flex-1 sm:flex-none btn-secondary px-4 py-2 rounded-lg text-sm"
                data-testid="btn-file-selection-cancel"
                @click="emit('close')"
              >
                {{ $t('common.cancel') }}
              </button>
              <button
                :disabled="selectedFiles.length === 0"
                class="flex-1 sm:flex-none btn-primary px-4 py-2 rounded-lg text-sm disabled:opacity-50 disabled:cursor-not-allowed"
                data-testid="btn-file-selection-attach"
                @click="attachFiles"
              >
                {{ $t('fileSelection.attachFiles') }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>

  <!-- Sub-dialogs (rendered via Teleport inside their own templates) -->
  <FileContentModal
    :is-open="contentModalOpen"
    :file-id="contentModalFileId"
    @close="contentModalOpen = false"
  />

  <ConfirmDialog
    :is-open="confirmDialogOpen"
    :title="$t('fileSelection.deleteConfirmTitle')"
    :message="$t('fileSelection.deleteConfirmMessage')"
    :confirm-text="$t('fileSelection.deleteConfirmButton')"
    :cancel-text="$t('common.cancel')"
    variant="danger"
    @confirm="executeDelete"
    @cancel="confirmDialogOpen = false"
  />
</template>

<script setup lang="ts">
import { ref, computed, watch, onUnmounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { XMarkIcon, ArrowDownTrayIcon, TrashIcon } from '@heroicons/vue/24/outline'
import { Icon } from '@iconify/vue'
import filesService, { type FileItem } from '@/services/filesService'
import { getApiBaseUrl } from '@/services/api/httpClient'
import { useNotification } from '@/composables/useNotification'
import FileContentModal from './FileContentModal.vue'
import ConfirmDialog from './ConfirmDialog.vue'

const { t } = useI18n()

const props = defineProps<{
  visible: boolean
}>()

const emit = defineEmits<{
  close: []
  select: [files: FileItem[]]
}>()

const { success, error: showError, warning } = useNotification()

// State
const isLoading = ref(false)
const isUploading = ref(false)
const uploadAbortController = ref<AbortController | null>(null)
const files = ref<FileItem[]>([])
const selectedFileIds = ref<Set<number>>(new Set())
const searchQuery = ref('')
const filterStatus = ref('all')
const fileInputRef = ref<HTMLInputElement | null>(null)
const uploadProgress = ref<{ current: number; total: number } | null>(null)
/** Byte upload progress (XHR); null when not active */
const uploadBytePercent = ref<number | null>(null)
/** Bytes finished; waiting for HTTP response body (store) */
const isFinishingUpload = ref(false)
const isDragging = ref(false)

// Sub-dialog state
const contentModalOpen = ref(false)
const contentModalFileId = ref<number | null>(null)
const confirmDialogOpen = ref(false)
const pendingDeleteIds = ref<number[]>([])

// Computed
const filteredFiles = computed(() => {
  let result = files.value

  if (searchQuery.value) {
    const query = searchQuery.value.toLowerCase()
    result = result.filter((f) => f.filename.toLowerCase().includes(query))
  }

  if (filterStatus.value !== 'all') {
    result = result.filter((f) => f.status === filterStatus.value)
  }

  return result
})

const selectedFiles = computed(() => {
  return files.value.filter((f) => selectedFileIds.value.has(f.id))
})

// Methods
const loadFiles = async () => {
  isLoading.value = true
  try {
    const response = await filesService.listFiles({ limit: 100 })
    files.value = response.files
  } catch (err) {
    console.error('Failed to load files:', err)
  } finally {
    isLoading.value = false
  }
}

const toggleFileSelection = (file: FileItem) => {
  if (selectedFileIds.value.has(file.id)) {
    selectedFileIds.value.delete(file.id)
  } else {
    selectedFileIds.value.add(file.id)
  }
}

const isSelected = (fileId: number) => {
  return selectedFileIds.value.has(fileId)
}

const attachFiles = () => {
  emit('select', selectedFiles.value)
  selectedFileIds.value.clear()
  emit('close')
}

// File management actions
const openContentModal = (fileId: number) => {
  contentModalFileId.value = fileId
  contentModalOpen.value = true
}

const handleDownload = async (fileId: number, filename: string) => {
  try {
    await filesService.downloadFile(fileId, filename)
  } catch (err: unknown) {
    const message = err instanceof Error ? err.message : t('fileSelection.downloadFailed')
    showError(message)
  }
}

const confirmDeleteFile = (fileId: number) => {
  pendingDeleteIds.value = [fileId]
  confirmDialogOpen.value = true
}

const confirmDeleteSelected = () => {
  pendingDeleteIds.value = Array.from(selectedFileIds.value)
  confirmDialogOpen.value = true
}

const executeDelete = async () => {
  confirmDialogOpen.value = false
  const ids = pendingDeleteIds.value

  try {
    if (ids.length === 1) {
      await filesService.deleteFile(ids[0])
      success(t('fileSelection.fileDeleted'))
    } else {
      const results = await filesService.deleteMultipleFiles(ids)
      const successCount = results.filter((r) => r.success).length
      const failCount = results.filter((r) => !r.success).length
      if (failCount > 0) {
        showError(
          t('fileSelection.bulkDeletePartial', { success: successCount, failed: failCount })
        )
      } else {
        success(t('fileSelection.bulkDeleteSuccess', { count: successCount }))
      }
    }

    ids.forEach((id) => selectedFileIds.value.delete(id))
    await loadFiles()
  } catch (err: unknown) {
    const msg = err instanceof Error ? err.message : t('fileSelection.deleteFailed')
    showError(msg)
  } finally {
    pendingDeleteIds.value = []
  }
}

const handleReVectorize = async (fileId: number) => {
  try {
    const result = await filesService.reVectorizeFile(fileId)
    if (result.success) {
      success(t('fileSelection.reVectorizeSuccess', { chunks: result.chunksCreated }))
      await loadFiles()
    }
  } catch (err: unknown) {
    const msg = err instanceof Error ? err.message : t('fileSelection.reVectorizeFailed')
    showError(msg)
  }
}

// Upload
const triggerFileUpload = () => {
  fileInputRef.value?.click()
}

const handleDragOver = () => {
  isDragging.value = true
}

const handleDragLeave = () => {
  isDragging.value = false
}

const handleDrop = async (event: DragEvent) => {
  isDragging.value = false
  const droppedFiles = event.dataTransfer?.files

  if (droppedFiles && droppedFiles.length > 0) {
    await uploadFiles(Array.from(droppedFiles))
  }
}

const handleFileUpload = async (event: Event) => {
  const target = event.target as HTMLInputElement
  const filesToUpload = target.files

  if (!filesToUpload || filesToUpload.length === 0) return

  await uploadFiles(Array.from(filesToUpload))
  target.value = ''
}

const pendingProcessingIds = ref<Set<number>>(new Set())
let pollingTimer: ReturnType<typeof setInterval> | null = null
const isPolling = ref(false)

const isBackgroundProcessing = computed(
  () => pendingProcessingIds.value.size > 0 && !isUploading.value
)

const abortUpload = () => {
  if (uploadAbortController.value) {
    uploadAbortController.value.abort()
    uploadAbortController.value = null
  }
}

/** Abort only closes the HTTP wait — server may already have stored the file (#589). */
const notifyUploadInterrupted = async () => {
  await loadFiles()
  warning(t('fileSelection.uploadInterrupted'))
}

const uploadFiles = async (filesToUpload: File[]) => {
  isUploading.value = true

  const controller = new AbortController()
  uploadAbortController.value = controller

  let successCount = 0
  const newFileIds: number[] = []

  try {
    for (let i = 0; i < filesToUpload.length; i++) {
      if (controller.signal.aborted) {
        break
      }

      uploadProgress.value = { current: i + 1, total: filesToUpload.length }
      const file = filesToUpload[i]

      uploadBytePercent.value = 0
      isFinishingUpload.value = false

      try {
        // XHR + onProgress: real byte progress vs server "store" response (#589)
        const result = await filesService.uploadFiles({
          files: [file],
          processLevel: 'store',
          signal: controller.signal,
          onProgress: (p) => {
            uploadBytePercent.value = p.percentage
            if (p.percentage >= 100) {
              isFinishingUpload.value = true
            }
          },
        })

        if (result.success && result.files.length > 0) {
          successCount++
          result.files.forEach((f) => {
            if (f.id) {
              selectedFileIds.value.add(f.id)
              newFileIds.push(f.id)
            }
          })
        }

        if (result.errors && result.errors.length > 0) {
          result.errors.forEach((err) => {
            showError(`${err.filename}: ${err.error}`)
          })
        }
      } catch (err: unknown) {
        if (err instanceof DOMException && err.name === 'AbortError') {
          await notifyUploadInterrupted()
          break
        }
        console.error('Upload failed:', file.name, err)
        const msg = err instanceof Error ? err.message : 'Unknown error'
        showError(`${file.name}: ${msg}`)
      } finally {
        uploadBytePercent.value = null
        isFinishingUpload.value = false
      }
    }

    if (successCount > 0) {
      success(t('fileSelection.uploadSuccess', { count: successCount }))
      await loadFiles()

      for (const fileId of newFileIds) {
        pendingProcessingIds.value.add(fileId)
        filesService
          .processFile(fileId)
          .catch((err) => {
            console.error('Background processing failed for file', fileId, err)
            const msg = err instanceof Error ? err.message : 'Unknown error'
            showError(`Background processing failed for file: ${msg}`)
          })
          .finally(() => {
            loadFiles()
          })
      }
      startPolling()
    }
  } catch (err: unknown) {
    if (err instanceof DOMException && err.name === 'AbortError') {
      await notifyUploadInterrupted()
    } else {
      console.error('Upload failed:', err)
      const msg = err instanceof Error ? err.message : 'Unknown error'
      showError(`Upload failed: ${msg}`)
    }
  } finally {
    isUploading.value = false
    uploadProgress.value = null
    uploadBytePercent.value = null
    isFinishingUpload.value = false
    uploadAbortController.value = null
  }
}

const startPolling = () => {
  if (pollingTimer) return
  pollingTimer = setInterval(async () => {
    if (pendingProcessingIds.value.size === 0) {
      stopPolling()
      return
    }
    if (isPolling.value) return
    isPolling.value = true
    try {
      const response = await filesService.listFiles({ limit: 100 })
      files.value = response.files

      const terminalStates = ['vectorized', 'processed', 'extracted', 'error']
      const stillProcessing = new Set<number>()
      for (const id of pendingProcessingIds.value) {
        const file = response.files.find((f) => f.id === id)
        if (file && !terminalStates.includes(file.status)) {
          stillProcessing.add(id)
        }
      }
      pendingProcessingIds.value = stillProcessing

      if (stillProcessing.size === 0) {
        stopPolling()
      }
    } catch (err) {
      console.error('Polling failed:', err)
    } finally {
      isPolling.value = false
    }
  }, 2000)
}

const stopPolling = () => {
  if (pollingTimer) {
    clearInterval(pollingTimer)
    pollingTimer = null
  }
}

const formatFileSize = (bytes: number): string => {
  if (bytes < 1024) return bytes + ' B'
  if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB'
  if (bytes < 1024 * 1024 * 1024) return (bytes / (1024 * 1024)).toFixed(1) + ' MB'
  return (bytes / (1024 * 1024 * 1024)).toFixed(1) + ' GB'
}

const isImageFile = (fileType: string): boolean => {
  const type = fileType.toLowerCase()
  return type.includes('image') || /jpg|jpeg|png|gif|webp/.test(type)
}

const getDownloadUrl = (fileId: number): string => {
  return `${getApiBaseUrl()}/api/v1/files/${fileId}/download`
}

const getFileIcon = (fileType: string): string => {
  const type = fileType.toLowerCase()
  if (type.includes('pdf')) return 'mdi:file-pdf-box'
  if (type.includes('doc')) return 'mdi:file-word'
  if (type.includes('xls') || type.includes('csv')) return 'mdi:file-excel'
  if (type.includes('ppt')) return 'mdi:file-powerpoint'
  if (type.includes('txt')) return 'mdi:file-document'
  if (isImageFile(type)) return 'mdi:file-image'
  if (type.includes('audio') || /mp3|wav|ogg/.test(type)) return 'mdi:file-music'
  if (type.includes('video') || /mp4|avi|mov/.test(type)) return 'mdi:file-video'
  return 'mdi:file'
}

watch(
  () => props.visible,
  (visible) => {
    if (visible) {
      loadFiles()
    } else {
      abortUpload()
      stopPolling()
      pendingProcessingIds.value.clear()
      uploadBytePercent.value = null
      isFinishingUpload.value = false
      selectedFileIds.value.clear()
      contentModalOpen.value = false
      confirmDialogOpen.value = false
    }
  }
)

onUnmounted(() => {
  abortUpload()
  stopPolling()
})
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
</style>
