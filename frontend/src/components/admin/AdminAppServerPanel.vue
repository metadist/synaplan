<template>
  <div data-testid="section-app-server" class="space-y-6">
    <div class="surface-card rounded-lg p-6">
      <div class="flex items-center gap-3 mb-2">
        <Icon icon="mdi:server-network" class="w-6 h-6 text-[var(--brand)]" />
        <h3 class="text-lg font-semibold txt-primary">{{ $t('admin.appServer.title') }}</h3>
      </div>
      <p class="txt-secondary text-sm mb-6">{{ $t('admin.appServer.description') }}</p>

      <div v-if="!available" class="surface-chip rounded-lg p-4 text-sm txt-secondary">
        {{ $t('admin.appServer.unavailable') }}
      </div>

      <div v-else class="space-y-5">
        <!-- Current / default summary -->
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div class="surface-elevated rounded-lg p-4">
            <div class="text-xs txt-secondary mb-1">{{ $t('admin.appServer.currentLabel') }}</div>
            <div class="txt-primary font-medium break-all">{{ currentServer || '—' }}</div>
          </div>
          <div class="surface-elevated rounded-lg p-4">
            <div class="text-xs txt-secondary mb-1">{{ $t('admin.appServer.defaultLabel') }}</div>
            <div class="txt-primary font-medium break-all">{{ defaultServer || '—' }}</div>
          </div>
        </div>

        <!-- Edit -->
        <div>
          <label for="app-server-url" class="block text-sm font-medium txt-primary mb-2">
            {{ $t('admin.appServer.inputLabel') }}
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
          <p class="text-xs txt-secondary mt-2">{{ $t('admin.appServer.hint') }}</p>
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
            {{ $t('admin.appServer.reset') }}
          </button>
          <button
            type="button"
            class="btn-primary px-6 py-2.5 rounded-lg disabled:opacity-50 flex items-center gap-2"
            :disabled="saving || !serverInput.trim()"
            data-testid="btn-app-server-save"
            @click="testAndSave"
          >
            <Icon v-if="saving" icon="mdi:loading" class="w-4 h-4 animate-spin" />
            {{ saving ? $t('admin.appServer.testing') : $t('admin.appServer.save') }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import { useDialog } from '@/composables/useDialog'
import { useNotification } from '@/composables/useNotification'
import {
  isNativeServerControlAvailable,
  getNativeServerUrl,
  getNativeDefaultServerUrl,
  saveNativeServerUrl,
  resetNativeServerUrl,
} from '@/services/api/nativeServer'

const { t } = useI18n()
const { confirm } = useDialog()
const { success, error: showError } = useNotification()

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

async function testAndSave() {
  const url = serverInput.value.trim()
  if (!url) {
    showError(t('admin.appServer.invalidUrl'))
    return
  }
  saving.value = true
  try {
    const result = await saveNativeServerUrl(url)
    if (result.ok) {
      // The native shell reloads the WebView to re-bootstrap against the new
      // server, so this notification is mostly the last thing the user sees.
      success(t('admin.appServer.savedReloading'))
    } else {
      showError(result.error || t('admin.appServer.unreachable'))
    }
  } finally {
    saving.value = false
  }
}

async function confirmReset() {
  const confirmed = await confirm({
    title: t('admin.appServer.resetConfirmTitle'),
    message: t('admin.appServer.resetConfirmMessage', {
      server: defaultServer.value || 'https://web.synaplan.com',
    }),
    confirmText: t('admin.appServer.resetConfirmAction'),
  })
  if (!confirmed) return
  resetNativeServerUrl()
}
</script>
