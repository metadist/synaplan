<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { PlusIcon } from '@heroicons/vue/24/outline'
import { useI18n } from 'vue-i18n'
import { useNotification } from '@/composables/useNotification'
import PageHeader from '@/components/PageHeader.vue'
import TabNav, { type TabNavItem } from '@/components/TabNav.vue'
import SavedTaskCard from '@/components/config/SavedTaskCard.vue'
import UrlWatchPanel from '@/components/config/UrlWatchPanel.vue'
import NewTaskDialog from '@/components/config/NewTaskDialog.vue'
import EmptyState from '@/components/common/EmptyState.vue'
import { savedTasksApi, type SavedTask } from '@/services/api/savedTasksApi'
import { iamApi, type IamSharedItem } from '@/services/api/iamApi'
import { isIamSharingEnabled } from '@/composables/useIamFeature'
import { isSavedTasksEnabled } from '@/composables/useSavedTasksFeature'

type OverviewTab = 'tasks' | 'watches'

const { t } = useI18n()
const { error: showError } = useNotification()
const route = useRoute()

/** `?task=ID` (Smart Search, links) points at one of the user's own tasks. */
const highlightedTaskId = computed(() => {
  const id = Number(route.query.task)
  return Number.isInteger(id) && id > 0 ? id : null
})

const tasks = ref<SavedTask[]>([])
const sharedItems = ref<IamSharedItem[]>([])
const filterShared = ref(false)
const loading = ref(true)
const activeTab = ref<OverviewTab>('tasks')
const watchesAvailable = ref(true)
const watchCount = ref(0)
const iamSharingEnabled = computed(() => isIamSharingEnabled())

const tabs = computed<TabNavItem[]>(() => {
  const items: TabNavItem[] = [
    {
      id: 'tasks',
      label: t('config.savedTasks.tabs.tasks'),
      icon: 'heroicons:clock',
      testid: 'tab-saved-tasks',
      badge: tasks.value.length,
    },
  ]
  if (watchesAvailable.value) {
    items.push({
      id: 'watches',
      label: t('config.savedTasks.tabs.watches'),
      icon: 'heroicons:globe-alt',
      testid: 'tab-watched-pages',
      badge: watchCount.value,
    })
  }
  return items
})

const onTabChange = (id: string) => {
  if (id === 'tasks' || id === 'watches') {
    activeTab.value = id
  }
}

const onWatchesUnavailable = () => {
  watchesAvailable.value = false
  activeTab.value = 'tasks'
}

const load = async () => {
  if (!isSavedTasksEnabled()) {
    tasks.value = []
    sharedItems.value = []
    loading.value = false
    return
  }
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
  await revealHighlightedTask()
}

const revealHighlightedTask = async () => {
  const id = highlightedTaskId.value
  if (id === null || !tasks.value.some((task) => task.id === id)) return
  activeTab.value = 'tasks'
  filterShared.value = false
  await nextTick()
  document
    .querySelector(`[data-task-id="${id}"]`)
    ?.scrollIntoView({ block: 'center', behavior: 'smooth' })
}

const onUpdated = (task: SavedTask) => {
  tasks.value = tasks.value.map((row) => (row.id === task.id ? task : row))
}

const onCopied = (task: SavedTask) => {
  tasks.value = [task, ...tasks.value.filter((row) => row.id !== task.id)]
  filterShared.value = false
}

const newTaskOpen = ref(false)

const onCreated = (task: SavedTask) => {
  tasks.value = [task, ...tasks.value.filter((row) => row.id !== task.id)]
  filterShared.value = false
  activeTab.value = 'tasks'
}

const onDeleted = (id: number) => {
  tasks.value = tasks.value.filter((row) => row.id !== id)
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
      waitingApprovalCount: 0,
    } satisfies SavedTask,
    ownerName: item.ownerName ?? '',
    sharedVia: item.sharedVia ?? null,
    permission: item.permission,
  }))
)

onMounted(() => {
  void load()
})

watch(highlightedTaskId, () => {
  if (!loading.value) void revealHighlightedTask()
})
</script>

<template>
  <div v-if="isSavedTasksEnabled()" class="space-y-6" data-testid="page-saved-tasks">
    <PageHeader
      :title="$t('config.savedTasks.overviewTitle')"
      :subtitle="$t('config.savedTasks.overviewSubtitle')"
      icon="heroicons:clock"
      tour-id="tasks"
      data-testid="section-header"
    >
      <template #actions>
        <button
          type="button"
          class="btn-primary px-4 py-2.5 text-sm font-medium inline-flex items-center gap-2"
          data-testid="btn-new-task"
          data-tour="tasks-new"
          @click="newTaskOpen = true"
        >
          <PlusIcon class="w-4 h-4" aria-hidden="true" />
          {{ $t('config.savedTasks.newTask.open') }}
        </button>
      </template>
      <TabNav
        v-if="watchesAvailable"
        :model-value="activeTab"
        :tabs="tabs"
        :aria-label="$t('config.savedTasks.overviewTitle')"
        mobile-trigger-testid="tab-saved-tasks-mobile-trigger"
        mobile-menu-testid="tab-saved-tasks-mobile-menu"
        @update:model-value="onTabChange"
      />
    </PageHeader>

    <!-- Both panes stay mounted (v-show) so switching tabs keeps their state and
         the watched-pages pane can report that the feature is off (404). -->
    <div v-show="activeTab === 'tasks'" class="space-y-4" data-testid="saved-tasks-pane">
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

        <EmptyState
          v-if="!filterShared && tasks.length === 0"
          :title="$t('config.savedTasks.overviewEmpty')"
          :hint="$t('config.savedTasks.overviewEmptyHint')"
          :action-label="$t('config.savedTasks.newTask.open')"
          test-id="saved-tasks-empty"
          action-test-id="btn-new-task-empty"
          @action="newTaskOpen = true"
        />

        <ul v-else-if="filterShared" class="space-y-4" data-testid="section-shared-tasks">
          <li v-for="row in sharedTasks" :key="row.task.id">
            <SavedTaskCard
              :task="row.task"
              shared-view
              :owner-name="row.ownerName"
              :shared-via="row.sharedVia"
              :permission="row.permission"
              @copied="onCopied"
            />
          </li>
        </ul>

        <ul v-else class="space-y-4">
          <li
            v-for="task in tasks"
            :key="task.id"
            :data-task-id="task.id"
            :class="task.id === highlightedTaskId && 'rounded-xl ring-2 ring-[var(--brand)]'"
            :data-testid="task.id === highlightedTaskId ? 'saved-task-highlighted' : undefined"
          >
            <SavedTaskCard :task="task" @updated="onUpdated" @deleted="onDeleted" />
          </li>
        </ul>
      </template>
    </div>

    <NewTaskDialog :open="newTaskOpen" @close="newTaskOpen = false" @created="onCreated" />

    <div v-show="activeTab === 'watches'" data-testid="watched-pages-pane">
      <UrlWatchPanel
        @unavailable="onWatchesUnavailable"
        @count="(value: number) => (watchCount = value)"
      />
    </div>
  </div>
</template>
