import { computed, onBeforeUnmount, ref, watch, type Ref } from 'vue'
import filesService, { type FileItem } from '@/services/filesService'
import { AI_CREATED_SOURCE_QUERY } from '@/services/fileSources'

export type FilePickerScope = 'mine' | 'ai'

/** Extensions plus the media kinds the backend stores for generated files. */
export const PICKER_TYPE_QUERIES: Record<string, string> = {
  all: '',
  images: 'jpg,jpeg,png,gif,webp,heic,heif,image',
  video: 'mp4,webm,mov,avi,mkv,video',
  audio: 'mp3,wav,ogg,m4a,audio',
  docs: 'pdf,doc,docx,txt,xlsx,xls,csv,pptx,ppt,md,document',
}

const PAGE_SIZE = 30

/**
 * Server-paged file list for the attach dialog. Search is debounced; scope
 * and type reload immediately. A newer request always wins.
 */
export function useFilePickerList(options?: { search?: Ref<string>; fileType?: Ref<string> }) {
  const scope = ref<FilePickerScope>('mine')
  const search = options?.search ?? ref('')
  const fileType = options?.fileType ?? ref('')
  const files = ref<FileItem[]>([])
  const total = ref(0)
  const page = ref(1)
  const loading = ref(false)
  const loadingMore = ref(false)
  const hasMore = computed(() => files.value.length < total.value)

  let timer: ReturnType<typeof setTimeout> | null = null
  let seq = 0
  let quiet = false

  async function fetchPage(nextPage: number, append: boolean): Promise<void> {
    const request = ++seq
    if (append) loadingMore.value = true
    else loading.value = true
    try {
      const list = await filesService.listFiles({
        ...(scope.value === 'ai'
          ? { source: AI_CREATED_SOURCE_QUERY }
          : { excludeSource: AI_CREATED_SOURCE_QUERY }),
        search: search.value.trim() || undefined,
        fileType: fileType.value || undefined,
        sort: 'date_desc',
        page: nextPage,
        limit: PAGE_SIZE,
      })
      if (request !== seq) return
      files.value = append ? [...files.value, ...list.files] : list.files
      total.value = list.pagination.total
      page.value = list.pagination.page
    } catch {
      if (request !== seq) return
      if (!append) files.value = []
    } finally {
      if (request === seq) {
        loading.value = false
        loadingMore.value = false
      }
    }
  }

  function reload(): Promise<void> {
    return fetchPage(1, false)
  }

  function loadMore(): void {
    if (!hasMore.value || loading.value || loadingMore.value) return
    void fetchPage(page.value + 1, true)
  }

  function schedule(delay: number): void {
    if (quiet) return
    if (timer) clearTimeout(timer)
    if (delay === 0) {
      void reload()
      return
    }
    timer = setTimeout(() => {
      timer = null
      void reload()
    }, delay)
  }

  /** Open on "My files" without a leftover search. One request, not three. */
  function resetToMine(): Promise<void> {
    quiet = true
    scope.value = 'mine'
    search.value = ''
    fileType.value = ''
    quiet = false
    return reload()
  }

  watch(search, () => schedule(300), { flush: 'sync' })
  watch([scope, fileType], () => schedule(0), { flush: 'sync' })

  onBeforeUnmount(() => {
    if (timer) clearTimeout(timer)
  })

  return {
    scope,
    search,
    fileType,
    files,
    total,
    loading,
    loadingMore,
    hasMore,
    reload,
    loadMore,
    resetToMine,
  }
}
