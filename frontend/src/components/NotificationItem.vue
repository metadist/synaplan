<template>
  <div
    :class="[
      'flex items-start gap-3 p-4 rounded shadow-lg border backdrop-blur-sm transition-all duration-300',
      'min-w-[320px] max-w-[420px]',
      typeClasses[notification.type],
    ]"
    data-testid="comp-notification-item"
    :data-notification-type="notification.type"
  >
    <div class="flex-shrink-0 mt-0.5">
      <CheckCircleIcon v-if="notification.type === 'success'" class="w-5 h-5" />
      <XCircleIcon v-else-if="notification.type === 'error'" class="w-5 h-5" />
      <ExclamationTriangleIcon v-else-if="notification.type === 'warning'" class="w-5 h-5" />
      <InformationCircleIcon v-else class="w-5 h-5" />
    </div>

    <div class="flex-1 min-w-0">
      <p class="text-sm font-medium break-words">{{ notification.message }}</p>
    </div>

    <button
      class="flex-shrink-0 p-0.5 rounded hover:bg-black/10 dark:hover:bg-white/10 transition-colors"
      data-testid="btn-notification-close"
      @click="$emit('close')"
    >
      <XMarkIcon class="w-4 h-4" />
    </button>
  </div>
</template>

<script setup lang="ts">
import {
  CheckCircleIcon,
  XCircleIcon,
  ExclamationTriangleIcon,
  InformationCircleIcon,
  XMarkIcon,
} from '@heroicons/vue/24/outline'
import type { Notification } from '@/composables/useNotification'

defineProps<{
  notification: Notification
}>()

defineEmits<{
  close: []
}>()

const typeClasses = {
  success: 'bg-green-500/90 dark:bg-green-600/90 text-white border-green-600',
  error: 'bg-red-500/90 dark:bg-red-600/90 text-white border-red-600',
  warning: 'bg-orange-500/90 dark:bg-orange-600/90 text-white border-orange-600',
  info: 'bg-blue-500/90 dark:bg-blue-600/90 text-white border-blue-600',
}
</script>
