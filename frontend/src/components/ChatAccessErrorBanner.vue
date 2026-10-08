<template>
  <div class="surface-card rounded-xl p-4" role="alert" data-testid="banner-chat-access-failed">
    <div class="flex items-start gap-3">
      <ExclamationTriangleIcon
        class="w-5 h-5 mt-0.5 flex-shrink-0 text-red-600 dark:text-red-400"
        aria-hidden="true"
      />
      <div class="min-w-0 flex-1 space-y-2">
        <p class="text-sm txt-primary">{{ $t('chat.accessFailed') }}</p>
        <button
          type="button"
          class="btn-primary inline-flex items-center gap-2 px-4 py-2.5 text-sm font-medium disabled:opacity-50 disabled:cursor-not-allowed"
          :disabled="retrying"
          data-testid="btn-chat-access-retry"
          @click="$emit('retry')"
        >
          <ArrowPathIcon class="w-5 h-5" :class="{ 'animate-spin': retrying }" aria-hidden="true" />
          {{ $t('chat.accessRetry') }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ArrowPathIcon, ExclamationTriangleIcon } from '@heroicons/vue/24/outline'

defineProps<{ retrying: boolean }>()

defineEmits<{ retry: [] }>()
</script>
