<script setup lang="ts">
/**
 * Master switch for the periodic version check. While it is off, the backend
 * never makes an outbound request.
 */
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useNotification } from '@/composables/useNotification'
import { useUpdatesStore } from '@/stores/updates'

const { t } = useI18n()
const { success, error: showError } = useNotification()
const updatesStore = useUpdatesStore()

const saving = ref(false)

async function handleToggle() {
  if (saving.value) return

  saving.value = true
  try {
    await updatesStore.setCheckEnabled(!updatesStore.checkEnabled)
    success(t('updates.panel.saved'))
  } catch {
    showError(t('updates.panel.saveError'))
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div
    class="flex items-center justify-between gap-4 pt-4 border-t border-light-border/20 dark:border-dark-border/10"
  >
    <div class="min-w-0">
      <p class="text-sm font-medium txt-primary">{{ $t('updates.panel.autoCheck') }}</p>
      <p class="text-xs txt-secondary mt-0.5">{{ $t('updates.panel.autoCheckHint') }}</p>
    </div>
    <div class="flex items-center gap-3 flex-shrink-0">
      <!--
        The track colour lives on an inner element on purpose: the V2 design
        sets a flat background on every `[role="switch"]`, which would beat a
        utility class on the button itself and make on and off look identical.
      -->
      <button
        type="button"
        role="switch"
        :aria-checked="updatesStore.checkEnabled"
        :aria-label="$t('updates.panel.autoCheck')"
        :disabled="saving"
        :class="[
          'relative inline-flex h-6 w-11 flex-shrink-0 items-center cursor-pointer rounded-full bg-transparent focus:outline-none focus:ring-2 focus:ring-[var(--brand)] focus:ring-offset-2',
          saving && 'opacity-50 cursor-not-allowed',
        ]"
        data-testid="toggle-admin-updates-check"
        @click="handleToggle"
      >
        <span
          :class="[
            'pointer-events-none absolute inset-0 rounded-full transition-colors duration-200 ease-in-out',
            updatesStore.checkEnabled ? 'bg-[var(--brand)]' : 'bg-[var(--status-neutral)]',
          ]"
        />
        <span
          :class="[
            'pointer-events-none relative inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out',
            updatesStore.checkEnabled ? 'translate-x-5' : 'translate-x-0.5',
          ]"
        />
      </button>
      <span class="text-sm txt-secondary">
        {{ updatesStore.checkEnabled ? $t('common.enabled') : $t('common.disabled') }}
      </span>
    </div>
  </div>
</template>
