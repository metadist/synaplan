<script setup lang="ts">
import { computed, ref } from 'vue'
import { Icon } from '@iconify/vue'
import type { TaskPlanState } from '@/stores/history'
import TaskCard from '@/components/multitask/TaskCard.vue'
import ExecutedPlanGraph from '@/components/multitask/ExecutedPlanGraph.vue'

const props = defineProps<{ plan: TaskPlanState }>()

const emit = defineEmits<{
  /** Bubbled from a failed TaskCard: retry that step with another model. */
  retryTask: [payload: { prompt: string; modelId: number }]
  /** Bubbled from a running TaskCard: stop that media step. */
  cancelTask: [nodeId: string]
}>()

const doneCount = computed(() => props.plan.cards.filter((c) => c.state === 'done').length)
const showGraph = ref(false)
const canShowGraph = computed(() => props.plan.cards.length > 1)
</script>

<template>
  <div class="task-plan space-y-2" data-testid="task-plan">
    <div class="flex items-center gap-2 text-xs txt-muted">
      <Icon icon="mdi:sitemap-outline" class="w-4 h-4" />
      <span>{{ $t('taskPlan.title') }}</span>
      <span class="font-medium">· {{ doneCount }}/{{ plan.cards.length }}</span>
    </div>

    <TaskCard
      v-for="card in plan.cards"
      :key="card.nodeId"
      :card="card"
      @retry="emit('retryTask', $event)"
      @cancel="emit('cancelTask', $event)"
    />

    <button
      v-if="canShowGraph"
      type="button"
      class="text-xs txt-secondary hover:txt-primary"
      data-testid="btn-toggle-plan-graph"
      @click="showGraph = !showGraph"
    >
      {{ showGraph ? $t('taskPlan.hideSteps') : $t('taskPlan.showSteps') }}
    </button>
    <ExecutedPlanGraph v-if="showGraph && canShowGraph" :plan="plan" />
  </div>
</template>
