<template>
  <div class="my-3 space-y-2" data-testid="section-message-links">
    <a
      v-for="(item, index) in items"
      :key="index"
      :href="item.url"
      target="_blank"
      rel="noopener noreferrer"
      class="block p-3 surface-chip hover:ring-1 hover:ring-primary/30 transition-all"
    >
      <div class="flex items-start gap-3">
        <div class="flex-1 min-w-0">
          <div class="flex items-center gap-2 mb-1">
            <h4 class="text-sm font-medium txt-primary truncate">{{ item.title }}</h4>
            <span v-if="item.host" class="text-xs txt-secondary surface-card px-2 py-0.5 rounded flex-shrink-0">
              {{ item.host }}
            </span>
          </div>
          <p v-if="item.desc" class="text-xs txt-secondary line-clamp-2">{{ item.desc }}</p>
        </div>
      </div>
    </a>
  </div>
</template>

<script setup lang="ts">
interface LinkItem {
  title: string
  url: string
  desc?: string
  host?: string
}

interface Props {
  items: LinkItem[]
}

defineProps<Props>()
</script>

<style scoped>
.line-clamp-2 {
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
</style>
