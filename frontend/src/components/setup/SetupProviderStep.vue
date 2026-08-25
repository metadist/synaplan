<template>
  <div class="flex flex-col gap-4" data-testid="setup-step-provider">
    <div>
      <h2 class="text-xl font-bold txt-primary">{{ $t('setup.provider.title') }}</h2>
      <p class="text-sm txt-secondary mt-1">{{ $t('setup.provider.description') }}</p>
    </div>

    <p v-if="loading" class="text-sm txt-secondary" data-testid="setup-provider-loading">
      {{ $t('setup.provider.loading') }}
    </p>

    <p
      v-else-if="loadError"
      class="text-sm text-[var(--status-error-text)] bg-[var(--status-error-muted)] rounded-lg px-3 py-2"
      data-testid="setup-provider-error"
    >
      {{ loadError }}
    </p>

    <template v-else>
      <p
        v-if="anyConfigured"
        class="text-sm text-[var(--status-success-text)] bg-[var(--status-success-muted)] rounded-lg px-3 py-2"
        data-testid="setup-provider-ready"
      >
        {{ $t('setup.provider.ready') }}
      </p>

      <div class="flex flex-col gap-3">
        <ProviderKeyCard
          v-for="provider in visibleProviders"
          :key="provider.name"
          :provider="provider"
          :is-default-chat="provider.name === defaultChatProvider"
          @changed="load"
        />
      </div>

      <button
        v-if="hiddenCount > 0"
        type="button"
        class="text-sm txt-brand hover:underline self-start"
        data-testid="setup-provider-show-all"
        @click="showAll = true"
      >
        {{ $t('setup.provider.showAll', { count: hiddenCount }) }}
      </button>
    </template>

    <div class="flex flex-col gap-2 mt-2">
      <button
        type="button"
        class="btn-primary w-full py-2.5 rounded-lg text-sm font-semibold"
        data-testid="setup-provider-continue"
        @click="emit('next')"
      >
        {{ $t('setup.provider.continue') }}
      </button>
      <p v-if="!anyConfigured && !loading" class="text-xs txt-secondary text-center">
        {{ $t('setup.provider.skipHint') }}
      </p>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import ProviderKeyCard from '@/components/admin/ProviderKeyCard.vue'
import { listProviderKeys, type ProviderKeyStatus } from '@/services/api/providerKeysApi'
import { getErrorMessage } from '@/utils/errorMessage'

const emit = defineEmits<{
  next: []
}>()

const { t } = useI18n()

const providers = ref<ProviderKeyStatus[]>([])
const defaultChatProvider = ref('')
const loading = ref(false)
const loadError = ref('')
const showAll = ref(false)

/**
 * Same order as Admin > AI Provider Setup: recommended first, then already
 * connected, then alphabetical.
 */
const sortedProviders = computed(() =>
  [...providers.value].sort((a, b) => {
    if (a.recommended !== b.recommended) return a.recommended ? -1 : 1
    if (a.configured !== b.configured) return a.configured ? -1 : 1
    return a.displayName.localeCompare(b.displayName)
  })
)

/**
 * A wall of a dozen providers is the wrong thing to hand someone in their first
 * two minutes, so only the recommended and the already-connected ones show until
 * they ask for the rest.
 */
const visibleProviders = computed(() =>
  showAll.value
    ? sortedProviders.value
    : sortedProviders.value.filter((p) => p.recommended || p.configured)
)

const hiddenCount = computed(() => sortedProviders.value.length - visibleProviders.value.length)
const anyConfigured = computed(() => providers.value.some((p) => p.configured))

async function load(): Promise<void> {
  loading.value = true
  loadError.value = ''
  try {
    const result = await listProviderKeys()
    providers.value = result.providers
    defaultChatProvider.value = result.defaultChatProvider
  } catch (err) {
    loadError.value = getErrorMessage(err) || t('setup.provider.loadFailed')
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>
