<template>
  <div class="my-3" data-testid="section-command-list">
    <h4 class="text-sm font-medium txt-primary mb-3">{{ $t('commands.availableCommands') }}</h4>
    <div class="space-y-2">
      <div v-for="(item, index) in items" :key="index" class="p-3 surface-chip">
        <div class="flex items-start gap-3">
          <code class="text-sm font-mono txt-primary font-medium flex-shrink-0">{{
            item.url
          }}</code>
          <p class="text-sm txt-secondary flex-1">{{ item.desc }}</p>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
interface CommandItem {
  title: string
  url: string
  desc: string
}

interface Props {
  items: CommandItem[]
}

defineProps<Props>()
</script>
