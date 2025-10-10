<template>
  <div class="my-3">
    <div 
      class="relative w-full aspect-video surface-card overflow-hidden cursor-pointer group border border-light-border/30 dark:border-dark-border/20 hover:border-light-border/50 dark:hover:border-dark-border/30 transition-all"
      @click="openFullscreen"
    >
      <img
        :src="url"
        :alt="alt"
        class="w-full h-full object-cover transition-transform group-hover:scale-105"
        loading="lazy"
      />
      <div class="absolute inset-0 bg-black/0 group-hover:bg-black/20 transition-colors flex items-center justify-center">
        <div class="opacity-0 group-hover:opacity-100 transition-opacity surface-card p-3 rounded-full">
          <svg class="w-6 h-6 txt-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM10 7v3m0 0v3m0-3h3m-3 0H7" />
          </svg>
        </div>
      </div>
    </div>
    <p v-if="alt" class="mt-2 text-sm txt-secondary">{{ alt }}</p>
  </div>

  <!-- Fullscreen Modal -->
  <Transition
    enter-active-class="transition-opacity duration-300"
    enter-from-class="opacity-0"
    enter-to-class="opacity-100"
    leave-active-class="transition-opacity duration-200"
    leave-from-class="opacity-100"
    leave-to-class="opacity-0"
  >
    <div
      v-if="isFullscreen"
      class="fixed inset-0 bg-black/95 z-[100] flex items-center justify-center p-4"
      @click="closeFullscreen"
    >
      <button
        @click.stop="closeFullscreen"
        class="absolute top-4 right-4 text-white/80 hover:text-white transition-colors p-2"
        aria-label="Close"
      >
        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
        </svg>
      </button>
      <img
        :src="url"
        :alt="alt"
        class="max-w-full max-h-full object-contain"
        @click.stop
      />
      <p v-if="alt" class="absolute bottom-6 left-1/2 -translate-x-1/2 text-white/90 text-sm bg-black/50 px-4 py-2 rounded-lg max-w-2xl text-center">
        {{ alt }}
      </p>
    </div>
  </Transition>
</template>

<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue'

interface Props {
  url: string
  alt?: string
}

defineProps<Props>()

const isFullscreen = ref(false)

const openFullscreen = () => {
  isFullscreen.value = true
}

const closeFullscreen = () => {
  isFullscreen.value = false
}

const handleEscape = (e: KeyboardEvent) => {
  if (e.key === 'Escape' && isFullscreen.value) {
    closeFullscreen()
  }
}

onMounted(() => {
  window.addEventListener('keydown', handleEscape)
})

onUnmounted(() => {
  window.removeEventListener('keydown', handleEscape)
})
</script>
