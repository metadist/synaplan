<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { ArrowTopRightOnSquareIcon } from '@heroicons/vue/24/outline'
import type { ServiceReadiness, ServiceReadinessState } from '@/services/featuresService'
import {
  serviceCopy,
  serviceDocsHref,
  serviceNeedsAttention,
  serviceProgress,
  serviceStateLabel,
  sortServices,
} from '@/utils/serviceReadiness'

/**
 * Operate → System status: the background services this install runs, with
 * what each one unlocks, what is missing while it is not ready and the one
 * step that brings it back.
 */
const props = defineProps<{
  services: ServiceReadiness[]
}>()

const { t, te } = useI18n()

const rows = computed(() =>
  sortServices(props.services).map((service) => ({
    service,
    copy: serviceCopy(service, t, te),
    state: serviceStateLabel(service, t),
    progress: serviceProgress(service, t),
    docs: serviceDocsHref(service),
    problem: serviceNeedsAttention(service),
  }))
)

const readyCount = computed(() => props.services.filter((s) => s.state === 'ready').length)
const runningCount = computed(() => props.services.filter((s) => s.state !== 'off').length)

const stateClass = (state: ServiceReadinessState): string => {
  switch (state) {
    case 'ready':
      return 'bg-[var(--status-success-muted)] text-[var(--status-success-text)]'
    case 'starting':
    case 'downloading':
      return 'bg-[var(--status-warning-muted)] text-[var(--status-warning-text)]'
    case 'unavailable':
    case 'failed':
      return 'bg-[var(--status-error-muted)] text-[var(--status-error-text)]'
    default:
      return 'bg-[var(--status-neutral-muted)] text-[var(--status-neutral-text)]'
  }
}
</script>

<template>
  <section class="space-y-3" data-testid="section-services">
    <div class="flex items-center gap-3 px-2 flex-wrap">
      <h2 class="text-xl font-semibold txt-primary">
        {{ t('settings.features.services.title') }}
      </h2>
      <span class="text-sm txt-secondary" data-testid="text-services-summary">
        {{
          t('settings.features.services.summary', {
            ready: readyCount,
            total: runningCount,
          })
        }}
      </span>
      <div class="h-px flex-1 bg-[var(--divider)]"></div>
    </div>
    <p class="px-2 text-sm txt-secondary">{{ t('settings.features.services.subtitle') }}</p>

    <ul class="surface-card rounded-2xl divide-y divide-light-border/20 dark:divide-dark-border/10">
      <li
        v-for="row in rows"
        :key="row.service.id"
        class="p-4 sm:p-5 space-y-2"
        :class="row.problem ? 'border-l-4 border-[var(--status-error)]' : ''"
        data-testid="item-service"
        :data-service="row.service.id"
        :data-state="row.service.state"
      >
        <div class="flex items-start justify-between gap-3 flex-wrap">
          <div class="min-w-0">
            <h3 class="text-base font-semibold txt-primary">{{ row.copy.name }}</h3>
            <p class="text-sm txt-secondary">{{ row.copy.unlocks }}</p>
          </div>
          <span
            :class="[
              'px-2.5 py-1 rounded-md text-xs font-bold uppercase tracking-wide whitespace-nowrap',
              stateClass(row.service.state),
            ]"
            data-testid="badge-service-state"
          >
            {{ row.state }}
          </span>
        </div>

        <p v-if="row.progress" class="text-sm txt-secondary" data-testid="text-service-progress">
          {{ row.progress }}
        </p>
        <p
          v-if="row.copy.effect && row.service.state !== 'off'"
          class="text-sm txt-primary"
          data-testid="text-service-effect"
        >
          {{ row.copy.effect }}
        </p>

        <div v-if="row.copy.recovery" class="space-y-1.5" data-testid="text-service-recovery">
          <p class="text-sm txt-secondary">{{ row.copy.recovery }}</p>
          <code
            v-for="command in row.copy.commands"
            :key="command"
            class="block w-fit max-w-full overflow-x-auto px-2.5 py-1 rounded-lg text-xs font-mono surface-chip txt-primary"
            data-testid="code-service-command"
            >{{ command }}</code
          >
        </div>

        <a
          v-if="row.docs && row.service.state !== 'ready'"
          :href="row.docs"
          target="_blank"
          rel="noopener noreferrer"
          class="inline-flex items-center gap-1 text-sm text-[var(--brand)] hover:underline"
          data-testid="link-service-docs"
        >
          {{ t('settings.features.services.guide') }}
          <ArrowTopRightOnSquareIcon class="w-4 h-4" aria-hidden="true" />
        </a>
      </li>
    </ul>
  </section>
</template>
