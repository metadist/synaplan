<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useNotification } from '@/composables/useNotification'
import PageHeader from '@/components/PageHeader.vue'
import SavedTaskCard from '@/components/config/SavedTaskCard.vue'
import { savedTasksApi, type SavedTask } from '@/services/api/savedTasksApi'
import { iamApi, type IamSharedItem } from '@/services/api/iamApi'
import { isIamSharingEnabled } from '@/composables/useIamFeature'

const { t } = useI18n()
const { error: showError } = useNotification()

const tasks = ref<SavedTask[]>([])
const sharedItems = ref<IamSharedItem[]>([])
const filterShared = ref(false)
const loading = ref(true)
const iamSharingEnabled = computed(() => isIamSharingEnabled())

const load = async () => {
  loading.value = true
  try {
    tasks.value = await savedTasksApi.list()
    if (isIamSharingEnabled()) {
      sharedItems.value = await iamApi.listSharedWithMe('saved_task')
    } else {
      sharedItems.value = []
    }
  } catch {
    showError(t('config.savedTasks.loadFailed'))
    tasks.value = []
  } finally {
    loading.value = false
  }
}

const onUpdated = (task: SavedTask) => {
  tasks.value = tasks.value.map((row) => (row.id === task.id ? task : row))
}

const onCopied = (task: SavedTask) => {
  tasks.value = [task, ...tasks.value.filter((row) => row.id !== task.id)]
  filterShared.value = false
}

const sharedTasks = computed(() =>
  sharedItems.value.map((item) => ({
    task: {
      id: Number(item.id),
      promptId: Number(item.meta?.promptId ?? 0),
      name: item.name,
      enabled: true,
      triggerType: String(item.meta?.triggerType ?? 'manual'),
      triggerConfig: null,
      graph: null,
      allowUnattended: false,
      chatId: null,
      nextRunAt: null,
      lastRunAt: typeof item.meta?.lastRunAt === 'string' ? item.meta.lastRunAt : null,
      consecutiveFailures: 0,
      autoPaused: false,
      summary: { key: 'config.savedTasks.summary.simple', params: { when: 'manual' } },
      instructionPreview: null,
    } satisfies SavedTask,
    ownerName: item.ownerName ?? '',
  }))
)

onMounted(() => {
  void load()
})
</script>

<template>
  <div class="space-y-6" data-testid="page-saved-tasks">
    <PageHeader
      :title="$t('config.savedTasks.overviewTitle')"
      :subtitle="$t('config.savedTasks.overviewSubtitle')"
      icon="heroicons:clock"
      data-testid="section-header"
    />

    <p v-if="loading" class="txt-secondary text-sm px-1">
      {{ $t('config.savedTasks.overviewLoading') }}
    </p>

    <template v-else>
      <button
        v-if="iamSharingEnabled && (sharedItems.length > 0 || filterShared)"
        type="button"
        class="px-3 py-1.5 rounded-full text-sm font-medium"
        :class="
          filterShared
            ? 'bg-[var(--brand)] text-white'
            : 'surface-chip txt-secondary hover:txt-primary'
        "
        data-testid="btn-shared-with-me"
        @click="filterShared = !filterShared"
      >
        {{ $t('iam.sharedWithMe') }}
        <span class="ml-1 text-xs">{{ sharedItems.length }}</span>
      </button>

      <p
        v-if="!filterShared && tasks.length === 0"
        class="txt-secondary text-sm px-1"
        data-testid="saved-tasks-empty"
      >
        {{ $t('config.savedTasks.overviewEmpty') }}
        <RouterLink to="/ai/instructions" class="txt-primary underline">
          {{ $t('nav.configTaskPrompts') }}
        </RouterLink>
      </p>

      <ul v-else-if="filterShared" class="space-y-4" data-testid="section-shared-tasks">
        <li v-for="row in sharedTasks" :key="row.task.id">
          <SavedTaskCard
            :task="row.task"
            shared-view
            :owner-name="row.ownerName"
            @copied="onCopied"
          />
        </li>
      </ul>

      <ul v-else class="space-y-4">
        <li v-for="task in tasks" :key="task.id">
          <SavedTaskCard :task="task" @updated="onUpdated" />
        </li>
      </ul>
    </template>
  </div>
</template>
