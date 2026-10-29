<template>
  <ol class="space-y-1.5" data-testid="list-research-steps">
    <li
      v-for="(row, index) in rows"
      :key="index"
      class="flex items-start gap-2 text-sm min-w-0 processing-enter"
      :class="row.current ? 'txt-primary' : 'txt-secondary'"
      :data-step-kind="row.kind"
      :data-testid="row.current ? 'text-research-activity' : undefined"
    >
      <component
        :is="row.icon"
        class="w-4 h-4 flex-shrink-0 mt-0.5"
        :class="[row.iconClass, row.current ? 'animate-pulse' : '']"
        aria-hidden="true"
      />
      <span class="flex-shrink-0" :class="row.current ? 'font-medium animate-pulse' : ''">{{
        row.title
      }}</span>
      <!-- The check row tells what the answer leaves out, so it wraps instead of truncating. -->
      <span
        v-if="row.detail"
        class="opacity-80"
        :class="row.kind === 'check' || row.kind === 'brief' ? 'min-w-0' : 'truncate'"
        :title="row.detail"
        >{{ row.detail }}</span
      >
    </li>
  </ol>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  ArrowPathIcon,
  CheckIcon,
  DocumentTextIcon,
  MagnifyingGlassIcon,
  NoSymbolIcon,
  PencilIcon,
  ShieldCheckIcon,
  XMarkIcon,
} from '@heroicons/vue/24/outline'
import type { ResearchPoint, ResearchStep } from '@/services/chat/stream/handlers/research'
import { researchStepCopy } from './researchStepCopy'

/**
 * The rows of one phase, oldest first. With `live` the last row is the
 * thing happening right now: it reads in the primary ink and pulses, the
 * rows above it stay muted.
 */
const props = defineProps<{
  steps: ResearchStep[]
  points: ResearchPoint[]
  live?: boolean
}>()

const { t } = useI18n()

const rows = computed(() =>
  props.steps.map((step, index) => ({
    kind: step.kind,
    current: props.live === true && index === props.steps.length - 1,
    ...researchStepCopy(t, step, props.points),
    ...glyph(step),
  }))
)

function glyph(step: ResearchStep): { icon: unknown; iconClass: string } {
  if (step.kind === 'search') {
    return {
      icon: MagnifyingGlassIcon,
      iconClass: step.status === 'skipped' ? 'opacity-60' : 'txt-brand',
    }
  }
  if (step.kind === 'source') {
    if (step.status === 'blocked') return { icon: NoSymbolIcon, iconClass: 'opacity-60' }
    return {
      icon: DocumentTextIcon,
      iconClass: step.status === 'read' || step.status === 'reading' ? 'txt-brand' : 'opacity-60',
    }
  }
  if (step.kind === 'round') return { icon: ArrowPathIcon, iconClass: 'txt-brand' }
  if (step.kind === 'writing' || step.kind === 'section')
    return { icon: PencilIcon, iconClass: 'txt-brand' }
  if (step.kind === 'checking' || step.kind === 'check') {
    return { icon: ShieldCheckIcon, iconClass: 'txt-brand' }
  }
  if (step.kind === 'failed' || step.kind === 'stopped' || step.status === 'not_confirmed') {
    return { icon: XMarkIcon, iconClass: 'opacity-60' }
  }
  if (step.kind === 'point' && step.status === 'open') {
    return { icon: CheckIcon, iconClass: 'opacity-30' }
  }
  return { icon: CheckIcon, iconClass: 'txt-brand' }
}
</script>
