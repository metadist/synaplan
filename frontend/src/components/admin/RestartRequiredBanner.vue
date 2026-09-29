<script setup lang="ts">
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import { useNotification } from '@/composables/useNotification'

defineProps<{ visible: boolean }>()
defineEmits<{ dismiss: [] }>()

const RESTART_COMMAND = 'docker compose restart backend'

const { t } = useI18n()
const { success, error: showError } = useNotification()

async function copyCommand(): Promise<void> {
  try {
    await navigator.clipboard.writeText(RESTART_COMMAND)
    success(t('admin.config.commandCopied'))
  } catch {
    showError(t('admin.config.restartBanner.copyFailed'))
  }
}
</script>

<template>
  <Transition
    enter-active-class="transition-all duration-300 ease-out"
    enter-from-class="opacity-0 -translate-y-4"
    enter-to-class="opacity-100 translate-y-0"
    leave-active-class="transition-all duration-200 ease-in"
    leave-from-class="opacity-100 translate-y-0"
    leave-to-class="opacity-0 -translate-y-4"
  >
    <div
      v-if="visible"
      class="mb-6 p-4 rounded-lg border bg-[var(--status-warning-muted)] border-[var(--status-warning)]"
      data-testid="restart-required-banner"
    >
      <div class="flex items-start gap-3">
        <Icon
          icon="mdi:alert"
          class="w-6 h-6 text-[var(--status-warning-text)] flex-shrink-0 mt-0.5"
        />
        <div class="flex-1 min-w-0">
          <h4 class="font-semibold text-[var(--status-warning-text)]">
            {{ $t('admin.config.restartBanner.title') }}
          </h4>
          <p class="text-sm text-[var(--status-warning-text)] mt-1">
            {{ $t('admin.config.restartBanner.message') }}
          </p>
          <div class="mt-3 flex items-center gap-2">
            <code
              class="flex-1 min-w-0 truncate px-3 py-2 surface-card rounded text-sm font-mono txt-primary"
            >
              {{ RESTART_COMMAND }}
            </code>
            <button
              type="button"
              class="p-2 rounded-lg hover-surface"
              :title="$t('admin.config.restartBanner.copyCommand')"
              :aria-label="$t('admin.config.restartBanner.copyCommand')"
              @click="copyCommand"
            >
              <Icon icon="mdi:content-copy" class="w-5 h-5 text-[var(--status-warning-text)]" />
            </button>
          </div>
        </div>
        <button
          type="button"
          class="p-1 rounded hover-surface"
          :aria-label="$t('common.close')"
          @click="$emit('dismiss')"
        >
          <Icon icon="mdi:close" class="w-5 h-5 text-[var(--status-warning-text)]" />
        </button>
      </div>
    </div>
  </Transition>
</template>
