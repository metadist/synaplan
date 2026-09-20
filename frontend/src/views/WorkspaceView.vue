<template>
  <MainLayout>
    <div
      class="min-h-screen bg-chat px-3 py-4 sm:p-4 md:p-8 overflow-y-auto scroll-thin"
      data-testid="page-files-workspace"
    >
      <div class="max-w-7xl mx-auto space-y-6">
        <FilesTabs active="workspace" />

        <div class="surface-card p-4 sm:p-6 space-y-4">
          <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
            <div>
              <h2 class="text-xl font-semibold txt-primary">{{ $t('files.workspace.title') }}</h2>
              <p class="text-sm txt-secondary mt-1">{{ $t('files.workspace.subtitle') }}</p>
              <p v-if="info?.exists" class="text-xs txt-muted mt-2">
                {{ $t('files.workspace.usage', { used: info.usedMb, quota: info.quotaMb }) }}
              </p>
            </div>
            <button
              v-if="info?.exists"
              type="button"
              class="btn-danger px-4 py-2.5 rounded-lg text-sm font-medium self-start disabled:opacity-50 disabled:cursor-not-allowed"
              data-testid="btn-workspace-delete"
              :disabled="busy"
              @click="onDelete"
            >
              {{ $t('files.workspace.delete') }}
            </button>
          </div>

          <div v-if="loading" class="py-12 text-sm txt-muted" data-testid="workspace-loading">
            {{ $t('common.loading') }}
          </div>

          <div
            v-else-if="error"
            class="flex flex-col items-center justify-center py-16 px-4 text-center"
            data-testid="workspace-error"
          >
            <p class="text-sm text-red-600 dark:text-red-400 max-w-sm">{{ error }}</p>
            <button
              type="button"
              class="btn-secondary px-4 py-2.5 rounded-lg text-sm font-medium mt-4"
              data-testid="btn-workspace-retry"
              @click="reload"
            >
              {{ $t('files.workspace.retry') }}
            </button>
          </div>

          <div
            v-else-if="files.length === 0"
            class="flex flex-col items-center justify-center py-16 px-4 text-center"
            data-testid="workspace-empty"
          >
            <p class="text-sm txt-secondary max-w-sm">{{ $t('files.workspace.empty') }}</p>
            <router-link
              to="/"
              class="btn-primary px-4 py-2.5 rounded-lg text-sm font-medium mt-4 inline-flex items-center"
              data-testid="btn-workspace-empty-chat"
            >
              {{ $t('files.workspace.emptyAction') }}
            </router-link>
          </div>

          <ul
            v-else
            class="divide-y divide-light-border/20 dark:divide-dark-border/10"
            data-testid="workspace-files"
          >
            <li v-for="file in files" :key="file.path" class="flex items-center gap-3 py-3">
              <Icon icon="mdi:file-outline" class="w-5 h-5 txt-secondary shrink-0" />
              <div class="flex-1 min-w-0">
                <p class="text-sm txt-primary truncate">{{ file.path }}</p>
                <p class="text-xs txt-muted">{{ formatSize(file.size) }}</p>
              </div>
              <div class="flex items-center gap-0.5 shrink-0">
                <button
                  type="button"
                  class="icon-ghost inline-flex items-center justify-center w-11 h-11 rounded-lg"
                  data-testid="btn-workspace-preview"
                  :title="$t('files.workspace.preview')"
                  :aria-label="$t('files.workspace.preview')"
                  @click="onPreview(file)"
                >
                  <Icon icon="mdi:eye-outline" class="w-5 h-5" />
                </button>
                <button
                  type="button"
                  class="icon-ghost inline-flex items-center justify-center w-11 h-11 rounded-lg"
                  data-testid="btn-workspace-download"
                  :title="$t('files.workspace.download')"
                  :aria-label="$t('files.workspace.download')"
                  @click="onDownload(file)"
                >
                  <Icon icon="mdi:download" class="w-5 h-5" />
                </button>
                <FilePushMenu
                  size="row"
                  source="workspace"
                  :targets="cloudTargets"
                  :path="file.path"
                  :file-name="workspaceFileName(file.path)"
                />
              </div>
            </li>
          </ul>
        </div>
      </div>
    </div>

    <div
      v-if="preview"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
      data-testid="workspace-preview"
      @click.self="closePreview"
    >
      <div
        role="dialog"
        aria-modal="true"
        aria-labelledby="workspace-preview-title"
        class="surface-card max-w-3xl w-full max-h-[80vh] overflow-auto p-4 space-y-3"
      >
        <div class="flex items-start justify-between gap-3">
          <h3 id="workspace-preview-title" class="text-sm font-medium txt-primary break-all">
            {{ preview.name }}
          </h3>
          <button
            ref="previewCloseButton"
            type="button"
            class="btn-secondary px-4 py-2.5 rounded-lg text-sm font-medium"
            data-testid="btn-workspace-preview-close"
            @click="closePreview"
          >
            {{ $t('files.workspace.closePreview') }}
          </button>
        </div>
        <img
          v-if="preview.kind === 'image' && preview.url"
          :src="preview.url"
          :alt="preview.name"
          class="max-w-full h-auto rounded-lg"
        />
        <pre
          v-else-if="preview.kind === 'text'"
          class="text-sm txt-primary whitespace-pre-wrap break-words"
          >{{ preview.text }}</pre>
        <p v-else class="text-sm txt-secondary">{{ $t('files.workspace.previewUnavailable') }}</p>
      </div>
    </div>
  </MainLayout>
</template>

<script setup lang="ts">
import { nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { Icon } from '@iconify/vue'
import MainLayout from '@/components/MainLayout.vue'
import FilesTabs from '@/components/files/FilesTabs.vue'
import FilePushMenu from '@/components/files/FilePushMenu.vue'
import { useCloudFolderTargets } from '@/composables/useCloudFolderTargets'
import { useDialog } from '@/composables/useDialog'
import { useNotification } from '@/composables/useNotification'
import { getConfigSync } from '@/services/api/httpClient'
import {
  deleteWorkspace,
  downloadWorkspaceFile,
  getWorkspace,
  listWorkspaceFiles,
  loadWorkspaceFileBlob,
  workspaceFileName,
  type ComputeWorkspaceFile,
  type ComputeWorkspaceInfo,
} from '@/services/computeWorkspaceService'

type PreviewState = {
  name: string
  kind: 'image' | 'text' | 'other'
  url?: string
  text?: string
}

const { t } = useI18n()
const router = useRouter()
const { confirm } = useDialog()
const { success, error: notifyError } = useNotification()
const { targets: cloudTargets } = useCloudFolderTargets()

const loading = ref(true)
const busy = ref(false)
const error = ref('')
const info = ref<ComputeWorkspaceInfo | null>(null)
const files = ref<ComputeWorkspaceFile[]>([])
const preview = ref<PreviewState | null>(null)
const previewCloseButton = ref<HTMLButtonElement | null>(null)

onMounted(async () => {
  if (getConfigSync().features?.computeWorkspacesEnabled !== true) {
    await router.replace('/files')
    return
  }
  await reload()
})

onUnmounted(() => {
  // Watchers are already stopped here, so drop the listener explicitly.
  document.removeEventListener('keydown', onPreviewKeydown)
  closePreview()
})

// Dialog semantics: Escape closes, focus lands on the close button while open.
watch(preview, async (open) => {
  if (open) {
    document.addEventListener('keydown', onPreviewKeydown)
    await nextTick()
    previewCloseButton.value?.focus()
  } else {
    document.removeEventListener('keydown', onPreviewKeydown)
  }
})

function onPreviewKeydown(event: KeyboardEvent) {
  if (event.key === 'Escape') {
    closePreview()
  }
}

async function reload() {
  loading.value = true
  error.value = ''
  try {
    info.value = await getWorkspace()
    files.value = info.value.exists ? await listWorkspaceFiles() : []
  } catch {
    error.value = t('files.workspace.loadError')
    info.value = null
    files.value = []
  } finally {
    loading.value = false
  }
}

async function onDelete() {
  const ok = await confirm({
    title: t('files.workspace.delete'),
    message: t('files.workspace.deleteConfirm'),
    danger: true,
    confirmText: t('common.delete'),
    cancelText: t('common.cancel'),
  })
  if (!ok) return
  busy.value = true
  try {
    await deleteWorkspace()
    success(t('files.workspace.deleteDone'))
    await reload()
  } catch {
    notifyError(t('files.workspace.deleteError'))
  } finally {
    busy.value = false
  }
}

async function onDownload(file: ComputeWorkspaceFile) {
  try {
    await downloadWorkspaceFile(file.path)
  } catch {
    notifyError(t('files.workspace.downloadError'))
  }
}

// Only the latest preview click may publish its result; an older response
// arriving later is dropped without creating an object URL.
let previewRequest = 0

async function onPreview(file: ComputeWorkspaceFile) {
  closePreview()
  const request = ++previewRequest
  try {
    const blob = await loadWorkspaceFileBlob(file.path)
    const text = isTextPreview(file.mime) && blob.size <= 200_000 ? await blob.text() : null
    if (request !== previewRequest) return
    const name = workspaceFileName(file.path)
    if (file.mime.startsWith('image/')) {
      preview.value = { name, kind: 'image', url: URL.createObjectURL(blob) }
      return
    }
    if (text !== null) {
      preview.value = { name, kind: 'text', text }
      return
    }
    preview.value = { name, kind: 'other' }
  } catch {
    if (request === previewRequest) {
      notifyError(t('files.workspace.previewError'))
    }
  }
}

function closePreview() {
  if (preview.value?.url) {
    URL.revokeObjectURL(preview.value.url)
  }
  preview.value = null
}

function isTextPreview(mime: string): boolean {
  return (
    mime.startsWith('text/') ||
    ['application/json', 'application/xml', 'application/csv'].includes(mime)
  )
}

function formatSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}
</script>
