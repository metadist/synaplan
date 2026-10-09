<template>
  <h3 class="flex items-center gap-3 text-lg font-semibold txt-primary mb-2">
    <span
      class="inline-flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-full bg-[var(--brand-alpha-light)] text-sm font-semibold text-[var(--brand)]"
      aria-hidden="true"
      >{{ number }}</span
    >
    <span>{{ title }}</span>
  </h3>
</template>

<script setup lang="ts">
defineProps<{
  number: number
  title: string
}>()
</script>
