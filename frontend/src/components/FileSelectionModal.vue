<template>
  <Teleport to="#app">
    <Transition name="modal">
      <div
        v-if="visible"
        class="modal-overlay fixed inset-0 z-50 flex items-end sm:items-center justify-center sm:p-4 bg-black/50"
        data-testid="modal-file-selection-root"
        @click.self="emit('close')"
      >
        <div
          class="modal-panel w-full sm:max-w-4xl overflow-hidden flex flex-col rounded-t-2xl sm:rounded-xl shadow-2xl bg-white dark:bg-[#0f1729] border border-light-border/20 dark:border-dark-border/20"
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
                class="btn-primary px-3 py-1.5 sm:px-4 sm:py-2 rounded-xl flex items-center gap-1.5 sm:gap-2 text-sm sm:text-base disabled:opacity-50 disabled:cursor-not-allowed"
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
                accept="image/*,.heic,.heif,video/*,audio/*,.pdf,.doc,.docx,.rtf,.txt,.md,.csv,.xlsx,.xls,.pptx,.ppt,.odt,.ods,.odp,.pages,.numbers,.key,.ics,.jar"
                data-testid="input-file-selection-upload"
                @change="handleFileUpload"
              />
              <span
                v-if="isUploading && uploadProgress"
                class="text-xs sm:text-sm flex items-center gap-1.5 min-w-0"
                :class="isUploadSlow ? 'text-yellow-600 dark:text-yellow-400' : 'txt-secondary'"
              >
                <template v-if="uploadBytePercent !== null && uploadBytePercent < 100">
                  <Icon
                    v-if="isUploadSlow"
                    icon="mdi:timer-sand"
                    class="w-3.5 h-3.5 flex-shrink-0"
                  />
                  {{
                    $t('fileSelection.uploadingFilePercent', {
                      current: uploadProgress.current,
                      total: uploadProgress.total,
                      percent: uploadBytePercent,
                    })
                  }}
                  <span v-if="isUploadSlow" class="hidden sm:inline">
                    — {{ $t('fileSelection.uploadSlow') }}
                  </span>
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

          <FilePickerBrowser
            ref="pickerRef"
            class="flex-1 overflow-y-auto px-2 py-1.5 sm:p-4"
            :selected-ids="selectedIdList"
            @toggle="toggleFileSelection"
            @view="openContentModal"
            @download="handleDownload"
            @delete="confirmDeleteFile"
            @revectorize="handleReVectorize"
            @upload="triggerFileUpload"
          />

          <!-- §4.10.2 helper line: clarify the consequence of attaching. -->
          <p
            class="px-3 sm:px-6 py-3 text-[11px] sm:text-xs txt-secondary leading-relaxed"
            data-testid="file-selection-helper"
          >
            {{ $t('fileSelection.helper') }}
          </p>

          <!-- Footer with actions -->
          <div
            class="flex items-center justify-between gap-3 px-3 py-3 sm:px-6 sm:py-4 border-t border-light-border/20 dark:border-dark-border/15"
          >
            <span class="txt-secondary text-xs sm:text-sm shrink-0">
              {{ $t('fileSelection.selectedCount', { count: selectedFiles.length }) }}
            </span>
            <div class="flex items-center gap-2 sm:gap-3">
              <button
                v-if="selectedFiles.length > 0"
                class="p-2 rounded-xl bg-red-500/10 text-red-500 hover:bg-red-500/20 transition-colors"
                :title="$t('fileSelection.deleteSelected')"
                :aria-label="$t('fileSelection.deleteSelected')"
                data-testid="btn-file-selection-delete-selected"
                @click="confirmDeleteSelected"
              >
                <TrashIcon class="w-4 h-4" />
              </button>
              <button
                class="btn-secondary px-3 py-2 sm:px-4 rounded-xl text-sm font-medium"
                data-testid="btn-file-selection-cancel"
                @click="emit('close')"
              >
                {{ $t('common.cancel') }}
              </button>
              <button
                :disabled="selectedFiles.length === 0"
                class="btn-primary px-3 py-2 sm:px-4 rounded-xl text-sm font-medium disabled:opacity-50 disabled:cursor-not-allowed whitespace-nowrap"
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
import { XMarkIcon, TrashIcon } from '@heroicons/vue/24/outline'
import { Icon } from '@iconify/vue'
import filesService, {
  type FileItem,
  type UploadPhase,
  UploadBlockedError,
  UploadFailedError,
} from '@/services/filesService'
import { useNotification } from '@/composables/useNotification'
import FileContentModal from './FileContentModal.vue'
import ConfirmDialog from './ConfirmDialog.vue'
import FilePickerBrowser from './files/FilePickerBrowser.vue'

const { t } = useI18n()

const props = defineProps<{
  visible: boolean
}>()

const emit = defineEmits<{
  close: []
  select: [files: FileItem[]]
}>()

const { success, error: showError, warning } = useNotification()

// Localized message for a pre-flight upload rejection, with a hard
// non-i18n fallback so the user never sees a raw dot-path key when a
// translation is missing in the active locale.
const translateUploadBlocked = (err: UploadBlockedError): string => {
  const params = {
    filename: err.filename,
    message: err.check.message ?? '',
  }
  const reasonKey = `files.uploadBlocked.${err.reason}`
  const reasonTranslated = t(reasonKey, params)
  if (reasonTranslated !== reasonKey) return reasonTranslated

  const genericKey = 'files.uploadBlocked.generic'
  const genericTranslated = t(genericKey, params)
  if (genericTranslated !== genericKey) return genericTranslated

  return params.message ? `${params.filename}: ${params.message}` : params.filename
}

// Localized message for a transfer-level failure (stall, timeout, proxy
// limit, network drop). Keeps the user informed with a remediation hint
// instead of a silent spinner, and never echoes server internals.
const translateUploadFailed = (filename: string, err: UploadFailedError): string => {
  const key = `fileSelection.uploadFailed.${err.code}`
  const params = { filename, percent: err.percentReached ?? 0, status: err.status ?? 0 }
  const translated = t(key, params)
  if (translated !== key) return translated
  return `${filename}: ${err.message}`
}

// State
const isUploading = ref(false)
const uploadAbortController = ref<AbortController | null>(null)
const pickerRef = ref<{
  reload: () => Promise<void>
  resetToMine: () => Promise<void>
  files: FileItem[]
} | null>(null)
const selectedById = ref(new Map<number, FileItem>())
const selectedIdList = computed(() => [...selectedById.value.keys()])
const selectedFiles = computed(() => [...selectedById.value.values()])
const fileInputRef = ref<HTMLInputElement | null>(null)
const uploadProgress = ref<{ current: number; total: number } | null>(null)
/** Byte upload progress (XHR); null when not active */
const uploadBytePercent = ref<number | null>(null)
/** Bytes finished; waiting for HTTP response body (store) */
const isFinishingUpload = ref(false)
/** True once the transfer has gone quiet long enough to warn the user (#589) */
const isUploadSlow = ref(false)
const isDragging = ref(false)

// Sub-dialog state
const contentModalOpen = ref(false)
const contentModalFileId = ref<number | null>(null)
const confirmDialogOpen = ref(false)
const pendingDeleteIds = ref<number[]>([])

const reloadPicker = () => pickerRef.value?.reload() ?? Promise.resolve()

const toggleFileSelection = (file: FileItem) => {
  const next = new Map(selectedById.value)
  if (next.has(file.id)) next.delete(file.id)
  else next.set(file.id, file)
  selectedById.value = next
}

const attachFiles = () => {
  emit('select', selectedFiles.value)
  selectedById.value = new Map()
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
  pendingDeleteIds.value = selectedIdList.value
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

    ids.forEach((id) => {
      const next = new Map(selectedById.value)
      next.delete(id)
      selectedById.value = next
    })
    await reloadPicker()
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
      await reloadPicker()
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
  await reloadPicker()
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
      isUploadSlow.value = false

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
          onPhase: (phase: UploadPhase) => {
            isUploadSlow.value = phase === 'slow'
            if (phase === 'finishing') isFinishingUpload.value = true
          },
          // 'store' is just an NFS write — it should answer in seconds. If a
          // node hangs (busy FrankenPHP worker, dead LB upstream), surface a
          // timeout instead of spinning forever. The transfer-stall guard in
          // filesService handles the "stuck at 89%" body-stall case.
          serverTimeoutMs: 90_000,
        })

        if (result.success && result.files.length > 0) {
          successCount++
          result.files.forEach((f) => {
            if (f.id) newFileIds.push(f.id)
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
        if (err instanceof UploadBlockedError) {
          showError(translateUploadBlocked(err))
          continue
        }
        if (err instanceof UploadFailedError) {
          // Stall / timeout failures may have left a partial file on the
          // server (#589) — refresh the list so a stored-but-unfinished
          // upload is visible rather than appearing lost.
          if (err.code === 'transfer_stalled' || err.code === 'server_timeout') {
            await reloadPicker()
          }
          showError(translateUploadFailed(file.name, err))
          continue
        }
        console.error('Upload failed:', file.name, err)
        const msg = err instanceof Error ? err.message : 'Unknown error'
        showError(`${file.name}: ${msg}`)
      } finally {
        uploadBytePercent.value = null
        isFinishingUpload.value = false
        isUploadSlow.value = false
      }
    }

    if (successCount > 0) {
      success(t('fileSelection.uploadSuccess', { count: successCount }))
      await reloadPicker()

      for (const fileId of newFileIds) {
        selectUploaded(fileId)
        pendingProcessingIds.value.add(fileId)
        filesService
          .processFile(fileId)
          .catch((err) => {
            console.error('Background processing failed for file', fileId, err)
            const msg = err instanceof Error ? err.message : 'Unknown error'
            showError(`Background processing failed for file: ${msg}`)
          })
          .finally(() => {
            reloadPicker()
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
    isUploadSlow.value = false
    uploadAbortController.value = null
  }
}

const selectUploaded = (id: number) => {
  const file = pickerRef.value?.files.find((item) => item.id === id)
  if (!file) return
  const next = new Map(selectedById.value)
  next.set(file.id, file)
  selectedById.value = next
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
      await reloadPicker()
      const current = pickerRef.value?.files ?? []

      const terminalStates = ['vectorized', 'processed', 'extracted', 'error']
      const stillProcessing = new Set<number>()
      for (const id of pendingProcessingIds.value) {
        const file = current.find((item) => item.id === id)
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

watch(
  () => props.visible,
  (visible) => {
    if (visible) return
    abortUpload()
    stopPolling()
    pendingProcessingIds.value.clear()
    uploadBytePercent.value = null
    isFinishingUpload.value = false
    isUploadSlow.value = false
    selectedById.value = new Map()
    contentModalOpen.value = false
    confirmDialogOpen.value = false
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
