<template>
  <section data-testid="section-project-sources" class="space-y-4">
    <div class="flex items-center justify-between gap-3">
      <p class="text-sm txt-secondary">{{ $t('projects.sources.intro') }}</p>
      <button
        type="button"
        class="btn-primary flex-shrink-0 px-4 py-2.5 text-sm font-medium"
        data-testid="btn-project-add-source"
        @click="dialogOpen = true"
      >
        {{ $t('projects.sources.add') }}
      </button>
    </div>

    <p v-if="loadFailed" class="text-sm text-red-600 dark:text-red-400" role="alert">
      {{ $t('projects.sources.loadFailed') }}
    </p>
    <p
      v-else-if="loaded && sources.length === 0"
      class="rounded-xl border border-dashed border-light-border/40 dark:border-dark-border/30 px-4 py-8 text-center text-sm txt-secondary"
      data-testid="text-project-sources-empty"
    >
      {{ $t('projects.sources.empty') }}
    </p>
    <ul v-else class="space-y-2" data-testid="list-project-sources">
      <ProjectSourceRow
        v-for="source in sources"
        :key="source.id"
        :source="source"
        :busy="busyIds.has(source.id)"
        @retry="retry"
        @remove="remove"
      />
    </ul>

    <AddSourceDialog
      :open="dialogOpen"
      :project-id="projectId"
      @close="dialogOpen = false"
      @added="onAdded"
    />
  </section>
</template>

<script setup lang="ts">
import { onMounted, onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import ProjectSourceRow from './ProjectSourceRow.vue'
import AddSourceDialog from './AddSourceDialog.vue'
import {
  projectsApi,
  type AddSourcesResult,
  type ProjectSourceItem,
} from '@/services/api/projectsApi'
import { useDialog } from '@/composables/useDialog'
import { useNotification } from '@/composables/useNotification'

const POLL_FAST_MS = 2000
const POLL_SLOW_MS = 6000
const POLL_FAST_ROUNDS = 15

const props = defineProps<{ projectId: string }>()
const emit = defineEmits<{ changed: [] }>()

const { t } = useI18n()
const { confirm } = useDialog()
const { success, error: showError } = useNotification()

const sources = ref<ProjectSourceItem[]>([])
const loaded = ref(false)
const loadFailed = ref(false)
const dialogOpen = ref(false)
const busyIds = ref(new Set<number>())

let timer: ReturnType<typeof setTimeout> | null = null
let rounds = 0
let disposed = false

function stopPolling(): void {
  if (timer) clearTimeout(timer)
  timer = null
}

/** A source that is still "reading" is re-read until it settles, so a reload mid-read recovers. */
function schedulePoll(): void {
  stopPolling()
  if (disposed || !sources.value.some((s) => s.state === 'reading')) {
    rounds = 0
    return
  }
  const delay = rounds < POLL_FAST_ROUNDS ? POLL_FAST_MS : POLL_SLOW_MS
  rounds++
  timer = setTimeout(() => void load(), delay)
}

async function load(): Promise<void> {
  const projectId = props.projectId
  try {
    const list = await projectsApi.listSources(projectId)
    if (disposed || projectId !== props.projectId) return
    sources.value = list
    loadFailed.value = false
  } catch {
    if (disposed || projectId !== props.projectId) return
    loadFailed.value = !loaded.value
  } finally {
    if (!disposed && projectId === props.projectId) {
      loaded.value = true
      schedulePoll()
    }
  }
}

function replace(source: ProjectSourceItem): void {
  sources.value = sources.value.map((s) => (s.id === source.id ? source : s))
  schedulePoll()
}

function withBusy(id: number, on: boolean): void {
  const next = new Set(busyIds.value)
  if (on) next.add(id)
  else next.delete(id)
  busyIds.value = next
}

async function retry(fileId: number): Promise<void> {
  withBusy(fileId, true)
  try {
    replace(await projectsApi.refreshSource(props.projectId, fileId))
  } catch {
    showError(t('projects.sources.retryFailed'))
  } finally {
    withBusy(fileId, false)
  }
}

async function remove(fileId: number): Promise<void> {
  const source = sources.value.find((s) => s.id === fileId)
  const confirmed = await confirm({
    title: t('projects.sources.removeTitle'),
    message: t('projects.sources.removeMessage', { name: source?.name ?? '' }),
    confirmText: t('projects.sources.remove'),
    cancelText: t('common.cancel'),
    danger: true,
  })
  if (!confirmed) return
  withBusy(fileId, true)
  try {
    await projectsApi.removeSource(props.projectId, fileId)
    sources.value = sources.value.filter((s) => s.id !== fileId)
    emit('changed')
  } catch {
    showError(t('projects.sources.removeFailed'))
  } finally {
    withBusy(fileId, false)
  }
}

function onAdded(result: AddSourcesResult): void {
  dialogOpen.value = false
  const known = new Set(sources.value.map((s) => s.id))
  sources.value = [...result.sources.filter((s) => !known.has(s.id)), ...sources.value]
  for (const failure of result.errors) {
    showError(`${failure.filename}: ${failure.error}`.replace(/^: /, ''))
  }
  if (result.sources.length > 0) success(t('projects.sources.added', result.sources.length))
  rounds = 0
  schedulePoll()
  emit('changed')
}

watch(
  () => props.projectId,
  () => {
    stopPolling()
    sources.value = []
    loaded.value = false
    rounds = 0
    void load()
  }
)

onMounted(() => void load())
onUnmounted(() => {
  disposed = true
  stopPolling()
})
</script>
