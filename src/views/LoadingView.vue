<template>
  <div class="min-h-screen flex items-center justify-center bg-app">
    <div class="text-center">
      <!-- Logo or Spinner -->
      <div class="mb-8">
        <div class="relative inline-flex">
          <!-- Outer spinning ring -->
          <div class="w-20 h-20 rounded-full border-4 border-light-border/20 dark:border-dark-border/20 border-t-[var(--brand)] animate-spin"></div>
          
          <!-- Inner pulsing dot -->
          <div class="absolute inset-0 flex items-center justify-center">
            <div class="w-8 h-8 rounded-full bg-[var(--brand)] animate-pulse"></div>
          </div>
        </div>
      </div>

      <!-- Loading Text -->
      <h2 class="text-xl font-semibold txt-primary mb-2 animate-pulse">
        {{ message || $t('loading.default') }}
      </h2>
      
      <!-- Optional subtitle -->
      <p v-if="subtitle" class="txt-secondary text-sm">
        {{ subtitle }}
      </p>

      <!-- Progress bar (optional) -->
      <div v-if="showProgress" class="mt-6 w-64 mx-auto">
        <div class="h-1 bg-light-border/20 dark:bg-dark-border/20 rounded-full overflow-hidden">
          <div 
            class="h-full bg-[var(--brand)] transition-all duration-300 ease-out rounded-full"
            :style="{ width: `${progress}%` }"
          ></div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
interface Props {
  message?: string
  subtitle?: string
  showProgress?: boolean
  progress?: number
}

withDefaults(defineProps<Props>(), {
  showProgress: false,
  progress: 0
})
</script>

