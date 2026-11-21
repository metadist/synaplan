<template>
  <div data-testid="web-search-plug-tab">
    <p class="text-sm txt-secondary mb-4">{{ $t('aiInfra.webSearch.lead') }}</p>

    <div v-if="loading" class="text-center py-12">
      <Icon icon="mdi:loading" class="w-8 h-8 animate-spin mx-auto txt-secondary" />
    </div>

    <div v-else-if="loadFailed" class="surface-card rounded-lg p-8 text-center">
      <p class="txt-secondary">{{ $t('aiInfra.webSearch.loadFailed') }}</p>
      <button
        type="button"
        class="btn-primary px-4 py-2.5 rounded-xl text-sm font-medium mt-4"
        @click="load"
      >
        {{ $t('common.retry') }}
      </button>
    </div>

    <template v-else>
      <label
        class="surface-card rounded-lg p-4 mb-4 flex items-start gap-3"
        data-testid="web-search-enabled"
      >
        <input v-model="enabled" type="checkbox" class="mt-1" />
        <span>
          <span class="block text-sm font-medium txt-primary">{{
            $t('aiInfra.webSearch.enabled')
          }}</span>
          <span class="block text-xs txt-secondary mt-0.5">{{
            $t('aiInfra.webSearch.enabledHint')
          }}</span>
        </span>
      </label>

      <div v-if="enabled" class="space-y-4 mb-6">
        <article
          v-for="provider in providers"
          :key="provider.key"
          class="surface-card rounded-lg p-4"
          :data-testid="`web-search-card-${provider.key}`"
        >
          <div class="flex flex-wrap items-start gap-3">
            <label class="flex items-center gap-2 min-w-0">
              <input
                v-model="active"
                type="radio"
                class="mt-0.5"
                :value="provider.key"
                :data-testid="`web-search-active-${provider.key}`"
              />
              <span class="text-sm font-semibold txt-primary">{{ provider.label }}</span>
            </label>
            <span
              class="sovereignty-badge"
              :class="sovereigntyClass(provider.sovereignty)"
              :data-testid="`web-search-sovereignty-${provider.key}`"
            >
              {{ sovereigntyLabel(provider.sovereignty) }}
            </span>
            <a
              v-if="provider.docsUrl"
              :href="provider.docsUrl"
              target="_blank"
              rel="noopener noreferrer"
              class="text-sm text-[var(--brand)] hover:underline"
            >
              {{ $t('aiInfra.webSearch.docs') }}
            </a>
          </div>

          <p class="text-sm txt-secondary mt-2" :data-testid="`web-search-health-${provider.key}`">
            {{
              provider.health.available
                ? $t('aiInfra.webSearch.healthAvailable')
                : provider.health.reason || $t('aiInfra.webSearch.healthUnavailable')
            }}
          </p>

          <ul class="flex flex-wrap gap-2 mt-3" :aria-label="$t('aiInfra.webSearch.capabilities')">
            <li
              v-for="cap in enabledCapabilities(provider)"
              :key="cap"
              class="inline-flex items-center gap-1 text-xs txt-secondary"
            >
              <Icon :icon="capabilityIcon(cap)" class="w-3.5 h-3.5" aria-hidden="true" />
              {{ $t(`aiInfra.webSearch.capability.${cap}`) }}
            </li>
          </ul>

          <div v-if="provider.key === 'brave'" class="mt-4 space-y-3">
            <div>
              <label class="block text-sm font-medium txt-primary" for="plug-key-brave">
                {{ $t('aiInfra.webSearch.apiKey') }}
              </label>
              <input
                id="plug-key-brave"
                v-model="braveKey"
                type="password"
                autocomplete="off"
                class="mt-1 w-full px-3 py-2 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
                :placeholder="
                  braveKeySet
                    ? $t('aiInfra.webSearch.apiKeyKept')
                    : $t('aiInfra.webSearch.apiKeyPlaceholder')
                "
                data-testid="web-search-key-brave"
              />
            </div>
            <div>
              <label class="block text-sm font-medium txt-primary" for="web-search-brave-count">
                {{ $t('aiInfra.webSearch.braveCount') }}
              </label>
              <input
                id="web-search-brave-count"
                v-model="braveCount"
                type="number"
                min="1"
                max="20"
                class="mt-1 w-28 px-3 py-2 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
                data-testid="web-search-brave-count"
              />
            </div>
            <p class="text-xs txt-secondary">{{ $t('aiInfra.webSearch.braveRestart') }}</p>
          </div>

          <div v-else-if="hasKeyField(provider.key)" class="mt-4">
            <label class="block text-sm font-medium txt-primary" :for="`plug-key-${provider.key}`">
              {{ $t('aiInfra.webSearch.apiKey') }}
            </label>
            <div class="mt-1 flex flex-col sm:flex-row gap-2">
              <input
                :id="`plug-key-${provider.key}`"
                v-model="keyDraft[provider.key]"
                type="password"
                autocomplete="off"
                class="flex-1 min-w-0 px-3 py-2 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
                :placeholder="
                  provider.keyStatus.maskedKey || $t('aiInfra.webSearch.apiKeyPlaceholder')
                "
                :data-testid="`web-search-key-${provider.key}`"
              />
              <button
                type="button"
                class="btn-secondary px-4 py-2.5 rounded-xl text-sm font-medium"
                :disabled="savingKey === provider.key || !keyDraft[provider.key]"
                :data-testid="`web-search-save-key-${provider.key}`"
                @click="saveKey(provider.key)"
              >
                {{ $t('aiInfra.webSearch.saveKey') }}
              </button>
            </div>
          </div>
        </article>
      </div>

      <div v-if="enabled" class="surface-card rounded-lg p-4 mb-4">
        <label class="block text-sm font-medium txt-primary" for="web-search-fallback">
          {{ $t('aiInfra.webSearch.fallback') }}
        </label>
        <select
          id="web-search-fallback"
          v-model="fallback"
          class="mt-1 w-full px-3 py-2 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
          data-testid="web-search-fallback"
        >
          <option value="">{{ $t('aiInfra.webSearch.fallbackNone') }}</option>
          <option v-for="provider in fallbackOptions" :key="provider.key" :value="provider.key">
            {{ provider.label }}
          </option>
        </select>

        <p class="mt-4 text-sm txt-secondary" data-testid="web-search-who-chooses">
          {{
            readyCount >= 2
              ? $t('aiInfra.webSearch.peopleChoose')
              : $t('aiInfra.webSearch.oneEngine')
          }}
        </p>
      </div>

      <div v-if="enabled" class="surface-card rounded-lg p-4">
        <h3 class="text-sm font-semibold txt-primary">{{ $t('aiInfra.webSearch.testTitle') }}</h3>
        <p class="text-sm txt-secondary mt-1 mb-3">{{ $t('aiInfra.webSearch.testHint') }}</p>
        <div class="flex flex-col sm:flex-row gap-2">
          <input
            v-model="testQuery"
            type="text"
            class="flex-1 min-w-0 px-3 py-2 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
            :placeholder="$t('aiInfra.webSearch.testPlaceholder')"
            data-testid="web-search-test-query"
          />
          <button
            type="button"
            class="btn-secondary px-4 py-2.5 rounded-xl text-sm font-medium"
            :disabled="testing || !testQuery.trim()"
            data-testid="web-search-test-button"
            @click="runTest"
          >
            {{ testing ? $t('aiInfra.webSearch.testing') : $t('aiInfra.webSearch.testButton') }}
          </button>
        </div>
        <ul v-if="testResult" class="mt-3 space-y-1" data-testid="web-search-test-results">
          <li
            v-if="testResult.provider && !testResult.error"
            class="text-sm txt-secondary"
            data-testid="web-search-test-provider"
          >
            {{ testProviderLabel(testResult) }}
          </li>
          <li v-if="testResult.error" class="text-sm text-red-600 dark:text-red-400">
            {{ testResult.error }}
          </li>
          <li
            v-for="(hit, index) in testResult.results ?? []"
            :key="`${hit.url}-${index}`"
            class="text-sm txt-primary"
          >
            {{ hit.title }}
          </li>
        </ul>
      </div>

      <button
        type="button"
        class="btn-primary px-4 py-2.5 rounded-xl text-sm font-medium mt-4"
        :disabled="saving"
        data-testid="web-search-save"
        @click="save"
      >
        {{ saving ? $t('common.saving') : $t('aiInfra.webSearch.save') }}
      </button>
    </template>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Icon } from '@iconify/vue'
import { useNotification } from '@/composables/useNotification'
import { webSearchSaveFeedback } from '@/components/admin/plugs/webSearchSaveFeedback'
import { getConfigValues, updateConfigValue } from '@/services/api/adminConfigApi'
import {
  getWebSearchStatus,
  savePlugKey,
  saveWebSearch,
  testWebSearch,
  type WebSearchStatus,
  type WebSearchTestResult,
} from '@/services/api/adminPlugsApi'

type ProviderCard = WebSearchStatus['providers'][number]
type CapabilityKey = 'freshness' | 'country' | 'language' | 'siteFilter' | 'fullContent' | 'answer'

const KEY_PROVIDERS = new Set(['tavily', 'exa', 'firecrawl', 'specstream', 'perplexity'])

const { t } = useI18n()
const { success, warning, error: showError } = useNotification()

const loading = ref(true)
const loadFailed = ref(false)
const saving = ref(false)
const testing = ref(false)
const savingKey = ref('')
const providers = ref<ProviderCard[]>([])
const active = ref('brave')
const fallback = ref('')
const enabled = ref(true)
const braveKey = ref('')
const braveKeySet = ref(false)
const braveCount = ref('10')
const loadedBraveCount = ref('10')
const testQuery = ref('')
const testResult = ref<WebSearchTestResult | null>(null)
const keyDraft = reactive<Record<string, string>>({})

const fallbackOptions = computed(() => providers.value.filter((p) => p.key !== active.value))
const readyCount = computed(
  () =>
    providers.value.filter(
      (provider) =>
        provider.keyStatus.configured || (provider.key === 'searxng' && provider.health.available)
    ).length
)

onMounted(() => {
  void load()
})

async function load(): Promise<void> {
  loading.value = true
  loadFailed.value = false
  try {
    const status = await getWebSearchStatus()
    applyStatus(status)
    await loadBraveFields()
  } catch (err) {
    loadFailed.value = true
    showError(err instanceof Error ? err.message : t('aiInfra.webSearch.loadFailed'))
  } finally {
    loading.value = false
  }
}

function applyStatus(status: WebSearchStatus): void {
  providers.value = status.providers
  active.value = status.active
  fallback.value = status.fallback
  enabled.value = status.enabled !== false
}

async function loadBraveFields(): Promise<void> {
  try {
    const values = await getConfigValues()
    const count = values.BRAVE_SEARCH_COUNT?.value
    braveCount.value = count && count !== '' ? count : '10'
    loadedBraveCount.value = braveCount.value
    braveKeySet.value = values.BRAVE_SEARCH_API_KEY?.isSet === true
    braveKey.value = ''
  } catch {
    // The provider list still works when the Brave fields cannot be read.
  }
}

async function save(): Promise<void> {
  saving.value = true
  try {
    const status = await saveWebSearch({
      active: active.value,
      fallback: fallback.value,
      enabled: enabled.value,
    })
    applyStatus(status)
    const restart = await saveBraveFields()
    const feedback = webSearchSaveFeedback(status)
    const message = t(`aiInfra.webSearch.${feedback.key}`, feedback.params)
    if (feedback.level === 'warning') {
      warning(message)
    } else if (restart) {
      warning(t('aiInfra.webSearch.braveRestart'))
    } else {
      success(message)
    }
  } catch (err) {
    showError(err instanceof Error ? err.message : t('aiInfra.webSearch.saveFailed'))
  } finally {
    saving.value = false
  }
}

async function saveBraveFields(): Promise<boolean> {
  let restart = false
  const key = braveKey.value.trim()
  if (key !== '') {
    const result = await updateConfigValue('BRAVE_SEARCH_API_KEY', key)
    braveKey.value = ''
    braveKeySet.value = true
    restart = result.requiresRestart === true
  }
  if (braveCount.value !== loadedBraveCount.value) {
    const result = await updateConfigValue('BRAVE_SEARCH_COUNT', braveCount.value)
    loadedBraveCount.value = braveCount.value
    restart = restart || result.requiresRestart === true
  }

  return restart
}

async function saveKey(provider: string): Promise<void> {
  const key = keyDraft[provider]?.trim()
  if (!key) return
  savingKey.value = provider
  try {
    const keyStatus = await savePlugKey(provider, key)
    keyDraft[provider] = ''
    const card = providers.value.find((p) => p.key === provider)
    if (card) {
      card.keyStatus = keyStatus
    }
    success(t('aiInfra.webSearch.keySaved'))
    try {
      const refreshed = await getWebSearchStatus()
      applyStatus(refreshed)
    } catch {
      // Key is already stored; a stale badge is better than a false save error.
    }
  } catch (err) {
    showError(err instanceof Error ? err.message : t('aiInfra.webSearch.saveFailed'))
  } finally {
    savingKey.value = ''
  }
}

async function runTest(): Promise<void> {
  testing.value = true
  testResult.value = null
  try {
    testResult.value = await testWebSearch(active.value, testQuery.value.trim())
  } catch (err) {
    showError(err instanceof Error ? err.message : t('aiInfra.webSearch.loadFailed'))
  } finally {
    testing.value = false
  }
}

function testProviderLabel(result: WebSearchTestResult): string {
  if (result.fellBackFrom) {
    return t('aiInfra.webSearch.testProviderFallback', {
      provider: result.provider,
      from: result.fellBackFrom,
    })
  }

  return t('aiInfra.webSearch.testProvider', { provider: result.provider ?? '' })
}

function hasKeyField(key: string): boolean {
  return KEY_PROVIDERS.has(key)
}

function enabledCapabilities(provider: ProviderCard): CapabilityKey[] {
  const caps = provider.capabilities
  return (Object.keys(caps) as CapabilityKey[]).filter((key) => caps[key])
}

function capabilityIcon(cap: CapabilityKey): string {
  const icons: Record<CapabilityKey, string> = {
    freshness: 'mdi:clock-outline',
    country: 'mdi:flag-outline',
    language: 'mdi:translate',
    siteFilter: 'mdi:web',
    fullContent: 'mdi:file-document-outline',
    answer: 'mdi:comment-quote-outline',
  }
  return icons[cap]
}

function sovereigntyClass(value: string): string {
  if (value === 'self-hosted') return 'sovereignty-badge--self-hosted'
  if (value === 'EU') return 'sovereignty-badge--eu'
  return 'sovereignty-badge--us-cloud'
}

function sovereigntyLabel(value: string): string {
  if (value === 'self-hosted') return t('aiInfra.webSearch.sovereignty.selfHosted')
  if (value === 'EU') return t('aiInfra.webSearch.sovereignty.eu')
  return t('aiInfra.webSearch.sovereignty.usCloud')
}
</script>
