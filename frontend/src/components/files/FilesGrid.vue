<template>
  <div data-testid="files-grid">
    <div class="mb-4">
      <h2 class="text-xl font-semibold txt-primary">{{ $t('files.generated.title') }}</h2>
      <p class="text-sm txt-secondary">{{ $t('files.generated.subtitle') }}</p>
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
        class="group rounded-lg border border-light-border/15 dark:border-dark-border/5 overflow-hidden bg-white dark:bg-white/[0.02]"
        data-testid="grid-tile"
      >
        <div
          class="aspect-video w-full overflow-hidden bg-gray-100 dark:bg-gray-800 relative flex items-center justify-center"
        >
          <img
            v-if="kindOf(file) === 'image'"
            :src="downloadUrl(file.id)"
            :alt="file.display_name || file.filename"
            class="w-full h-full object-cover transition-transform group-hover:scale-105"
            loading="lazy"
          />
          <Icon v-else :icon="kindIcon(file)" class="w-10 h-10 text-gray-400" />
          <div
            v-if="kindOf(file) !== 'image'"
            class="absolute bottom-2 right-2 bg-black/60 p-1 rounded backdrop-blur-sm"
          >
            <Icon :icon="kindIcon(file)" class="text-white w-4 h-4" />
          </div>
        </div>
        <div class="p-2">
          <p class="text-xs font-medium txt-primary truncate" :title="file.filename">
            {{ file.display_name || file.filename }}
          </p>
          <p class="text-[10px] txt-secondary truncate">{{ file.uploaded_date }}</p>
          <div class="flex items-center gap-1 mt-1.5">
            <button
              class="flex-1 px-2 py-1 rounded-md bg-[var(--brand)]/10 text-[var(--brand)] hover:bg-[var(--brand)]/20 transition-colors text-[11px] font-medium flex items-center justify-center gap-1"
              :title="$t('files.generated.download')"
              :data-testid="`btn-generated-download-${file.id}`"
              @click="download(file)"
            >
              <ArrowDownTrayIcon class="w-3.5 h-3.5" />
              {{ $t('files.generated.download') }}
            </button>
            <button
              v-if="file.chat_id"
              class="px-2 py-1 rounded-md border border-light-border/30 dark:border-dark-border/10 txt-secondary hover:txt-primary transition-colors text-[11px] flex items-center gap-1"
              :title="$t('files.generated.openInChat')"
              :data-testid="`btn-generated-open-${file.id}`"
              @click="openInChat(file)"
            >
              <ChatBubbleLeftRightIcon class="w-3.5 h-3.5" />
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
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { Icon } from '@iconify/vue'
import { ArrowDownTrayIcon, ChatBubbleLeftRightIcon } from '@heroicons/vue/24/outline'
import filesService, { type FileItem, type FileOriginKind } from '@/services/filesService'
import { getApiBaseUrl } from '@/services/api/httpClient'
import { useNotification } from '@/composables/useNotification'
import { useChatsStore } from '@/stores/chats'

const { t } = useI18n()
const router = useRouter()
const { error: showError } = useNotification()
const chatsStore = useChatsStore()

const files = ref<FileItem[]>([])
const loading = ref(false)
const currentPage = ref(1)
const totalCount = ref(0)
const itemsPerPage = 30
const totalPages = computed(() => Math.max(1, Math.ceil(totalCount.value / itemsPerPage)))

const load = async (page = currentPage.value) => {
  loading.value = true
  try {
    const list = await filesService.listFiles({
      source: 'generated',
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

const kindOf = (file: FileItem): FileOriginKind => {
  if (file.origin_kind) return file.origin_kind
  const type = (file.file_type || '').toLowerCase()
  if (/png|jpe?g|gif|webp|image/.test(type)) return 'image'
  if (/mp4|webm|mov|avi|mkv|video/.test(type)) return 'video'
  if (/mp3|wav|ogg|m4a|audio/.test(type)) return 'audio'
  if (/ics/.test(type)) return 'calendar'
  return 'document'
}

const kindIcon = (file: FileItem): string => {
  switch (kindOf(file)) {
    case 'video':
      return 'mdi:play-circle'
    case 'audio':
      return 'mdi:music-note'
    case 'calendar':
      return 'mdi:calendar'
    case 'document':
      return 'mdi:file-document-outline'
    default:
      return 'mdi:image'
  }
}

const downloadUrl = (id: number): string => `${getApiBaseUrl()}/api/v1/files/${id}/download`

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

onMounted(load)
</script>
