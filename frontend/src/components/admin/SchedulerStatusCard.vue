<template>
  <section
    v-if="store.canRead"
    class="surface-card p-5 space-y-4"
    data-testid="section-scheduler-status"
  >
    <div class="flex items-start justify-between gap-4 flex-wrap">
      <div class="flex-1 min-w-0">
        <h3 class="text-base font-semibold txt-primary mb-2">
          {{ $t('settings.features.scheduler.title') }}
        </h3>
        <p class="txt-secondary text-sm" data-testid="scheduler-state-line">{{ stateLine }}</p>
      </div>
      <span
        v-if="store.status"
        :class="[
          'px-4 py-2 rounded-lg text-xs font-bold uppercase tracking-wide whitespace-nowrap flex-shrink-0',
          statusClass,
        ]"
        data-testid="scheduler-status-pill"
      >
        {{ statusLabel }}
      </span>
    </div>

    <ul v-if="store.status" class="space-y-3" data-testid="list-scheduler-lanes">
      <li
        v-for="lane in lanes"
        :key="lane.id"
        class="space-y-1"
        data-testid="item-scheduler-lane"
        :data-lane="lane.id"
      >
        <div class="flex items-center justify-between gap-3 text-sm">
          <span class="inline-flex items-center gap-2 min-w-0 txt-primary font-medium">
            <component
              :is="lane.icon"
              class="w-4 h-4 flex-shrink-0"
              :class="lane.tone"
              aria-hidden="true"
            />
            {{ lane.label }}
          </span>
          <span :class="['flex-shrink-0', lane.tone]" data-testid="scheduler-lane-time">
            {{ lane.timeLabel }}
          </span>
        </div>
        <p v-if="lane.failures" class="text-sm text-[var(--status-error-text)]">
          {{ lane.failures }}
        </p>
        <p v-if="lane.unfinished" class="text-sm text-[var(--status-warning-text)]">
          {{ lane.unfinished }}
        </p>
      </li>
    </ul>

    <div class="flex items-center gap-3 flex-wrap">
      <a
        v-if="store.status && store.status.state !== 'running'"
        :href="SCHEDULER_DOCS_URL"
        target="_blank"
        rel="noopener noreferrer"
        :class="[
          store.status.state === 'never' ? 'btn-primary' : 'btn-secondary',
          'px-4 py-2.5 rounded-lg text-sm font-medium inline-flex items-center gap-2',
        ]"
        data-testid="link-scheduler-docs"
      >
        <ArrowTopRightOnSquareIcon class="w-4 h-4" aria-hidden="true" />
        {{ $t('settings.features.scheduler.howToStart') }}
      </a>
      <button
        type="button"
        class="btn-secondary px-4 py-2.5 rounded-lg text-sm font-medium inline-flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed"
        data-testid="btn-scheduler-refresh"
        :disabled="store.loading"
        @click="refresh"
      >
        <ArrowPathIcon
          class="w-4 h-4"
          :class="{ 'animate-spin': store.loading }"
          aria-hidden="true"
        />
        {{ $t('settings.features.scheduler.refresh') }}
      </button>
    </div>

    <i18n-t
      v-if="store.status"
      keypath="settings.features.scheduler.owner"
      tag="p"
      scope="global"
      class="txt-secondary text-xs"
      data-testid="text-scheduler-owner"
    >
      <template #command>
        <code class="font-mono text-xs txt-primary">docker compose restart scheduler</code>
      </template>
    </i18n-t>
  </section>
</template>

<script setup lang="ts">
import { computed, onMounted, watch, type Component } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  ArrowPathIcon,
  ArrowTopRightOnSquareIcon,
  CheckCircleIcon,
  ClockIcon,
  ExclamationTriangleIcon,
  XCircleIcon,
} from '@heroicons/vue/24/outline'
import { useSchedulerStore } from '@/stores/scheduler'
import type { SchedulerStatus } from '@/services/api/scheduler'
import { useDateFormat } from '@/composables/useDateFormat'

const SCHEDULER_DOCS_URL =
  'https://github.com/metadist/synaplan/blob/main/docs/ADMIN.md#background-jobs-scheduler'

const LANE_ORDER = ['tick', 'tasks', 'hourly', 'daily', 'health'] as const

type SchedulerLane = SchedulerStatus['lanes'][number]
type LaneId = (typeof LANE_ORDER)[number]

const JOB_LABEL_KEYS: Record<string, string> = {
  'app:media:reap-jobs': 'settings.features.scheduler.jobs.mediaReap',
  'app:chat:reap-stuck': 'settings.features.scheduler.jobs.chatReap',
  'app:desktop:reap-jobs': 'settings.features.scheduler.jobs.desktopReap',
  'app:process-mail-handlers': 'settings.features.scheduler.jobs.mailHandlers',
  'app:process-emails': 'settings.features.scheduler.jobs.processEmails',
  'app:saved-tasks:tick': 'settings.features.scheduler.jobs.savedTasks',
  'app:files:reap-ephemeral': 'settings.features.scheduler.jobs.ephemeralFiles',
  'app:approvals:expire': 'settings.features.scheduler.jobs.approvalsExpire',
  'app:models:discover': 'settings.features.scheduler.jobs.modelsDiscover',
  'app:updates:check': 'settings.features.scheduler.jobs.updatesCheck',
  'app:models:check-availability': 'settings.features.scheduler.jobs.modelsAvailability',
  'app:digest:run': 'settings.features.scheduler.jobs.digest',
  'app:selfaware:sync-docs': 'settings.features.scheduler.jobs.syncDocs',
  'app:approvals:digest': 'settings.features.scheduler.jobs.approvalsDigest',
  'app:sync-model-prices': 'settings.features.scheduler.jobs.syncPrices',
  'app:model:health-check': 'settings.features.scheduler.jobs.modelHealth',
}

const store = useSchedulerStore()
const { t, locale } = useI18n()
const { formatRelativeTime } = useDateFormat()

onMounted(() => {
  void store.load()
})

watch(
  () => store.canRead,
  (allowed) => {
    if (allowed) void store.load()
  }
)

function refresh(): void {
  void store.load()
}

function jobName(command: string): string {
  const key = JOB_LABEL_KEYS[command]
  return key ? t(key) : command
}

function jobList(commands: string[]): string {
  const names = commands.map(jobName)
  return new Intl.ListFormat(locale.value, { type: 'conjunction', style: 'long' }).format(names)
}

function relativeTime(unixSeconds: number | null): string {
  if (unixSeconds === null) return t('settings.features.scheduler.notRunYet')
  return formatRelativeTime(new Date(unixSeconds * 1000))
}

const stateLine = computed(() => {
  if (store.loadFailed && store.status === null) {
    return t('settings.features.scheduler.error')
  }
  const current = store.status
  if (!current) return t('settings.features.scheduler.loading')
  if (current.state === 'never') return t('settings.features.scheduler.never')
  const time =
    current.lastRunAt === null
      ? t('settings.features.scheduler.notRunYet')
      : formatRelativeTime(new Date(current.lastRunAt * 1000))
  if (current.state === 'stale') return t('settings.features.scheduler.stale', { time })
  return t('settings.features.scheduler.running', { time })
})

const statusLabel = computed(() => {
  switch (store.status?.state) {
    case 'running':
      return t('settings.features.scheduler.statusRunning')
    case 'stale':
      return t('settings.features.scheduler.statusStopped')
    default:
      return t('settings.features.scheduler.statusNever')
  }
})

const statusClass = computed(() => {
  switch (store.status?.state) {
    case 'running':
      return 'bg-[var(--status-success-muted)] text-[var(--status-success-text)]'
    case 'stale':
      return 'bg-[var(--status-error-muted)] text-[var(--status-error-text)]'
    default:
      return 'bg-[var(--status-neutral-muted)] text-[var(--status-neutral-text)]'
  }
})

const jobsRunning = computed(() => store.status?.state === 'running')

function laneTone(lane: SchedulerLane): string {
  if (lane.failedJobs.length > 0) return 'text-[var(--status-error-text)]'
  if (lane.unfinishedJobs.length > 0) return 'text-[var(--status-warning-text)]'
  if (lane.lastFinishedAt === null || !jobsRunning.value) return 'text-[var(--status-neutral-text)]'
  return 'text-[var(--status-success-text)]'
}

function laneIcon(lane: SchedulerLane): Component {
  if (lane.failedJobs.length > 0) return XCircleIcon
  if (lane.unfinishedJobs.length > 0) return ExclamationTriangleIcon
  if (lane.lastFinishedAt === null || !jobsRunning.value) return ClockIcon
  return CheckCircleIcon
}

const lanes = computed(() => {
  const byId = new Map((store.status?.lanes ?? []).map((lane) => [lane.lane, lane]))
  return LANE_ORDER.map((id: LaneId) => {
    const lane: SchedulerLane = byId.get(id) ?? {
      lane: id,
      lastStartedAt: null,
      lastFinishedAt: null,
      failedJobs: [],
      unfinishedJobs: [],
    }
    const failures =
      lane.failedJobs.length > 0
        ? t(
            'settings.features.scheduler.failures',
            { count: lane.failedJobs.length, names: jobList(lane.failedJobs) },
            lane.failedJobs.length
          )
        : ''
    const unfinished =
      lane.unfinishedJobs.length > 0
        ? t('settings.features.scheduler.unfinished', { names: jobList(lane.unfinishedJobs) })
        : ''
    return {
      id,
      label: t(`settings.features.scheduler.lanes.${id}`),
      timeLabel: relativeTime(lane.lastFinishedAt),
      tone: laneTone(lane),
      icon: laneIcon(lane),
      failures,
      unfinished,
    }
  })
})
</script>
