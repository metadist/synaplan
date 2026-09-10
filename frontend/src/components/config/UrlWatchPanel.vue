<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useDialog } from '@/composables/useDialog'
import { useNotification } from '@/composables/useNotification'
import { ApiError } from '@/services/api/httpClient'
import { urlWatchesApi, type UrlWatch, type UrlWatchCompare } from '@/services/api/urlWatchesApi'

const { t, locale } = useI18n()
const { success, error: showError } = useNotification()
const dialog = useDialog()

const available = ref(true)
const loading = ref(true)
const watches = ref<UrlWatch[]>([])
const url = ref('')
const adding = ref(false)
const checkingId = ref<number | null>(null)
const viewing = ref<UrlWatch | null>(null)
const viewLoading = ref(false)

const canAdd = computed(() => url.value.trim().length > 0 && !adding.value)

const formatWhen = (iso: string | null): string => {
  if (!iso) {
    return t('config.savedTasks.watches.neverFetched')
  }
  return t('config.savedTasks.watches.lastFetched', {
    when: new Date(iso).toLocaleString(locale.value),
  })
}

const rowStatus = (watch: UrlWatch): string => {
  if (watch.lastError && watch.lastFailedAt) {
    const failed = Date.parse(watch.lastFailedAt)
    const fetched = watch.fetchedAt ? Date.parse(watch.fetchedAt) : 0
    if (!Number.isNaN(failed) && failed >= fetched) {
      return t('config.savedTasks.watches.lastCheckFailed', { reason: watch.lastError })
    }
  }
  return formatWhen(watch.fetchedAt)
}

const load = async () => {
  loading.value = true
  try {
    watches.value = await urlWatchesApi.list()
    available.value = true
  } catch (err) {
    if (err instanceof ApiError && err.status === 404) {
      available.value = false
      watches.value = []
    } else {
      showError(t('config.savedTasks.watches.loadFailed'))
    }
  } finally {
    loading.value = false
  }
}

const onAdd = async () => {
  const value = url.value.trim()
  if (!value) return
  adding.value = true
  try {
    const { watch, created } = await urlWatchesApi.create(value)
    watches.value = [watch, ...watches.value.filter((row) => row.id !== watch.id)]
    url.value = ''
    success(
      created
        ? t('config.savedTasks.watches.added')
        : t('config.savedTasks.watches.alreadyWatching')
    )
  } catch (err) {
    if (err instanceof ApiError && err.status === 400) {
      showError(
        err.code === 'blocked_url'
          ? t('config.savedTasks.watches.blockedUrl')
          : t('config.savedTasks.watches.invalidUrl')
      )
    } else {
      showError(t('config.savedTasks.watches.addFailed'))
    }
  } finally {
    adding.value = false
  }
}

const toastCompare = (compare: UrlWatchCompare) => {
  if (compare.status === 'unchanged') {
    success(t('config.savedTasks.watches.checkedUnchanged'))
    return
  }
  if (compare.status === 'changed') {
    success(t('config.savedTasks.watches.checkedChanged'))
    return
  }
  success(t('config.savedTasks.watches.checkedFirst'))
}

const onCheck = async (id: number) => {
  if (checkingId.value !== null) {
    return
  }
  checkingId.value = id
  try {
    const result = await urlWatchesApi.refresh(id)
    watches.value = watches.value.map((row) => (row.id === result.watch.id ? result.watch : row))
    if (viewing.value?.id === result.watch.id) {
      viewing.value = result.watch
    }
    toastCompare(result.compare)
  } catch (err) {
    showError(
      err instanceof ApiError && err.message
        ? err.message
        : t('config.savedTasks.watches.checkFailed')
    )
    await load()
  } finally {
    checkingId.value = null
  }
}

const onView = async (id: number) => {
  viewLoading.value = true
  try {
    viewing.value = await urlWatchesApi.get(id)
  } catch {
    showError(t('config.savedTasks.watches.loadFailed'))
  } finally {
    viewLoading.value = false
  }
}

const onDelete = async (watch: UrlWatch) => {
  const ok = await dialog.confirm({
    title: t('config.savedTasks.watches.delete'),
    message: t('config.savedTasks.watches.deleteConfirm', { url: watch.url }),
    confirmText: t('config.savedTasks.watches.delete'),
    danger: true,
  })
  if (!ok) return
  try {
    await urlWatchesApi.remove(watch.id)
    watches.value = watches.value.filter((row) => row.id !== watch.id)
    if (viewing.value?.id === watch.id) {
      viewing.value = null
    }
    success(t('config.savedTasks.watches.deleted'))
  } catch {
    showError(t('config.savedTasks.watches.deleteFailed'))
  }
}

onMounted(() => {
  void load()
})
</script>

<template>
  <section v-if="available" class="space-y-4 pt-2" data-testid="url-watch-panel">
    <div>
      <h2 class="text-lg font-semibold txt-primary">
        {{ $t('config.savedTasks.watches.title') }}
      </h2>
      <p class="mt-1 text-sm txt-secondary">
        {{ $t('config.savedTasks.watches.subtitle') }}
      </p>
      <p class="mt-1 text-sm txt-secondary">
        {{ $t('config.savedTasks.watches.hint') }}
      </p>
    </div>

    <form class="flex flex-col gap-3 sm:flex-row sm:items-center" @submit.prevent="onAdd">
      <input
        v-model="url"
        type="url"
        class="w-full flex-1 min-w-0 px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)] disabled:opacity-50 disabled:cursor-not-allowed"
        :placeholder="$t('config.savedTasks.watches.urlPlaceholder')"
        :disabled="adding"
        data-testid="url-watch-input"
      />
      <button
        type="submit"
        class="btn-primary px-4 py-2.5 rounded-lg text-sm font-medium disabled:opacity-50 disabled:cursor-not-allowed"
        :disabled="!canAdd"
        data-testid="url-watch-add"
      >
        {{ adding ? $t('config.savedTasks.watches.adding') : $t('config.savedTasks.watches.add') }}
      </button>
    </form>

    <p v-if="loading" class="txt-secondary text-sm" data-testid="url-watch-loading">
      {{ $t('config.savedTasks.watches.loading') }}
    </p>
    <p v-else-if="watches.length === 0" class="txt-secondary text-sm" data-testid="url-watch-empty">
      {{ $t('config.savedTasks.watches.empty') }}
    </p>
    <ul v-else class="space-y-3" data-testid="url-watch-list">
      <li v-for="watch in watches" :key="watch.id" class="surface-card p-4 space-y-2">
        <div class="min-w-0">
          <p class="font-medium txt-primary truncate">
            {{ watch.title || $t('config.savedTasks.watches.untitled') }}
          </p>
          <p class="text-sm txt-secondary break-all">{{ watch.url }}</p>
          <p class="text-xs txt-secondary" data-testid="url-watch-status">{{ rowStatus(watch) }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
          <button
            type="button"
            class="btn-secondary px-3 py-1.5 rounded-lg text-sm font-medium disabled:opacity-50 disabled:cursor-not-allowed"
            :disabled="checkingId !== null"
            data-testid="url-watch-check"
            @click="onCheck(watch.id)"
          >
            {{
              checkingId === watch.id
                ? $t('config.savedTasks.watches.checking')
                : $t('config.savedTasks.watches.checkNow')
            }}
          </button>
          <button
            type="button"
            class="btn-secondary px-3 py-1.5 rounded-lg text-sm font-medium"
            data-testid="url-watch-view"
            @click="onView(watch.id)"
          >
            {{ $t('config.savedTasks.watches.view') }}
          </button>
          <button
            type="button"
            class="btn-danger px-3 py-1.5 rounded-lg text-sm font-medium"
            data-testid="url-watch-delete"
            @click="onDelete(watch)"
          >
            {{ $t('config.savedTasks.watches.delete') }}
          </button>
        </div>
      </li>
    </ul>

    <div
      v-if="viewing || viewLoading"
      class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40"
      data-testid="url-watch-dialog"
    >
      <div class="surface-card w-full max-w-2xl max-h-[80vh] overflow-y-auto p-5 space-y-3">
        <p v-if="viewLoading" class="text-sm txt-secondary">
          {{ $t('config.savedTasks.watches.loading') }}
        </p>
        <template v-else-if="viewing">
          <h3 class="font-semibold txt-primary">
            {{ viewing.title || $t('config.savedTasks.watches.untitled') }}
          </h3>
          <p class="text-sm txt-secondary break-all">{{ viewing.url }}</p>
          <p class="text-xs txt-secondary">{{ rowStatus(viewing) }}</p>
          <div v-if="viewing.lastDiffText" class="space-y-1">
            <p class="text-sm font-medium txt-primary">
              {{ $t('config.savedTasks.watches.lastDiff') }}
            </p>
            <pre
              class="whitespace-pre-wrap text-sm txt-primary surface-card border border-light-border/30 dark:border-dark-border/20 rounded-lg p-3 max-h-80 overflow-y-auto"
              data-testid="url-watch-diff"
              >{{ viewing.lastDiffText }}</pre>
          </div>
          <pre
            class="whitespace-pre-wrap text-sm txt-primary surface-card border border-light-border/30 dark:border-dark-border/20 rounded-lg p-3 max-h-80 overflow-y-auto"
            data-testid="url-watch-body"
            >{{ viewing.body || $t('config.savedTasks.watches.neverFetched') }}</pre>
          <button
            type="button"
            class="btn-secondary px-4 py-2.5 rounded-lg text-sm font-medium"
            data-testid="url-watch-close"
            @click="viewing = null"
          >
            {{ $t('config.savedTasks.watches.close') }}
          </button>
        </template>
      </div>
    </div>
  </section>
</template>
