<template>
  <section v-if="showSection" class="flex flex-col gap-4" data-testid="section-long-term-memory">
    <i18n-t
      keypath="memories.longTerm.intro"
      tag="p"
      scope="global"
      class="txt-secondary text-sm leading-relaxed"
      data-testid="text-long-term-intro"
    >
      <template #settings>
        <button
          type="button"
          class="underline txt-primary font-medium"
          data-testid="btn-long-term-settings"
          @click="goSettings"
        >
          {{ t('memories.longTerm.settingsLink') }}
        </button>
      </template>
    </i18n-t>

    <p
      v-if="offNoticeKey"
      class="txt-secondary text-sm"
      :data-testid="enabled ? 'text-long-term-memories-off' : 'text-long-term-server-off'"
    >
      {{ t(offNoticeKey) }}
    </p>

    <div
      v-if="status === 'ready' && total > 0"
      class="flex flex-wrap items-center justify-between gap-3"
    >
      <p class="txt-secondary text-sm" data-testid="text-long-term-total">
        {{ t('memories.longTerm.total', { count: total }, total) }}
      </p>
      <button
        type="button"
        class="btn-danger px-4 py-2.5 rounded-xl text-sm font-medium"
        data-testid="btn-long-term-delete-all"
        :disabled="busy"
        @click="onDeleteAll"
      >
        {{ t('memories.longTerm.deleteAll') }}
      </button>
    </div>

    <div
      v-if="status === 'loading'"
      class="flex items-center gap-3 min-h-48"
      data-testid="state-long-term-loading"
      aria-busy="true"
    >
      <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-brand-500"></div>
      <p class="txt-secondary text-sm">{{ t('memories.longTerm.loading') }}</p>
    </div>

    <div
      v-else-if="status === 'error'"
      class="flex flex-col items-start gap-3 min-h-48"
      data-testid="state-long-term-error"
    >
      <p class="text-sm text-red-600 dark:text-red-400">{{ t('memories.longTerm.loadError') }}</p>
      <button
        type="button"
        class="btn-secondary px-4 py-2.5 text-sm font-medium"
        data-testid="btn-long-term-retry"
        @click="reload"
      >
        {{ t('memories.longTerm.retry') }}
      </button>
    </div>

    <div
      v-else-if="total === 0"
      class="flex flex-col items-start gap-3 min-h-48"
      data-testid="state-long-term-empty"
    >
      <p class="txt-primary text-sm">
        {{ memoriesEnabled ? t('memories.longTerm.emptyNone') : t('memories.longTerm.emptyOff') }}
      </p>
      <button
        type="button"
        class="btn-primary px-4 py-2.5 text-sm font-medium"
        data-testid="btn-long-term-empty-action"
        @click="onEmptyAction"
      >
        {{ memoriesEnabled ? t('memories.longTerm.openChat') : t('memories.longTerm.goSettings') }}
      </button>
    </div>

    <div v-else class="flex flex-col gap-2">
      <LongTermMemoryEntry
        v-for="entry in entries"
        :key="entry.id"
        :entry="entry"
        :can-open="canOpen(entry)"
        @open="openEntry(entry)"
        @delete="onDelete(entry)"
      />
      <button
        v-if="entries.length < total"
        type="button"
        class="btn-secondary px-4 py-2.5 text-sm font-medium self-start"
        data-testid="btn-long-term-load-more"
        :disabled="loadingMore || busy"
        @click="loadMore"
      >
        {{ t('memories.longTerm.loadMore') }}
      </button>
    </div>
  </section>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import LongTermMemoryEntry from '@/components/memories/LongTermMemoryEntry.vue'
import { useDialog } from '@/composables/useDialog'
import { useNotification } from '@/composables/useNotification'
import { openMessageLocation } from '@/composables/useScrollToMessage'
import { ApiError } from '@/services/api/httpClient'
import {
  deleteAllMessageDigestEntries,
  deleteMessageDigestEntry,
  listMessageDigestEntries,
  type MessageDigestListEntry,
} from '@/services/api/messageDigestEntriesApi'

const PAGE_SIZE = 25

const emit = defineEmits<{
  availability: [available: boolean]
}>()

const { t } = useI18n()
const router = useRouter()
const { confirm } = useDialog()
const { success: notifySuccess, error: notifyError, info: notifyInfo } = useNotification()

const status = ref<'loading' | 'ready' | 'error'>('loading')
const enabled = ref(true)
const memoriesEnabled = ref(true)
const entries = ref<MessageDigestListEntry[]>([])
const total = ref(0)
const loadingMore = ref(false)
const busy = ref(false)

const available = computed(() => status.value !== 'ready' || enabled.value || total.value > 0)
const showSection = computed(() => status.value !== 'ready' || available.value)

const offNoticeKey = computed(() => {
  if (status.value !== 'ready' || total.value === 0) return null
  if (!enabled.value) return 'memories.longTerm.serverOff'
  return memoriesEnabled.value ? null : 'memories.longTerm.memoriesOff'
})

watch(available, (value) => emit('availability', value), { immediate: true })

function canOpen(entry: MessageDigestListEntry): boolean {
  return entry.chatId != null && entry.chatId > 0
}

function goSettings() {
  router.push({ name: 'settings', hash: '#memories' })
}

function onEmptyAction() {
  if (!memoriesEnabled.value) {
    goSettings()
    return
  }
  router.push({ name: 'chat' })
}

function openEntry(entry: MessageDigestListEntry) {
  if (!canOpen(entry) || entry.chatId == null) return
  router.push(openMessageLocation(entry.chatId, entry.messageId))
}

async function reload() {
  status.value = 'loading'
  await loadPage(1, true)
}

/** Returns how many entries were added, or null when the request failed. */
async function loadPage(nextPage: number, replace: boolean): Promise<number | null> {
  try {
    const data = await listMessageDigestEntries(nextPage, PAGE_SIZE)
    enabled.value = data.enabled
    memoriesEnabled.value = data.memoriesEnabled
    total.value = data.total
    const known = new Set(replace ? [] : entries.value.map((entry) => entry.id))
    const fresh = data.entries.filter((entry) => !known.has(entry.id))
    entries.value = replace ? fresh : [...entries.value, ...fresh]
    status.value = 'ready'
    return fresh.length
  } catch {
    if (entries.value.length === 0) {
      status.value = 'error'
    } else {
      status.value = 'ready'
      notifyError(t('memories.longTerm.loadError'))
    }
    return null
  }
}

async function loadMore() {
  if (loadingMore.value || entries.value.length >= total.value) return
  loadingMore.value = true
  try {
    // Deletes shift the server offsets, so continue from the loaded count.
    const added = await loadPage(Math.floor(entries.value.length / PAGE_SIZE) + 1, false)
    if (added === 0 && entries.value.length < total.value) await reload()
  } finally {
    loadingMore.value = false
  }
}

function dropEntry(id: number) {
  const had = entries.value.some((entry) => entry.id === id)
  if (!had) return
  entries.value = entries.value.filter((entry) => entry.id !== id)
  total.value = Math.max(0, total.value - 1)
  if (entries.value.length === 0 && total.value > 0) {
    void reload()
  }
}

async function onDelete(entry: MessageDigestListEntry) {
  if (busy.value) return
  const confirmed = await confirm({
    title: t('memories.longTerm.deleteConfirm.title'),
    message: t('memories.longTerm.deleteConfirm.message'),
    confirmText: t('memories.longTerm.deleteConfirm.confirm'),
    danger: true,
  })
  if (!confirmed) return
  busy.value = true
  try {
    await deleteMessageDigestEntry(entry.id)
    dropEntry(entry.id)
    notifySuccess(t('memories.longTerm.deleted'))
  } catch (err) {
    if (err instanceof ApiError && err.status === 404) {
      dropEntry(entry.id)
      notifyInfo(t('memories.longTerm.alreadyGone'))
      return
    }
    notifyError(t('memories.longTerm.deleteError'))
  } finally {
    busy.value = false
  }
}

async function onDeleteAll() {
  if (busy.value || total.value === 0) return
  const confirmed = await confirm({
    title: t('memories.longTerm.deleteAllConfirm.title'),
    message: t('memories.longTerm.deleteAllConfirm.message', { count: total.value }, total.value),
    confirmText: t('memories.longTerm.deleteAllConfirm.confirm'),
    danger: true,
  })
  if (!confirmed) return
  busy.value = true
  try {
    const result = await deleteAllMessageDigestEntries()
    entries.value = []
    total.value = 0
    notifySuccess(t('memories.longTerm.deletedAll', { count: result.deleted }, result.deleted))
  } catch {
    notifyError(t('memories.longTerm.deleteAllError'))
    await reload()
  } finally {
    busy.value = false
  }
}

onMounted(() => {
  void loadPage(1, true)
})
</script>
