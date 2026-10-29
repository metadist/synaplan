<template>
  <ol class="space-y-1 processing-enter" data-testid="list-research-steps">
    <li
      v-for="(row, index) in rows"
      :key="index"
      class="flex items-center gap-2 text-xs txt-tertiary min-w-0"
      :data-step-kind="row.kind"
    >
      <component
        :is="row.icon"
        class="w-3.5 h-3.5 flex-shrink-0"
        :class="row.iconClass"
        aria-hidden="true"
      />
      <span class="flex-shrink-0">{{ row.title }}</span>
      <span v-if="row.detail" class="truncate opacity-70" :title="row.detail">{{
        row.detail
      }}</span>
    </li>
  </ol>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import {
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

/** Everything a run did, oldest first — the part a person opens to see how the answer came about. */
const props = defineProps<{
  steps: ResearchStep[]
  points: ResearchPoint[]
}>()

const { t } = useI18n()

const rows = computed(() =>
  props.steps.map((step) => ({
    kind: step.kind,
    ...researchStepCopy(t, step, props.points),
    ...glyph(step),
  }))
)

function glyph(step: ResearchStep): { icon: unknown; iconClass: string } {
  if (step.kind === 'search') return { icon: MagnifyingGlassIcon, iconClass: 'txt-brand' }
  if (step.kind === 'source') {
    if (step.status === 'blocked') return { icon: NoSymbolIcon, iconClass: 'opacity-60' }
    return {
      icon: DocumentTextIcon,
      iconClass: step.status === 'read' ? 'txt-brand' : 'opacity-60',
    }
  }
  if (step.kind === 'writing' || step.kind === 'section')
    return { icon: PencilIcon, iconClass: 'txt-brand' }
  if (step.kind === 'checking' || step.kind === 'check') {
    return { icon: ShieldCheckIcon, iconClass: 'txt-brand' }
  }
  if (step.kind === 'failed' || step.kind === 'stopped' || step.status === 'not_confirmed') {
    return { icon: XMarkIcon, iconClass: 'opacity-60' }
  }
  return { icon: CheckIcon, iconClass: 'txt-brand' }
}
</script>
