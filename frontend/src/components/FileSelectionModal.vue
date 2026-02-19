<template>
  <Teleport to="#app">
    <Transition name="modal">
      <div
        v-if="visible"
        class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50"
        data-testid="modal-file-selection-root"
        @click.self="emit('close')"
      >
        <div
          class="max-w-4xl w-full max-h-[80vh] overflow-hidden flex flex-col rounded-xl shadow-2xl bg-white dark:bg-[#0f1729] border border-light-border/20 dark:border-dark-border/20"
          data-testid="modal-file-selection"
        >
          <!-- Header -->
          <div
            class="flex items-center justify-between p-6 border-b border-light-border/20 dark:border-dark-border/15"
          >
            <h2 class="text-xl font-semibold txt-primary">
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
            class="p-4 border-b border-light-border/20 dark:border-dark-border/15 transition-colors"
            :class="isDragging ? 'bg-brand-alpha-light' : 'bg-gray-50 dark:bg-white/[0.03]'"
            data-testid="section-file-dropzone"
            @dragover.prevent="handleDragOver"
            @dragleave.prevent="handleDragLeave"
            @drop.prevent="handleDrop"
          >
            <div class="flex items-center gap-3">
              <button
                :disabled="isUploading"
                class="btn-primary px-4 py-2 rounded-lg flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed"
                data-testid="btn-file-selection-upload"
                @click="triggerFileUpload"
              >
                <Icon v-if="isUploading" icon="mdi:loading" class="w-5 h-5 animate-spin" />
                <Icon v-else icon="mdi:cloud-upload" class="w-5 h-5" />
                <span>{{ $t('fileSelection.uploadNew') }}</span>
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
              <span v-if="uploadProgress" class="text-sm txt-secondary">
                {{
                  $t('fileSelection.uploading', {
                    count: uploadProgress.current,
                    total: uploadProgress.total,
                  })
                }}
              </span>
              <span v-else-if="isDragging" class="text-sm txt-brand font-medium">
                {{ $t('fileSelection.dropHere') }}
              </span>
              <span v-else class="text-sm txt-secondary">
                {{ $t('fileSelection.orDragDrop') }}
              </span>
            </div>
          </div>

          <!-- Search and Filter -->
          <div class="p-4 border-b border-light-border/20 dark:border-dark-border/15">
            <div class="flex gap-3">
              <input
                v-model="searchQuery"
                type="text"
                :placeholder="$t('fileSelection.searchPlaceholder')"
                class="flex-1 px-4 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
                data-testid="input-file-selection-search"
              />
              <select
                v-model="filterStatus"
                class="px-4 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
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
          <div class="flex-1 overflow-y-auto p-4">
            <div v-if="isLoading" class="flex items-center justify-center py-12">
              <Icon icon="mdi:loading" class="w-8 h-8 animate-spin txt-secondary" />
            </div>

            <div v-else-if="filteredFiles.length === 0" class="text-center py-12 txt-secondary">
              {{ $t('files.noFiles') }}
            </div>

            <div v-else class="space-y-2">
              <div
                v-for="file in filteredFiles"
                :key="file.id"
                class="group flex items-center gap-4 p-4 rounded-xl border cursor-pointer transition-all"
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
                  class="w-5 h-5 rounded border-light-border/30 dark:border-dark-border/20 text-[var(--brand)]"
                  @click.stop
                  @change="toggleFileSelection(file)"
                />

                <Icon
                  :icon="getFileIcon(file.file_type)"
                  class="w-8 h-8 txt-secondary flex-shrink-0"
                />

                <div class="flex-1 min-w-0">
                  <div class="font-medium txt-primary truncate">{{ file.filename }}</div>
                  <div class="text-sm txt-secondary flex items-center gap-2 mt-1">
                    <span>{{ formatFileSize(file.file_size) }}</span>
                    <span>·</span>
                    <span
                      :class="{
                        'text-green-600 dark:text-green-400': file.status === 'vectorized',
                        'text-yellow-600 dark:text-yellow-400': file.status === 'extracted',
                        'text-gray-600 dark:text-gray-400': file.status === 'uploaded',
                      }"
                    >
                      {{ $t(`files.status_${file.status}`) }}
                    </span>
                    <span v-if="file.is_attached">·</span>
                    <span v-if="file.is_attached" class="text-blue-600 dark:text-blue-400">
                      {{ $t('files.attached') }}
                    </span>
                  </div>
                </div>

                <!-- Per-file action buttons -->
                <div
                  class="flex items-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity flex-shrink-0"
                  @click.stop
                >
                  <button
                    v-if="file.status !== 'vectorized'"
                    class="p-1.5 rounded hover:bg-purple-500/10 text-purple-600 dark:text-purple-400 transition-colors"
                    :title="$t('fileSelection.reVectorize')"
                    data-testid="btn-file-revectorize"
                    @click="handleReVectorize(file.id)"
                  >
                    <Icon icon="heroicons:arrow-path" class="w-4 h-4" />
                  </button>
                  <button
                    class="p-1.5 rounded hover:bg-[var(--brand)]/10 text-[var(--brand)] transition-colors"
                    :title="$t('fileSelection.viewContent')"
                    data-testid="btn-file-view"
                    @click="openContentModal(file.id)"
                  >
                    <Icon icon="heroicons:eye" class="w-4 h-4" />
                  </button>
                  <button
                    class="p-1.5 rounded hover:bg-blue-500/10 text-blue-400 transition-colors"
                    :title="$t('fileSelection.download')"
                    data-testid="btn-file-download"
                    @click="handleDownload(file.id, file.filename)"
                  >
                    <ArrowDownTrayIcon class="w-4 h-4" />
                  </button>
                  <button
                    class="p-1.5 rounded hover:bg-red-500/10 text-red-400 transition-colors"
                    :title="$t('fileSelection.deleteFile')"
                    data-testid="btn-file-delete"
                    @click="confirmDeleteFile(file.id)"
                  >
                    <TrashIcon class="w-4 h-4" />
                  </button>
                </div>
              </div>
            </div>
          </div>

          <!-- Footer with actions -->
          <div
            class="flex items-center justify-between p-6 border-t border-light-border/20 dark:border-dark-border/15"
          >
            <div class="flex items-center gap-3">
              <span class="txt-secondary text-sm">
                {{ $t('fileSelection.selectedCount', { count: selectedFiles.length }) }}
              </span>
              <button
                v-if="selectedFiles.length > 0"
                class="px-3 py-1.5 rounded-lg bg-red-500/10 text-red-500 hover:bg-red-500/20 transition-colors text-sm flex items-center gap-1.5"
                data-testid="btn-file-selection-delete-selected"
                @click="confirmDeleteSelected"
              >
                <TrashIcon class="w-3.5 h-3.5" />
                {{ $t('fileSelection.deleteSelected') }}
              </button>
            </div>
            <div class="flex gap-3">
              <button
                class="btn-secondary px-6 py-2 rounded-lg"
                data-testid="btn-file-selection-cancel"
                @click="emit('close')"
              >
                {{ $t('common.cancel') }}
              </button>
              <button
                :disabled="selectedFiles.length === 0"
                class="btn-primary px-6 py-2 rounded-lg disabled:opacity-50 disabled:cursor-not-allowed"
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
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { XMarkIcon, ArrowDownTrayIcon, TrashIcon } from '@heroicons/vue/24/outline'
import { Icon } from '@iconify/vue'
import filesService, { type FileItem } from '@/services/filesService'
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

const { success, error: showError } = useNotification()

// State
const isLoading = ref(false)
const isUploading = ref(false)
const files = ref<FileItem[]>([])
const selectedFileIds = ref<Set<number>>(new Set())
const searchQuery = ref('')
const filterStatus = ref('all')
const fileInputRef = ref<HTMLInputElement | null>(null)
const uploadProgress = ref<{ current: number; total: number } | null>(null)
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
    const response = await filesService.listFiles(undefined, 1, 100)
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

const uploadFiles = async (filesToUpload: File[]) => {
  isUploading.value = true
  uploadProgress.value = { current: 0, total: filesToUpload.length }

  try {
    const result = await filesService.uploadFiles({
      files: filesToUpload,
      processLevel: 'vectorize',
    })

    if (result.success) {
      success(
        `${result.files.length} ${result.files.length === 1 ? 'file' : 'files'} uploaded successfully`
      )
      await loadFiles()
      result.files.forEach((file) => {
        if (file.id) {
          selectedFileIds.value.add(file.id)
        }
      })
    }

    if (result.errors && result.errors.length > 0) {
      result.errors.forEach((err) => {
        showError(`${err.filename}: ${err.error}`)
      })
    }
  } catch (err: unknown) {
    console.error('Upload failed:', err)
    const msg = err instanceof Error ? err.message : 'Unknown error'
    showError(`Upload failed: ${msg}`)
  } finally {
    isUploading.value = false
    uploadProgress.value = null
  }
}

const formatFileSize = (bytes: number): string => {
  if (bytes < 1024) return bytes + ' B'
  if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB'
  if (bytes < 1024 * 1024 * 1024) return (bytes / (1024 * 1024)).toFixed(1) + ' MB'
  return (bytes / (1024 * 1024 * 1024)).toFixed(1) + ' GB'
}

const getFileIcon = (fileType: string): string => {
  const type = fileType.toLowerCase()
  if (type.includes('pdf')) return 'mdi:file-pdf-box'
  if (type.includes('doc')) return 'mdi:file-word'
  if (type.includes('xls') || type.includes('csv')) return 'mdi:file-excel'
  if (type.includes('ppt')) return 'mdi:file-powerpoint'
  if (type.includes('txt')) return 'mdi:file-document'
  if (type.includes('image') || type.match(/jpg|jpeg|png|gif|webp/)) return 'mdi:file-image'
  if (type.includes('audio') || type.match(/mp3|wav|ogg/)) return 'mdi:file-music'
  if (type.includes('video') || type.match(/mp4|avi|mov/)) return 'mdi:file-video'
  return 'mdi:file'
}

watch(
  () => props.visible,
  (visible) => {
    if (visible) {
      loadFiles()
    } else {
      selectedFileIds.value.clear()
      contentModalOpen.value = false
      confirmDialogOpen.value = false
    }
  }
)
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
