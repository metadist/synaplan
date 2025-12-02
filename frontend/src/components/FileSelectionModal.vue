<template>
  <Teleport to="body">
    <Transition name="modal">
      <div
        v-if="visible"
        class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50"
        @click.self="emit('close')"
        data-testid="modal-file-selection-root"
      >
        <div class="surface-card max-w-4xl w-full max-h-[80vh] overflow-hidden flex flex-col rounded-lg shadow-xl" data-testid="modal-file-selection">
          <!-- Header -->
          <div class="flex items-center justify-between p-6 border-b border-light-border/30 dark:border-dark-border/20">
            <h2 class="text-xl font-semibold txt-primary">
              {{ $t('fileSelection.title') }}
            </h2>
            <button
              @click="emit('close')"
              class="icon-ghost p-2"
              :aria-label="$t('common.close')"
              data-testid="btn-file-selection-close"
            >
              <XMarkIcon class="w-5 h-5" />
            </button>
          </div>

          <!-- Upload Area -->
          <div 
            class="p-4 border-b border-light-border/30 dark:border-dark-border/20 transition-colors"
            :class="isDragging ? 'bg-brand-alpha-light' : 'bg-black/5 dark:bg-white/5'"
            @dragover.prevent="handleDragOver"
            @dragleave.prevent="handleDragLeave"
            @drop.prevent="handleDrop"
            data-testid="section-file-dropzone"
          >
            <div class="flex items-center gap-3">
              <button
                @click="triggerFileUpload"
                :disabled="isUploading"
                class="btn-primary px-4 py-2 rounded-lg flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed"
                data-testid="btn-file-selection-upload"
              >
                <Icon v-if="isUploading" icon="mdi:loading" class="w-5 h-5 animate-spin" />
                <Icon v-else icon="mdi:cloud-upload" class="w-5 h-5" />
                <span>{{ $t('fileSelection.uploadNew') }}</span>
              </button>
              <input
                ref="fileInputRef"
                type="file"
                multiple
                @change="handleFileUpload"
                class="hidden"
                accept="image/*,video/*,audio/*,.pdf,.doc,.docx,.txt,.xlsx,.xls,.pptx,.ppt"
                data-testid="input-file-selection-upload"
              />
              <span v-if="uploadProgress" class="text-sm txt-secondary">
                {{ $t('fileSelection.uploading', { count: uploadProgress.current, total: uploadProgress.total }) }}
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
          <div class="p-4 border-b border-light-border/30 dark:border-dark-border/20">
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
                @click="toggleFileSelection(file)"
                class="flex items-center gap-4 p-4 rounded-lg border border-light-border/30 dark:border-dark-border/20 cursor-pointer transition-all hover:bg-black/5 dark:hover:bg-white/5"
                :class="{ 'ring-2 ring-[var(--brand)]': isSelected(file.id) }"
              >
                <input
                  type="checkbox"
                  :checked="isSelected(file.id)"
                  @click.stop
                  @change="toggleFileSelection(file)"
                  class="w-5 h-5 rounded border-light-border/30 dark:border-dark-border/20 text-[var(--brand)]"
                />
                
                <Icon 
                  :icon="getFileIcon(file.file_type)" 
                  class="w-8 h-8 txt-secondary flex-shrink-0" 
                />
                
                <div class="flex-1 min-w-0">
                  <div class="font-medium txt-primary truncate">{{ file.filename }}</div>
                  <div class="text-sm txt-secondary flex items-center gap-2 mt-1">
                    <span>{{ formatFileSize(file.file_size) }}</span>
                    <span>•</span>
                    <span
                      :class="{
                        'text-green-600 dark:text-green-400': file.status === 'vectorized',
                        'text-yellow-600 dark:text-yellow-400': file.status === 'extracted',
                        'text-gray-600 dark:text-gray-400': file.status === 'uploaded'
                      }"
                    >
                      {{ $t(`files.status_${file.status}`) }}
                    </span>
                    <span v-if="file.is_attached">•</span>
                    <span v-if="file.is_attached" class="text-blue-600 dark:text-blue-400">
                      {{ $t('files.attached') }}
                    </span>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Footer with actions -->
          <div class="flex items-center justify-between p-6 border-t border-light-border/30 dark:border-dark-border/20">
            <div class="txt-secondary text-sm">
              {{ $t('fileSelection.selectedCount', { count: selectedFiles.length }) }}
            </div>
            <div class="flex gap-3">
              <button
                @click="emit('close')"
                class="btn-secondary px-6 py-2 rounded-lg"
                data-testid="btn-file-selection-cancel"
              >
                {{ $t('common.cancel') }}
              </button>
              <button
                @click="attachFiles"
                :disabled="selectedFiles.length === 0"
                class="btn-primary px-6 py-2 rounded-lg disabled:opacity-50 disabled:cursor-not-allowed"
                data-testid="btn-file-selection-attach"
              >
                {{ $t('fileSelection.attachFiles') }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { XMarkIcon } from '@heroicons/vue/24/outline'
import { Icon } from '@iconify/vue'
import filesService, { type FileItem } from '@/services/filesService'
import { useNotification } from '@/composables/useNotification'

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

// Computed
const filteredFiles = computed(() => {
  let result = files.value

  // Filter by search query
  if (searchQuery.value) {
    const query = searchQuery.value.toLowerCase()
    result = result.filter(f => f.filename.toLowerCase().includes(query))
  }

  // Filter by status
  if (filterStatus.value !== 'all') {
    result = result.filter(f => f.status === filterStatus.value)
  }

  return result
})

const selectedFiles = computed(() => {
  return files.value.filter(f => selectedFileIds.value.has(f.id))
})

// Methods
const loadFiles = async () => {
  isLoading.value = true
  try {
    const response = await filesService.listFiles(undefined, 1, 100)
    files.value = response.files
  } catch (error) {
    console.error('Failed to load files:', error)
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
  
  // Reset input
  target.value = ''
}

const uploadFiles = async (filesToUpload: File[]) => {
  isUploading.value = true
  uploadProgress.value = { current: 0, total: filesToUpload.length }
  
  try {
    // Upload files with default processing level (vectorize)
    const result = await filesService.uploadFiles({
      files: filesToUpload,
      processLevel: 'vectorize'
    })
    
    if (result.success) {
      success(`${result.files.length} ${result.files.length === 1 ? 'file' : 'files'} uploaded successfully`)
      
      // Reload file list to show newly uploaded files
      await loadFiles()
      
      // Auto-select newly uploaded files
      result.files.forEach(file => {
        if (file.id) {
          selectedFileIds.value.add(file.id)
        }
      })
    }
    
    // Show any errors
    if (result.errors && result.errors.length > 0) {
      result.errors.forEach(err => {
        showError(`${err.filename}: ${err.error}`)
      })
    }
  } catch (err: any) {
    console.error('Upload failed:', err)
    showError(`Upload failed: ${err.message || 'Unknown error'}`)
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

// Watch for modal visibility
watch(() => props.visible, (visible) => {
  if (visible) {
    loadFiles()
  } else {
    // Reset selection when modal closes
    selectedFileIds.value.clear()
  }
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
