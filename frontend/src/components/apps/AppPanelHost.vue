<script setup lang="ts">
import { defineAsyncComponent, shallowRef, watch, type Component } from 'vue'
import { provideEmbeddedPageHeader } from '@/components/pageHeaderEmbedding'

const props = defineProps<{
  loader: () => Promise<{ default: Component }>
  panelProps?: Record<string, unknown>
}>()

provideEmbeddedPageHeader()

const panel = shallowRef<Component | null>(null)

watch(
  () => props.loader,
  (loader) => {
    panel.value = defineAsyncComponent(loader)
  },
  { immediate: true }
)
</script>

<template>
  <component :is="panel" v-if="panel" v-bind="panelProps ?? {}" />
</template>
