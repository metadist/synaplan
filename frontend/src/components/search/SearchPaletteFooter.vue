<template>
  <footer
    class="hidden sm:flex items-center gap-4 px-4 py-2 border-t border-light-border/30 dark:border-dark-border/20 text-[11px] txt-secondary"
  >
    <span>↑↓ {{ $t('search.palette.footer.navigate') }}</span>
    <span>↵ {{ $t('search.palette.footer.open') }}</span>
    <span v-if="canSwitch" data-testid="text-smart-search-switch-hint"
      >⇧↵ {{ $t('search.palette.footer.switch') }}</span
    >
    <span>Esc {{ $t('search.palette.footer.close') }}</span>
    <span class="ml-auto truncate">{{ $t('search.palette.footer.prefixes') }}</span>
  </footer>
</template>

<script setup lang="ts">
defineProps<{
  /** The active row is a setting that Shift+Enter switches in place. */
  canSwitch: boolean
}>()
</script>
