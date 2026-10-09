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

    <div
      v-else-if="loadFailed"
      class="surface-card p-6 flex flex-wrap items-center justify-between gap-3"
      data-testid="section-agents-load-error"
    >
      <p class="text-sm txt-secondary">{{ $t('messagesGateway.loadError') }}</p>
      <button
        type="button"
        class="btn-secondary px-4 py-2.5 text-sm font-medium"
        data-testid="btn-agents-retry"
        @click="load()"
      >
        {{ $t('common.retry') }}
      </button>
    </div>

    <template v-else-if="status">
      <div class="surface-card p-6" data-testid="section-agents-status">
        <h3 class="text-lg font-semibold txt-primary mb-3">
          {{ $t('messagesGateway.statusTitle') }}
        </h3>
        <div class="flex flex-wrap items-center gap-3 text-sm">
          <span
            class="inline-flex items-center gap-2 px-3 py-1 rounded-full"
            :class="
              gatewayReady
                ? 'bg-[var(--status-success-muted)] text-[var(--status-success-text)]'
                : 'bg-[var(--status-warning-muted)] text-[var(--status-warning-text)]'
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
              <a
                href="#anthropic-key"
                class="text-[var(--brand)] hover:underline font-medium"
                data-testid="link-missing-key-accounts"
              >
                {{ $t('messagesGateway.yourAiAccounts') }}
              </a>
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
        <p v-if="status.is_admin" class="text-sm mt-3" data-testid="text-gateway-admin">
          <span v-if="!status.enabled" class="txt-secondary">
            {{ $t('apps.claudeCode.adminOff') }}
          </span>
          <RouterLink
            :to="ADMIN_GATEWAY_PATH"
            class="text-[var(--brand)] hover:underline font-medium"
            data-testid="link-gateway-admin"
          >
            {{ $t('apps.claudeCode.adminLink') }}
          </RouterLink>
        </p>
      </div>

      <!-- Hidden from non-admins while the gateway is off. -->
      <ol v-if="showSetup" class="space-y-6" data-testid="section-agents-steps">
        <li id="anthropic-key" class="surface-card p-6" data-testid="section-anthropic">
          <StepHeading :number="1" :title="$t('apps.claudeCode.keyTitle')" />
          <p class="text-sm txt-secondary mb-4">{{ $t('apps.claudeCode.keyHint') }}</p>
          <AnthropicByokSection @changed="load(true)" />
        </li>

        <li class="surface-card p-6" data-testid="text-api-key-step">
          <StepHeading :number="2" :title="$t('apps.claudeCode.apiKeyTitle')" />
          <p class="txt-secondary text-sm">
            <i18n-t keypath="messagesGateway.apiKeyStep" tag="span">
              <template #keys>
                <RouterLink
                  to="/apps/api"
                  class="text-[var(--brand)] hover:underline font-medium"
                  data-testid="link-api-keys"
                >
                  {{ $t('messagesGateway.apiKeysLink') }}
                </RouterLink>
              </template>
            </i18n-t>
          </p>
        </li>

        <li class="surface-card p-6" data-testid="section-agents-setup">
          <StepHeading :number="3" :title="$t('messagesGateway.setupTitle')" />
          <p class="txt-secondary text-sm mb-3">{{ $t('messagesGateway.setupHint') }}</p>
          <pre
            class="p-4 rounded-lg surface-chip txt-primary text-xs font-mono overflow-x-auto whitespace-pre-wrap"
            data-testid="text-setup-snippet"
            >{{ setupSnippet }}</pre>
          <button
            type="button"
            class="btn-primary mt-3 px-4 py-2.5 text-sm font-medium"
            data-testid="btn-copy-setup"
            @click="copySetup"
          >
            {{ $t('messagesGateway.copySetup') }}
          </button>
          <p class="txt-secondary text-sm mt-4" data-testid="text-first-start">
            {{ $t('messagesGateway.firstStartHint') }}
          </p>
        </li>
      </ol>
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
import StepHeading from '@/components/common/StepHeading.vue'
import AnthropicByokSection from '@/components/config/AnthropicByokSection.vue'
import {
  getMessagesGatewayStatus,
  type MessagesGatewayStatus,
} from '@/services/api/messagesGatewayApi'
import {
  codingClientBaseUrl,
  codingClientSetupSnippet,
  providerKeyReady,
} from '@/utils/codingClientSetup'

const ADMIN_GATEWAY_PATH = '/admin/setup?tab=gateway'

const { t } = useI18n()
const { success, error } = useNotification()
const configStore = useConfigStore()

const loading = ref(true)
const loadFailed = ref(false)
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

/** `silent` refreshes the status after a key change without swapping the page for a spinner. */
async function load(silent = false) {
  if (!silent) {
    loading.value = true
  }
  try {
    status.value = await getMessagesGatewayStatus()
    loadFailed.value = false
  } catch {
    if (!status.value) loadFailed.value = true
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
