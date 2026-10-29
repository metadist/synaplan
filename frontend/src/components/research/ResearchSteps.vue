<template>
  <ol class="space-y-1.5" data-testid="list-research-steps">
    <ResearchStepRow
      v-for="row in rows"
      :key="row.key"
      :kind="row.kind"
      :title="row.title"
      :detail="row.detail"
      :aside="row.aside"
      :icon="row.icon"
      :icon-class="row.iconClass"
      :current="row.current"
    />
  </ol>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  ArrowPathIcon,
  ChatBubbleBottomCenterTextIcon,
  CheckIcon,
  DocumentTextIcon,
  LightBulbIcon,
  MagnifyingGlassIcon,
  NoSymbolIcon,
  PencilIcon,
  ShieldCheckIcon,
  ShieldExclamationIcon,
  XMarkIcon,
} from '@heroicons/vue/24/outline'
import type { ResearchPoint, ResearchStep } from '@/services/chat/stream/handlers/research'
import ResearchStepRow from './ResearchStepRow.vue'
import { researchStepCopy } from './researchStepCopy'

/**
 * The rows of one phase, oldest first. With `live` the last row is the
 * thing happening right now: it reads in the primary ink, its words run in
 * as they are written and the model's sentence about it shows underneath.
 * The rows above it fold to one muted line each.
 */
const props = defineProps<{
  steps: ResearchStep[]
  points: ResearchPoint[]
  sourceTitles?: Record<string, string>
  live?: boolean
  /** Where the visible rows start in the phase, so a row keeps its key when earlier ones hide. */
  offset?: number
}>()

const { t } = useI18n()

const rows = computed(() =>
  props.steps.map((step, index) => ({
    // The note still being written and the finished note share a row, so it does not run in twice.
    key: `${(props.offset ?? 0) + index}:${step.kind}`,
    kind: step.kind,
    current: props.live === true && index === props.steps.length - 1,
    ...researchStepCopy(t, step, props.points, props.sourceTitles ?? {}),
    ...glyph(step),
  }))
)

function glyph(step: ResearchStep): { icon: unknown; iconClass: string } {
  if (step.kind === 'thought')
    return { icon: ChatBubbleBottomCenterTextIcon, iconClass: 'txt-brand' }
  if (step.kind === 'finding') return { icon: LightBulbIcon, iconClass: 'txt-brand' }
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
  if (step.kind === 'verdict') {
    return {
      icon: step.status === 'partial' ? PencilIcon : ShieldExclamationIcon,
      iconClass: 'opacity-60',
    }
  }
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
