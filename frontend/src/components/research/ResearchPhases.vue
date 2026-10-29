<template>
  <ol class="flex flex-col gap-1" data-testid="list-research-phases">
    <li
      v-for="phase in phases"
      :key="phase.id"
      class="min-w-0"
      data-testid="research-phase"
      :data-phase="phase.id"
      :data-state="phase.state"
    >
      <button
        type="button"
        class="flex items-start gap-2.5 w-full min-w-0 text-left rounded-lg -mx-1 px-1 py-1 disabled:cursor-default focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--brand)]"
        :disabled="phase.rows.length === 0"
        :aria-expanded="phase.rows.length > 0 ? isOpen(phase) : undefined"
        data-testid="btn-research-phase"
        @click="toggle(phase)"
      >
        <ResearchSpinner v-if="phase.state === 'active'" class="w-4 h-4 mt-0.5" />
        <CheckIcon
          v-else-if="phase.state === 'done'"
          class="w-4 h-4 mt-0.5 txt-brand flex-shrink-0"
          aria-hidden="true"
        />
        <span
          v-else
          class="w-4 h-4 mt-0.5 flex-shrink-0 rounded-full border-2 border-current opacity-30"
          aria-hidden="true"
        />
        <span class="flex-1 min-w-0">
          <span class="flex items-center gap-2 min-w-0">
            <span
              class="text-sm truncate"
              :class="{
                'font-medium txt-primary': phase.state === 'active',
                'font-medium txt-secondary': phase.state === 'done',
                'txt-tertiary': phase.state === 'upcoming',
              }"
              >{{ t(`research.phase.${phase.id}`) }}</span
            >
            <ChevronDownIcon
              v-if="phase.rows.length > 0"
              class="w-3.5 h-3.5 flex-shrink-0 txt-tertiary transition-transform"
              :class="{ 'rotate-180': isOpen(phase) }"
              aria-hidden="true"
            />
          </span>
          <span
            v-if="summaryOf(phase)"
            class="block text-sm txt-secondary opacity-80 truncate"
            :title="summaryOf(phase)"
            data-testid="text-research-phase-summary"
            >{{ summaryOf(phase) }}</span
          >
        </span>
      </button>

      <div
        v-if="isOpen(phase)"
        class="ml-[7px] pl-4 py-1 border-l border-light-border/30 dark:border-dark-border/20 processing-enter"
      >
        <button
          v-if="hiddenCount(phase) > 0"
          type="button"
          class="text-sm txt-tertiary hover:underline mb-1.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--brand)] rounded"
          data-testid="btn-research-earlier"
          @click="showAll[phase.id] = true"
        >
          {{ t('research.phase.earlier', { count: hiddenCount(phase) }, hiddenCount(phase)) }}
        </button>
        <ResearchSteps
          :steps="visibleRows(phase)"
          :points="progress.points"
          :source-titles="progress.sourceTitles"
          :live="phase.state === 'active'"
        />
      </div>
    </li>
  </ol>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { CheckIcon, ChevronDownIcon } from '@heroicons/vue/24/outline'
import type { ResearchProgress } from '@/services/chat/stream/handlers/research'
import ResearchSpinner from './ResearchSpinner.vue'
import ResearchSteps from './ResearchSteps.vue'
import { phaseSummary, researchPhases, type ResearchPhase } from './researchPhases'

/**
 * The run as a list of phases. The phase the run is in is open and shows
 * what it does right now; a phase it has finished folds to its title and
 * one summary line, and opens again on a click; the phases still to come
 * are listed muted. When the run moves on, the phase it left folds and the
 * next one opens, so the eye follows the work without touching anything.
 */
const props = defineProps<{
  progress: ResearchProgress
}>()

/** How many rows an open phase shows while the run works; the rest wait behind one line. */
const RECENT_ROWS = 6

const { t } = useI18n()

const phases = computed(() => researchPhases(props.progress))
const activeId = computed(() => phases.value.find((phase) => phase.state === 'active')?.id ?? null)

/** A person's own choice to open or close a phase; cleared when the run moves on. */
const opened = ref<Partial<Record<ResearchPhase['id'], boolean>>>({})
const showAll = ref<Partial<Record<ResearchPhase['id'], boolean>>>({})

watch(activeId, () => {
  opened.value = {}
  showAll.value = {}
})

function isOpen(phase: ResearchPhase): boolean {
  return opened.value[phase.id] ?? phase.state === 'active'
}

function toggle(phase: ResearchPhase): void {
  if (phase.rows.length === 0) return
  opened.value[phase.id] = !isOpen(phase)
}

function summaryOf(phase: ResearchPhase): string {
  return phaseSummary(t, phase, props.progress)
}

function hiddenCount(phase: ResearchPhase): number {
  if (phase.state !== 'active' || showAll.value[phase.id]) return 0
  return Math.max(0, phase.rows.length - RECENT_ROWS)
}

function visibleRows(phase: ResearchPhase) {
  const hidden = hiddenCount(phase)
  return hidden > 0 ? phase.rows.slice(hidden) : phase.rows
}
</script>
