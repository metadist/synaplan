<template>
  <div class="my-3" data-testid="section-message-screenshot">
    <div class="relative">
      <div class="absolute top-3 left-3 z-10">
        <a
          :href="url"
          target="_blank"
          rel="noopener noreferrer"
          class="inline-flex items-center gap-1.5 px-2.5 py-1.5 surface-card backdrop-blur-sm text-xs font-medium txt-primary hover:ring-1 hover:ring-primary/30 transition-all"
          data-testid="link-screenshot-source"
        >
          <GlobeAltIcon class="w-3.5 h-3.5" />
          <span>{{ displayUrl }}</span>
        </a>
      </div>
      <div class="w-full aspect-video surface-card overflow-hidden">
        <img
          :src="imageUrl"
          :alt="`Screenshot of ${url}`"
          class="w-full h-full object-cover"
          loading="lazy"
        />
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { GlobeAltIcon } from '@heroicons/vue/24/outline'

interface Props {
  url: string
  imageUrl?: string
}

const props = defineProps<Props>()

const displayUrl = computed(() => {
  try {
    const urlObj = new URL(props.url)
    return urlObj.hostname
  } catch {
    return props.url
  }
})
</script>
