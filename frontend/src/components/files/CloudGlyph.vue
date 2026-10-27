<template>
  <span class="relative inline-flex shrink-0" data-testid="cloud-glyph">
    <img v-if="override" :src="override" alt="" class="block" :class="sizeClass" />
    <svg
      v-else
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      stroke-width="1.5"
      stroke-linecap="round"
      stroke-linejoin="round"
      aria-hidden="true"
      class="block"
      :class="sizeClass"
    >
      <path d="M4 14c2-6 6-8 9-7 2 .6 3 2 4 2 2 0 4-1.5 4-1.5" />
      <path d="M13 7c1 3 1 6-1 8-2 2-5 3-8 2 1-2 2-4 2-6" />
      <path d="M8 16c1 2 3 3 5 3" />
      <template v-if="eyes">
        <circle cx="15.5" cy="8.5" r="0.8" fill="currentColor" stroke="none" />
        <circle cx="18" cy="8" r="0.8" fill="currentColor" stroke="none" />
      </template>
    </svg>
    <svg
      v-if="badge && !override"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      stroke-width="1.5"
      stroke-linecap="round"
      stroke-linejoin="round"
      aria-hidden="true"
      class="absolute -bottom-0.5 -right-1 block h-2.5 w-2.5"
    >
      <template v-if="badge === 'clock'">
        <circle cx="12" cy="12" r="7" />
        <path d="M12 9v4l2 2" />
      </template>
      <template v-else-if="badge === 'arrows'">
        <path d="M7 8h8l-2-2M17 16H9l2 2" />
      </template>
      <template v-else-if="badge === 'dot'">
        <circle cx="12" cy="12" r="3" fill="currentColor" stroke="none" />
      </template>
      <template v-else-if="badge === 'alert'">
        <path d="M12 5l7 12H5l7-12z" />
        <path d="M12 10v3" />
      </template>
      <template v-else-if="badge === 'pause'">
        <path d="M9 7v10M15 7v10" />
      </template>
    </svg>
  </span>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { getConfigSync } from '@/services/api/httpClient'

const props = withDefaults(
  defineProps<{
    eyes?: boolean
    badge?: 'clock' | 'arrows' | 'dot' | 'alert' | 'pause' | null
    size?: 16 | 20
  }>(),
  {
    eyes: false,
    badge: null,
    size: 16,
  }
)

const sizeClass = computed(() => (props.size === 20 ? 'h-5 w-5' : 'h-4 w-4'))

const override = computed(() => {
  const config = getConfigSync() as {
    cloud_files?: { glyphs?: { bird?: string | null; bird_eyes?: string | null } }
  }
  const glyphs = config.cloud_files?.glyphs
  const url = props.eyes ? glyphs?.bird_eyes : glyphs?.bird
  return typeof url === 'string' && url !== '' ? url : null
})
</script>
