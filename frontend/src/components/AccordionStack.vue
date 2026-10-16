<script setup lang="ts">
interface Props {
  testid?: string
}

withDefaults(defineProps<Props>(), {
  testid: undefined,
})
</script>

<template>
  <div
    class="surface-card rounded-xl overflow-hidden divide-y divide-light-border/30 dark:divide-dark-border/20"
    :data-testid="testid ?? 'accordion-stack'"
  >
    <slot />
  </div>
</template>
