<template>
  <div data-testid="section-app-server" class="space-y-6">
    <div class="surface-card rounded-lg p-6">
      <div class="flex items-center gap-3 mb-2">
        <Icon icon="mdi:server-network" class="w-6 h-6 text-[var(--brand)]" />
        <h3 class="text-lg font-semibold txt-primary">{{ $t('nativeServer.appServer.title') }}</h3>
      </div>
      <p class="txt-secondary text-sm mb-6">{{ $t('nativeServer.appServer.description') }}</p>

      <div v-if="!available" class="surface-chip rounded-lg p-4 text-sm txt-secondary">
        {{ $t('nativeServer.appServer.unavailable') }}
      </div>

      <div v-else class="space-y-5">
        <!-- Current / default summary -->
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div class="surface-elevated rounded-lg p-4">
            <div class="text-xs txt-secondary mb-1">
              {{ $t('nativeServer.appServer.currentLabel') }}
            </div>
            <div class="txt-primary font-medium break-all">{{ currentServer || '—' }}</div>
          </div>
          <div class="surface-elevated rounded-lg p-4">
            <div class="text-xs txt-secondary mb-1">
              {{ $t('nativeServer.appServer.defaultLabel') }}
            </div>
            <div class="txt-primary font-medium break-all">{{ defaultServer || '—' }}</div>
          </div>
        </div>

        <!-- Edit -->
        <div>
          <label for="app-server-url" class="block text-sm font-medium txt-primary mb-2">
            {{ $t('nativeServer.appServer.inputLabel') }}
          </label>
          <input
            id="app-server-url"
            v-model="serverInput"
            type="url"
            inputmode="url"
            autocapitalize="off"
            autocorrect="off"
            spellcheck="false"
            :placeholder="defaultServer || 'https://web.synaplan.com'"
            :disabled="saving"
            data-testid="input-app-server-url"
            class="w-full px-4 py-2.5 rounded-lg bg-chat border border-light-border/30 dark:border-dark-border/20 txt-primary focus:ring-2 focus:ring-[var(--brand)] focus:outline-none disabled:opacity-60"
            @keyup.enter="testAndSave"
          />
          <p class="text-xs txt-secondary mt-2">{{ $t('nativeServer.appServer.hint') }}</p>
        </div>

        <!-- Actions -->
        <div class="flex flex-wrap items-center justify-end gap-3">
          <button
            type="button"
            class="btn-secondary px-4 py-2.5 rounded-lg mr-auto disabled:opacity-50"
            :disabled="saving"
            data-testid="btn-app-server-reset"
            @click="confirmReset"
          >
            {{ $t('nativeServer.appServer.reset') }}
          </button>
          <button
            type="button"
            class="btn-primary px-6 py-2.5 rounded-lg disabled:opacity-50 flex items-center gap-2"
            :disabled="saving || !serverInput.trim()"
            data-testid="btn-app-server-save"
            @click="testAndSave"
          >
            <Icon v-if="saving" icon="mdi:loading" class="w-4 h-4 animate-spin" />
            {{ saving ? $t('nativeServer.appServer.testing') : $t('nativeServer.appServer.save') }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
/**
 * Shared "change the app server" control, embedded in Settings (every
 * authenticated user) and Admin → App server. A server change is a deliberate
 * action that always requires a fresh login afterwards — see
 * `docs/SERVER_CONFIG.md` (synaplan-apps repo) for the full contract.
 */
import { onMounted, ref } from 'vue'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import { useDialog } from '@/composables/useDialog'
import { useNotification } from '@/composables/useNotification'
import { useAuthStore } from '@/stores/auth'
import {
  isNativeServerControlAvailable,
  getNativeServerUrl,
  getNativeDefaultServerUrl,
  saveNativeServerUrl,
  resetNativeServerUrl,
  reloadNativeApp,
} from '@/services/api/nativeServer'
import { clearAllNativeTokens } from '@/services/api/nativeAuth'

const { t } = useI18n()
const { confirm } = useDialog()
const { success, error: showError } = useNotification()
const authStore = useAuthStore()

const available = ref(false)
const currentServer = ref('')
const defaultServer = ref('')
const serverInput = ref('')
const saving = ref(false)

onMounted(() => {
  available.value = isNativeServerControlAvailable()
  if (!available.value) return
  currentServer.value = getNativeServerUrl()
  defaultServer.value = getNativeDefaultServerUrl()
  serverInput.value = currentServer.value
})

/**
 * A server change always requires a fresh login: sign out of the current
 * session and drop every stored native token (not just the current server's),
 * so switching back to a previously-used server never silently restores it.
 * Only then does the WebView reload against the newly persisted URL.
 */
async function signOutAndSwitchOver() {
  await authStore.logout(true)
  await clearAllNativeTokens()
  reloadNativeApp()
}

async function testAndSave() {
  const url = serverInput.value.trim()
  if (!url) {
    showError(t('nativeServer.appServer.invalidUrl'))
    return
  }
  saving.value = true
  try {
    const result = await saveNativeServerUrl(url)
    if (result.ok) {
      // The reload is imminent, so this notification is mostly the last thing
      // the user sees before landing back on the login screen.
      success(t('nativeServer.appServer.savedSigningOut'))
      await signOutAndSwitchOver()
    } else {
      showError(result.error || t('nativeServer.appServer.unreachable'))
    }
  } finally {
    saving.value = false
  }
}

async function confirmReset() {
  const confirmed = await confirm({
    title: t('nativeServer.appServer.resetConfirmTitle'),
    message: t('nativeServer.appServer.resetConfirmMessage', {
      server: defaultServer.value || 'https://web.synaplan.com',
    }),
    confirmText: t('nativeServer.appServer.resetConfirmAction'),
  })
  if (!confirmed) return
  resetNativeServerUrl()
  await signOutAndSwitchOver()
}
</script>
