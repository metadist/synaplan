<template>
  <div
    class="rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 p-4 space-y-3"
    role="status"
    data-testid="mcp-permissions-notice"
  >
    <p class="txt-primary text-sm">{{ $t('mcpServers.permissions.notice') }}</p>
    <div class="flex flex-wrap gap-3">
      <button
        v-if="showReview"
        type="button"
        class="btn-primary px-4 py-2.5 text-sm font-medium"
        data-testid="btn-mcp-notice-review"
        @click="emit('review')"
      >
        {{ $t('mcpServers.permissions.noticeReview') }}
      </button>
      <button
        type="button"
        class="btn-secondary px-4 py-2.5 text-sm font-medium"
        :disabled="busy"
        data-testid="btn-mcp-notice-dismiss"
        @click="emit('dismiss')"
      >
        {{ $t('mcpServers.permissions.noticeDismiss') }}
      </button>
    </div>
  </div>
</template>

<script setup lang="ts">
defineProps<{ showReview: boolean; busy: boolean }>()
const emit = defineEmits<{ review: []; dismiss: [] }>()
</script>
