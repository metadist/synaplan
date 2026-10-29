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
      <div class="flex-1 min-w-0">
        <!-- A thought is the model's own sentence: it reads as prose and wraps. -->
        <p v-if="row.kind === 'thought'" :class="row.current ? 'animate-pulse' : ''">
          {{ row.title }}
        </p>
        <div v-else class="flex items-baseline gap-2 min-w-0">
          <span class="flex-shrink-0" :class="row.current ? 'font-medium animate-pulse' : ''">{{
            row.title
          }}</span>
          <!-- What a page said or what the check left out wraps; a query or title truncates. -->
          <span
            v-if="row.detail"
            class="opacity-80"
            :class="wraps(row.kind) ? 'min-w-0' : 'truncate'"
            :title="row.detail"
            >{{ row.detail }}</span
          >
        </div>
        <p
          v-if="row.aside"
          class="text-xs txt-tertiary mt-0.5"
          data-testid="text-research-step-aside"
        >
          {{ row.aside }}
        </p>
      </div>
    </li>
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
import { researchStepCopy } from './researchStepCopy'

/**
 * The rows of one phase, oldest first. With `live` the last row is the
 * thing happening right now: it reads in the primary ink and pulses, the
 * rows above it stay muted. Under a row, in the smallest ink, the model's
 * own sentence about it: why it picked a source, what the page said, why
 * a statement was softened.
 */
const props = defineProps<{
  steps: ResearchStep[]
  points: ResearchPoint[]
  sourceTitles?: Record<string, string>
  live?: boolean
}>()

/** Rows whose detail is a sentence, not a name: they wrap instead of truncating. */
const WRAPPING = new Set(['check', 'brief', 'finding', 'verdict'])

const { t } = useI18n()

const rows = computed(() =>
  props.steps.map((step, index) => ({
    kind: step.kind,
    current: props.live === true && index === props.steps.length - 1,
    ...researchStepCopy(t, step, props.points, props.sourceTitles ?? {}),
    ...glyph(step),
  }))
)

function wraps(kind: string): boolean {
  return WRAPPING.has(kind)
}

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
