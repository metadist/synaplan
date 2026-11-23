<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import CapacityPartsBar from './CapacityPartsBar.vue'
import { useCapacityLabels } from '@/composables/useCapacityLabels'
import { useNotification } from '@/composables/useNotification'
import type { SlowRequest } from '@/services/api/capacityApi'
import { formatDuration, largestPart, requestParts } from '@/utils/capacityTimeline'

/**
 * One of the slowest requests of the last hour: a summary line that opens to
 * where its time went, call by call. Counts and timings only; the record
 * holds no content, prompt or argument.
 */
const props = defineProps<{ request: SlowRequest }>()

const { t, locale } = useI18n()
const { label } = useCapacityLabels()
const { success, error } = useNotification()

const parts = computed(() => requestParts(props.request))
const largest = computed(() => largestPart(parts.value))
const time = computed(() =>
  new Date(props.request.ts * 1000).toLocaleTimeString(locale.value, {
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
  })
)
const statusLabel = computed(() => label('status', props.request.status_class))
const routeLabel = computed(() => label('routeClass', props.request.route_class))

async function copyId(): Promise<void> {
  try {
    await navigator.clipboard.writeText(props.request.id)
    success(t('capacity.requests.idCopied'))
  } catch {
    error(t('capacity.requests.idCopyFailed'))
  }
}
</script>

<template>
  <details
    class="group surface-card rounded-xl border border-light-border/30 dark:border-dark-border/20"
    data-testid="capacity-slow-request"
    :data-request-id="request.id"
  >
    <summary class="cursor-pointer list-none p-3 sm:p-4 space-y-2">
      <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 text-sm">
        <span class="txt-secondary tabular-nums">{{ time }}</span>
        <span class="txt-primary font-medium">{{ routeLabel }}</span>
        <span class="txt-secondary">{{ statusLabel }}</span>
        <span
          class="ml-auto txt-primary font-semibold tabular-nums"
          data-testid="capacity-slow-total"
        >
          {{ formatDuration(request.total_ms) }}
        </span>
      </div>
      <CapacityPartsBar :parts="parts" :total="request.total_ms" />
      <p v-if="largest" class="text-xs txt-secondary" data-testid="capacity-slow-largest">
        {{
          t('capacity.requests.mostly', {
            part: t(`capacity.parts.${largest.key}`),
            duration: formatDuration(largest.ms),
          })
        }}
      </p>
    </summary>

    <div class="px-3 pb-4 sm:px-4 space-y-4 text-sm" data-testid="capacity-slow-detail">
      <CapacityPartsBar :parts="parts" :total="request.total_ms" legend />

      <section v-if="request.ai.length > 0" class="space-y-1">
        <h4 class="font-medium txt-primary">{{ t('capacity.requests.aiCalls') }}</h4>
        <ul class="space-y-1">
          <li
            v-for="(call, index) in request.ai"
            :key="`ai-${index}`"
            class="flex flex-wrap gap-x-3 txt-secondary"
          >
            <span class="txt-primary">{{ call.provider }} · {{ call.model }}</span>
            <span>{{ label('aiSource', call.source) }}</span>
            <span class="tabular-nums">{{ formatDuration(call.duration_ms) }}</span>
            <span v-if="call.ttft_ms !== null" class="tabular-nums">
              {{ t('capacity.requests.firstWord', { duration: formatDuration(call.ttft_ms) }) }}
            </span>
            <span>{{
              t('capacity.requests.tokens', { in: call.tokens_in, out: call.tokens_out })
            }}</span>
          </li>
        </ul>
      </section>

      <section v-if="request.tools.length > 0" class="space-y-1">
        <h4 class="font-medium txt-primary">{{ t('capacity.requests.toolCalls') }}</h4>
        <ul class="space-y-1">
          <li
            v-for="(call, index) in request.tools"
            :key="`tool-${index}`"
            class="flex flex-wrap gap-x-3 txt-secondary"
          >
            <span class="txt-primary">{{ label('toolSource', call.source) }}</span>
            <span>{{ label('toolKind', call.kind) }}</span>
            <span>{{ label('toolOutcome', call.outcome) }}</span>
            <span class="tabular-nums">{{ formatDuration(call.duration_ms) }}</span>
          </li>
        </ul>
      </section>

      <section v-if="request.egress.length > 0" class="space-y-1">
        <h4 class="font-medium txt-primary">{{ t('capacity.requests.outboundCalls') }}</h4>
        <ul class="space-y-1">
          <li
            v-for="(call, index) in request.egress"
            :key="`egress-${index}`"
            class="flex flex-wrap gap-x-3 txt-secondary"
          >
            <span class="txt-primary break-all">{{
              call.destination === 'typed' ? t('capacity.outbound.typed') : call.destination
            }}</span>
            <span>{{ label('egressStatus', call.status_class) }}</span>
            <span class="tabular-nums">{{ formatDuration(call.duration_ms) }}</span>
          </li>
        </ul>
      </section>

      <p class="txt-secondary">
        {{
          t(
            'capacity.requests.database',
            { count: request.db_queries, duration: formatDuration(request.db_ms) },
            request.db_queries
          )
        }}
      </p>

      <div class="flex flex-wrap items-center gap-3">
        <span class="txt-secondary">{{ t('capacity.requests.requestId') }}</span>
        <code class="text-xs txt-primary break-all" data-testid="capacity-slow-id">{{
          request.id
        }}</code>
        <button
          type="button"
          class="btn-secondary px-4 py-2.5 text-sm font-medium"
          data-testid="btn-capacity-copy-id"
          @click="copyId"
        >
          {{ t('capacity.requests.copyId') }}
        </button>
      </div>
    </div>
  </details>
</template>
