<template>
  <div class="flex flex-col gap-4 text-center" data-testid="setup-step-done">
    <div
      class="mx-auto h-12 w-12 rounded-full bg-[var(--status-success-muted)] flex items-center justify-center"
    >
      <Icon icon="mdi:check" class="w-7 h-7 text-[var(--status-success-text)]" />
    </div>

    <div>
      <h2 class="text-xl font-bold txt-primary">{{ $t('setup.done.title') }}</h2>
      <p class="text-sm txt-secondary mt-1">{{ $t('setup.done.description') }}</p>
    </div>

    <!--
      The one line someone reads while locked out of their own server, so the
      command is set apart from the prose instead of wrapping inside it.
    -->
    <div class="flex flex-col gap-1.5">
      <p class="text-xs txt-secondary">{{ $t('setup.done.recoveryHint') }}</p>
      <code
        class="text-xs font-mono surface-chip px-2.5 py-2 rounded-lg txt-primary break-all text-left"
        data-testid="setup-done-recovery-command"
        >{{ $t('setup.done.recoveryCommand') }}</code
      >
    </div>

    <button
      type="button"
      class="btn-primary w-full py-2.5 rounded-lg text-sm font-semibold"
      :disabled="busy"
      data-testid="setup-done-enter"
      @click="enter"
    >
      {{ busy ? $t('setup.done.entering') : $t('setup.done.enter', { brand: brandName }) }}
    </button>
  </div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { Icon } from '@iconify/vue'
import { useConfigStore } from '@/stores/config'
import { useAuthStore } from '@/stores/auth'
import { useNotification } from '@/composables/useNotification'
import { getErrorMessage } from '@/utils/errorMessage'

const router = useRouter()
const { t } = useI18n()
const { error: notifyError } = useNotification()

const busy = ref(false)
const brandName = computed(() => useConfigStore().branding.name)

/**
 * The runtime config still says `wizardRequired: true` — it was loaded before
 * this wizard ran. Reloading it BEFORE navigating is what keeps the router's
 * setup gate from bouncing us straight back here, so a failed reload must not
 * navigate: it leaves the user on this step to retry.
 */
async function enter(): Promise<void> {
  busy.value = true
  try {
    await useConfigStore().reload()
    await useAuthStore().refreshUser()
    await router.replace('/')
  } catch (err) {
    notifyError(getErrorMessage(err) || t('setup.done.enterFailed'))
  } finally {
    busy.value = false
  }
}
</script>
