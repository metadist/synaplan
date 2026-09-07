<template>
  <section class="surface-card rounded-xl p-5 space-y-4" data-testid="section-assistant-publish">
    <h2 class="txt-primary font-medium text-lg">{{ $t('assistants.publish') }}</h2>

    <div class="flex flex-wrap gap-2">
      <span
        class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
        :class="statusBadgeClass"
        data-testid="badge-assistant-status"
      >
        {{ statusLabel }}
      </span>
    </div>

    <label class="block">
      <span class="txt-secondary text-sm">{{ $t('assistants.changelog') }}</span>
      <textarea
        v-model="changelog"
        rows="3"
        class="mt-1 w-full px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
        :placeholder="$t('assistants.changelogPlaceholder')"
        data-testid="input-publish-changelog"
      />
    </label>

    <div class="flex flex-wrap gap-2">
      <button
        type="button"
        class="btn-primary px-4 py-2.5 rounded-lg text-sm font-medium"
        :disabled="publishing || store.current?.status === 'archived'"
        data-testid="btn-publish-assistant"
        @click="onPublish"
      >
        {{ $t('assistants.publish') }}
      </button>
      <button
        type="button"
        class="btn-secondary px-4 py-2.5 rounded-lg text-sm font-medium"
        :disabled="store.current?.status !== 'published'"
        data-testid="btn-share-assistant"
        @click="shareOpen = true"
      >
        {{ $t('assistants.share') }}
      </button>
      <button
        v-if="store.current?.status === 'published'"
        type="button"
        class="btn-secondary px-4 py-2.5 rounded-lg text-sm font-medium"
        data-testid="btn-archive-assistant"
        @click="onArchive"
      >
        {{ $t('assistants.archive') }}
      </button>
      <button
        v-else-if="store.current?.status === 'archived'"
        type="button"
        class="btn-secondary px-4 py-2.5 rounded-lg text-sm font-medium"
        data-testid="btn-unarchive-assistant"
        @click="onUnarchive"
      >
        {{ $t('assistants.unarchive') }}
      </button>
    </div>

    <ul v-if="versions.length > 0" class="space-y-2" data-testid="list-assistant-versions">
      <li v-for="row in versions" :key="row.id ?? row.version" class="txt-secondary text-sm">
        v{{ row.version }}
        <span v-if="row.publishedByName"> · {{ row.publishedByName }}</span>
        <span v-if="row.changelog"> — {{ row.changelog }}</span>
      </li>
    </ul>
    <p v-else class="txt-secondary text-sm">{{ $t('assistants.noVersions') }}</p>

    <AssistantUsagePanel :rows="usage.byVersion" />

    <ShareDialog
      :is-open="shareOpen"
      kind="agent"
      :resource-id="resourceId"
      :resource-name="store.current?.name ?? ''"
      @close="shareOpen = false"
    />
  </section>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useDialog } from '@/composables/useDialog'
import { useNotification } from '@/composables/useNotification'
import { agentsApi, type AgentUsage, type AgentVersionCard } from '@/services/api/agentsApi'
import { useAgentsStore } from '@/stores/agents'
import ShareDialog from '@/components/iam/ShareDialog.vue'
import AssistantUsagePanel from './AssistantUsagePanel.vue'

const store = useAgentsStore()
const { t } = useI18n()
const { confirm } = useDialog()
const { success, error } = useNotification()

const changelog = ref('')
const publishing = ref(false)
const shareOpen = ref(false)
const versions = ref<AgentVersionCard[]>([])
const usage = ref<AgentUsage>({ byVersion: [], byDay: [] })

const resourceId = computed(() => String(store.current?.id ?? ''))
const statusLabel = computed(() => {
  const status = store.current?.status
  if (status === 'published') return t('assistants.statusPublished')
  if (status === 'archived') return t('assistants.statusArchived')
  return t('assistants.statusDraft')
})
const statusBadgeClass = computed(() =>
  store.current?.status === 'published'
    ? 'bg-[var(--brand)]/10 text-[var(--brand)]'
    : 'bg-[var(--bg-chip)] txt-secondary'
)

async function reload(): Promise<void> {
  const id = store.current?.id
  if (id == null) return
  try {
    versions.value = await agentsApi.versions(id)
    usage.value = await agentsApi.usage(id)
  } catch {
    versions.value = []
    usage.value = { byVersion: [], byDay: [] }
  }
}

async function onPublish(): Promise<void> {
  const id = store.current?.id
  if (id == null) return
  const ok = await confirm({
    title: t('assistants.publish'),
    message: t('assistants.publishConfirm'),
  })
  if (!ok) return
  publishing.value = true
  try {
    await store.saveDraft()
    await agentsApi.publish(id, changelog.value)
    await store.load(id)
    changelog.value = ''
    await reload()
    success(t('assistants.publishSuccess'))
  } catch (err) {
    const message = err instanceof Error ? err.message : ''
    error(
      message.includes('nothing_changed')
        ? t('assistants.nothingChanged')
        : t('assistants.publishFailed')
    )
  } finally {
    publishing.value = false
  }
}

async function onArchive(): Promise<void> {
  const id = store.current?.id
  if (id == null) return
  const ok = await confirm({
    title: t('assistants.archive'),
    message: t('assistants.archiveConfirm'),
    danger: true,
  })
  if (!ok) return
  try {
    await agentsApi.update(id, { status: 'archived' })
    await store.load(id)
    success(t('assistants.archiveSuccess'))
  } catch {
    error(t('assistants.archiveFailed'))
  }
}

async function onUnarchive(): Promise<void> {
  const id = store.current?.id
  if (id == null) return
  try {
    await agentsApi.update(id, { status: 'published' })
    await store.load(id)
  } catch {
    error(t('assistants.archiveFailed'))
  }
}

onMounted(() => {
  void reload()
})
</script>
