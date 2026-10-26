<script setup lang="ts">
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import FolderMoveMenu from '@/components/FolderMoveMenu.vue'
import FileMakeSearchableButton from '@/components/files/FileMakeSearchableButton.vue'
import FileRowActions, { type FileRowAction } from '@/components/files/FileRowActions.vue'
import FileSourceBadge from '@/components/files/FileSourceBadge.vue'
import FileVectorPill from '@/components/files/FileVectorPill.vue'
import { previewBadgeClass, previewIconForName } from '@/services/filePreview'
import type { FileItem } from '@/services/filesService'
import { fileDisplayName, vectorStateOf } from '@/utils/fileDisplayName'

withDefaults(
  defineProps<{
    files: FileItem[]
    selectedIds: number[]
    allSelected: boolean
    /** The last row drops its divider when a footer follows it. */
    borderlessLast: boolean
    showFolderLink?: boolean
    organize: boolean
    currentFolder: string | null
    folders: Array<{ name: string; count: number }>
    folderMenuOpen: number | null
    describing: (id: number) => boolean
    actionsFor: (file: FileItem) => FileRowAction[]
  }>(),
  { showFolderLink: false }
)

const emit = defineEmits<{
  toggleAll: []
  toggleOne: [id: number]
  openChat: [file: FileItem]
  enterFolder: [name: string]
  toggleMenu: [id: number]
  move: [id: number, folder: string]
  remove: [id: number]
  describe: [file: FileItem]
}>()

const { t, locale } = useI18n()

function nameOf(file: FileItem): string {
  return fileDisplayName(file, (key, values) => t(key, values as never), locale.value)
}

function sizeOf(bytes: number): string {
  if (bytes < 1024) return bytes + ' B'
  if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB'
  if (bytes < 1024 * 1024 * 1024) return (bytes / (1024 * 1024)).toFixed(1) + ' MB'
  return (bytes / (1024 * 1024 * 1024)).toFixed(1) + ' GB'
}
</script>

<template>
  <table class="hidden w-full table-fixed sm:table" data-testid="files-table">
    <thead>
      <tr class="border-b border-light-border/30 dark:border-dark-border/8">
        <th class="w-9 p-0 align-middle">
          <div class="flex items-center justify-center">
            <input
              type="checkbox"
              :checked="allSelected"
              class="checkbox-brand"
              @change="emit('toggleAll')"
            />
          </div>
        </th>
        <th class="px-3 py-2.5 text-left text-xs font-medium txt-secondary">
          {{ t('files.name') }}
        </th>
        <th
          class="hidden w-24 px-3 py-2.5 text-left text-xs font-medium txt-secondary md:table-cell"
        >
          {{ t('files.size') }}
        </th>
        <th
          class="hidden w-28 px-3 py-2.5 text-left text-xs font-medium txt-secondary lg:table-cell"
        >
          {{ t('files.uploaded') }}
        </th>
        <th class="w-52"></th>
      </tr>
    </thead>
    <tbody>
      <tr
        v-for="(file, index) in files"
        :key="file.id"
        class="group transition-colors hover:bg-black/[0.03] dark:hover:bg-white/[0.03]"
        :class="
          borderlessLast && index === files.length - 1
            ? ''
            : 'border-b border-light-border/10 dark:border-dark-border/5'
        "
        data-testid="item-file"
      >
        <td class="px-2 py-2.5">
          <input
            type="checkbox"
            :checked="selectedIds.includes(file.id)"
            class="checkbox-brand"
            @change="emit('toggleOne', file.id)"
          />
        </td>
        <td class="max-w-0 px-3 py-2.5">
          <div class="flex min-w-0 items-center gap-3">
            <div
              class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg"
              :class="previewBadgeClass()"
            >
              <Icon :icon="previewIconForName(file.filename)" class="h-4 w-4" />
            </div>
            <div class="flex min-w-0 flex-col gap-1">
              <span class="truncate text-sm txt-primary" :title="file.filename">{{
                nameOf(file)
              }}</span>
              <div class="flex min-w-0 flex-wrap items-center gap-2 overflow-hidden">
                <button
                  v-if="file.source && file.chat_id"
                  type="button"
                  class="inline-flex max-w-full min-w-0 rounded-xl px-2 py-0.5 hover:bg-black/5 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-[var(--brand)] dark:hover:bg-white/5"
                  :title="t('files.openInChat')"
                  :data-testid="`btn-open-in-chat-${file.id}`"
                  @click="emit('openChat', file)"
                >
                  <FileSourceBadge :source="file.source" />
                </button>
                <FileSourceBadge v-else-if="file.source" :source="file.source" />
                <FileVectorPill
                  :state="vectorStateOf(file)"
                  :chunk-count="file.chunk_count ?? file.chunks ?? 0"
                  :group-key="file.group_key"
                />
                <button
                  v-if="showFolderLink && file.group_key"
                  class="inline-flex items-center gap-1 text-[10px] text-[var(--brand)]/70 transition-colors hover:text-[var(--brand)]"
                  @click="emit('enterFolder', file.group_key)"
                >
                  <Icon icon="heroicons:folder-solid" class="h-3 w-3" />
                  {{ file.group_key }}
                </button>
              </div>
            </div>
          </div>
        </td>
        <td class="hidden whitespace-nowrap px-3 py-2.5 text-xs txt-secondary md:table-cell">
          {{ sizeOf(file.file_size) }}
        </td>
        <td class="hidden px-3 py-2.5 text-xs txt-secondary lg:table-cell">
          {{ file.uploaded_date }}
        </td>
        <td class="px-3 py-2.5">
          <div
            class="flex justify-end gap-0.5 opacity-0 transition-opacity pointer-coarse:opacity-100 group-hover:opacity-100 focus-within:opacity-100"
          >
            <FolderMoveMenu
              v-if="organize"
              :open="folderMenuOpen === file.id"
              :folders="folders"
              :current-folder="currentFolder ?? undefined"
              :can-remove="!!file.group_key"
              @toggle="emit('toggleMenu', file.id)"
              @move="emit('move', file.id, $event)"
              @remove="emit('remove', file.id)"
            />
            <FileMakeSearchableButton
              v-if="organize"
              :file="file"
              :busy="describing(file.id)"
              @activate="emit('describe', file)"
            />
            <FileRowActions :actions="actionsFor(file)" />
          </div>
        </td>
      </tr>
    </tbody>
  </table>
</template>
