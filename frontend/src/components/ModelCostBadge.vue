<template>
  <span
    :class="[
      'inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold leading-none',
      tierInfo.class,
    ]"
  >
    {{ $t(tierInfo.label) }}
  </span>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { getCostTier } from '@/composables/useModelCostTier'
import type { AIModel } from '@/types/ai-models'

const props = defineProps<{
  model: AIModel
  peers: readonly AIModel[]
}>()

const tierInfo = computed(() => getCostTier(props.model, props.peers))
</script>
