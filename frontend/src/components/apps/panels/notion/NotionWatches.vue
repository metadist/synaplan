<template>
  <div data-testid="section-notion-watches">
    <p class="text-sm font-medium txt-primary">{{ $t('apps.notion.watches.title') }}</p>
    <p class="txt-secondary text-sm mt-1">{{ $t('apps.notion.watches.intro') }}</p>

    <p v-if="loadFailed" class="txt-secondary text-sm mt-3" data-testid="text-notion-watches-error">
      {{ $t('apps.notion.watches.loadFailed') }}
    </p>
    <template v-else-if="list">
      <ul v-if="list.watches.length > 0" class="mt-3 space-y-2">
        <li
          v-for="row in list.watches"
          :key="row.id"
          class="rounded-xl border border-light-border/30 dark:border-dark-border/20 p-3 flex flex-wrap items-start gap-3"
          data-testid="item-notion-watch"
        >
          <span class="min-w-0 flex-1">
            <a
              :href="row.page_url"
              target="_blank"
              rel="noopener noreferrer"
              class="block txt-primary text-sm font-medium truncate hover:underline"
            >
              {{ row.title ?? row.page_url }}
            </a>
            <span class="block txt-secondary text-xs mt-0.5">{{ describe(row) }}</span>
            <span
              v-if="row.last_error"
              class="block text-sm text-red-600 dark:text-red-400 mt-1"
              data-testid="text-notion-watch-error"
            >
              {{ row.last_error }}
            </span>
            <span v-else class="block txt-secondary text-xs mt-0.5">{{ checked(row) }}</span>
            <router-link
              v-if="row.file_id"
              :to="{ path: '/files', query: { file: String(row.file_id) } }"
              class="inline-block txt-brand text-xs mt-1 hover:underline"
              data-testid="link-notion-watch-file"
            >
              {{ $t('apps.notion.watches.openCopy') }}
            </router-link>
          </span>
          <span class="flex items-center gap-2 shrink-0">
            <button
              type="button"
              class="btn-secondary px-4 py-2.5 text-sm font-medium disabled:opacity-50 disabled:cursor-not-allowed"
              :disabled="busy === row.id"
              data-testid="btn-notion-watch-toggle"
              @click="toggle(row)"
            >
              {{ row.enabled ? $t('apps.notion.watches.pause') : $t('apps.notion.watches.resume') }}
            </button>
            <button
              type="button"
              class="icon-ghost p-2 rounded-lg text-red-400/70 hover:text-red-500 disabled:opacity-50 disabled:cursor-not-allowed"
              :aria-label="$t('apps.notion.watches.remove')"
              :title="$t('apps.notion.watches.remove')"
              :disabled="busy === row.id"
              data-testid="btn-notion-watch-remove"
              @click="remove(row)"
            >
              <TrashIcon class="w-5 h-5" aria-hidden="true" />
            </button>
          </span>
        </li>
      </ul>
      <p v-else class="txt-secondary text-sm mt-3" data-testid="text-notion-watches-empty">
        {{ $t('apps.notion.watches.empty') }}
      </p>

      <div v-if="list.watches.length < list.limit" class="mt-4">
        <NotionWatchForm ref="form" :tasks="list.tasks" :saving="saving" @submit="create" />
      </div>
      <p v-else class="txt-secondary text-sm mt-3">
        {{ $t('apps.notion.watches.limit', { count: list.limit }) }}
      </p>
    </template>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { TrashIcon } from '@heroicons/vue/24/outline'
import { useDialog } from '@/composables/useDialog'
import { useNotification } from '@/composables/useNotification'
import { useDateFormat } from '@/composables/useDateFormat'
import { userFacingError } from '@/utils/userFacingError'
import NotionWatchForm from './NotionWatchForm.vue'
import {
  notionAppApi,
  type NotionWatch,
  type NotionWatchInput,
  type NotionWatchList,
} from '@/services/api/notionAppApi'

const { t } = useI18n()
const { confirm } = useDialog()
const { success, error } = useNotification()
const { formatRelativeTime } = useDateFormat()

const list = ref<NotionWatchList | null>(null)
const loadFailed = ref(false)
const saving = ref(false)
const busy = ref<number | null>(null)
const form = ref<InstanceType<typeof NotionWatchForm> | null>(null)

const message = userFacingError

const describe = (row: NotionWatch) => {
  if (!row.enabled) return t('apps.notion.watches.paused')
  return row.action === 'task'
    ? t('apps.notion.watches.describeTask', { task: row.task_name ?? '—' })
    : t('apps.notion.watches.describeSearchable', { folder: row.folder ?? 'Notion' })
}

const checked = (row: NotionWatch) => {
  if (row.checked_at === null) return t('apps.notion.watches.notChecked')
  const at = formatRelativeTime(new Date(row.checked_at * 1000))
  if (row.changed_at === null) return t('apps.notion.watches.checked', { time: at })
  return t('apps.notion.watches.changed', {
    time: at,
    changed: formatRelativeTime(new Date(row.changed_at * 1000)),
  })
}

const load = async () => {
  try {
    list.value = await notionAppApi.watches()
    loadFailed.value = false
  } catch {
    loadFailed.value = true
  }
}

const replace = (row: NotionWatch) => {
  if (!list.value) return
  list.value.watches = list.value.watches.map((item) => (item.id === row.id ? row : item))
}

const create = async (input: NotionWatchInput) => {
  saving.value = true
  try {
    const row = await notionAppApi.createWatch(input)
    list.value?.watches.unshift(row)
    form.value?.reset()
    success(
      row.last_error
        ? t('apps.notion.watches.addedWithError')
        : input.action === 'task'
          ? t('apps.notion.watches.addedTask')
          : t('apps.notion.watches.addedSearchable', { folder: row.folder ?? 'Notion' })
    )
  } catch (err) {
    error(message(err, t('apps.notion.watches.addFailed')))
  } finally {
    saving.value = false
  }
}

const toggle = async (row: NotionWatch) => {
  busy.value = row.id
  try {
    replace(await notionAppApi.setWatchEnabled(row.id, !row.enabled))
    success(
      row.enabled ? t('apps.notion.watches.pausedToast') : t('apps.notion.watches.resumedToast')
    )
  } catch (err) {
    error(message(err, t('apps.notion.watches.saveFailed')))
  } finally {
    busy.value = null
  }
}

const remove = async (row: NotionWatch) => {
  const ok = await confirm({
    title: t('apps.notion.watches.removeTitle'),
    message:
      row.action === 'searchable'
        ? t('apps.notion.watches.removeSearchable')
        : t('apps.notion.watches.removeTask'),
    confirmText: t('apps.notion.watches.remove'),
    danger: true,
  })
  if (!ok) return
  busy.value = row.id
  try {
    await notionAppApi.deleteWatch(row.id)
    if (list.value) list.value.watches = list.value.watches.filter((item) => item.id !== row.id)
    success(t('apps.notion.watches.removed'))
  } catch (err) {
    error(message(err, t('apps.notion.watches.saveFailed')))
  } finally {
    busy.value = null
  }
}

onMounted(() => {
  void load()
})
</script>
