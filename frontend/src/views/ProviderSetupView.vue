<template>
  <MainLayout data-testid="view-admin-setup">
    <div class="container mx-auto px-6 py-8 max-w-5xl overflow-x-hidden">
      <!-- Header -->
      <div class="mb-6">
        <div class="flex items-center gap-3 mb-2">
          <Icon icon="mdi:rocket-launch-outline" class="w-8 h-8 text-[var(--brand)]" />
          <h1 class="text-3xl font-bold txt-primary">{{ $t('adminSetup.title') }}</h1>
        </div>
        <p class="txt-secondary">{{ $t('adminSetup.description') }}</p>
      </div>

      <!-- Readiness status -->
      <div
        class="rounded-lg p-4 mb-6 flex items-start gap-3 border"
        :class="
          chatReady ? 'bg-green-500/10 border-green-500/30' : 'bg-amber-500/10 border-amber-500/30'
        "
        data-testid="setup-status"
      >
        <Icon
          :icon="chatReady ? 'mdi:check-circle' : 'mdi:alert-circle-outline'"
          class="w-6 h-6 shrink-0 mt-0.5"
          :class="
            chatReady ? 'text-green-600 dark:text-green-400' : 'text-amber-600 dark:text-amber-400'
          "
        />
        <div>
          <p class="font-semibold txt-primary">
            {{
              chatReady ? $t('adminSetup.statusReadyTitle') : $t('adminSetup.statusNotReadyTitle')
            }}
          </p>
          <p class="text-sm txt-secondary mt-0.5">
            {{ chatReady ? $t('adminSetup.statusReadyText') : $t('adminSetup.statusNotReadyText') }}
          </p>
        </div>
      </div>

      <!-- Loading -->
      <div v-if="loading" class="text-center py-12">
        <Icon icon="mdi:loading" class="w-8 h-8 animate-spin mx-auto txt-secondary" />
      </div>

      <template v-else>
        <!-- Provider cards -->
        <h2 class="text-xl font-semibold txt-primary mb-3">
          {{ $t('adminSetup.cloudProviders') }}
        </h2>
        <p class="text-sm txt-secondary mb-4">{{ $t('adminSetup.cloudProvidersHint') }}</p>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-8">
          <ProviderKeyCard
            v-for="provider in sortedProviders"
            :key="provider.name"
            :provider="provider"
            :is-default-chat="provider.name === defaultChatProvider"
            @changed="refresh"
          />
        </div>

        <!-- Local AI note -->
        <div class="surface-card rounded-lg p-5 flex items-start gap-3">
          <Icon icon="mdi:server-outline" class="w-6 h-6 shrink-0 txt-brand mt-0.5" />
          <div class="min-w-0 flex-1">
            <div class="flex items-center gap-2">
              <h3 class="text-lg font-semibold txt-primary">
                {{ $t('adminSetup.localAi.title') }}
              </h3>
              <ProviderHelpHint
                help-id="ollama"
                url="https://ollama.com/download"
                :is-download="true"
              />
            </div>
            <p class="text-sm txt-secondary mt-1">{{ $t('adminSetup.localAi.description') }}</p>
          </div>
        </div>
      </template>
    </div>
  </MainLayout>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import MainLayout from '@/components/MainLayout.vue'
import ProviderHelpHint from '@/components/admin/ProviderHelpHint.vue'
import ProviderKeyCard from '@/components/admin/ProviderKeyCard.vue'
import { useNotification } from '@/composables/useNotification'
import { useConfigStore } from '@/stores/config'
import { listProviderKeys, type ProviderKeyStatus } from '@/services/api/providerKeysApi'

const { t } = useI18n()
const { error: showError } = useNotification()
const config = useConfigStore()

const loading = ref(true)
const providers = ref<ProviderKeyStatus[]>([])
const defaultChatProvider = ref('')

const chatReady = computed(() => config.setup.chatReady)

// Recommended first, then configured, then alphabetical — the fresh-install
// visitor should see the quickest path (free-tier key) at the top.
const sortedProviders = computed(() =>
  [...providers.value].sort((a, b) => {
    if (a.recommended !== b.recommended) return a.recommended ? -1 : 1
    if (a.configured !== b.configured) return a.configured ? -1 : 1
    return a.displayName.localeCompare(b.displayName)
  })
)

const load = async () => {
  try {
    const result = await listProviderKeys()
    providers.value = result.providers
    defaultChatProvider.value = result.defaultChatProvider
  } catch (err) {
    showError(err instanceof Error ? err.message : t('adminSetup.loadFailed'))
  } finally {
    loading.value = false
  }
}

// After any key change, re-fetch the list AND the runtime config so the
// readiness banner (here and in the chat) reflects the new state immediately.
const refresh = async () => {
  await Promise.all([load(), config.reload()])
}

onMounted(load)
</script>
