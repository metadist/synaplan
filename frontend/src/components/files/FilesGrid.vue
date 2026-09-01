<template>
  <div data-testid="files-grid">
    <div class="mb-4">
      <h2 class="text-xl font-semibold txt-primary">{{ $t('files.generated.title') }}</h2>
      <p class="text-sm txt-secondary">{{ $t('files.generated.subtitle') }}</p>
    </div>

    <!-- Kind filter: server-side origin_kind facet (image/video/audio/document/calendar) -->
    <div class="flex flex-wrap gap-1.5 mb-4" data-testid="generated-kind-filter">
      <button
        v-for="option in kindOptions"
        :key="option.value || 'all'"
        type="button"
        class="px-3 py-1.5 rounded-full text-xs font-medium transition-colors border"
        :class="
          kindFilter === option.value
            ? 'bg-[var(--brand)]/15 text-[var(--brand)] border-[var(--brand)]/40'
            : 'txt-secondary hover:txt-primary border-light-border/30 dark:border-dark-border/10 hover:bg-black/5 dark:hover:bg-white/5'
        "
        :data-testid="`btn-kind-${option.value || 'all'}`"
        @click="setKindFilter(option.value)"
      >
        {{ $t(option.labelKey) }}
      </button>
    </div>

    <!-- Loading skeletons -->
    <div
      v-if="loading"
      class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3"
      data-testid="grid-loading"
    >
      <div
        v-for="i in 8"
        :key="i"
        class="rounded-lg border border-light-border/15 dark:border-dark-border/5 overflow-hidden animate-pulse"
      >
        <div class="aspect-video bg-gray-200 dark:bg-gray-700"></div>
        <div class="p-2 space-y-2">
          <div class="h-3 bg-gray-200 dark:bg-gray-700 rounded w-2/3"></div>
        </div>
      </div>
    </div>

    <!-- Empty state -->
    <div
      v-else-if="files.length === 0"
      class="flex flex-col items-center justify-center py-16 px-4 text-center"
      data-testid="grid-empty"
    >
      <div
        class="w-16 h-16 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center mb-4"
      >
        <Icon icon="mdi:image-multiple-outline" class="w-8 h-8 text-gray-400" />
      </div>
      <p class="text-sm txt-secondary max-w-sm">{{ $t('files.empty.generatedBody') }}</p>
    </div>

    <!-- Gallery -->
    <div
      v-else
      class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3"
      data-testid="grid-items"
    >
      <div
        v-for="file in files"
        :key="file.id"
        class="group rounded-lg border border-light-border/15 dark:border-dark-border/5 bg-white dark:bg-white/[0.02]"
        data-testid="grid-tile"
      >
        <div class="rounded-t-lg overflow-hidden">
          <FilePreview
            :file="file"
            :playing="activePlayerId === file.id"
            @play="activePlayerId = file.id"
          />
        </div>
        <div class="p-2">
          <p class="text-xs font-medium txt-primary truncate" :title="file.filename">
            {{ fileDisplayName(file, translate, locale) }}
          </p>
          <p class="text-[10px] txt-secondary truncate">{{ file.uploaded_date }}</p>
          <div class="mt-1">
            <FileVectorPill
              :state="vectorStateOf(file)"
              :chunk-count="file.chunk_count ?? 0"
              :group-key="file.group_key ?? null"
            />
          </div>
          <!--
            Delete is meant to sit in the same spot on every card, but the
            download button could not shrink below its label (German
            "Herunterladen"), so on a narrow two-column tile it pushed the icon
            buttons out of place — by a different amount depending on which of
            the optional ones the file has. Now the label yields and the icons
            hold their size, which keeps the trailing edge fixed.
          -->
          <div class="flex items-center gap-1 mt-1.5 min-w-0">
            <button
              class="flex-1 min-w-0 px-2 py-1 rounded-md bg-[var(--brand)]/10 text-[var(--brand)] hover:bg-[var(--brand)]/20 transition-colors text-[11px] font-medium flex items-center justify-center gap-1"
              :title="$t('files.generated.download')"
              :data-testid="`btn-generated-download-${file.id}`"
              @click="download(file)"
            >
              <ArrowDownTrayIcon class="w-3.5 h-3.5 shrink-0" />
              <span class="truncate">{{ $t('files.generated.download') }}</span>
            </button>
            <button
              v-if="file.chat_id"
              class="shrink-0 px-2 py-1 rounded-md border border-light-border/30 dark:border-dark-border/10 txt-secondary hover:txt-primary transition-colors text-[11px] flex items-center gap-1"
              :title="$t('files.generated.openInChat')"
              :data-testid="`btn-generated-open-${file.id}`"
              @click="openInChat(file)"
            >
              <ChatBubbleLeftRightIcon class="w-3.5 h-3.5" />
            </button>
            <div v-if="vectorStateOf(file) !== 'vectorized'" class="relative shrink-0">
              <button
                class="px-2 py-1 rounded-md border border-light-border/30 dark:border-dark-border/10 txt-secondary hover:text-[var(--brand)] transition-colors text-[11px] flex items-center gap-1 disabled:opacity-50"
                :title="$t('files.describeSortAction')"
                :disabled="isIndexing(file.id)"
                :data-testid="`btn-generated-index-${file.id}`"
                @click.stop="toggleKbMenu(file.id)"
              >
                <Icon
                  :icon="isIndexing(file.id) ? 'mdi:loading' : 'mdi:text-box-search-outline'"
                  class="w-3.5 h-3.5"
                  :class="isIndexing(file.id) && 'animate-spin'"
                />
              </button>
              <Transition name="fade">
                <div
                  v-if="kbMenuOpen === file.id"
                  class="absolute right-0 bottom-full mb-1 z-30 w-52 surface-card rounded-xl border border-light-border/30 dark:border-dark-border/20 shadow-xl py-1.5 overflow-hidden"
                  :data-testid="`menu-generated-index-${file.id}`"
                  @click.stop
                >
                  <div
                    class="px-3 py-1.5 text-[10px] font-semibold uppercase tracking-wider txt-secondary"
                  >
                    {{ $t('files.describeSortAction') }}
                  </div>
                  <button
                    class="w-full flex items-center gap-2 px-3 py-2 text-xs txt-primary hover:bg-[var(--brand)]/10 transition-colors text-left"
                    :data-testid="`btn-generated-index-auto-${file.id}`"
                    @click="addToKnowledgeBase(file)"
                  >
                    <Icon icon="mdi:auto-fix" class="w-4 h-4 shrink-0 text-[var(--brand)]" />
                    <span class="truncate">{{ $t('files.generated.autoGroup') }}</span>
                  </button>
                  <button
                    v-for="folder in folders"
                    :key="folder.name"
                    class="w-full flex items-center gap-2 px-3 py-2 text-xs txt-primary hover:bg-[var(--brand)]/10 transition-colors text-left"
                    @click="addToKnowledgeBase(file, folder.name)"
                  >
                    <Icon icon="heroicons:folder-solid" class="w-4 h-4 shrink-0" />
                    <span class="truncate">{{ folder.name }}</span>
                  </button>
                  <div
                    class="border-t border-light-border/20 dark:border-dark-border/10 mt-1.5 pt-1.5"
                  >
                    <div class="flex items-center gap-1.5 px-3 py-1">
                      <Icon
                        icon="heroicons:folder-plus"
                        class="w-4 h-4 text-[var(--brand)] shrink-0"
                      />
                      <input
                        v-model="newFolderName"
                        type="text"
                        class="flex-1 text-xs bg-transparent txt-primary placeholder:txt-secondary/50 focus:outline-none"
                        :placeholder="$t('files.folderPicker.newPlaceholder')"
                        @keyup.enter="addToKnowledgeBase(file, newFolderName.trim())"
                        @click.stop
                      />
                    </div>
                  </div>
                </div>
              </Transition>
            </div>
            <button
              class="shrink-0 px-2 py-1 rounded-md border border-light-border/30 dark:border-dark-border/10 text-red-400/70 hover:text-red-500 hover:bg-red-500/10 transition-colors text-[11px] flex items-center gap-1 disabled:opacity-50"
              :title="$t('files.delete')"
              :disabled="isDeleting(file.id)"
              :data-testid="`btn-generated-delete-${file.id}`"
              @click="confirmAndDelete(file)"
            >
              <TrashIcon class="w-3.5 h-3.5" />
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Pagination -->
    <div
      v-if="!loading && totalPages > 1"
      class="flex items-center justify-between mt-4 pt-4 border-t border-light-border/10 dark:border-dark-border/5"
      data-testid="generated-pagination"
    >
      <span class="text-xs txt-secondary">
        {{ $t('files.page') }} {{ currentPage }} / {{ totalPages }}
      </span>
      <div class="flex gap-2">
        <button
          :disabled="currentPage === 1"
          class="px-3 py-1.5 rounded-lg border border-light-border/30 dark:border-dark-border/8 txt-primary text-sm hover:bg-black/5 dark:hover:bg-white/5 transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
          data-testid="btn-generated-prev"
          @click="previousPage"
        >
          {{ $t('files.previous') }}
        </button>
        <button
          :disabled="currentPage >= totalPages"
          class="px-3 py-1.5 rounded-lg border border-light-border/30 dark:border-dark-border/8 txt-primary text-sm hover:bg-black/5 dark:hover:bg-white/5 transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
          data-testid="btn-generated-next"
          @click="nextPage"
        >
          {{ $t('files.next') }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { Icon } from '@iconify/vue'
import { ArrowDownTrayIcon, ChatBubbleLeftRightIcon, TrashIcon } from '@heroicons/vue/24/outline'
import FilePreview from '@/components/files/FilePreview.vue'
import FileVectorPill from '@/components/files/FileVectorPill.vue'
import filesService, { type FileItem, type FileOriginKind } from '@/services/filesService'
import { fileDisplayName, vectorStateOf } from '@/utils/fileDisplayName'
import { useNotification } from '@/composables/useNotification'
import { useDialog } from '@/composables/useDialog'
import { useChatsStore } from '@/stores/chats'

const { t, locale } = useI18n()
const translate = (key: string, values?: Record<string, unknown>): string =>
  t(key, (values ?? {}) as never)
const router = useRouter()
const { success: showSuccess, error: showError } = useNotification()
const { confirm } = useDialog()
const chatsStore = useChatsStore()

const files = ref<FileItem[]>([])
const loading = ref(false)
// Only one media player is mounted at a time (#1499): tiles show a poster/icon
// by default and mount the real <video>/<audio> only for the tile the user taps.
// This keeps the grid lightweight and stays within the mobile WebView's
// concurrent-media-element limit.
const activePlayerId = ref<number | null>(null)
const currentPage = ref(1)
const totalCount = ref(0)
const itemsPerPage = 30
const totalPages = computed(() => Math.max(1, Math.ceil(totalCount.value / itemsPerPage)))

// Kind filter — '' means all kinds; other values map to BORIGINKIND server-side.
const kindFilter = ref<FileOriginKind | ''>('')
const kindOptions: Array<{ value: FileOriginKind | ''; labelKey: string }> = [
  { value: '', labelKey: 'files.generated.kinds.all' },
  { value: 'image', labelKey: 'files.generated.kinds.image' },
  { value: 'video', labelKey: 'files.generated.kinds.video' },
  { value: 'audio', labelKey: 'files.generated.kinds.audio' },
  { value: 'document', labelKey: 'files.generated.kinds.document' },
  { value: 'calendar', labelKey: 'files.generated.kinds.calendar' },
]

const setKindFilter = (kind: FileOriginKind | '') => {
  if (kindFilter.value === kind) return
  kindFilter.value = kind
  void load(1)
}

// Knowledge-base menu + per-file busy state for index/delete actions.
const folders = ref<Array<{ name: string; count: number }>>([])
const kbMenuOpen = ref<number | null>(null)
const newFolderName = ref('')
const indexingIds = ref<number[]>([])
const deletingIds = ref<number[]>([])
const isIndexing = (id: number) => indexingIds.value.includes(id)
const isDeleting = (id: number) => deletingIds.value.includes(id)

const toggleKbMenu = (id: number) => {
  kbMenuOpen.value = kbMenuOpen.value === id ? null : id
  newFolderName.value = ''
}

const closeKbMenu = () => {
  kbMenuOpen.value = null
}

const loadFolders = async () => {
  try {
    folders.value = await filesService.getFileGroups()
  } catch {
    folders.value = []
  }
}

const load = async (page = currentPage.value) => {
  loading.value = true
  activePlayerId.value = null
  try {
    const list = await filesService.listFiles({
      source: 'generated',
      originKind: kindFilter.value || undefined,
      sort: 'date_desc',
      page,
      limit: itemsPerPage,
    })
    files.value = list.files
    totalCount.value = list.pagination.total
    currentPage.value = list.pagination.page
  } catch {
    showError(t('files.toast.genericError', { reason: '' }))
  } finally {
    loading.value = false
  }
}

const nextPage = () => {
  if (currentPage.value < totalPages.value) load(currentPage.value + 1)
}

const previousPage = () => {
  if (currentPage.value > 1) load(currentPage.value - 1)
}

const download = async (file: FileItem) => {
  try {
    await filesService.downloadFile(file.id, file.display_name || file.filename)
  } catch {
    showError(t('files.downloadFailed'))
  }
}

const openInChat = (file: FileItem) => {
  // Deep-link to the exact conversation the artefact was generated in. The file
  // row carries chat_id (resolved from its originating message); selecting it
  // before navigating ensures ChatView loads that chat instead of the latest.
  if (!file.chat_id) return
  chatsStore.setActiveChat(file.chat_id)
  router.push({ name: 'chat' })
}

// Index the generated artefact into the knowledge base: AI description with
// generation-prompt fallback (backend), filed into the chosen group — or an
// AI-suggested one when no group is picked.
const addToKnowledgeBase = async (file: FileItem, groupKey?: string) => {
  closeKbMenu()
  if (isIndexing(file.id)) return
  if (groupKey !== undefined && groupKey === '') return
  indexingIds.value = [...indexingIds.value, file.id]
  try {
    const res = await filesService.indexPromptFile(file.id, groupKey)
    if (res.success) {
      if (res.groupKey) {
        showSuccess(t('files.describeSortDoneGroup', { group: res.groupKey }))
      } else {
        showSuccess(t('files.describeSortDone'))
      }
      await Promise.all([load(), loadFolders()])
    } else {
      showError(res.error || t('files.describeSortFailed'))
    }
  } catch {
    showError(t('files.describeSortFailed'))
  } finally {
    indexingIds.value = indexingIds.value.filter((x) => x !== file.id)
  }
}

// Delete the artefact: the backend removes its vector chunks, the stored file
// and the DB row in one call.
const confirmAndDelete = async (file: FileItem) => {
  if (isDeleting(file.id)) return
  const confirmed = await confirm({
    title: t('files.generated.deleteConfirmTitle'),
    message: t('files.generated.deleteConfirmMessage', {
      name: file.display_name || file.filename,
    }),
    confirmText: t('files.delete'),
    danger: true,
  })
  if (!confirmed) return

  deletingIds.value = [...deletingIds.value, file.id]
  try {
    await filesService.deleteFile(file.id)
    showSuccess(t('files.generated.deleted'))
    await Promise.all([load(), loadFolders()])
  } catch {
    showError(t('files.generated.deleteFailed'))
  } finally {
    deletingIds.value = deletingIds.value.filter((x) => x !== file.id)
  }
}

onMounted(() => {
  void load()
  void loadFolders()
  document.addEventListener('click', closeKbMenu)
})

onUnmounted(() => {
  document.removeEventListener('click', closeKbMenu)
})
</script>

<style scoped>
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.15s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
