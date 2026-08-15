<script setup lang="ts">
import { computed } from 'vue'
import type { TaskCard, TaskPlanState } from '@/stores/history'

const props = defineProps<{
  plan: TaskPlanState
}>()

interface GraphNode {
  id: string
  label: string
  state: string
}

interface GraphEdge {
  from: string
  to: string
}

const nodes = computed<GraphNode[]>(() =>
  props.plan.cards.map((card) => ({
    id: card.nodeId,
    label: card.capability.replaceAll('_', ' '),
    state: card.state,
  }))
)

const edges = computed<GraphEdge[]>(() => {
  const result: GraphEdge[] = []
  for (const card of props.plan.cards) {
    for (const from of card.dependsOn ?? []) {
      result.push({ from, to: card.nodeId })
    }
  }
  return result
})

const visible = computed(() => nodes.value.length > 1)

function cardFor(id: string): TaskCard | undefined {
  return props.plan.cards.find((card) => card.nodeId === id)
}
</script>

<template>
  <div v-if="visible" class="executed-plan-graph" data-testid="executed-plan-graph">
    <ol class="flex flex-wrap items-center gap-2">
      <template v-for="(node, index) in nodes" :key="node.id">
        <li
          class="px-2.5 py-1 rounded-lg surface-chip text-xs txt-primary"
          :data-testid="`plan-step-${node.id}`"
          :data-state="node.state"
        >
          <span class="txt-muted font-mono mr-1">{{ node.id }}</span>
          {{ $t(`taskPlan.capability.${cardFor(node.id)?.capability}`, node.label) }}
        </li>
        <li
          v-if="index < nodes.length - 1"
          class="txt-muted text-xs"
          aria-hidden="true"
        >
          →
        </li>
      </template>
    </ol>
    <p v-if="edges.length > 0" class="sr-only">
      {{
        edges
          .map((edge) => `${edge.from} → ${edge.to}`)
          .join(', ')
      }}
    </p>
  </div>
</template>
