<template>
  <span class="service-icon" :style="rootStyle">
    <component :is="glyph" v-if="glyph" :size="size" />
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
import { computed, type Component } from 'vue'
import { Icon } from '@iconify/vue'
import GroqIcon from '@/components/icons/GroqIcon.vue'
import MistralIcon from '@/components/icons/MistralIcon.vue'
import OpenAiCompatibleIcon from '@/components/icons/OpenAiCompatibleIcon.vue'
import { useProviderCatalogStore } from '@/stores/providerCatalog'

/** The bundled glyphs a descriptor may name in `iconComponent`. */
const GLYPHS: Record<string, Component> = {
  GroqIcon,
  MistralIcon,
  OpenAiCompatibleIcon,
}

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
// Branding comes from the provider catalog; until it has loaded (or for a
// name the server does not know) the bundled fallback answers.
const catalog = useProviderCatalogStore()
catalog.ensureLoaded()
const glyph = computed<Component | null>(() => {
  const name = catalog.iconComponentFor(props.service)
  return name ? (GLYPHS[name] ?? null) : null
})
const providerIcon = computed(() => catalog.iconFor(props.service))
const flagIcon = computed(() => catalog.flagFor(props.service))
const isLocal = computed(() => catalog.isLocal(props.service))

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
