<script setup lang="ts">
interface Props {
  active: boolean
  direction: 'asc' | 'desc'
}

defineProps<Props>()
</script>

<template>
  <span class="inline-flex flex-col -my-1" :class="active ? 'txt-primary' : 'txt-secondary/50'">
    <svg
      class="w-3 h-3 -mb-1"
      :class="active && direction === 'asc' ? 'text-[var(--brand)]' : ''"
      viewBox="0 0 12 12"
      fill="currentColor"
    >
      <path d="M6 3L10 7H2L6 3Z" />
    </svg>
    <svg
      class="w-3 h-3"
      :class="active && direction === 'desc' ? 'text-[var(--brand)]' : ''"
      viewBox="0 0 12 12"
      fill="currentColor"
    >
      <path d="M6 9L2 5H10L6 9Z" />
    </svg>
  </span>
</template>
