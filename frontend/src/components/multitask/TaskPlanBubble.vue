<script setup lang="ts">
import { computed } from 'vue'
import { Icon } from '@iconify/vue'
import type { TaskPlanState } from '@/stores/history'
import TaskCard from '@/components/multitask/TaskCard.vue'

const props = defineProps<{ plan: TaskPlanState }>()

const emit = defineEmits<{
  /** Bubbled from a failed TaskCard: retry that step with another model. */
  retryTask: [payload: { prompt: string; modelId: number }]
}>()

const doneCount = computed(() => props.plan.cards.filter((c) => c.state === 'done').length)
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
    />
  </div>
</template>
