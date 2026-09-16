<script setup lang="ts">
/**
 * Dropbox-side setup steps for the Dropbox connector.
 *
 * The fields below this panel are meaningless until a Dropbox app exists, and
 * the two values that are easiest to get wrong (the redirect URI, which must
 * match character for character, and the permission list) are shown here as
 * copyable text rather than described in prose.
 */
import { onMounted, ref } from 'vue'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import { useDialog } from '@/composables/useDialog'
import { useNotification } from '@/composables/useNotification'
import { dropboxApi } from '@/services/api/dropboxApi'

const { t } = useI18n()
const dialog = useDialog()
const { success, error: showError } = useNotification()

const DROPBOX_APP_CONSOLE_URL = 'https://www.dropbox.com/developers/apps'

/** Exactly the scoped-app permissions DropboxOAuthConfig::SCOPES requests. */
const SCOPES = 'account_info.read files.content.write'

const redirectUri = ref('')
const available = ref(false)
const copied = ref<'redirect' | 'scopes' | null>(null)

onMounted(async () => {
  try {
    const status = await dropboxApi.status()
    redirectUri.value = status.redirectUri
    available.value = status.available
  } catch {
    // The guide is still useful without the resolved URI; fall back to the
    // current origin, which is right for every non-proxied install.
    redirectUri.value = `${window.location.origin}/api/v1/connections/dropbox/callback`
  }
})

const copy = async (value: string, which: 'redirect' | 'scopes') => {
  try {
    await navigator.clipboard.writeText(value)
    copied.value = which
    success(t('common.copied'))
    window.setTimeout(() => {
      copied.value = null
    }, 2000)
  } catch {
    showError(t('admin.config.dropbox.copyFailed'))
  }
}

const resetting = ref(false)

const resetConnections = async () => {
  const confirmed = await dialog.confirm({
    title: t('admin.config.dropbox.resetConfirmTitle'),
    message: t('admin.config.dropbox.resetConfirmMessage'),
    confirmText: t('admin.config.dropbox.resetButton'),
    danger: true,
  })
  if (!confirmed) return

  resetting.value = true
  try {
    const removed = await dropboxApi.resetAllConnections()
    success(t('admin.config.dropbox.resetDone', { count: removed }, removed))
  } catch {
    showError(t('admin.config.dropbox.resetFailed'))
  } finally {
    resetting.value = false
  }
}
</script>

<template>
  <div
    class="surface-chip rounded-lg p-4 mb-5 border border-blue-500/20 bg-blue-500/5"
    data-testid="dropbox-setup-guide"
  >
    <div class="flex items-start gap-3">
      <Icon
        icon="heroicons:information-circle"
        class="w-5 h-5 text-blue-500 flex-shrink-0 mt-0.5"
      />
      <div class="flex-1 min-w-0">
        <h4 class="font-medium txt-primary mb-1">{{ $t('admin.config.dropbox.title') }}</h4>
        <p class="text-sm txt-secondary mb-3">{{ $t('admin.config.dropbox.intro') }}</p>

        <ol class="text-sm txt-secondary space-y-3 list-decimal list-outside ml-4">
          <li>
            <i18n-t keypath="admin.config.dropbox.step1" tag="span" scope="global">
              <template #console>
                <a
                  :href="DROPBOX_APP_CONSOLE_URL"
                  target="_blank"
                  rel="noopener noreferrer"
                  class="text-[var(--brand)] hover:underline"
                >
                  {{ $t('admin.config.dropbox.consoleLink') }}
                </a>
              </template>
            </i18n-t>
          </li>

          <li>
            <p>{{ $t('admin.config.dropbox.step2') }}</p>
            <div class="flex items-center gap-2 mt-1.5">
              <code
                class="flex-1 min-w-0 truncate surface-card rounded px-2 py-1.5 text-xs font-mono txt-primary"
                >{{ SCOPES }}</code
              >
              <button
                type="button"
                class="btn-secondary inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium flex-shrink-0"
                @click="copy(SCOPES, 'scopes')"
              >
                <Icon
                  :icon="copied === 'scopes' ? 'heroicons:check' : 'heroicons:clipboard-document'"
                  class="w-4 h-4"
                />
                {{ copied === 'scopes' ? $t('common.copied') : $t('common.copy') }}
              </button>
            </div>
          </li>

          <li>
            <p>{{ $t('admin.config.dropbox.step3') }}</p>
            <div class="flex items-center gap-2 mt-1.5">
              <code
                class="flex-1 min-w-0 truncate surface-card rounded px-2 py-1.5 text-xs font-mono txt-primary"
                data-testid="dropbox-redirect-uri"
                >{{ redirectUri }}</code
              >
              <button
                type="button"
                class="btn-secondary inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium flex-shrink-0"
                :disabled="!redirectUri"
                @click="copy(redirectUri, 'redirect')"
              >
                <Icon
                  :icon="copied === 'redirect' ? 'heroicons:check' : 'heroicons:clipboard-document'"
                  class="w-4 h-4"
                />
                {{ copied === 'redirect' ? $t('common.copied') : $t('common.copy') }}
              </button>
            </div>
          </li>

          <li>{{ $t('admin.config.dropbox.step4') }}</li>
          <li>{{ $t('admin.config.dropbox.step5') }}</li>
        </ol>

        <p
          class="text-sm mt-3 flex items-center gap-2"
          :class="available ? 'text-[var(--status-success)]' : 'txt-secondary'"
          data-testid="dropbox-readiness"
        >
          <Icon
            :icon="available ? 'heroicons:check-circle' : 'heroicons:clock'"
            class="w-4 h-4 flex-shrink-0"
          />
          {{ available ? $t('admin.config.dropbox.ready') : $t('admin.config.dropbox.notReady') }}
        </p>

        <div class="mt-4 pt-4 border-t border-light-border/30 dark:border-dark-border/20">
          <h5 class="text-sm font-medium txt-primary mb-1">
            {{ $t('admin.config.dropbox.resetTitle') }}
          </h5>
          <p class="text-sm txt-secondary mb-2">
            {{ $t('admin.config.dropbox.resetDescription') }}
          </p>
          <button
            type="button"
            class="btn-danger inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium"
            :disabled="resetting"
            data-testid="btn-reset-dropbox-connections"
            @click="resetConnections"
          >
            <Icon icon="heroicons:arrow-path" class="w-4 h-4" />
            {{ $t('admin.config.dropbox.resetButton') }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
