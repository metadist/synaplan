<template>
  <button
    v-for="option in options"
    :key="`${option.provider}-${option.model}`"
    type="button"
    :class="[
      'dropdown-item',
      selected && selected.model === option.model && selected.provider === option.provider
        ? 'dropdown-item--active'
        : '',
    ]"
    @click="emit('select', option)"
  >
    <ServiceIcon class="shrink-0" :service="option.provider" :size="20" />
    <span class="min-w-0 flex-1">
      <span class="block truncate text-[13px] font-medium">{{ option.label }}</span>
      <span class="block truncate text-xs txt-secondary">{{ option.provider }}</span>
    </span>
    <ModelCostBadge
      v-if="modelFor(option)"
      class="shrink-0 whitespace-nowrap"
      :model="modelFor(option)!"
      :peers="peers"
    />
  </button>
</template>

<script setup lang="ts">
import ModelCostBadge from '@/components/ModelCostBadge.vue'
import ServiceIcon from '@/components/icons/ServiceIcon.vue'
import type { ModelOption } from '@/composables/useModelSelection'
import type { AIModel } from '@/types/ai-models'

const props = defineProps<{
  options: ModelOption[]
  selected: ModelOption | null
  peers: AIModel[]
}>()

const emit = defineEmits<{ select: [option: ModelOption] }>()

function modelFor(option: ModelOption): AIModel | undefined {
  return props.peers.find((model) => model.id === option.id)
}
</script>
