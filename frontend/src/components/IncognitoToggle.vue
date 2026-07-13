<template>
  <button
    type="button"
    :class="[
      'flex items-center justify-center w-10 h-10 rounded-full shadow-lg active:scale-95 transition-transform',
      incognitoStore.active ? 'btn-primary' : 'surface-card txt-primary',
    ]"
    :aria-label="incognitoStore.active ? $t('incognito.toggleEnd') : $t('incognito.toggleStart')"
    :title="incognitoStore.active ? $t('incognito.toggleEnd') : $t('incognito.toggleStart')"
    :aria-pressed="incognitoStore.active"
    data-testid="btn-incognito-toggle"
    @click="handleToggle"
  >
    <Icon icon="mdi:incognito" class="w-6 h-6" aria-hidden="true" />
  </button>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { Icon } from '@iconify/vue'
import { useIncognitoStore } from '@/stores/incognito'
import { useHistoryStore } from '@/stores/history'
import { useDialog } from '@/composables/useDialog'
import { triggerHapticImpact } from '@/services/api/nativeHaptics'

/**
 * Incognito session toggle. Only flips the store state — ChatView watches
 * `incognitoStore.active` and swaps the transcript / cleans up files, so the
 * mobile (MainLayout) and desktop (ChatView) instances behave identically.
 */
const { t } = useI18n()
const incognitoStore = useIncognitoStore()
const historyStore = useHistoryStore()
const { confirm } = useDialog()

const handleToggle = async () => {
  triggerHapticImpact('light')

  if (!incognitoStore.active) {
    incognitoStore.startSession()
    return
  }

  // Ending with a non-empty transcript discards data — ask first.
  if (historyStore.messages.length > 0 || incognitoStore.hasEphemeralFiles) {
    const confirmed = await confirm({
      title: t('incognito.endConfirmTitle'),
      message: t('incognito.endConfirmMessage'),
      confirmText: t('incognito.endConfirmButton'),
      cancelText: t('common.cancel'),
      danger: true,
    })
    if (!confirmed) return
  }

  await incognitoStore.endSession()
}
</script>
