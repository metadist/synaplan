<template>
  <Transition name="slide-down">
    <div v-if="visible" class="flex justify-center mx-4 mt-2 mb-0">
      <div
        data-testid="guest-banner"
        class="inline-flex items-center gap-2.5 px-3 py-1.5 rounded-full border border-brand/20 bg-brand/5 dark:bg-brand/10 backdrop-blur-sm"
      >
        <div class="hidden sm:flex items-center gap-1">
          <span
            v-for="i in maxMessages"
            :key="i"
            class="w-1.5 h-1.5 rounded-full transition-colors duration-300"
            :class="i <= messagesUsed ? 'bg-brand' : 'bg-gray-300 dark:bg-gray-600'"
          />
        </div>

        <span class="text-xs txt-secondary whitespace-nowrap">
          {{ $t('guest.banner.remaining', { count: remaining }) }}
        </span>

        <span class="w-px h-3.5 bg-gray-300 dark:bg-gray-600" />

        <router-link
          to="/register"
          data-testid="guest-banner-signup"
          class="text-xs font-semibold text-brand hover:underline whitespace-nowrap"
        >
          {{ $t('guest.banner.signUp') }}
        </router-link>

        <button
          data-testid="guest-banner-dismiss"
          class="p-0.5 -mr-0.5 rounded-full hover:bg-black/5 dark:hover:bg-white/10 transition-colors txt-secondary"
          @click="$emit('dismiss')"
        >
          <Icon icon="mdi:close" class="w-3.5 h-3.5" />
        </button>
      </div>
    </div>
  </Transition>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { Icon } from '@iconify/vue'

const props = defineProps<{
  visible: boolean
  remaining: number
  maxMessages: number
}>()

defineEmits<{
  dismiss: []
}>()

const messagesUsed = computed(() => props.maxMessages - props.remaining)
</script>

<style scoped>
.slide-down-enter-active,
.slide-down-leave-active {
  transition: all 0.3s ease;
}
.slide-down-enter-from,
.slide-down-leave-to {
  opacity: 0;
  transform: translateY(-8px);
}
</style>
