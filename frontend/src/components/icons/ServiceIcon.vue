<template>
  <span class="service-icon" :style="rootStyle">
    <GroqIcon v-if="isGroq" :size="size" />
    <MistralIcon v-else-if="isMistral" :size="size" />
    <Icon v-else :icon="providerIcon" :style="rootStyle" />
    <Icon
      v-if="hasService"
      :icon="flagIcon"
      class="service-icon__flag"
      :style="flagStyle"
      aria-hidden="true"
    />
  </span>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { Icon } from '@iconify/vue'
import GroqIcon from '@/components/icons/GroqIcon.vue'
import MistralIcon from '@/components/icons/MistralIcon.vue'
import { getProviderIcon, getProviderFlag } from '@/utils/providerIcons'

interface Props {
  service: string
  size?: number
}

const props = withDefaults(defineProps<Props>(), {
  size: 20,
})

const hasService = computed(() => props.service.trim().length > 0)
const isGroq = computed(() => props.service.toLowerCase().includes('groq'))
const isMistral = computed(() => props.service.toLowerCase().includes('mistral'))
const providerIcon = computed(() => getProviderIcon(props.service))
const flagIcon = computed(() => getProviderFlag(props.service))

const flagPx = computed(() => Math.max(9, Math.round(props.size * 0.55)))
const rootStyle = computed(() => ({ width: `${props.size}px`, height: `${props.size}px` }))
const flagStyle = computed(() => ({ width: `${flagPx.value}px`, height: `${flagPx.value}px` }))
</script>

<style scoped>
.service-icon {
  position: relative;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.service-icon__flag {
  position: absolute;
  right: -3px;
  bottom: -3px;
  border-radius: 9999px;
  /* Thin ring in the surface color so the flag reads as a separate badge. */
  box-shadow: 0 0 0 1.5px var(--bg-card);
}
</style>
