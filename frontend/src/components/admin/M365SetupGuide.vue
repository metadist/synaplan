<script setup lang="ts">
/**
 * Azure-side setup steps for the Microsoft 365 connector.
 *
 * The fields below this panel are meaningless until an app registration exists,
 * and the two values that are easiest to get wrong (the redirect URI, which must
 * match character for character, and the scope list) are shown here as copyable
 * text rather than described in prose.
 */
import { onMounted, ref } from 'vue'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import { useNotification } from '@/composables/useNotification'
import { m365Api } from '@/services/api/m365Api'

const { t } = useI18n()
const { success, error: showError } = useNotification()

const AZURE_APP_REGISTRATIONS_URL =
  'https://portal.azure.com/#view/Microsoft_AAD_RegisteredApps/ApplicationsListBlade'

/** Exactly the delegated permissions MicrosoftOAuthConfig::SCOPES requests. */
const SCOPES = 'offline_access openid email profile User.Read Mail.Read'

const redirectUri = ref('')
const available = ref(false)
const copied = ref<'redirect' | 'scopes' | null>(null)

onMounted(async () => {
  try {
    const status = await m365Api.status()
    redirectUri.value = status.redirectUri
    available.value = status.available
  } catch {
    // The guide is still useful without the resolved URI; fall back to the
    // current origin, which is right for every non-proxied install.
    redirectUri.value = `${window.location.origin}/api/v1/connections/m365/callback`
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
    showError(t('admin.config.m365.copyFailed'))
  }
}
</script>

<template>
  <div
    class="surface-chip rounded-lg p-4 mb-5 border border-blue-500/20 bg-blue-500/5"
    data-testid="m365-setup-guide"
  >
    <div class="flex items-start gap-3">
      <Icon
        icon="heroicons:information-circle"
        class="w-5 h-5 text-blue-500 flex-shrink-0 mt-0.5"
      />
      <div class="flex-1 min-w-0">
        <h4 class="font-medium txt-primary mb-1">{{ $t('admin.config.m365.title') }}</h4>
        <p class="text-sm txt-secondary mb-3">{{ $t('admin.config.m365.intro') }}</p>

        <ol class="text-sm txt-secondary space-y-3 list-decimal list-outside ml-4">
          <li>
            <i18n-t keypath="admin.config.m365.step1" tag="span" scope="global">
              <template #portal>
                <a
                  :href="AZURE_APP_REGISTRATIONS_URL"
                  target="_blank"
                  rel="noopener noreferrer"
                  class="text-[var(--brand)] hover:underline"
                >
                  {{ $t('admin.config.m365.portalLink') }}
                </a>
              </template>
            </i18n-t>
          </li>

          <li>
            <p>{{ $t('admin.config.m365.step2') }}</p>
            <div class="flex items-center gap-2 mt-1.5">
              <code
                class="flex-1 min-w-0 truncate surface-card rounded px-2 py-1.5 text-xs font-mono txt-primary"
                data-testid="m365-redirect-uri"
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

          <li>
            <p>{{ $t('admin.config.m365.step3') }}</p>
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
            <p class="text-xs txt-secondary mt-1">{{ $t('admin.config.m365.step3Note') }}</p>
          </li>

          <li>{{ $t('admin.config.m365.step4') }}</li>
          <li>{{ $t('admin.config.m365.step5') }}</li>
        </ol>

        <p
          class="text-sm mt-3 flex items-center gap-2"
          :class="available ? 'text-[var(--status-success)]' : 'txt-secondary'"
          data-testid="m365-readiness"
        >
          <Icon
            :icon="available ? 'heroicons:check-circle' : 'heroicons:clock'"
            class="w-4 h-4 flex-shrink-0"
          />
          {{ available ? $t('admin.config.m365.ready') : $t('admin.config.m365.notReady') }}
        </p>
      </div>
    </div>
  </div>
</template>
