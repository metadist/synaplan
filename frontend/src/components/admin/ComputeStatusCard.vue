<template>
  <section class="surface-card p-5 space-y-4" data-testid="section-compute-status">
    <div class="flex items-start justify-between gap-4">
      <div class="flex-1 min-w-0">
        <div class="flex items-center gap-2 flex-wrap mb-2">
          <h3 class="text-base font-semibold txt-primary">
            {{ $t('settings.features.compute.title') }}
          </h3>
          <span
            v-if="compute.reachable && tierLabel"
            class="px-2.5 py-1 rounded-md text-xs font-semibold bg-[var(--status-info)] text-white shadow-sm"
            data-testid="compute-tier-badge"
          >
            {{ tierLabel }}
          </span>
        </div>
        <p class="txt-secondary text-sm" data-testid="compute-state-line">{{ stateLine }}</p>
      </div>
      <span
        :class="[
          'px-4 py-2 rounded-lg text-xs font-bold uppercase tracking-wide whitespace-nowrap flex-shrink-0',
          statusClass,
        ]"
        data-testid="compute-status-pill"
      >
        {{ statusLabel }}
      </span>
    </div>

    <p
      v-if="compute.reachable && !compute.tierMeetsRequirement"
      class="text-sm font-medium text-[var(--status-warning-text)]"
      data-testid="compute-posture-warning"
    >
      {{ $t('settings.features.compute.postureWarning', { tier: tierLabel }) }}
    </p>

    <div v-if="compute.reachable" class="space-y-3">
      <div>
        <div class="flex items-center justify-between gap-3 text-sm mb-1.5">
          <span class="txt-secondary">{{ $t('settings.features.compute.capacity') }}</span>
          <span class="txt-primary font-medium" data-testid="compute-capacity-text">
            {{
              $t('settings.features.compute.runningOf', {
                running: compute.capacity.running,
                max: compute.capacity.maxConcurrent,
              })
            }}<span v-if="compute.capacity.queued > 0">
              ·
              {{ $t('settings.features.compute.queued', { count: compute.capacity.queued }) }}</span
            >
          </span>
        </div>
        <div
          class="h-2 rounded-full bg-black/10 dark:bg-white/10 overflow-hidden"
          role="progressbar"
          :aria-valuenow="compute.capacity.running"
          :aria-valuemin="0"
          :aria-valuemax="compute.capacity.maxConcurrent"
          :aria-label="$t('settings.features.compute.capacity')"
          data-testid="compute-capacity-bar"
        >
          <div
            class="h-full rounded-full bg-[var(--brand)] transition-all"
            :style="{ width: `${capacityPct}%` }"
          />
        </div>
      </div>

      <div v-if="compute.images.length > 0" class="flex items-center gap-2 flex-wrap">
        <span class="txt-secondary text-sm">{{ $t('settings.features.compute.images') }}</span>
        <code
          v-for="image in compute.images"
          :key="image.key"
          class="px-2 py-1 rounded-md text-xs font-mono surface-chip txt-primary"
          data-testid="compute-image-chip"
          >{{ image.key }}:{{ image.digest }}</code
        >
      </div>

      <div class="flex items-center gap-4 flex-wrap text-sm">
        <span class="txt-secondary" data-testid="compute-runs-24h">
          {{ $t('settings.features.compute.runs24', { count: compute.runsLast24h }) }}
        </span>
        <span class="txt-secondary" data-testid="compute-failed-24h">
          {{ $t('settings.features.compute.failed24', { count: compute.failedLast24h }) }}
        </span>
      </div>
    </div>

    <div class="flex items-center gap-3 flex-wrap pt-1">
      <RouterLink
        :to="{ path: '/admin/config', query: { tab: 'processing', section: 'compute' } }"
        class="btn-secondary px-4 py-2.5 rounded-lg text-sm font-medium"
        data-testid="link-compute-config"
      >
        {{ $t('settings.features.compute.openConfig') }}
      </RouterLink>
      <button
        v-if="!compute.reachable"
        type="button"
        class="btn-primary px-4 py-2.5 rounded-lg text-sm font-medium"
        data-testid="btn-compute-retry"
        @click="$emit('retry')"
      >
        {{ $t('common.retry') }}
      </button>
    </div>
  </section>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { ComputeStatus } from '@/services/featuresService'

const props = defineProps<{
  compute: ComputeStatus
}>()

defineEmits<{
  retry: []
}>()

const { t } = useI18n()

const tierLabel = computed(() => {
  switch (props.compute.tier) {
    case 'docker':
      return t('settings.features.compute.tierDocker')
    case 'gvisor':
      return t('settings.features.compute.tierGvisor')
    case 'microvm':
      return t('settings.features.compute.tierMicrovm')
    default:
      return props.compute.tier || t('settings.features.compute.tierUnknown')
  }
})

const stateLine = computed(() => {
  if (!props.compute.enabled) {
    return t('settings.features.compute.stateDisabled')
  }
  if (!props.compute.reachable) {
    return t('settings.features.compute.stateUnreachable')
  }
  return t('settings.features.compute.stateRunning')
})

const statusLabel = computed(() => {
  if (!props.compute.enabled) {
    return t('settings.features.compute.statusOff')
  }
  return props.compute.reachable
    ? t('settings.features.compute.statusOn')
    : t('settings.features.compute.statusDown')
})

const statusClass = computed(() => {
  if (!props.compute.enabled) {
    return 'bg-[var(--status-neutral)] text-white shadow-sm'
  }
  return props.compute.reachable
    ? 'bg-[var(--status-success)] text-white shadow-sm'
    : 'bg-[var(--status-error)] text-white shadow-sm'
})

const capacityPct = computed(() => {
  const max = props.compute.capacity.maxConcurrent
  if (max <= 0) {
    return 0
  }
  return Math.min(100, Math.round((props.compute.capacity.running / max) * 100))
})
</script>
