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

      <div
        class="grid grid-cols-2 sm:grid-cols-3 gap-2.5"
        role="group"
        :aria-label="$t('setup.provider.chooseProvider')"
        data-testid="setup-provider-grid"
      >
        <SetupProviderTile
          v-for="provider in sortedProviders"
          :key="provider.name"
          :provider="provider"
          :selected="provider.name === selectedName"
          @select="toggle(provider.name)"
        />
      </div>

      <SetupProviderKeyForm
        v-if="selectedProvider"
        :key="selectedProvider.name"
        :provider="selectedProvider"
        :is-default-chat="selectedProvider.name === defaultChatProvider"
        @saved="onSaved"
      />
      <p v-else class="text-xs txt-secondary" data-testid="setup-provider-pick-hint">
        {{ $t('setup.provider.pickHint') }}
      </p>
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
import SetupProviderKeyForm from '@/components/setup/SetupProviderKeyForm.vue'
import SetupProviderTile from '@/components/setup/SetupProviderTile.vue'
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
const selectedName = ref('')

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

const selectedProvider = computed(
  () => providers.value.find((p) => p.name === selectedName.value) ?? null
)

const anyConfigured = computed(() => providers.value.some((p) => p.configured))

/**
 * The grid shows every provider as a logo and a name; the key field belongs to
 * whichever one is open. Eight key fields at once was a wall of inputs on a
 * screen whose only job is "connect one provider".
 */
function toggle(name: string): void {
  selectedName.value = selectedName.value === name ? '' : name
}

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

// Collapsing the panel after a successful save puts the new "Connected" tile and
// the Continue button in view, instead of leaving an empty field that looks like
// the key did not take.
async function onSaved(): Promise<void> {
  selectedName.value = ''
  await load()
}

onMounted(load)
</script>
