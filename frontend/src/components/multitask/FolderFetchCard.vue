<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { FolderRead } from '@/utils/folderRead'
import { skipCounts } from '@/utils/folderRead'

const props = defineProps<{
  read: FolderRead
  /** Show every name read and left out (a task run's record), not the first few. */
  full?: boolean
}>()

const { t } = useI18n()
const CHIPS = 3
const expanded = ref(false)

const app = computed(() => props.read.label || props.read.host)
const showsAll = computed(() => props.full === true || expanded.value)

const headline = computed(() => {
  const r = props.read
  const named = { app: app.value, folder: r.folder, host: r.host || app.value, count: r.read }
  const where = r.folder ? '' : 'Anywhere'
  switch (r.outcome) {
    case 'reading':
      return t('taskPlan.folderRead.reading', named)
    case 'read':
    case 'partial':
      return t(`taskPlan.folderRead.read${where}`, named, r.read)
    case 'listed':
      return t(`taskPlan.folderRead.listed${where}`, named, r.read)
    case 'refused':
      return t(`taskPlan.folderRead.refused${where}`, named)
    case 'not_found':
      return t('taskPlan.folderRead.notFound', named)
    case 'unreachable':
      return t('taskPlan.folderRead.unreachable', named)
    case 'nothing':
      // Files that were there but too large or past the limit are not "no match".
      return r.skipped.length > 0
        ? t(`taskPlan.folderRead.nothingRead${where}`, named)
        : t(`taskPlan.folderRead.nothing${where}`, named)
    case 'busy':
      return t('taskPlan.folderRead.busy', named)
    default:
      return t('taskPlan.folderRead.notConnected')
  }
})

const progress = computed(() =>
  props.read.outcome === 'reading' && props.read.total > 0
    ? t('taskPlan.folderRead.progress', { read: props.read.read, total: props.read.total })
    : ''
)

const shownNames = computed(() =>
  showsAll.value ? props.read.files : props.read.files.slice(0, CHIPS)
)
const hiddenCount = computed(() => props.read.files.length - shownNames.value.length)
const canExpand = computed(
  () => props.full !== true && (props.read.files.length > CHIPS || props.read.skipped.length > 0)
)

const skipLines = computed(() => {
  const counts = skipCounts(props.read)
  const lines: string[] = []
  if (counts.tooLarge > 0) {
    lines.push(t('taskPlan.folderRead.tooLarge', { count: counts.tooLarge }, counts.tooLarge))
  }
  if (counts.overLimit > 0) {
    lines.push(t('taskPlan.folderRead.overLimit', { count: counts.overLimit }, counts.overLimit))
  }
  if (counts.other > 0) {
    lines.push(t('taskPlan.folderRead.unreadable', { count: counts.other }, counts.other))
  }
  if (props.read.stopped) lines.push(t('taskPlan.folderRead.stopped'))
  return lines
})

const hint = computed(() => {
  const named = { app: app.value }
  switch (props.read.outcome) {
    case 'refused':
      return t('taskPlan.folderRead.refusedHint', named)
    case 'not_found':
      return t('taskPlan.folderRead.notFoundHint')
    case 'unreachable':
      return t('taskPlan.folderRead.unreachableHint', named)
    case 'not_connected':
      return t('taskPlan.folderRead.notConnectedHint')
    default:
      return ''
  }
})

function reasonKey(reason: string): string {
  return reason === 'too_large' || reason === 'over_limit' ? reason : 'other'
}
</script>

<template>
  <div class="space-y-2 min-w-0" data-testid="folder-read-card" :data-outcome="read.outcome">
    <p class="text-sm font-medium txt-primary break-words" data-testid="folder-read-headline">
      {{ headline }}
    </p>
    <p v-if="progress" class="text-xs txt-muted" data-testid="folder-read-progress">
      {{ progress }}
    </p>
    <ul
      v-if="shownNames.length > 0"
      class="flex flex-wrap gap-1.5"
      :aria-label="t('taskPlan.folderRead.filesLabel')"
      data-testid="folder-read-files"
    >
      <li
        v-for="name in shownNames"
        :key="name"
        class="surface-chip max-w-full truncate rounded-lg px-2 py-1 text-xs txt-primary"
        :title="name"
      >
        {{ name }}
      </li>
      <li v-if="hiddenCount > 0" class="px-1 py-1 text-xs txt-muted">+{{ hiddenCount }}</li>
    </ul>
    <p v-for="line in skipLines" :key="line" class="text-sm txt-secondary break-words">
      {{ line }}
    </p>
    <ul
      v-if="showsAll && read.skipped.length > 0"
      class="space-y-0.5 text-xs txt-secondary"
      data-testid="folder-read-skipped"
    >
      <li v-for="row in read.skipped" :key="row.name" class="break-words">
        {{ t(`taskPlan.folderRead.skippedReason.${reasonKey(row.reason)}`, { name: row.name }) }}
      </li>
    </ul>
    <button
      v-if="canExpand"
      type="button"
      class="btn-secondary px-3 py-2 rounded-xl text-xs font-medium"
      data-testid="folder-read-toggle"
      @click="expanded = !expanded"
    >
      {{
        expanded
          ? t('taskPlan.folderRead.showFewer')
          : t('taskPlan.folderRead.showAll', { count: read.files.length + read.skipped.length })
      }}
    </button>
    <p v-if="hint" class="text-sm txt-secondary break-words" data-testid="folder-read-hint">
      {{ hint }}
    </p>
    <a
      v-if="read.openUrl && app"
      :href="read.openUrl"
      target="_blank"
      rel="noopener noreferrer"
      class="inline-block text-sm font-medium text-[var(--brand)] hover:underline"
      data-testid="folder-read-open"
    >
      {{ t('taskPlan.folderRead.openIn', { app }) }}
    </a>
  </div>
</template>
