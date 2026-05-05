<template>
  <div class="my-3 p-4 surface-chip" data-testid="section-message-link">
    <div class="space-y-3">
      <div>
        <h4 class="text-sm font-medium txt-primary mb-2">
          {{ $t('commands.loginLinkGenerated') }}
        </h4>
        <a
          :href="url"
          target="_blank"
          rel="noopener noreferrer"
          class="block p-3 surface-card hover:ring-1 hover:ring-primary/30 transition-all break-all text-sm font-mono txt-secondary"
          data-testid="link-message-login"
        >
          {{ url }}
        </a>
      </div>
      <div class="text-xs txt-secondary">{{ $t('commands.expiresAt') }}: {{ formattedExpiry }}</div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useDateFormat } from '@/composables/useDateFormat'

interface Props {
  url: string
  expiresAt: string
}

const props = defineProps<Props>()
const { formatDateTime } = useDateFormat()

const formattedExpiry = computed(() => {
  return formatDateTime(new Date(props.expiresAt))
})
</script>
