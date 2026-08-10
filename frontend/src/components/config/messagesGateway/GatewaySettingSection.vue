<script setup lang="ts">
/**
 * A titled group of gateway settings. The heading carries the operator's
 * vocabulary ("Tool calling", "Images") rather than the BCONFIG key names.
 */
defineProps<{
  title: string
  description: string
}>()
</script>

<template>
  <section class="mt-8">
    <h4 class="text-sm font-semibold txt-primary uppercase tracking-wide">{{ title }}</h4>
    <p class="txt-secondary text-xs mt-1">{{ description }}</p>
    <slot />
  </section>
</template>
