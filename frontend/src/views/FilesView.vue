<template>
  <MainLayout>
    <div
      class="min-h-screen bg-chat p-4 md:p-8 overflow-y-auto scroll-thin"
      data-testid="page-files-upload"
    >
      <div class="max-w-7xl mx-auto space-y-6">
        <!-- Storage Quota Widget -->
        <StorageQuotaWidget ref="storageWidget" @upgrade="handleUpgrade" />

        <div
          class="surface-card p-6 relative"
          data-testid="section-upload-form"
          @dragenter.prevent="handleDragEnter"
          @dragover.prevent="handleDragOver"
          @dragleave="handleDragLeave"
          @drop.prevent="handleDrop"
        >
          <!-- Drag & Drop Overlay -->
          <Transition name="fade">
            <div
              v-if="isDragging"
              class="absolute inset-0 z-50 flex items-center justify-center bg-primary/10 dark:bg-primary/20 backdrop-blur-sm border-4 border-dashed border-primary rounded-lg pointer-events-none"
            >
              <div class="flex flex-col items-center gap-4 p-8 surface-card rounded-xl shadow-2xl">
                <div
                  class="w-20 h-20 rounded-full bg-primary/20 flex items-center justify-center animate-bounce"
                >
                  <Icon icon="mdi:cloud-upload" class="w-10 h-10 text-primary" />
                </div>
                <div class="text-center">
                  <p class="text-xl font-bold txt-primary mb-1">{{ $t('files.dropFiles') }}</p>
                  <p class="text-sm txt-secondary">{{ $t('files.dropFilesHint') }}</p>
                </div>
              </div>
            </div>
          </Transition>

          <h1 class="text-2xl font-semibold txt-primary mb-6 flex items-center gap-2">
            <CloudArrowUpIcon class="w-6 h-6 text-[var(--brand)]" />
            {{ $t('files.uploadTitle') }}
          </h1>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
            <div>
              <label class="block text-sm font-medium txt-primary mb-2">
                {{ $t('files.groupKeyword') }}
              </label>
              <input
                v-model="groupKeyword"
                type="text"
                class="w-full px-4 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
                :placeholder="$t('files.groupKeywordPlaceholder')"
                data-testid="input-group-keyword"
              />
              <p class="text-xs txt-secondary mt-1">
                {{ $t('files.groupKeywordHelp') }}
              </p>
            </div>

            <div>
              <label class="block text-sm font-medium txt-primary mb-2">
                {{ $t('files.orSelectExisting') }}
              </label>
              <select
                v-model="selectedGroup"
                class="w-full px-4 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
                data-testid="input-group-select"
              >
                <option value="">{{ $t('files.orSelectExisting') }}</option>
                <option v-for="group in fileGroups" :key="group.name" :value="group.name">
                  {{ group.name }} ({{ group.count }})
                </option>
              </select>
            </div>
          </div>

          <div class="mb-6" data-testid="section-file-picker">
            <label class="block text-sm font-medium txt-primary mb-2">
              {{ $t('files.selectFiles') }}
            </label>
            <div class="mb-3">
              <label
                class="px-4 py-2 rounded-lg border border-light-border/30 dark:border-dark-border/20 txt-primary hover:bg-black/5 dark:hover:bg-white/5 transition-colors cursor-pointer inline-flex items-center gap-2"
                data-testid="btn-select-files"
              >
                <input
                  type="file"
                  multiple
                  accept=".pdf,.docx,.txt,.jpg,.jpeg,.png,.mp3,.mp4,.xlsx,.csv"
                  class="hidden"
                  data-testid="input-files"
                  @change="handleFileSelect"
                />
                <CloudArrowUpIcon class="w-5 h-5" />
                {{ $t('files.selectFilesButton') }}
              </label>
            </div>

            <!-- Selected Files List -->
            <div v-if="selectedFiles.length > 0" class="space-y-2 mb-3">
              <div
                v-for="(file, index) in selectedFiles"
                :key="index"
                class="flex items-center gap-3 p-3 rounded-lg border border-light-border/30 dark:border-dark-border/20 bg-black/[0.02] dark:bg-white/[0.02]"
              >
                <Icon :icon="getFileIcon(file.name)" class="w-5 h-5 txt-secondary" />
                <div class="flex-1 min-w-0">
                  <p class="text-sm txt-primary truncate">{{ file.name }}</p>
                  <p class="text-xs txt-secondary">{{ formatFileSize(file.size) }}</p>
                </div>
                <button
                  :disabled="isUploading"
                  class="p-1.5 rounded-lg hover:bg-red-500/10 transition-colors disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:bg-transparent"
                  :aria-label="$t('files.removeFile')"
                  @click="removeSelectedFile(index)"
                >
                  <XMarkIcon class="w-4 h-4 text-red-500" />
                </button>
              </div>
            </div>

            <p class="text-xs txt-secondary mt-2">
              {{ $t('files.supportedFormats') }}
            </p>
            <p class="text-sm alert-info mt-3">
              <strong class="alert-info-text">{{ $t('files.autoProcessingTitle') }}:</strong>
              <span class="alert-info-text">{{ $t('files.autoProcessingInfo') }}</span>
            </p>
          </div>

          <button
            :disabled="selectedFiles.length === 0 || isUploading"
            class="btn-primary px-6 py-2 rounded-lg flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed"
            data-testid="btn-upload"
            @click="uploadFiles"
          >
            <CloudArrowUpIcon v-if="!isUploading" class="w-5 h-5" />
            <svg
              v-else
              class="animate-spin h-5 w-5"
              xmlns="http://www.w3.org/2000/svg"
              fill="none"
              viewBox="0 0 24 24"
            >
              <circle
                class="opacity-25"
                cx="12"
                cy="12"
                r="10"
                stroke="currentColor"
                stroke-width="4"
              ></circle>
              <path
                class="opacity-75"
                fill="currentColor"
                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
              ></path>
            </svg>
            {{ isUploading ? $t('files.uploading') : $t('files.uploadAndProcess') }}
          </button>

          <!-- Upload Progress Bar -->
          <Transition name="fade">
            <div v-if="isUploading && uploadProgress" class="mt-4" data-testid="upload-progress">
              <div class="flex items-center justify-between mb-1.5">
                <span class="text-sm txt-secondary">{{ $t('files.uploadProgress') }}</span>
                <span class="text-sm font-medium txt-primary"
                  >{{ uploadProgress.percentage }}%</span
                >
              </div>
              <div class="w-full h-2 rounded-full bg-black/10 dark:bg-white/10 overflow-hidden">
                <div
                  class="h-full rounded-full bg-[var(--brand)] transition-all duration-300 ease-out"
                  :style="{ width: `${uploadProgress.percentage}%` }"
                ></div>
              </div>
              <p class="text-xs txt-secondary mt-1.5">
                {{
                  uploadProgress.percentage === 100
                    ? $t('files.processingFiles')
                    : $t('files.uploadingBytes', {
                        loaded: formatFileSize(uploadProgress.loaded),
                        total: formatFileSize(uploadProgress.total),
                      })
                }}
              </p>
            </div>
          </Transition>
        </div>

        <div class="surface-card p-6" data-testid="section-files-list">
          <div class="flex items-center justify-between mb-6">
            <h2 class="text-xl font-semibold txt-primary">
              {{ $t('files.yourFiles') }}
            </h2>
          </div>

          <div class="flex items-center gap-3 mb-6">
            <div class="flex-1">
              <label class="block text-sm font-medium txt-primary mb-2">
                {{ $t('files.filterByGroup') }}
              </label>
              <select
                v-model="filterGroup"
                class="w-full px-4 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
                data-testid="input-filter-group"
              >
                <option value="">{{ $t('files.allFiles') }}</option>
                <option v-for="group in fileGroups" :key="group.name" :value="group.name">
                  {{ group.name }}
                </option>
              </select>
            </div>
            <button
              class="btn-primary px-6 py-2 rounded-lg mt-7"
              data-testid="btn-filter"
              @click="applyFilter"
            >
              {{ $t('files.filterButton') }}
            </button>
          </div>

          <p class="text-xs txt-secondary mb-4">
            {{ $t('files.filterHelp') }}
          </p>

          <div v-if="selectedFileIds.length > 0" class="mb-4">
            <button
              class="px-4 py-2 rounded-lg bg-red-500 text-white hover:bg-red-600 transition-colors flex items-center gap-2"
              data-testid="btn-delete-selected"
              @click="deleteSelected"
            >
              <TrashIcon class="w-4 h-4" />
              {{ $t('files.deleteSelected') }}
            </button>
          </div>

          <div v-if="isLoading" class="text-center py-12 txt-secondary" data-testid="state-loading">
            <svg
              class="animate-spin h-8 w-8 mx-auto mb-2"
              xmlns="http://www.w3.org/2000/svg"
              fill="none"
              viewBox="0 0 24 24"
            >
              <circle
                class="opacity-25"
                cx="12"
                cy="12"
                r="10"
                stroke="currentColor"
                stroke-width="4"
              ></circle>
              <path
                class="opacity-75"
                fill="currentColor"
                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
              ></path>
            </svg>
            Loading files...
          </div>

          <div
            v-else-if="filteredFiles.length === 0"
            class="text-center py-12 txt-secondary"
            data-testid="state-empty"
          >
            {{ $t('files.noFiles') }}
          </div>

          <div v-else class="overflow-x-auto" data-testid="section-table">
            <table class="w-full">
              <thead>
                <tr class="border-b border-light-border/30 dark:border-dark-border/20">
                  <th class="text-left py-3 px-2 txt-secondary text-xs font-medium w-8">
                    <input
                      type="checkbox"
                      :checked="allSelected"
                      class="checkbox-brand"
                      @change="toggleSelectAll"
                    />
                  </th>
                  <th class="text-left py-3 px-3 txt-secondary text-xs font-medium w-12">ID</th>
                  <th class="text-left py-3 px-3 txt-secondary text-xs font-medium">
                    {{ $t('files.name') }}
                  </th>
                  <th
                    class="text-left py-3 px-3 txt-secondary text-xs font-medium whitespace-nowrap"
                  >
                    {{ $t('files.groupKey') }}
                  </th>
                  <th class="text-left py-3 px-3 txt-secondary text-xs font-medium">
                    {{ $t('files.uploaded') }}
                  </th>
                  <th class="text-left py-3 px-3 txt-secondary text-xs font-medium">
                    {{ $t('files.action') }}
                  </th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="file in paginatedFiles"
                  :key="file.id"
                  class="border-b border-light-border/10 dark:border-dark-border/10 hover:bg-black/5 dark:hover:bg-white/5 transition-colors cursor-pointer"
                  data-testid="item-file"
                  @click="toggleFileSelection(file.id)"
                >
                  <td class="py-3 px-2">
                    <input
                      type="checkbox"
                      :checked="selectedFileIds.includes(file.id)"
                      class="checkbox-brand"
                      @change="toggleFileSelection(file.id)"
                      @click.stop
                    />
                  </td>
                  <td class="py-3 px-3 txt-secondary text-xs align-top">{{ file.id }}</td>
                  <td class="py-3 px-3">
                    <div class="txt-primary text-sm truncate max-w-xs">{{ file.filename }}</div>
                    <div class="flex items-center gap-2 mt-0.5">
                      <span class="txt-secondary text-xs">{{
                        formatFileSize(file.file_size)
                      }}</span>
                      <span class="txt-secondary text-xs">·</span>
                      <span v-if="file.status === 'vectorized'" class="text-xs text-emerald-500">
                        Vectorized<template v-if="fileGroupKeys[file.id]?.qdrantChunks > 0"
                          >: Qdrant</template
                        ><template v-else-if="fileGroupKeys[file.id]?.mariadbChunks > 0"
                          >: MariaDB</template
                        >
                      </span>
                      <span
                        v-else
                        :class="{
                          'text-amber-500': file.status === 'extracted',
                          'txt-secondary': file.status === 'uploaded',
                        }"
                        class="text-xs"
                      >
                        {{ $t(`files.status_${file.status}`) }}
                      </span>
                      <template v-if="file.is_attached">
                        <span class="txt-secondary text-xs">·</span>
                        <span class="text-xs text-blue-400" :title="$t('files.attachedToMessage')">
                          {{ $t('files.attached') }}
                        </span>
                      </template>
                    </div>
                  </td>
                  <!-- GroupKey Column with inline edit -->
                  <td class="py-3 px-3 align-top">
                    <div v-if="editingGroupKey === file.id" class="flex items-center gap-2">
                      <input
                        :ref="
                          (el) => {
                            if (el && editingGroupKey === file.id)
                              groupKeyInput = el as HTMLInputElement
                          }
                        "
                        v-model="tempGroupKey"
                        type="text"
                        class="px-2 py-1 text-xs rounded border border-[var(--brand)] focus:outline-none focus:ring-1 focus:ring-[var(--brand)] bg-transparent txt-primary"
                        placeholder="GroupKey"
                        :data-group-key-input="file.id"
                        @keyup.enter="saveGroupKey(file.id)"
                        @keyup.escape="cancelEditGroupKey"
                      />
                      <button
                        class="icon-ghost icon-ghost--success"
                        title="Save"
                        @click="saveGroupKey(file.id)"
                      >
                        <Icon icon="heroicons:check" class="w-4 h-4" />
                      </button>
                      <button
                        class="icon-ghost icon-ghost--danger"
                        title="Cancel"
                        @click="cancelEditGroupKey"
                      >
                        <Icon icon="heroicons:x-mark" class="w-4 h-4" />
                      </button>
                    </div>
                    <div v-else class="flex items-center gap-2">
                      <span
                        v-if="fileGroupKeys[file.id]?.groupKey"
                        class="pill text-xs font-mono cursor-pointer hover:bg-[var(--brand)]/20 transition-colors"
                        :title="`Click to edit • ${fileGroupKeys[file.id]?.chunks || 0} chunks`"
                        @click="startEditGroupKey(file.id, fileGroupKeys[file.id]?.groupKey)"
                      >
                        {{ fileGroupKeys[file.id].groupKey }}
                      </span>
                      <span
                        v-else-if="fileGroupKeys[file.id]?.isVectorized === false"
                        class="pill pill--warning text-xs"
                        title="Not vectorized - click Re-Vectorize below"
                      >
                        Not vectorized
                      </span>
                      <span
                        v-else-if="fileGroupKeys[file.id] !== undefined"
                        class="pill pill--warning text-xs"
                        title="No vector data found"
                      >
                        —
                      </span>
                      <span v-else class="pill text-xs opacity-50"> Loading... </span>
                      <button
                        v-if="fileGroupKeys[file.id]?.groupKey"
                        class="p-1 rounded hover:bg-[var(--brand)]/10 text-[var(--brand)] opacity-0 group-hover:opacity-100 transition-opacity"
                        title="Edit GroupKey"
                        @click="startEditGroupKey(file.id, fileGroupKeys[file.id]?.groupKey)"
                      >
                        <Icon icon="heroicons:pencil" class="w-3 h-3" />
                      </button>
                    </div>
                  </td>
                  <td class="py-3 px-3 txt-secondary text-xs whitespace-nowrap align-top">
                    {{ file.uploaded_date }}
                  </td>
                  <td class="py-3 px-3 align-top">
                    <div class="flex gap-1">
                      <!-- Migrate to Qdrant (MariaDB data exists, Qdrant empty) -->
                      <button
                        v-if="fileGroupKeys[file.id]?.needsMigration"
                        class="p-1.5 rounded hover:bg-amber-500/10 text-amber-500 transition-colors"
                        :title="$t('files.migrateToQdrant')"
                        data-testid="btn-migrate"
                        @click="migrateToQdrant(file.id)"
                      >
                        <Icon icon="heroicons:arrow-up-tray" class="w-4 h-4" />
                      </button>
                      <!-- Re-Vectorize (no vectors anywhere) -->
                      <button
                        v-else-if="fileGroupKeys[file.id]?.isVectorized === false"
                        class="p-1.5 rounded hover:bg-purple-500/10 text-purple-600 dark:text-purple-400 transition-colors"
                        :title="$t('files.reVectorize')"
                        data-testid="btn-revectorize"
                        @click="reVectorize(file.id)"
                      >
                        <Icon icon="heroicons:arrow-path" class="w-4 h-4" />
                      </button>
                      <button
                        class="p-1.5 rounded hover:bg-[var(--brand)]/10 text-[var(--brand)] transition-colors"
                        title="View content"
                        data-testid="btn-view"
                        @click="viewFileContent(file.id)"
                      >
                        <Icon icon="heroicons:eye" class="w-4 h-4" />
                      </button>
                      <button
                        class="p-1.5 rounded hover:bg-blue-500/10 text-blue-400 transition-colors"
                        title="Download file"
                        data-testid="btn-download"
                        @click="downloadFile(file.id, file.filename)"
                      >
                        <ArrowDownTrayIcon class="w-4 h-4" />
                      </button>
                      <button
                        class="p-1.5 rounded hover:bg-red-500/10 text-red-400 transition-colors"
                        title="Delete file"
                        data-testid="btn-delete"
                        @click="deleteFile(file.id)"
                      >
                        <TrashIcon class="w-4 h-4" />
                      </button>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <div
            v-if="filteredFiles.length > 0"
            class="flex items-center justify-between mt-6"
            data-testid="section-pagination"
          >
            <div class="txt-secondary text-sm">
              {{ $t('files.page') }} {{ currentPage }} ({{ $t('files.showing') }}
              {{ paginatedFiles.length }} {{ $t('files.files') }})
            </div>
            <div class="flex gap-2">
              <button
                :disabled="currentPage === 1"
                class="px-4 py-2 rounded-lg border border-light-border/30 dark:border-dark-border/20 txt-primary hover:bg-black/5 dark:hover:bg-white/5 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                data-testid="btn-prev-page"
                @click="previousPage"
              >
                {{ $t('files.previous') }}
              </button>
              <button
                :disabled="currentPage >= totalPages"
                class="px-4 py-2 rounded-lg border border-light-border/30 dark:border-dark-border/20 txt-primary hover:bg-black/5 dark:hover:bg-white/5 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                data-testid="btn-next-page"
                @click="nextPage"
              >
                {{ $t('files.next') }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- File Content Modal -->
    <FileContentModal :is-open="isModalOpen" :file-id="selectedFileId" @close="closeModal" />
    <ShareModal
      :is-open="isShareModalOpen"
      :file-id="shareFileId"
      :filename="shareFileName"
      @close="closeShareModal"
      @shared="handleShared"
      @unshared="handleUnshared"
    />

    <!-- Confirm Delete Dialog (Single File) -->
    <ConfirmDialog
      :is-open="isConfirmOpen"
      title="Delete File"
      message="Are you sure you want to delete this file? This action cannot be undone."
      confirm-text="Delete"
      cancel-text="Cancel"
      variant="danger"
      @confirm="confirmDelete"
      @cancel="cancelDelete"
    />

    <!-- Confirm Delete Selected Dialog (Multiple Files) -->
    <Teleport to="body">
      <Transition name="dialog-fade">
        <div
          v-if="isDeleteSelectedOpen"
          class="fixed inset-0 z-50 flex items-center justify-center p-4"
          data-testid="modal-delete-selected-root"
          @click.self="cancelDeleteSelected"
        >
          <!-- Backdrop -->
          <div
            class="absolute inset-0 bg-black/50 dark:bg-black/70 backdrop-blur-sm"
            data-testid="modal-delete-selected-backdrop"
          ></div>

          <!-- Dialog -->
          <div
            class="relative surface-card rounded-xl shadow-2xl max-w-md w-full p-6 space-y-4 animate-scale-in"
            role="dialog"
            aria-modal="true"
            data-testid="modal-delete-selected"
          >
            <!-- Icon and Title -->
            <div class="flex items-center gap-3">
              <div
                class="flex-shrink-0 w-10 h-10 rounded-full flex items-center justify-center bg-red-500/10 text-red-500"
              >
                <ExclamationTriangleIcon class="w-6 h-6" />
              </div>
              <h3 class="text-lg font-semibold txt-primary">
                {{ $t('files.deleteSelectedConfirmTitle') }}
              </h3>
            </div>

            <!-- Message -->
            <p class="txt-secondary text-sm leading-relaxed">
              {{ $t('files.deleteSelectedConfirmMessage', { count: selectedFileIds.length }) }}
            </p>

            <!-- Actions -->
            <div class="flex gap-3 justify-end pt-2">
              <button
                class="px-4 py-2 rounded-lg border border-light-border/30 dark:border-dark-border/20 txt-secondary hover:bg-black/5 dark:hover:bg-white/5 transition-all text-sm font-medium"
                data-testid="btn-delete-selected-cancel"
                @click="cancelDeleteSelected"
              >
                {{ $t('common.cancel') }}
              </button>
              <button
                class="px-4 py-2 rounded-lg bg-red-500 hover:bg-red-600 text-white text-sm font-medium transition-all"
                data-testid="btn-delete-selected-confirm"
                @click="confirmDeleteSelected"
              >
                {{ $t('common.delete') }}
              </button>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>
  </MainLayout>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'
import MainLayout from '@/components/MainLayout.vue'
import FileContentModal from '@/components/FileContentModal.vue'
import ShareModal from '@/components/ShareModal.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import StorageQuotaWidget from '@/components/StorageQuotaWidget.vue'
import { Icon } from '@iconify/vue'
import {
  CloudArrowUpIcon,
  TrashIcon,
  ArrowDownTrayIcon,
  XMarkIcon,
  ExclamationTriangleIcon,
} from '@heroicons/vue/24/outline'
import filesService, { type FileItem, type UploadProgress } from '@/services/filesService'
import { useNotification } from '@/composables/useNotification'
import { useFilePersistence } from '@/composables/useInputPersistence'

const { t } = useI18n()
const { success: showSuccess, error: showError, info: showInfo } = useNotification()

// File persistence - save selected files metadata
const { saveFileMetadata, loadFileMetadata, clearFiles } = useFilePersistence('files_upload')

const storageWidget = ref<InstanceType<typeof StorageQuotaWidget> | null>(null)

const groupKeyword = ref('')
const selectedGroup = ref('')
// File upload state (removed processLevel - always vectorize)
const selectedFiles = ref<File[]>([])
const filterGroup = ref('')
const files = ref<FileItem[]>([])
const fileGroups = ref<Array<{ name: string; count: number }>>([])
const selectedFileIds = ref<number[]>([])
const currentPage = ref(1)
const itemsPerPage = 10
const isUploading = ref(false)
const uploadProgress = ref<UploadProgress | null>(null)
const isLoading = ref(false)

// Drag & Drop state
const isDragging = ref(false)
const dragCounter = ref(0)

// GroupKey management
const fileGroupKeys = ref<
  Record<
    number,
    {
      groupKey: string | null
      isVectorized: boolean
      chunks: number
      status: string
      needsMigration: boolean
      mariadbChunks: number
      qdrantChunks: number
    }
  >
>({})
// Track recently uploaded file IDs to prevent race condition with loadAllFileGroupKeys
const recentlyUploadedFileIds = ref<Set<number>>(new Set())
const editingGroupKey = ref<number | null>(null)
const tempGroupKey = ref('')
const groupKeyInput = ref<HTMLInputElement | null>(null)

// Modal state
const isModalOpen = ref(false)
const selectedFileId = ref<number | null>(null)

// Share modal state
const isShareModalOpen = ref(false)
const shareFileId = ref<number | null>(null)
const shareFileName = ref('')

// Confirm dialog state
const isConfirmOpen = ref(false)
const fileToDelete = ref<number | null>(null)
const isDeleteSelectedOpen = ref(false)
const totalCount = ref(0)

const filteredFiles = computed(() => files.value)

const totalPages = computed(() => {
  return Math.ceil(totalCount.value / itemsPerPage)
})

const paginatedFiles = computed(() => files.value)

const allSelected = computed(() => {
  return (
    paginatedFiles.value.length > 0 &&
    paginatedFiles.value.every((file) => selectedFileIds.value.includes(file.id))
  )
})

const handleFileSelect = (event: Event) => {
  const target = event.target as HTMLInputElement
  if (target.files) {
    selectedFiles.value = Array.from(target.files)
    // Save metadata for potential recovery after auth
    saveFileMetadata(selectedFiles.value)
  }
}

const removeSelectedFile = (index: number) => {
  if (isUploading.value) {
    return
  }
  selectedFiles.value.splice(index, 1)
}

// Drag & Drop handlers
const handleDragEnter = (event: DragEvent) => {
  // Check if dragging files
  if (event.dataTransfer?.types.includes('Files')) {
    dragCounter.value++
    isDragging.value = true
  }
}

const handleDragOver = (event: DragEvent) => {
  // Just prevent default to allow drop, don't change state here
  event.preventDefault()
}

const handleDragLeave = (_event: DragEvent) => {
  dragCounter.value--
  // Only hide overlay when truly leaving the area
  if (dragCounter.value <= 0) {
    dragCounter.value = 0
    isDragging.value = false
  }
}

const handleDrop = async (event: DragEvent) => {
  dragCounter.value = 0
  isDragging.value = false

  const files = event.dataTransfer?.files
  if (files && files.length > 0) {
    // Add dropped files to selectedFiles array
    selectedFiles.value = [...selectedFiles.value, ...Array.from(files)]
    showSuccess(t('files.filesAddedToQueue', { count: files.length }))
    // Save metadata for potential recovery after auth
    saveFileMetadata(selectedFiles.value)
  }
}

const getFileIcon = (filename: string): string => {
  const ext = filename.split('.').pop()?.toLowerCase() || ''

  const iconMap: Record<string, string> = {
    pdf: 'heroicons:document-text',
    docx: 'heroicons:document-text',
    doc: 'heroicons:document-text',
    txt: 'heroicons:document-text',
    jpg: 'heroicons:photo',
    jpeg: 'heroicons:photo',
    png: 'heroicons:photo',
    gif: 'heroicons:photo',
    webp: 'heroicons:photo',
    mp3: 'heroicons:musical-note',
    mp4: 'heroicons:film',
    xlsx: 'heroicons:table-cells',
    csv: 'heroicons:table-cells',
  }

  return iconMap[ext] || 'heroicons:document'
}

const uploadFiles = async () => {
  if (selectedFiles.value.length === 0) {
    showError('Please select files to upload')
    return
  }

  const groupKey = selectedGroup.value || groupKeyword.value || 'DEFAULT'

  isUploading.value = true
  uploadProgress.value = { loaded: 0, total: 0, percentage: 0 }

  try {
    const result = await filesService.uploadFiles({
      files: selectedFiles.value,
      groupKey,
      processLevel: 'vectorize', // Always vectorize for optimal RAG performance
      onProgress: (progress) => {
        uploadProgress.value = progress
      },
    })

    if (result.success) {
      showSuccess(`Successfully uploaded ${result.files.length} file(s)`)

      // Pre-populate fileGroupKeys from upload response to avoid Qdrant lookup race
      result.files.forEach((file) => {
        const details = `${file.filename}: ${file.extracted_text_length} chars extracted, ${file.chunks_created || 0} chunks created`
        console.log(details)

        // Immediately set group key from upload response — this is authoritative
        fileGroupKeys.value[file.id] = {
          groupKey: file.group_key || groupKey,
          isVectorized: file.vectorized ?? (file.chunks_created ?? 0) > 0,
          chunks: file.chunks_created || 0,
          status: file.vectorized ? 'vectorized' : 'processed',
          needsMigration: false,
          mariadbChunks: 0,
          qdrantChunks: file.chunks_created || 0,
        }
        // Mark as recently uploaded so loadAllFileGroupKeys won't overwrite
        recentlyUploadedFileIds.value.add(file.id)
      })
      // Clear recently uploaded markers after a delay (allow Qdrant replication to settle)
      setTimeout(() => recentlyUploadedFileIds.value.clear(), 30000)

      // Clear form
      selectedFiles.value = []
      groupKeyword.value = ''
      selectedGroup.value = ''
      // Clear persisted file metadata after successful upload
      clearFiles()

      // Reload files list AND storage widget
      await loadFiles()
      await loadFileGroups()
      if (storageWidget.value) {
        await storageWidget.value.refresh()
      }
    } else {
      // Show errors
      result.errors.forEach((error) => {
        showError(`${error.filename}: ${error.error}`)
      })
    }
  } catch (error) {
    console.error('Upload error:', error)
    showError('Failed to upload files: ' + (error as Error).message)
  } finally {
    isUploading.value = false
    uploadProgress.value = null
  }
}

const handleUpgrade = () => {
  // Navigate to pricing/subscription page
  showInfo('Upgrade functionality coming soon! Contact support@synaplan.com for premium plans.')
}

const loadFiles = async (page = currentPage.value) => {
  isLoading.value = true

  try {
    const response = await filesService.listFiles(
      filterGroup.value || undefined,
      page,
      itemsPerPage
    )

    files.value = response.files
    totalCount.value = response.pagination.total
    currentPage.value = response.pagination.page

    // Load groupKeys for all loaded files
    await loadAllFileGroupKeys()
  } catch (error: any) {
    console.error('Failed to load files:', error)

    // Handle 401 (not authenticated) gracefully
    if (error.message && error.message.includes('401')) {
      // Silently fail - router should redirect to login
      files.value = []
      totalCount.value = 0
    } else {
      showError('Failed to load files')
    }
  } finally {
    isLoading.value = false
  }
}

const loadFileGroups = async () => {
  try {
    fileGroups.value = await filesService.getFileGroups()
  } catch (error: any) {
    console.error('Failed to load file groups:', error)

    // Handle 401 (not authenticated) gracefully
    if (error.message && error.message.includes('401')) {
      // Silently fail - router should redirect to login
      fileGroups.value = []
    }
  }
}

const applyFilter = () => {
  currentPage.value = 1
  loadFiles(1)
}

const toggleFileSelection = (fileId: number) => {
  const index = selectedFileIds.value.indexOf(fileId)
  if (index > -1) {
    selectedFileIds.value.splice(index, 1)
  } else {
    selectedFileIds.value.push(fileId)
  }
}

const toggleSelectAll = () => {
  if (allSelected.value) {
    paginatedFiles.value.forEach((file) => {
      const index = selectedFileIds.value.indexOf(file.id)
      if (index > -1) {
        selectedFileIds.value.splice(index, 1)
      }
    })
  } else {
    paginatedFiles.value.forEach((file) => {
      if (!selectedFileIds.value.includes(file.id)) {
        selectedFileIds.value.push(file.id)
      }
    })
  }
}

const deleteSelected = () => {
  if (selectedFileIds.value.length === 0) return
  isDeleteSelectedOpen.value = true
}

const confirmDeleteSelected = async () => {
  if (selectedFileIds.value.length === 0) return

  try {
    const results = await filesService.deleteMultipleFiles(selectedFileIds.value)

    const successCount = results.filter((r) => r.success).length
    const failCount = results.filter((r) => !r.success).length

    if (successCount > 0) {
      showSuccess(`Deleted ${successCount} file(s)`)
    }

    if (failCount > 0) {
      showError(`Failed to delete ${failCount} file(s)`)
    }

    selectedFileIds.value = []
    await loadFiles()
    await loadFileGroups()
    if (storageWidget.value) {
      await storageWidget.value.refresh()
    }
  } catch (error) {
    console.error('Delete error:', error)
    showError('Failed to delete files')
  } finally {
    isDeleteSelectedOpen.value = false
  }
}

const cancelDeleteSelected = () => {
  isDeleteSelectedOpen.value = false
}

const deleteFile = (fileId: number) => {
  fileToDelete.value = fileId
  isConfirmOpen.value = true
}

const confirmDelete = async () => {
  if (!fileToDelete.value) return

  try {
    await filesService.deleteFile(fileToDelete.value)
    showSuccess('File deleted successfully')
    await loadFiles()
    await loadFileGroups()
    if (storageWidget.value) {
      await storageWidget.value.refresh()
    }
  } catch (error) {
    console.error('Delete error:', error)
    showError('Failed to delete file')
  } finally {
    isConfirmOpen.value = false
    fileToDelete.value = null
  }
}

const cancelDelete = () => {
  isConfirmOpen.value = false
  fileToDelete.value = null
}

const viewFileContent = (fileId: number) => {
  selectedFileId.value = fileId
  isModalOpen.value = true
}

const closeModal = () => {
  isModalOpen.value = false
  selectedFileId.value = null
}

const downloadFile = async (fileId: number, filename: string) => {
  try {
    await filesService.downloadFile(fileId, filename)
    showSuccess('File downloaded successfully')
  } catch (error) {
    console.error('Download error:', error)
    showError('Failed to download file')
  }
}

const closeShareModal = () => {
  isShareModalOpen.value = false
  shareFileId.value = null
  shareFileName.value = ''
}

const handleShared = async () => {
  showSuccess('File is now publicly accessible')
  await loadFiles()
}

const handleUnshared = async () => {
  showSuccess('Public access revoked')
  await loadFiles()
}

const nextPage = () => {
  if (currentPage.value < totalPages.value) {
    loadFiles(currentPage.value + 1)
  }
}

const previousPage = () => {
  if (currentPage.value > 1) {
    loadFiles(currentPage.value - 1)
  }
}

const formatFileSize = (bytes: number): string => {
  if (bytes < 1024) return bytes + ' B'
  if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB'
  if (bytes < 1024 * 1024 * 1024) return (bytes / (1024 * 1024)).toFixed(1) + ' MB'
  return (bytes / (1024 * 1024 * 1024)).toFixed(1) + ' GB'
}

/**
 * Load groupKey for a file
 */
const loadFileGroupKey = async (fileId: number) => {
  // Skip if recently uploaded — upload response data is authoritative and fresher
  // than what the API might return (Qdrant replication lag)
  if (recentlyUploadedFileIds.value.has(fileId)) {
    return
  }

  try {
    const timeoutPromise = new Promise<never>((_, reject) =>
      setTimeout(() => reject(new Error('Timeout')), 8000)
    )
    const result = await Promise.race([filesService.getFileGroupKey(fileId), timeoutPromise])
    fileGroupKeys.value[fileId] = {
      groupKey: result.groupKey,
      isVectorized: result.isVectorized,
      chunks: result.chunks,
      status: result.status,
      needsMigration: result.needsMigration ?? false,
      mariadbChunks: result.mariadbChunks ?? 0,
      qdrantChunks: result.qdrantChunks ?? 0,
    }
  } catch (err: any) {
    console.error(`Failed to load groupKey for file ${fileId}:`, err)
    // Preserve existing data (e.g. from upload response) instead of overwriting with error state
    const existing = fileGroupKeys.value[fileId]
    if (!existing || !existing.groupKey) {
      fileGroupKeys.value[fileId] = {
        groupKey: null,
        isVectorized: false,
        chunks: 0,
        status: 'unknown',
        needsMigration: false,
        mariadbChunks: 0,
        qdrantChunks: 0,
      }
    }
  }
}

/**
 * Load groupKeys for all visible files — parallel for speed
 */
const loadAllFileGroupKeys = async () => {
  await Promise.allSettled(paginatedFiles.value.map((file) => loadFileGroupKey(file.id)))
}

/**
 * Start editing groupKey
 */
const startEditGroupKey = async (fileId: number, currentGroupKey: string | null) => {
  editingGroupKey.value = fileId
  tempGroupKey.value = currentGroupKey || ''
  await nextTick()
  // Find the input element for this specific file
  const inputElement = document.querySelector(
    `[data-group-key-input="${fileId}"]`
  ) as HTMLInputElement
  if (inputElement) {
    inputElement.focus()
  } else if (groupKeyInput.value) {
    groupKeyInput.value.focus()
  }
}

/**
 * Cancel editing groupKey
 */
const cancelEditGroupKey = () => {
  editingGroupKey.value = null
  tempGroupKey.value = ''
}

/**
 * Save groupKey
 */
const saveGroupKey = async (fileId: number) => {
  if (!tempGroupKey.value.trim()) {
    showError('GroupKey cannot be empty')
    return
  }

  try {
    await filesService.updateFileGroupKey(fileId, tempGroupKey.value.trim())
    showSuccess('GroupKey updated successfully!')

    // Reload the groupKey for this file
    await loadFileGroupKey(fileId)

    cancelEditGroupKey()

    // Reload file groups to update the dropdown
    await loadFileGroups()
  } catch (err: any) {
    const errorMessage = err.message || 'Failed to update groupKey'
    showError(errorMessage)
  }
}

/**
 * Re-vectorize a file
 */
const reVectorize = async (fileId: number) => {
  const groupKey = tempGroupKey.value || 'DEFAULT'

  try {
    showInfo('Re-vectorizing file... This may take a moment.')

    const result = await filesService.reVectorizeFile(fileId, groupKey)

    showSuccess(
      `File re-vectorized! Created ${result.chunksCreated} chunks from ${result.extractedTextLength} characters.`
    )

    // Reload the groupKey for this file
    await loadFileGroupKey(fileId)

    // Reload file groups
    await loadFileGroups()
  } catch (err: any) {
    const errorMessage = err.message || 'Failed to re-vectorize file'
    showError(errorMessage)
  }
}

/**
 * Migrate file vectors from MariaDB to Qdrant
 */
const migrateToQdrant = async (fileId: number) => {
  try {
    showInfo(t('files.migratingToQdrant'))

    const result = await filesService.migrateFileToQdrant(fileId)

    if (result.errors > 0) {
      showError(t('files.migrationPartial', { migrated: result.migrated, errors: result.errors }))
    } else {
      showSuccess(t('files.migrationSuccess', { count: result.migrated }))
    }

    await loadFileGroupKey(fileId)
    await loadFileGroups()
  } catch (err: any) {
    showError(err.message || t('files.migrationFailed'))
  }
}

// Load initial data
onMounted(async () => {
  await loadFiles()
  await loadFileGroups()

  // Check for persisted file metadata (from before potential auth refresh)
  const persistedFiles = loadFileMetadata()
  if (persistedFiles && persistedFiles.length > 0) {
    showInfo(
      t('files.filesLostAfterReload', {
        count: persistedFiles.length,
        names: persistedFiles.map((f) => f.name).join(', '),
      })
    )
    // Clear the persisted data now that we've shown the notice
    clearFiles()
  }
})
</script>

<style scoped>
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}

.dialog-fade-enter-active,
.dialog-fade-leave-active {
  transition: opacity 0.2s ease;
}

.dialog-fade-enter-from,
.dialog-fade-leave-to {
  opacity: 0;
}

.animate-scale-in {
  animation: scale-in 0.2s ease-out;
}

@keyframes scale-in {
  from {
    opacity: 0;
    transform: scale(0.95);
  }
  to {
    opacity: 1;
    transform: scale(1);
  }
}
</style>
