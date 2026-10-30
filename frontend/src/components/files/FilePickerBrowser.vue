<template>
  <div>
    <div
      role="tablist"
      :aria-label="$t('fileSelection.title')"
      class="flex gap-1 p-1 mb-3 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20"
      data-testid="file-selection-scope"
    >
      <button
        type="button"
        role="tab"
        class="flex-1 px-3 py-2 rounded-xl text-sm font-medium"
        :class="scope === 'mine' ? 'btn-primary' : 'txt-secondary hover:txt-primary'"
        :aria-selected="scope === 'mine'"
        data-testid="tab-file-selection-mine"
        @click="scope = 'mine'"
        @keydown="onTabKey"
      >
        {{ $t('fileSelection.tabMine') }}
      </button>
      <button
        type="button"
        role="tab"
        class="flex-1 px-3 py-2 rounded-xl text-sm font-medium inline-flex items-center justify-center gap-1.5"
        :class="scope === 'ai' ? 'btn-primary' : 'txt-secondary hover:txt-primary'"
        :aria-selected="scope === 'ai'"
        data-testid="tab-file-selection-ai"
        @click="scope = 'ai'"
        @keydown="onTabKey"
      >
        {{ $t('fileSelection.tabAiCreated') }}
        <span v-if="aiCount > 0" class="text-xs opacity-80">{{ aiCount }}</span>
      </button>
    </div>

    <div class="flex gap-2 sm:gap-3">
      <input
        v-model="search"
        type="text"
        :placeholder="$t('fileSelection.searchPlaceholder')"
        class="flex-1 min-w-0 px-3 py-1.5 sm:px-4 sm:py-2 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
        data-testid="input-file-selection-search"
      />
      <select
        v-model="filterStatus"
        class="shrink-0 min-w-[7rem] px-2 py-1.5 sm:px-4 sm:py-2 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
        data-testid="select-file-selection-status"
      >
        <option value="all">{{ $t('fileSelection.allStatuses') }}</option>
        <option value="vectorized">{{ $t('files.status_vectorized') }}</option>
        <option value="extracted">{{ $t('files.status_extracted') }}</option>
        <option value="uploaded">{{ $t('files.status_uploaded') }}</option>
      </select>
    </div>
    <div
      class="flex items-center gap-1.5 mt-2 overflow-x-auto scroll-thin"
      data-testid="file-selection-type-chips"
    >
      <button
        v-for="chip in typeChips"
        :key="chip.value"
        type="button"
        class="shrink-0 px-2.5 py-1 rounded-full text-xs font-medium border transition-colors"
        :class="
          filterType === chip.value
            ? 'border-[var(--brand)] bg-[var(--brand)]/10 text-[var(--brand)]'
            : 'border-light-border/30 dark:border-dark-border/15 txt-secondary hover:text-[var(--brand)] hover:border-[var(--brand)]/40'
        "
        :data-testid="`file-selection-type-${chip.value}`"
        @click="setType(chip.value)"
      >
        {{ $t(chip.labelKey) }}
      </button>
    </div>

    <div class="mt-3">
      <div v-if="loading" class="flex items-center justify-center py-12">
        <Icon icon="mdi:loading" class="w-8 h-8 animate-spin txt-secondary" />
      </div>

      <div v-else-if="visibleFiles.length === 0 && !hasMore" class="text-center py-12">
        <p class="text-sm txt-secondary mb-4">{{ emptyMessage }}</p>
        <button
          v-if="scope === 'mine' && !hasActiveFilter"
          type="button"
          class="btn-primary px-4 py-2.5 text-sm font-medium"
          data-testid="btn-file-selection-empty-upload"
          @click="emit('upload')"
        >
          {{ $t('files.aiCreated.mineEmptyAction') }}
        </button>
        <button
          v-else-if="scope === 'ai' && !hasActiveFilter"
          type="button"
          class="btn-secondary px-4 py-2.5 text-sm font-medium"
          data-testid="btn-file-selection-empty-mine"
          @click="scope = 'mine'"
        >
          {{ $t('files.aiCreated.emptyAction') }}
        </button>
      </div>

      <div v-else class="space-y-1 sm:space-y-2">
        <div
          v-for="file in visibleFiles"
          :key="file.id"
          class="group flex items-center gap-2 sm:gap-4 px-2 py-2 sm:p-4 rounded-lg sm:rounded-xl border cursor-pointer transition-all"
          :class="
            selectedIds.includes(file.id)
              ? 'border-[var(--brand)]/40 bg-[var(--brand)]/[0.04] ring-1 ring-[var(--brand)]/30'
              : 'border-light-border/30 dark:border-dark-border/20 hover:bg-[var(--bg-chip)]'
          "
          @click="emit('toggle', file)"
        >
          <input
            type="checkbox"
            :checked="props.selectedIds.includes(file.id)"
            class="w-4 h-4 sm:w-5 sm:h-5 rounded border-light-border/30 dark:border-dark-border/20 text-[var(--brand)] flex-shrink-0"
            @click.stop
            @change="emit('toggle', file)"
          />
          <div
            class="w-10 h-10 sm:w-12 sm:h-12 flex-shrink-0 rounded-lg overflow-hidden bg-[var(--bg-chip)] flex items-center justify-center"
          >
            <img
              v-if="isImageFile(file.file_type)"
              :src="downloadUrl(file.id)"
              :alt="file.filename"
              class="w-full h-full object-cover"
              loading="lazy"
            />
            <Icon v-else :icon="fileIcon(file.file_type)" class="w-6 h-6 txt-secondary" />
          </div>
          <div class="flex-1 min-w-0">
            <div class="text-sm sm:text-base font-medium txt-primary truncate">
              {{ file.filename }}
            </div>
            <div class="text-xs sm:text-sm txt-secondary flex items-center gap-1.5 mt-0.5 min-w-0">
              <span class="shrink-0">{{ formatFileSize(file.file_size) }}</span>
              <FileSourceBadge v-if="scope === 'ai'" :source="file.source" />
            </div>
          </div>
          <div
            class="flex items-center gap-0.5 sm:opacity-0 sm:group-hover:opacity-100 transition-opacity flex-shrink-0"
            @click.stop
          >
            <button
              v-if="
                !['vectorized', 'extracting', 'vectorizing'].includes(file.status) &&
                !skipsExtraction(extensionOf(file.filename) || file.file_type)
              "
              type="button"
              class="p-1 sm:p-1.5 rounded-xl hover:bg-purple-500/10 text-purple-600 dark:text-purple-400 transition-colors"
              :title="$t('fileSelection.reVectorize')"
              data-testid="btn-file-revectorize"
              @click="emit('revectorize', file.id)"
            >
              <Icon icon="heroicons:arrow-path" class="w-3.5 h-3.5 sm:w-4 sm:h-4" />
            </button>
            <button
              type="button"
              class="p-1 sm:p-1.5 rounded-xl hover:bg-[var(--brand)]/10 text-[var(--brand)] transition-colors"
              :title="$t('fileSelection.viewContent')"
              data-testid="btn-file-view"
              @click="emit('view', file.id)"
            >
              <Icon icon="heroicons:eye" class="w-3.5 h-3.5 sm:w-4 sm:h-4" />
            </button>
            <button
              type="button"
              class="hidden sm:block p-1.5 rounded-xl hover:bg-blue-500/10 text-blue-400 transition-colors"
              :title="$t('fileSelection.download')"
              data-testid="btn-file-download"
              @click="emit('download', file.id, file.filename)"
            >
              <ArrowDownTrayIcon class="w-4 h-4" />
            </button>
            <button
              type="button"
              class="p-1 sm:p-1.5 rounded-xl hover:bg-red-500/10 text-red-400 transition-colors"
              :title="$t('fileSelection.deleteFile')"
              data-testid="btn-file-delete"
              @click="emit('delete', file.id)"
            >
              <TrashIcon class="w-3.5 h-3.5 sm:w-4 sm:h-4" />
            </button>
          </div>
        </div>
        <button
          v-if="hasMore"
          type="button"
          class="btn-secondary w-full px-4 py-2.5 text-sm font-medium mt-2"
          :disabled="loadingMore"
          data-testid="btn-file-selection-more"
          @click="loadMore"
        >
          {{ $t('files.aiCreated.loadMore') }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ArrowDownTrayIcon, TrashIcon } from '@heroicons/vue/24/outline'
import { Icon } from '@iconify/vue'
import filesService, { type FileItem } from '@/services/filesService'
import { getApiBaseUrl } from '@/services/api/httpClient'
import { useMediaSrc } from '@/services/api/mediaAuth'
import { extensionOf, skipsExtraction } from '@/services/filePreview'
import FileSourceBadge from '@/components/files/FileSourceBadge.vue'
import { PICKER_TYPE_QUERIES, useFilePickerList } from '@/composables/useFilePickerList'

const props = defineProps<{ selectedIds: number[] }>()
const emit = defineEmits<{
  toggle: [file: FileItem]
  view: [id: number]
  download: [id: number, filename: string]
  delete: [id: number]
  revectorize: [id: number]
  upload: []
}>()

const { t } = useI18n()
const { mediaSrc } = useMediaSrc()
const filterStatus = ref('all')
const filterType = ref('all')
const aiCount = ref(0)
const typeChips = [
  { value: 'all', labelKey: 'fileSelection.typeAll' },
  { value: 'docs', labelKey: 'fileSelection.typeDocs' },
  { value: 'images', labelKey: 'fileSelection.typeImages' },
  { value: 'video', labelKey: 'fileSelection.typeVideo' },
  { value: 'audio', labelKey: 'fileSelection.typeAudio' },
]

const {
  scope,
  search,
  fileType,
  files,
  loading,
  loadingMore,
  hasMore,
  reload,
  loadMore,
  resetToMine: resetListToMine,
} = useFilePickerList()

/** Back to "My files" with no search, type or status filter, newest first. */
const resetToMine = (): Promise<void> => {
  filterType.value = 'all'
  filterStatus.value = 'all'
  return resetListToMine()
}

const visibleFiles = computed(() => {
  if (filterStatus.value === 'all') return files.value
  return files.value.filter((file) => file.status === filterStatus.value)
})

const hasActiveFilter = computed(
  () => search.value.trim() !== '' || filterType.value !== 'all' || filterStatus.value !== 'all'
)

const emptyMessage = computed(() => {
  if (hasActiveFilter.value) return t('files.noFiles')
  return scope.value === 'ai' ? t('files.aiCreated.empty') : t('files.aiCreated.mineEmpty')
})

const setType = (value: string) => {
  filterType.value = value
  fileType.value = PICKER_TYPE_QUERIES[value] ?? ''
}

const onTabKey = (event: KeyboardEvent) => {
  if (event.key !== 'ArrowRight' && event.key !== 'ArrowLeft') return
  event.preventDefault()
  scope.value = scope.value === 'mine' ? 'ai' : 'mine'
}

const downloadUrl = (fileId: number): string =>
  mediaSrc(`${getApiBaseUrl()}/api/v1/files/${fileId}/download`)

const isImageFile = (fileTypeName: string): boolean => {
  const type = fileTypeName.toLowerCase()
  return type.includes('image') || /jpg|jpeg|png|gif|webp/.test(type)
}

const fileIcon = (fileTypeName: string): string => {
  const type = fileTypeName.toLowerCase()
  if (type.includes('pdf')) return 'mdi:file-pdf-box'
  if (type.includes('doc')) return 'mdi:file-word'
  if (type.includes('xls') || type.includes('csv')) return 'mdi:file-excel'
  if (isImageFile(type)) return 'mdi:file-image'
  if (type.includes('audio') || /mp3|wav|ogg/.test(type)) return 'mdi:file-music'
  if (type.includes('video') || /mp4|avi|mov/.test(type)) return 'mdi:file-video'
  return 'mdi:file'
}

const formatFileSize = (bytes: number): string => {
  if (bytes < 1024) return bytes + ' B'
  if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB'
  if (bytes < 1024 * 1024 * 1024) return (bytes / (1024 * 1024)).toFixed(1) + ' MB'
  return (bytes / (1024 * 1024 * 1024)).toFixed(1) + ' GB'
}

onMounted(async () => {
  void reload()
  try {
    const facets = await filesService.getFacets()
    aiCount.value = (facets.source.generated ?? 0) + (facets.source.compute ?? 0)
  } catch {
    aiCount.value = 0
  }
})

defineExpose({
  files,
  reload,
  resetToMine,
  scope,
})
</script>
