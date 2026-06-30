<template>
  <div data-testid="incoming-inbox">
    <div class="flex flex-col sm:flex-row sm:items-center gap-2 sm:gap-3 mb-4">
      <div class="min-w-0">
        <h2 class="text-xl font-semibold txt-primary">{{ $t('files.incoming.title') }}</h2>
        <p class="text-sm txt-secondary">{{ $t('files.incoming.subtitle') }}</p>
      </div>
      <div v-if="files.length > 0" class="flex items-center gap-2 sm:ml-auto">
        <select
          v-model="bulkGroup"
          class="px-2 py-2 text-sm rounded-lg bg-black/[0.04] dark:bg-white/[0.04] border border-black/[0.06] dark:border-white/[0.06] txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]/30"
          data-testid="select-incoming-bulk-group"
        >
          <option value="">{{ $t('files.incoming.keepAll') }}</option>
          <option v-for="g in groups" :key="g.name" :value="g.name">{{ g.name }}</option>
        </select>
        <button
          class="btn-primary px-3 py-2 rounded-lg text-sm flex items-center gap-1.5 disabled:opacity-50"
          :disabled="busy"
          data-testid="btn-incoming-keep-all"
          @click="keepAll"
        >
          <Icon icon="mdi:check-all" class="w-4 h-4" />
          {{ bulkGroup ? $t('files.incoming.assignGroup') : $t('files.incoming.keepAll') }}
        </button>
        <button
          class="px-3 py-2 rounded-lg border border-red-500/40 text-red-500 hover:bg-red-500/10 transition-colors text-sm flex items-center gap-1.5 disabled:opacity-50"
          :disabled="busy"
          data-testid="btn-incoming-dismiss-all"
          @click="dismissAll"
        >
          <TrashIcon class="w-4 h-4" />
          {{ $t('files.incoming.dismiss') }}
        </button>
      </div>
    </div>

    <!-- Loading skeletons -->
    <div v-if="loading" class="space-y-2" data-testid="incoming-loading">
      <div
        v-for="i in 4"
        :key="i"
        class="flex items-center gap-4 py-3 px-3 animate-pulse rounded-xl border border-light-border/15 dark:border-dark-border/5"
      >
        <div class="w-9 h-9 rounded bg-gray-200 dark:bg-gray-700 shrink-0"></div>
        <div class="h-4 bg-gray-200 dark:bg-gray-700 rounded w-1/3"></div>
        <div class="h-4 bg-gray-200 dark:bg-gray-700 rounded w-24 ml-auto"></div>
      </div>
    </div>

    <!-- Empty state -->
    <div
      v-else-if="files.length === 0"
      class="flex flex-col items-center justify-center py-16 px-4 text-center"
      data-testid="incoming-empty"
    >
      <div
        class="w-16 h-16 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center mb-4"
      >
        <Icon icon="mdi:inbox-arrow-down-outline" class="w-8 h-8 text-gray-400" />
      </div>
      <p class="text-sm txt-secondary max-w-sm">{{ $t('files.empty.incomingBody') }}</p>
    </div>

    <!-- Incoming rows -->
    <div v-else class="space-y-2">
      <div
        v-for="file in files"
        :key="file.id"
        class="flex items-center gap-3 p-3 rounded-xl border border-light-border/15 dark:border-dark-border/5 hover:bg-black/[0.02] dark:hover:bg-white/[0.02] transition-colors"
        data-testid="incoming-row"
      >
        <div
          class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0 bg-[var(--brand)]/10 text-[var(--brand)]"
        >
          <Icon :icon="fileIcon(file)" class="w-5 h-5" />
        </div>
        <div class="flex-1 min-w-0">
          <p class="text-sm font-medium txt-primary truncate" :title="file.filename">
            {{ file.display_name || file.original_name || file.filename }}
          </p>
          <div class="flex items-center gap-2 mt-1 min-w-0 flex-wrap">
            <FileSourceBadge v-if="file.source" :source="file.source" />
            <span class="text-[11px] txt-secondary">{{ provenance(file) }}</span>
            <FileVectorPill
              :state="file.vector_state ?? (file.is_vectorized ? 'vectorized' : 'pending')"
              :chunk-count="file.chunk_count ?? file.chunks ?? 0"
              :group-key="file.group_key"
            />
          </div>
        </div>
        <div class="flex items-center gap-1 shrink-0">
          <select
            class="hidden sm:block px-2 py-1.5 text-xs rounded-lg bg-black/[0.04] dark:bg-white/[0.04] border border-black/[0.06] dark:border-white/[0.06] txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]/30"
            :value="''"
            :disabled="busy"
            :aria-label="$t('files.incoming.assignGroup')"
            :data-testid="`select-incoming-group-${file.id}`"
            @change="onAssignGroup(file, $event)"
          >
            <option value="">{{ $t('files.incoming.assignGroup') }}</option>
            <option v-for="g in groups" :key="g.name" :value="g.name">{{ g.name }}</option>
          </select>
          <button
            class="p-1.5 rounded-lg hover:bg-black/5 dark:hover:bg-white/5 txt-secondary transition-colors"
            :title="$t('files.incoming.open')"
            :disabled="busy"
            :data-testid="`btn-incoming-open-${file.id}`"
            @click="open(file)"
          >
            <ArrowDownTrayIcon class="w-4 h-4" />
          </button>
          <button
            class="px-2.5 py-1.5 rounded-lg bg-[var(--brand)]/10 text-[var(--brand)] hover:bg-[var(--brand)]/20 transition-colors text-xs font-medium flex items-center gap-1 disabled:opacity-50"
            :disabled="busy"
            :data-testid="`btn-incoming-keep-${file.id}`"
            @click="keep(file)"
          >
            <Icon icon="mdi:check" class="w-3.5 h-3.5" />
            {{ $t('files.incoming.keep') }}
          </button>
          <button
            class="p-1.5 rounded-lg hover:bg-red-500/10 text-red-400/80 hover:text-red-500 transition-colors disabled:opacity-50"
            :title="$t('files.incoming.dismiss')"
            :disabled="busy"
            :data-testid="`btn-incoming-dismiss-${file.id}`"
            @click="dismiss(file)"
          >
            <TrashIcon class="w-4 h-4" />
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { Icon } from '@iconify/vue'
import { ArrowDownTrayIcon, TrashIcon } from '@heroicons/vue/24/outline'
import filesService, { type FileItem } from '@/services/filesService'
import { useNotification } from '@/composables/useNotification'
import { useDateFormat } from '@/composables/useDateFormat'
import FileVectorPill from './FileVectorPill.vue'
import FileSourceBadge from './FileSourceBadge.vue'

const { t } = useI18n()
const { success: showSuccess, error: showError } = useNotification()
const { formatDateTime } = useDateFormat()

const files = ref<FileItem[]>([])
const groups = ref<Array<{ name: string; count: number }>>([])
const loading = ref(false)
const busy = ref(false)
const bulkGroup = ref('')

const load = async () => {
  loading.value = true
  try {
    const [list, grp] = await Promise.all([
      filesService.listFiles({ incoming: true, limit: 100 }),
      filesService.getFileGroups().catch(() => []),
    ])
    files.value = list.files
    groups.value = grp
  } catch {
    showError(t('files.toast.genericError', { reason: '' }))
  } finally {
    loading.value = false
  }
}

const provenance = (file: FileItem): string =>
  t('files.incoming.provenance', {
    source: file.source ? t(`files.sourceLabel.${file.source}`) : '',
    time: formatDateTime(new Date(file.uploaded_at * 1000)),
  })

const fileIcon = (file: FileItem): string => {
  const type = (file.file_type || '').toLowerCase()
  if (/pdf/.test(type)) return 'mdi:file-pdf-box'
  if (/docx?|word/.test(type)) return 'mdi:file-word'
  if (/xlsx?|csv/.test(type)) return 'mdi:file-excel'
  if (/pptx?/.test(type)) return 'mdi:file-powerpoint'
  if (/png|jpe?g|gif|webp|image/.test(type)) return 'mdi:file-image'
  return 'mdi:file-document-outline'
}

const keep = async (file: FileItem, group?: string) => {
  busy.value = true
  try {
    await filesService.acceptIncoming(file.id, group)
    files.value = files.value.filter((f) => f.id !== file.id)
    showSuccess(
      t('files.toast.incomingKept', { count: 1, group: group || t('files.filter.noGroup') })
    )
  } catch {
    showError(t('files.toast.genericError', { reason: '' }))
  } finally {
    busy.value = false
  }
}

const onAssignGroup = (file: FileItem, event: Event) => {
  const group = (event.target as HTMLSelectElement).value
  if (group) keep(file, group)
}

const dismiss = async (file: FileItem) => {
  busy.value = true
  try {
    await filesService.deleteFile(file.id)
    files.value = files.value.filter((f) => f.id !== file.id)
    showSuccess(t('files.toast.incomingDismissed', { count: 1 }))
  } catch {
    showError(t('files.toast.genericError', { reason: '' }))
  } finally {
    busy.value = false
  }
}

const open = async (file: FileItem) => {
  try {
    await filesService.downloadFile(file.id, file.original_name || file.filename)
  } catch {
    showError(t('files.downloadFailed'))
  }
}

const keepAll = async () => {
  if (files.value.length === 0) return
  busy.value = true
  const ids = files.value.map((f) => f.id)
  const group = bulkGroup.value || undefined
  try {
    await filesService.acceptIncomingBulk(ids, group)
    showSuccess(
      t('files.toast.incomingKept', {
        count: ids.length,
        group: group || t('files.filter.noGroup'),
      })
    )
    files.value = []
    bulkGroup.value = ''
  } catch {
    showError(t('files.toast.genericError', { reason: '' }))
  } finally {
    busy.value = false
  }
}

const dismissAll = async () => {
  if (files.value.length === 0) return
  busy.value = true
  const ids = files.value.map((f) => f.id)
  try {
    await filesService.deleteMultipleFiles(ids)
    showSuccess(t('files.toast.incomingDismissed', { count: ids.length }))
    files.value = []
  } catch {
    showError(t('files.toast.genericError', { reason: '' }))
  } finally {
    busy.value = false
  }
}

onMounted(load)
</script>
