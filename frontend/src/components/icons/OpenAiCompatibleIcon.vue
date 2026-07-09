<template>
  <svg
    :height="size + 'px'"
    :width="size + 'px'"
    style="flex: none; line-height: 1"
    viewBox="0 0 24 24"
    xmlns="http://www.w3.org/2000/svg"
    :class="className"
  >
    <title>OpenAI-compatible (local)</title>
    <defs>
      <linearGradient :id="gradientId" x1="0" y1="0" x2="1" y2="1">
        <stop offset="0%" stop-color="#6ee7b7" />
        <stop offset="55%" stop-color="#22c55e" />
        <stop offset="100%" stop-color="#047857" />
      </linearGradient>
    </defs>
    <!-- Rounded "chip" body: the green gradient signals a self-hosted / local engine. -->
    <rect x="2.5" y="2.5" width="19" height="19" rx="5.5" :fill="`url(#${gradientId})`" />
    <!-- Central spark = the AI. White so it reads on light + dark surfaces. -->
    <path
      d="M12 6.3c.37 3.24 1.46 4.33 4.7 4.7-3.24.37-4.33 1.46-4.7 4.7-.37-3.24-1.46-4.33-4.7-4.7 3.24-.37 4.33-1.46 4.7-4.7Z"
      fill="#ffffff"
      fill-opacity="0.96"
    />
  </svg>
</template>

<script setup lang="ts">
import { useId } from 'vue'

withDefaults(
  defineProps<{
    size?: number
    className?: string
  }>(),
  {
    size: 24,
    className: '',
  },
)

// Each instance needs its own gradient id — reusing one id across multiple
// inline SVGs on the page makes later instances reference the first one's def
// and render as black. useId() gives a stable, collision-free id per instance.
const gradientId = `oac-grad-${useId()}`
</script>
