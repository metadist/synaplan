<template>
  <div class="my-3 p-4 surface-chip" data-testid="section-message-translation">
    <div class="space-y-3">
      <div data-testid="section-translation-original">
        <div class="text-xs txt-secondary mb-1">{{ $t('commands.original') }}</div>
        <p class="text-sm txt-primary">{{ content }}</p>
      </div>
      <div class="border-t border-light-border/30 dark:border-dark-border/20 pt-3" data-testid="section-translation-result">
        <div class="text-xs txt-secondary mb-1">
          {{ $t('commands.translatedTo') }} <span class="uppercase font-medium">{{ lang }}</span>
        </div>
        <p class="text-sm txt-primary font-medium">{{ result }}</p>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
interface Props {
  content: string
  lang: string
  result: string
}

defineProps<Props>()
</script>
