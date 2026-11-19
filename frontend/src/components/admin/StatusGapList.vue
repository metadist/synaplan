<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { StatusGap } from '@/utils/statusGaps'
import { serviceCopy } from '@/utils/serviceReadiness'

/**
 * The names under the system-status summary. Each name is a link to that
 * service further down the page.
 */
defineProps<{
  gaps: StatusGap[]
}>()

const emit = defineEmits<{
  show: [gap: StatusGap]
}>()

const { t, te } = useI18n()

function label(gap: StatusGap): string {
  return gap.service ? serviceCopy(gap.service, t, te).name : gap.name
}
</script>

<template>
  <ul class="mt-4 space-y-2" data-testid="list-status-gaps">
    <li
      v-for="gap in gaps"
      :key="`${gap.kind}-${gap.id}`"
      class="flex flex-wrap items-center gap-x-3 gap-y-1"
    >
      <a
        :href="`#${gap.anchor}`"
        class="text-sm font-medium txt-primary underline decoration-[var(--brand)] underline-offset-2"
        data-testid="link-status-gap"
        :data-gap="gap.id"
        @click.prevent="emit('show', gap)"
      >
        {{ label(gap) }}
      </a>
      <span
        class="text-xs font-semibold uppercase tracking-wide"
        :class="gap.kind === 'critical' ? 'text-red-600 dark:text-red-400' : 'txt-secondary'"
        data-testid="text-status-gap-kind"
      >
        {{
          gap.kind === 'critical'
            ? t('settings.features.gaps.critical')
            : t('settings.features.gaps.optional')
        }}
      </span>
    </li>
  </ul>
</template>
