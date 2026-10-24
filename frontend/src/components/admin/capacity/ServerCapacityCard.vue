<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { ArrowTopRightOnSquareIcon } from '@heroicons/vue/24/outline'
import CapacityBar from './CapacityBar.vue'
import type { CapacityServer } from '@/services/api/capacityApi'
import { useConfigStore } from '@/stores/config'
import { formatBytes, logCommand, needsSizingHint, secondsSince } from '@/utils/capacityFacts'

/**
 * One server on Admin › System › Capacity (architecture update AU7, J-AU-2):
 * how many AI answers it holds against how many it can, its memory and
 * processor, today's peak, and — under Technical details — where it runs and
 * where its log lines go. Workers and the scheduler that share its machine are
 * compact rows underneath. Every word is a fact the watchdog reported.
 */
const props = defineProps<{
  server: CapacityServer
  helpers: CapacityServer[]
  connected: boolean
  now: Date
}>()

const { t, locale } = useI18n()
const configStore = useConfigStore()

// `updated_at` is the time of the last report that exists, live or expired.
const silentFor = computed(() =>
  props.server.reporting ? null : secondsSince(props.server.updated_at, props.now)
)

const isWeb = computed(() => props.server.role === 'web')

const silentSentence = computed(() => {
  const seconds = silentFor.value
  if (seconds === null) return t('capacity.card.silentUnknown')
  if (seconds < 120) return t('capacity.card.silentSeconds', { count: seconds })
  return t('capacity.card.silentMinutes', { count: Math.round(seconds / 60) })
})

const memoryReading = computed(() => {
  const { rss_bytes, limit_bytes, limited } = props.server.memory
  return limited && limit_bytes > 0
    ? t('capacity.card.memoryOf', { used: formatBytes(rss_bytes), total: formatBytes(limit_bytes) })
    : t('capacity.card.memoryUnlimited', { used: formatBytes(rss_bytes) })
})

const cpuPercent = computed(() => Math.round((props.server.cpu.usage_ratio ?? 0) * 100))
const cpuReading = computed(() =>
  props.server.cpu.usage_ratio === null
    ? t('capacity.card.processorMeasuring', { cores: props.server.cpu.cores })
    : t('capacity.card.processorOf', { percent: cpuPercent.value, cores: props.server.cpu.cores })
)

const peakNote = computed(() => {
  const peak = props.server.peak_today
  if (!peak.at || peak.streams_total <= 0) return undefined
  const at = new Date(peak.at)
  const time = Number.isNaN(at.getTime())
    ? ''
    : new Intl.DateTimeFormat(locale.value, { timeStyle: 'short' }).format(at)
  return t('capacity.card.peakToday', { count: peak.streams_busy, time })
})

const sizingHint = computed(() =>
  needsSizingHint(props.server)
    ? t('capacity.card.sizingHint', {
        server: props.server.id,
        peak: props.server.peak_today.streams_busy,
        total: props.server.peak_today.streams_total || props.server.streams.total,
      })
    : null
)

const runtimeLabel = computed(() => {
  switch (props.server.runtime) {
    case 'kubernetes':
      return t('capacity.card.runtime.kubernetes')
    case 'compose':
      return t('capacity.card.runtime.docker')
    default:
      return t('capacity.card.runtime.host')
  }
})

const placeParts = computed(() => {
  const { place } = props.server
  const parts: string[] = [runtimeLabel.value]
  if (place.host) parts.push(t('capacity.card.place.machine', { name: place.host }))
  if (place.pod) parts.push(t('capacity.card.place.pod', { name: place.pod }))
  if (place.deployment) parts.push(t('capacity.card.place.deployment', { name: place.deployment }))
  if (place.namespace) parts.push(t('capacity.card.place.namespace', { name: place.namespace }))
  if (place.memory_limit)
    parts.push(t('capacity.card.place.memoryLimit', { value: place.memory_limit }))
  if (place.cpu_request)
    parts.push(t('capacity.card.place.cpuRequest', { value: place.cpu_request }))
  return parts
})

const poolsSentence = computed(() =>
  props.server.streams.total > 0 || props.server.api.total > 0
    ? t('capacity.card.pools', { streams: props.server.streams.total, api: props.server.api.total })
    : t('capacity.card.poolsSingle', { count: props.server.threads.count })
)

const command = computed(() => logCommand(props.server))

// The event ring has no page of its own yet (AU33 adds the Requests tab);
// until then the filtered API answer is the one place that shows this
// server's recent warnings, and the admin's session opens it directly.
const eventsHref = computed(() => {
  const host = encodeURIComponent(props.server.logs.event_ring_host || props.server.hostname)
  return `${configStore.apiBaseUrl}/api/v1/admin/logs?host=${host}&since_minutes=60`
})
</script>

<template>
  <article
    class="surface-card rounded-2xl p-4 sm:p-5 space-y-3"
    :class="server.reporting ? '' : 'opacity-70 border-l-4 border-[var(--status-warning)]'"
    data-testid="item-capacity-server"
    :data-server="server.id"
    :data-reporting="server.reporting ? 'yes' : 'no'"
  >
    <header class="flex items-start justify-between gap-3 flex-wrap">
      <div class="min-w-0">
        <h3 class="text-base font-semibold txt-primary truncate" data-testid="text-server-name">
          {{ server.id }}
          <span
            v-if="!isWeb"
            class="ml-2 align-middle surface-chip px-2 py-0.5 rounded-full text-xs font-normal txt-secondary"
            data-testid="badge-server-role"
          >
            {{ t(`capacity.role.${server.role}`) }}
          </span>
        </h3>
        <p v-if="connected" class="text-sm txt-secondary" data-testid="text-connected-here">
          {{ t('capacity.card.connectedHere') }}
        </p>
      </div>
      <span
        v-if="!server.reporting"
        class="px-3 py-1 rounded-full text-xs font-semibold bg-[var(--status-warning-muted)] text-[var(--status-warning-text)]"
        data-testid="badge-server-silent"
      >
        {{ silentSentence }}
      </span>
      <span
        v-else
        class="px-3 py-1 rounded-full text-xs font-semibold bg-[var(--status-success-muted)] text-[var(--status-success-text)]"
        data-testid="badge-server-reporting"
      >
        {{ t('capacity.card.reporting') }}
      </span>
    </header>

    <div class="space-y-2">
      <CapacityBar
        v-if="isWeb"
        :label="t('capacity.card.aiAnswers')"
        :used="server.streams.busy"
        :total="server.streams.total"
        :reading="
          server.streams.total > 0
            ? t('capacity.card.ofAtOnce', {
                used: server.streams.busy,
                total: server.streams.total,
              })
            : t('capacity.card.noPools')
        "
        :note="peakNote"
        test-id="bar-ai-answers"
      />
      <CapacityBar
        v-if="isWeb"
        :label="t('capacity.card.otherRequests')"
        :used="server.api.busy"
        :total="server.api.total"
        :reading="
          server.api.total > 0
            ? t('capacity.card.ofAtOnce', { used: server.api.busy, total: server.api.total })
            : t('capacity.card.noPools')
        "
        test-id="bar-other-requests"
      />
      <CapacityBar
        :label="t('capacity.card.memory')"
        :used="server.memory.rss_bytes"
        :total="server.memory.limited ? server.memory.limit_bytes : 0"
        :reading="memoryReading"
        test-id="bar-memory"
      />
      <CapacityBar
        :label="t('capacity.card.processor')"
        :used="cpuPercent"
        :total="server.cpu.usage_ratio === null ? 0 : 100"
        :reading="cpuReading"
        test-id="bar-processor"
      />
    </div>

    <p
      v-if="sizingHint"
      class="text-sm txt-primary bg-[var(--status-warning-muted)] rounded-xl px-3 py-2"
      data-testid="text-sizing-hint"
    >
      {{ sizingHint }}
    </p>

    <details class="group" data-testid="details-technical">
      <summary class="cursor-pointer text-sm txt-secondary select-none">
        {{ t('capacity.card.technical') }}
      </summary>
      <dl class="mt-2 space-y-1.5 text-sm">
        <div class="flex flex-wrap gap-x-2">
          <dt class="txt-secondary">{{ t('capacity.card.runsOn') }}</dt>
          <dd class="txt-primary" data-testid="text-place">{{ placeParts.join(' · ') }}</dd>
        </div>
        <div class="flex flex-wrap gap-x-2">
          <dt class="txt-secondary">{{ t('capacity.card.workers') }}</dt>
          <dd class="txt-primary">
            {{ poolsSentence }} ·
            {{
              t('capacity.card.version', {
                version: server.version,
                watchdog: server.watchdog_version,
              })
            }}
          </dd>
        </div>
        <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
          <dt class="txt-secondary">{{ t('capacity.card.logs') }}</dt>
          <dd class="flex flex-wrap items-center gap-2">
            <code
              v-if="command"
              class="surface-chip px-2 py-0.5 rounded-lg text-xs txt-primary"
              data-testid="text-log-command"
              >{{ command }}</code
            >
            <span v-else class="txt-primary" data-testid="text-log-command">{{
              t('capacity.card.logsStderr')
            }}</span>
            <a
              :href="eventsHref"
              target="_blank"
              rel="noopener"
              class="inline-flex items-center gap-1 text-[var(--brand)] hover:underline"
              data-testid="link-recent-events"
            >
              {{ t('capacity.card.openEvents') }}
              <ArrowTopRightOnSquareIcon class="w-3.5 h-3.5" />
            </a>
          </dd>
        </div>
        <p class="txt-secondary text-xs">{{ t('capacity.card.howToStop') }}</p>
      </dl>
    </details>

    <ul
      v-if="helpers.length > 0"
      class="divide-y divide-light-border/20 dark:divide-dark-border/10 border-t border-light-border/20 dark:border-dark-border/10 pt-1"
      data-testid="list-helpers"
    >
      <li
        v-for="helper in helpers"
        :key="helper.id"
        class="py-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm"
        data-testid="item-capacity-helper"
        :data-server="helper.id"
      >
        <span class="font-medium txt-primary">{{ helper.id }}</span>
        <span class="surface-chip px-2 py-0.5 rounded-full text-xs txt-secondary">{{
          t(`capacity.role.${helper.role}`)
        }}</span>
        <span class="txt-secondary tabular-nums">{{ formatBytes(helper.memory.rss_bytes) }}</span>
        <span class="txt-secondary tabular-nums">{{
          t('capacity.card.processorShort', {
            percent: Math.round((helper.cpu.usage_ratio ?? 0) * 100),
          })
        }}</span>
        <span v-if="!helper.reporting" class="text-xs text-[var(--status-warning-text)]">{{
          t('capacity.card.helperSilent')
        }}</span>
        <code
          v-if="logCommand(helper)"
          class="surface-chip px-2 py-0.5 rounded-lg text-xs txt-primary"
          >{{ logCommand(helper) }}</code
        >
      </li>
    </ul>
  </article>
</template>
