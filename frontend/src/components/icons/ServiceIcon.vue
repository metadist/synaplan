<template>
  <span class="service-icon" :style="rootStyle">
    <OpenAiCompatibleIcon v-if="isOpenAiCompatible" :size="size" />
    <GroqIcon v-else-if="isGroq" :size="size" />
    <MistralIcon v-else-if="isMistral" :size="size" />
    <Icon v-else :icon="providerIcon" :style="rootStyle" />
    <Icon
      v-if="hasService && showFlag"
      :icon="flagIcon"
      class="service-icon__flag"
      :class="{ 'service-icon__flag--local': isLocal }"
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
import OpenAiCompatibleIcon from '@/components/icons/OpenAiCompatibleIcon.vue'
import { getProviderIcon, getProviderFlag, isLocalSelfHostedProvider } from '@/utils/providerIcons'

interface Props {
  service: string
  size?: number
  /**
   * The jurisdiction badge earns its place next to a model name, where the
   * question is where the data goes. On a surface that is nothing but logos —
   * the setup wizard's provider grid — it reads as visual noise on every tile.
   */
  showFlag?: boolean
}

const props = withDefaults(defineProps<Props>(), {
  size: 20,
  showFlag: true,
})

const hasService = computed(() => props.service.trim().length > 0)
// Check the OpenAI-compatible provider FIRST: its service string
// ("openaicompatible") contains "openai", so it must win before any
// substring check that would otherwise show the OpenAI logo + US flag.
const isOpenAiCompatible = computed(() =>
  props.service
    .toLowerCase()
    .replace(/[\s_-]/g, '')
    .includes('openaicompatible')
)
const isGroq = computed(() => props.service.toLowerCase().includes('groq'))
const isMistral = computed(() => props.service.toLowerCase().includes('mistral'))
const providerIcon = computed(() => getProviderIcon(props.service))
const flagIcon = computed(() => getProviderFlag(props.service))
const isLocal = computed(() => isLocalSelfHostedProvider(props.service))

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

/* Pin badge for local/self-hosted engines (Ollama, Piper, Triton…).
   Flags are colourful assets; a monochrome pin needs its own disc so it
   stays readable on both themes. Scoped to this badge — never recolor
   overlays that happen to render inside a parent. */
.service-icon__flag--local {
  background: var(--bg-card);
  color: var(--brand);
}

.dark .service-icon__flag--local {
  color: var(--brand-light);
}
</style>
