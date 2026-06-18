<template>
  <div class="space-y-6" data-testid="page-config-higgsfield">
    <!-- Header -->
    <div class="surface-card p-6" data-testid="section-higgsfield-overview">
      <div class="flex items-start gap-3">
        <div class="p-2 rounded-lg bg-[var(--brand)]/10">
          <Icon icon="heroicons:film" class="w-6 h-6 text-[var(--brand)]" />
        </div>
        <div class="flex-1 min-w-0">
          <h2 class="text-2xl font-semibold txt-primary mb-1">
            {{ $t('config.providers.higgsfield.title') }}
          </h2>
          <p class="txt-secondary text-sm leading-relaxed">
            {{ $t('config.providers.higgsfield.description') }}
          </p>
          <p class="txt-secondary text-xs mt-3">
            <a
              href="https://cloud.higgsfield.ai/"
              target="_blank"
              rel="noopener noreferrer"
              class="text-[var(--brand)] hover:underline font-medium"
            >
              {{ $t('config.providers.higgsfield.getKeyLink') }}
            </a>
          </p>
        </div>
      </div>
    </div>

    <!-- Status -->
    <div v-if="!loading" class="surface-card p-6" data-testid="section-higgsfield-status">
      <h3 class="text-lg font-semibold txt-primary mb-3">
        {{ $t('config.providers.higgsfield.statusTitle') }}
      </h3>
      <div class="flex flex-wrap items-center gap-4 text-sm">
        <span
          class="inline-flex items-center gap-2 px-3 py-1 rounded-full"
          :class="
            state.effective_source === 'user'
              ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'
              : state.effective_source === 'platform'
                ? 'bg-blue-500/10 text-blue-600 dark:text-blue-400'
                : 'bg-amber-500/10 text-amber-600 dark:text-amber-400'
          "
          data-testid="badge-effective-source"
        >
          <Icon
            :icon="
              state.effective_source === 'none'
                ? 'heroicons:exclamation-triangle'
                : 'heroicons:check-circle'
            "
            class="w-4 h-4"
          />
          {{ effectiveSourceLabel }}
        </span>

        <span v-if="state.has_user_credentials" class="txt-secondary">
          {{ $t('config.providers.higgsfield.userKeyLabel') }}:
          <code class="font-mono text-xs">{{ state.user_api_key_masked || '****' }}</code>
        </span>
      </div>
      <p v-if="state.effective_source === 'none'" class="text-sm txt-secondary mt-3">
        {{ $t('config.providers.higgsfield.noCredentialsHint') }}
      </p>
    </div>

    <!-- Form -->
    <div class="surface-card p-6" data-testid="section-higgsfield-form">
      <h3 class="text-lg font-semibold txt-primary mb-1">
        {{
          state.has_user_credentials
            ? $t('config.providers.higgsfield.replaceTitle')
            : $t('config.providers.higgsfield.connectTitle')
        }}
      </h3>
      <p class="txt-secondary text-sm mb-5">
        {{ $t('config.providers.higgsfield.formHint') }}
      </p>

      <div class="space-y-4">
        <label class="block">
          <span class="text-sm font-medium txt-primary">
            {{ $t('config.providers.higgsfield.apiKeyLabel') }}
          </span>
          <input
            v-model="apiKey"
            type="password"
            autocomplete="off"
            spellcheck="false"
            :placeholder="
              state.has_user_credentials
                ? state.user_api_key_masked
                : $t('config.providers.higgsfield.apiKeyPlaceholder')
            "
            class="mt-1 w-full px-3 py-2 rounded surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)] font-mono"
            data-testid="input-higgsfield-api-key"
          />
        </label>

        <label class="block">
          <span class="text-sm font-medium txt-primary">
            {{ $t('config.providers.higgsfield.apiSecretLabel') }}
          </span>
          <input
            v-model="apiSecret"
            type="password"
            autocomplete="off"
            spellcheck="false"
            :placeholder="$t('config.providers.higgsfield.apiSecretPlaceholder')"
            class="mt-1 w-full px-3 py-2 rounded surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)] font-mono"
            data-testid="input-higgsfield-api-secret"
          />
        </label>
      </div>

      <div class="flex flex-wrap items-center gap-3 mt-6">
        <button
          type="button"
          class="btn-primary px-4 py-2 rounded-lg text-sm font-medium disabled:opacity-50 disabled:cursor-not-allowed"
          :disabled="!canSave || saving"
          data-testid="btn-higgsfield-save"
          @click="onSave"
        >
          <Icon
            v-if="saving"
            icon="heroicons:arrow-path"
            class="w-4 h-4 inline animate-spin mr-1"
          />
          {{
            state.has_user_credentials
              ? $t('config.providers.higgsfield.replaceButton')
              : $t('config.providers.higgsfield.saveButton')
          }}
        </button>

        <button
          type="button"
          class="px-4 py-2 rounded-lg text-sm font-medium border border-light-border/30 dark:border-dark-border/20 txt-primary hover:border-[var(--brand)]/50 disabled:opacity-50 disabled:cursor-not-allowed"
          :disabled="state.effective_source === 'none' || testing"
          data-testid="btn-higgsfield-test"
          @click="onTest"
        >
          <Icon
            v-if="testing"
            icon="heroicons:arrow-path"
            class="w-4 h-4 inline animate-spin mr-1"
          />
          {{ $t('config.providers.higgsfield.testButton') }}
        </button>

        <button
          v-if="state.has_user_credentials"
          type="button"
          class="px-4 py-2 rounded-lg text-sm font-medium border border-red-500/30 text-red-600 dark:text-red-400 hover:bg-red-500/10 disabled:opacity-50 disabled:cursor-not-allowed"
          :disabled="clearing"
          data-testid="btn-higgsfield-clear"
          @click="onClear"
        >
          <Icon
            v-if="clearing"
            icon="heroicons:arrow-path"
            class="w-4 h-4 inline animate-spin mr-1"
          />
          {{ $t('config.providers.higgsfield.clearButton') }}
        </button>
      </div>
    </div>

    <div v-if="loading" class="text-center py-12" data-testid="section-higgsfield-loading">
      <div
        class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-[var(--brand)]"
      ></div>
      <p class="mt-2 txt-secondary text-sm">{{ $t('common.loading') }}</p>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Icon } from '@iconify/vue'
import { useDialog } from '@/composables/useDialog'
import { useNotification } from '@/composables/useNotification'
import {
  clearHiggsfieldCredentials,
  getHiggsfieldCredentialState,
  saveHiggsfieldCredentials,
  testHiggsfieldCredentials,
  type HiggsfieldCredentialState,
} from '@/services/api/higgsfieldCredentialsApi'

const { t } = useI18n()
const { confirm } = useDialog()
const { success, error } = useNotification()

const loading = ref(true)
const saving = ref(false)
const clearing = ref(false)
const testing = ref(false)

const apiKey = ref('')
const apiSecret = ref('')

const state = ref<HiggsfieldCredentialState>({
  has_platform_credentials: false,
  has_user_credentials: false,
  user_api_key_masked: '',
  effective_source: 'none',
})

const canSave = computed(() => apiKey.value.trim() !== '' && apiSecret.value.trim() !== '')

const effectiveSourceLabel = computed(() => {
  switch (state.value.effective_source) {
    case 'user':
      return t('config.providers.higgsfield.sourceUser')
    case 'platform':
      return t('config.providers.higgsfield.sourcePlatform')
    default:
      return t('config.providers.higgsfield.sourceNone')
  }
})

async function loadState() {
  loading.value = true
  try {
    state.value = await getHiggsfieldCredentialState()
  } catch (err) {
    error((err as Error).message || t('config.providers.higgsfield.loadError'))
  } finally {
    loading.value = false
  }
}

async function onSave() {
  if (!canSave.value || saving.value) return
  saving.value = true
  try {
    await saveHiggsfieldCredentials({
      api_key: apiKey.value.trim(),
      api_secret: apiSecret.value.trim(),
    })
    apiKey.value = ''
    apiSecret.value = ''
    success(t('config.providers.higgsfield.saveSuccess'))
    await loadState()
  } catch (err) {
    error((err as Error).message || t('config.providers.higgsfield.saveError'))
  } finally {
    saving.value = false
  }
}

async function onClear() {
  if (clearing.value) return

  const confirmed = await confirm({
    title: t('config.providers.higgsfield.clearConfirmTitle'),
    message: t('config.providers.higgsfield.clearConfirm'),
    confirmText: t('config.providers.higgsfield.clearButton'),
    danger: true,
  })
  if (!confirmed) return

  clearing.value = true
  try {
    await clearHiggsfieldCredentials()
    success(t('config.providers.higgsfield.clearSuccess'))
    await loadState()
  } catch (err) {
    error((err as Error).message || t('config.providers.higgsfield.clearError'))
  } finally {
    clearing.value = false
  }
}

async function onTest() {
  if (testing.value) return
  testing.value = true
  try {
    const result = await testHiggsfieldCredentials()
    if (result.success) {
      success(result.message ?? t('config.providers.higgsfield.testSuccess'))
    } else {
      error(result.message ?? t('config.providers.higgsfield.testError'))
    }
  } catch (err) {
    error((err as Error).message || t('config.providers.higgsfield.testError'))
  } finally {
    testing.value = false
  }
}

onMounted(() => {
  loadState()
})
</script>
