<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Icon } from '@iconify/vue'
import { useAuthStore } from '@/stores/auth'

/**
 * Expandable provider dump. The server sends `debug` only to an administrator,
 * including one who is impersonating this account. This check is the second
 * gate: a field that leaked to the account itself stays shut, because
 * `canSeeAdminDiagnostics` is false for that person.
 */
const props = defineProps<{
  debug?: string | null
  detailsTestId?: string
  debugTestId?: string
}>()

const { t } = useI18n()
const authStore = useAuthStore()
const open = ref(false)
const visible = computed(() => authStore.canSeeAdminDiagnostics && !!props.debug?.trim())
</script>

<template>
  <div v-if="visible" class="pt-2 border-t border-light-border/30 dark:border-dark-border/20">
    <button
      type="button"
      class="flex items-center gap-2 text-xs font-semibold alert-error-text hover:opacity-80"
      :data-testid="detailsTestId ?? 'btn-admin-error-details'"
      @click="open = !open"
    >
      <Icon :icon="open ? 'mdi:chevron-up' : 'mdi:chevron-down'" class="w-4 h-4" />
      {{ t('chatError.showDetails') }}
      <span
        class="text-[10px] uppercase font-bold px-2 py-0.5 rounded bg-black/5 dark:bg-white/10 alert-error-text tracking-wider"
      >
        {{ t('error.adminOnly') }}
      </span>
    </button>
    <pre
      v-if="open"
      class="mt-2 text-xs txt-primary font-mono bg-black/5 dark:bg-white/5 p-3 rounded break-words whitespace-pre-wrap"
      :data-testid="debugTestId ?? 'admin-error-debug'"
      >{{ debug }}</pre>
  </div>
</template>
