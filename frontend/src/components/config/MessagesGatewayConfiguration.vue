<template>
  <div class="space-y-6" data-testid="page-config-messages-gateway">
    <PageHeader
      :title="$t('messagesGateway.title')"
      :subtitle="$t('messagesGateway.description')"
      icon="heroicons:command-line"
      data-testid="section-agents-overview"
    />

    <div v-if="loading" class="text-center py-12" data-testid="section-agents-loading">
      <div
        class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-[var(--brand)]"
      ></div>
      <p class="mt-2 txt-secondary text-sm">{{ $t('common.loading') }}</p>
    </div>

    <template v-else-if="status">
      <!-- Status -->
      <div class="surface-card p-6" data-testid="section-agents-status">
        <h3 class="text-lg font-semibold txt-primary mb-3">
          {{ $t('messagesGateway.statusTitle') }}
        </h3>
        <div class="flex flex-wrap items-center gap-3 text-sm">
          <span
            class="inline-flex items-center gap-2 px-3 py-1 rounded-full"
            :class="
              gatewayReady
                ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'
                : 'bg-amber-500/10 text-amber-600 dark:text-amber-400'
            "
            data-testid="badge-gateway-enabled"
          >
            <Icon
              :icon="gatewayReady ? 'heroicons:check-circle' : 'heroicons:pause-circle'"
              class="w-4 h-4"
            />
            {{ statusBadgeLabel }}
          </span>
          <span class="txt-secondary font-mono text-xs" data-testid="text-upstream-url">
            {{ status.upstream_url }}
          </span>
        </div>
        <p v-if="missingKey" class="txt-secondary text-sm mt-3" data-testid="text-missing-key">
          <i18n-t keypath="messagesGateway.missingKey" tag="span">
            <template #accounts>
              <RouterLink
                to="/ai/providers?section=anthropic"
                class="text-[var(--brand)] hover:underline font-medium"
                data-testid="link-missing-key-accounts"
              >
                {{ $t('messagesGateway.yourAiAccounts') }}
              </RouterLink>
            </template>
          </i18n-t>
          <span v-if="!status.is_admin"> {{ $t('messagesGateway.missingKeyAdmin') }}</span>
        </p>
        <p
          v-else-if="!status.enabled && !status.is_admin"
          class="txt-secondary text-sm mt-3"
          data-testid="text-gateway-off"
        >
          {{ $t('messagesGateway.gatewayOff') }}
        </p>
        <p class="txt-secondary text-sm mt-3">
          {{
            budgetUnlimited
              ? $t('messagesGateway.budgetLineUnlimited', {
                  used: status.budget.used_cost ?? '0',
                })
              : $t('messagesGateway.budgetLine', {
                  percent: status.budget.percent ?? 0,
                  used: status.budget.used_cost ?? '0',
                  budget: status.budget.budget ?? '0',
                })
          }}
        </p>
      </div>

      <!-- Setup snippet. Hidden from non-admins while the gateway is off. -->
      <div v-if="showSetup" class="surface-card p-6" data-testid="section-agents-setup">
        <h3 class="text-lg font-semibold txt-primary mb-2">
          {{ $t('messagesGateway.setupTitle') }}
        </h3>
        <p class="txt-secondary text-sm mb-3">{{ $t('messagesGateway.setupHint') }}</p>
        <p class="txt-secondary text-sm mb-3" data-testid="text-api-key-step">
          <i18n-t keypath="messagesGateway.apiKeyStep" tag="span">
            <template #keys>
              <RouterLink
                to="/channels/api"
                class="text-[var(--brand)] hover:underline font-medium"
                data-testid="link-api-keys"
              >
                {{ $t('messagesGateway.apiKeysLink') }}
              </RouterLink>
            </template>
          </i18n-t>
        </p>
        <p class="txt-secondary text-sm mb-4" data-testid="text-first-start">
          {{ $t('messagesGateway.firstStartHint') }}
        </p>
        <pre
          class="p-4 rounded-lg surface-chip txt-primary text-xs font-mono overflow-x-auto whitespace-pre-wrap"
          data-testid="text-setup-snippet"
          >{{ setupSnippet }}</pre>
        <button
          type="button"
          class="btn-primary mt-3 px-4 py-2.5 rounded-xl text-sm font-medium"
          data-testid="btn-copy-setup"
          @click="copySetup"
        >
          {{ $t('messagesGateway.copySetup') }}
        </button>
      </div>

      <p class="txt-secondary text-sm" data-testid="text-ai-accounts-pointer">
        <RouterLink
          to="/ai/providers?section=anthropic"
          class="text-[var(--brand)] hover:underline font-medium"
          data-testid="link-ai-accounts-byok"
        >
          {{ $t('messagesGateway.byokPointer') }}
        </RouterLink>
      </p>

      <MessagesGatewayAdminSettings v-if="status.is_admin" :status="status" @saved="load(true)" />
    </template>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Icon } from '@iconify/vue'
import { RouterLink } from 'vue-router'
import { useNotification } from '@/composables/useNotification'
import { useConfigStore } from '@/stores/config'
import PageHeader from '@/components/PageHeader.vue'
import {
  getMessagesGatewayStatus,
  type MessagesGatewayStatus,
} from '@/services/api/messagesGatewayApi'
import {
  codingClientBaseUrl,
  codingClientSetupSnippet,
  providerKeyReady,
} from '@/utils/codingClientSetup'
import MessagesGatewayAdminSettings from './messagesGateway/MessagesGatewayAdminSettings.vue'

const { t } = useI18n()
const { success, error } = useNotification()
const configStore = useConfigStore()

const loading = ref(true)
const status = ref<MessagesGatewayStatus | null>(null)

// A budget of 0 means "no monthly budget configured" (unlimited), not "exhausted".
const budgetUnlimited = computed(() => Number(status.value?.budget?.budget ?? 0) <= 0)

const keyReady = computed(() => providerKeyReady(status.value?.keys?.anthropic?.effective_source))

const gatewayReady = computed(() => Boolean(status.value?.enabled) && keyReady.value)

const missingKey = computed(() => Boolean(status.value?.enabled) && !keyReady.value)

const showSetup = computed(() => Boolean(status.value?.enabled) || Boolean(status.value?.is_admin))

const statusBadgeLabel = computed(() => {
  if (!status.value?.enabled) {
    return t('messagesGateway.statusDisabled')
  }
  if (!keyReady.value) {
    return t('messagesGateway.statusNotReady')
  }
  return t('messagesGateway.statusEnabled')
})

const setupSnippet = computed(() => {
  const origin = typeof window !== 'undefined' ? window.location.origin : 'https://web.synaplan.com'
  const base = codingClientBaseUrl(
    configStore.apiBaseUrl,
    status.value?.setup?.base_url_hint ?? '',
    origin
  )
  return codingClientSetupSnippet(base)
})

/**
 * `silent` keeps the rendered page in place while a settings change is written
 * back — swapping the whole panel for a spinner after every toggle would make
 * the settings feel like they reload rather than save.
 */
async function load(silent = false) {
  if (!silent) {
    loading.value = true
  }
  try {
    status.value = await getMessagesGatewayStatus()
  } catch (err) {
    error((err as Error).message || t('messagesGateway.loadError'))
  } finally {
    loading.value = false
  }
}

async function copySetup() {
  try {
    await navigator.clipboard.writeText(setupSnippet.value)
    success(t('messagesGateway.copySuccess'))
  } catch {
    error(t('messagesGateway.copyError'))
  }
}

onMounted(() => {
  load()
})
</script>
