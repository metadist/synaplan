<template>
  <div class="space-y-6" data-testid="page-config-api-keys">
    <!-- Error Alert -->
    <div v-if="error" class="bg-red-500/10 border border-red-500/30 rounded-lg p-4 flex items-start gap-3" data-testid="alert-error">
      <svg class="w-5 h-5 text-red-500 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
      </svg>
      <div class="flex-1">
        <p class="text-red-500 text-sm font-medium">{{ error }}</p>
      </div>
      <button @click="error = null" class="text-red-500 hover:text-red-600" data-testid="btn-alert-close">
        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
        </svg>
      </button>
    </div>

    <div class="surface-card p-6" data-testid="section-create-key">
      <h2 class="text-2xl font-semibold txt-primary mb-3">
        {{ $t('config.apiKeys.title') }}
      </h2>
      <p class="txt-secondary text-sm mb-6">
        {{ $t('config.apiKeys.description') }}
      </p>

      <div class="flex gap-3">
        <input
          v-model="newKeyName"
          type="text"
          :placeholder="$t('config.apiKeys.namePlaceholder')"
          class="flex-1 px-4 py-2.5 rounded surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
          @keypress.enter="createAPIKey"
          data-testid="input-key-name"
        />
        <button
          @click="createAPIKey"
          :disabled="!newKeyName.trim() || loading"
          class="btn-primary px-6 py-2.5 rounded flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed"
          data-testid="btn-create"
        >
          <svg v-if="loading" class="animate-spin h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
          </svg>
          <PlusIcon v-else class="w-5 h-5" />
          {{ loading ? 'Creating...' : $t('config.apiKeys.createKey') }}
        </button>
      </div>
    </div>

    <!-- Loading State -->
    <div v-if="loading && apiKeys.length === 0" class="surface-card p-12 text-center" data-testid="section-loading">
      <svg class="animate-spin h-12 w-12 mx-auto txt-secondary mb-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
      </svg>
      <p class="txt-secondary text-lg">Loading API keys...</p>
    </div>

    <div v-else-if="apiKeys.length === 0" class="surface-card p-12 text-center" data-testid="section-empty">
      <KeyIcon class="w-16 h-16 mx-auto txt-secondary mb-4" />
      <p class="txt-secondary text-lg">{{ $t('config.apiKeys.noKeys') }}</p>
    </div>

    <div v-else class="surface-card overflow-hidden" data-testid="section-keys-table">
      <div class="overflow-x-auto">
        <table class="w-full">
          <thead class="border-b border-light-border/30 dark:border-dark-border/20">
            <tr class="bg-black/5 dark:bg-white/5">
              <th class="px-6 py-3 text-left text-xs font-semibold txt-primary uppercase tracking-wider">
                {{ $t('config.apiKeys.tableHeaders.name') }}
              </th>
              <th class="px-6 py-3 text-left text-xs font-semibold txt-primary uppercase tracking-wider">
                {{ $t('config.apiKeys.tableHeaders.key') }}
              </th>
              <th class="px-6 py-3 text-left text-xs font-semibold txt-primary uppercase tracking-wider">
                {{ $t('config.apiKeys.tableHeaders.status') }}
              </th>
              <th class="px-6 py-3 text-left text-xs font-semibold txt-primary uppercase tracking-wider">
                {{ $t('config.apiKeys.tableHeaders.created') }}
              </th>
              <th class="px-6 py-3 text-left text-xs font-semibold txt-primary uppercase tracking-wider">
                {{ $t('config.apiKeys.tableHeaders.lastUsed') }}
              </th>
              <th class="px-6 py-3 text-left text-xs font-semibold txt-primary uppercase tracking-wider">
                {{ $t('config.apiKeys.tableHeaders.actions') }}
              </th>
            </tr>
          </thead>
          <tbody class="divide-y divide-light-border/30 dark:divide-dark-border/20">
            <tr
              v-for="apiKey in apiKeys"
              :key="apiKey.id"
              class="hover:bg-black/5 dark:hover:bg-white/5 transition-colors"
            data-testid="item-api-key">
              <td class="px-6 py-4 whitespace-nowrap">
                <div class="flex items-center gap-2">
                  <span class="text-sm font-medium txt-primary">{{ apiKey.name }}</span>
                  <span class="text-xs txt-secondary">
                    ({{ apiKey.usageCount }} {{ $t('config.apiKeys.usageCount') }})
                  </span>
                </div>
              </td>
              <td class="px-6 py-4">
                <div class="flex items-center gap-2">
                  <code class="text-xs font-mono txt-secondary bg-black/5 dark:bg-white/5 px-2 py-1 rounded">
                    {{ maskAPIKey(apiKey.key) }}
                  </code>
                </div>
              </td>
              <td class="px-6 py-4 whitespace-nowrap">
                <span
                  :class="[
                    'inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium',
                    apiKey.status === 'active'
                      ? 'bg-green-500/10 text-green-500'
                      : 'bg-gray-500/10 text-gray-500'
                  ]"
                >
                  <span class="w-1.5 h-1.5 rounded-full" :class="apiKey.status === 'active' ? 'bg-green-500' : 'bg-gray-500'"></span>
                  {{ $t(`config.apiKeys.status.${apiKey.status}`) }}
                </span>
              </td>
              <td class="px-6 py-4 whitespace-nowrap text-sm txt-secondary">
                {{ formatDate(apiKey.created) }}
              </td>
              <td class="px-6 py-4 whitespace-nowrap text-sm txt-secondary">
                {{ apiKey.lastUsed ? formatDate(apiKey.lastUsed) : '-' }}
              </td>
              <td class="px-6 py-4 whitespace-nowrap">
                <div class="flex items-center gap-2">
                  <button
                    v-if="apiKey.status === 'active'"
                    @click="revokeAPIKey(apiKey.id)"
                    class="text-sm text-orange-500 hover:text-orange-600 font-medium"
                    data-testid="btn-revoke"
                  >
                    {{ $t('config.apiKeys.actions.revoke') }}
                  </button>
                  <button
                    v-else
                    @click="activateAPIKey(apiKey.id)"
                    class="text-sm text-green-500 hover:text-green-600 font-medium"
                    data-testid="btn-activate"
                  >
                    {{ $t('config.apiKeys.actions.activate') }}
                  </button>
                  <button
                    @click="deleteAPIKey(apiKey.id)"
                    class="text-sm text-red-500 hover:text-red-600 font-medium"
                    data-testid="btn-delete"
                  >
                    {{ $t('config.apiKeys.actions.delete') }}
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- API Key Created Modal -->
    <Teleport to="body">
      <Transition
        enter-active-class="transition-opacity duration-200"
        leave-active-class="transition-opacity duration-150"
        enter-from-class="opacity-0"
        leave-to-class="opacity-0"
      >
        <div
          v-if="showKeyModal"
          class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm"
          data-testid="modal-api-key-created"
          @click.self="closeKeyModal"
        >
          <Transition
            enter-active-class="transition-all duration-200"
            leave-active-class="transition-all duration-150"
            enter-from-class="opacity-0 scale-95 translate-y-4"
            leave-to-class="opacity-0 scale-95 translate-y-4"
          >
            <div
              v-if="showKeyModal"
              class="surface-elevated max-w-2xl w-full p-6 md:p-8"
            >
              <!-- Header -->
              <div class="flex items-start gap-4 mb-6">
                <div class="flex-shrink-0 w-12 h-12 rounded-full bg-green-500/10 dark:bg-green-500/20 flex items-center justify-center">
                  <KeyIcon class="w-6 h-6 text-green-600 dark:text-green-400" />
                </div>
                <div class="flex-1">
                  <h3 class="text-xl font-semibold txt-primary mb-1">
                    {{ $t('config.apiKeys.keyCreated') }}
                  </h3>
                  <p class="text-sm txt-secondary">
                    {{ $t('config.apiKeys.modal.subtitle') }}
                  </p>
                </div>
              </div>

              <!-- Key Display -->
              <div class="mb-6">
                <label class="block text-sm font-medium txt-primary mb-2">
                  {{ $t('config.apiKeys.modal.yourKey') }}
                </label>
                <div class="surface-card p-4">
                  <code class="block text-sm font-mono txt-primary break-all leading-relaxed select-all">
                    {{ newlyCreatedKey }}
                  </code>
                </div>
              </div>

              <!-- Warning Box -->
              <div class="mb-6 surface-card p-4 border-l-4 border-amber-500">
                <div class="flex gap-3">
                  <div class="flex-shrink-0">
                    <svg class="w-5 h-5 text-amber-500" fill="currentColor" viewBox="0 0 20 20">
                      <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                    </svg>
                  </div>
                  <div>
                    <h4 class="text-sm font-semibold txt-primary mb-1">
                      {{ $t('config.apiKeys.modal.warningTitle') }}
                    </h4>
                    <p class="text-sm txt-secondary">
                      {{ $t('config.apiKeys.modal.warningText') }}
                    </p>
                  </div>
                </div>
              </div>

              <!-- Actions -->
              <div class="flex flex-col sm:flex-row gap-3">
                <button
                  @click="copyKeyFromModal"
                  class="flex-1 btn-primary px-4 py-3 rounded-lg flex items-center justify-center gap-2 font-medium"
                  data-testid="btn-copy"
                >
                  <CheckIcon v-if="copiedFromModal" class="w-5 h-5" />
                  <ClipboardDocumentIcon v-else class="w-5 h-5" />
                  {{ copiedFromModal ? $t('config.apiKeys.actions.copied') : $t('config.apiKeys.actions.copy') }}
                </button>
                <button
                  @click="closeKeyModal"
                  class="flex-1 surface-chip px-4 py-3 rounded-lg font-medium txt-primary hover:bg-black/5 dark:hover:bg-white/10 transition-colors"
                  data-testid="btn-close"
                >
                  {{ $t('common.close') }}
                </button>
              </div>

              <!-- Countdown -->
              <div class="mt-4 text-center">
                <p class="text-xs txt-secondary">
                  {{ $t('config.apiKeys.modal.autoClose', { seconds: modalCountdown }) }}
                </p>
              </div>
            </div>
          </Transition>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { 
  PlusIcon, 
  KeyIcon,
  ClipboardDocumentIcon,
  CheckIcon
} from '@heroicons/vue/24/outline'
import { 
  listApiKeys,
  createApiKey,
  updateApiKey,
  revokeApiKey as deleteApiKeyApi
} from '@/services/api/apiKeysApi'
import { useDialog } from '@/composables/useDialog'
import { useNotification } from '@/composables/useNotification'
import { useI18n } from 'vue-i18n'

const dialog = useDialog()
const { success, error: showError } = useNotification()
const { t } = useI18n()

interface UIApiKey {
  id: number
  name: string
  key: string
  key_prefix: string
  status: 'active' | 'inactive'
  created: number
  lastUsed: number | null
  usageCount: number
  scopes: string[]
}

const apiKeys = ref<UIApiKey[]>([])
const newKeyName = ref('')
const loading = ref(false)
const error = ref<string | null>(null)
const showKeyModal = ref(false)
const newlyCreatedKey = ref<string>('')
const copiedFromModal = ref(false)
const modalCountdown = ref(30)
let countdownInterval: number | null = null

const loadAPIKeys = async () => {
  try {
    loading.value = true
    error.value = null
    const response = await listApiKeys()
    
    // Convert backend format to UI format
    apiKeys.value = response.api_keys.map(key => ({
      id: key.id,
      name: key.name,
      key: key.key_prefix, // Only prefix is returned for existing keys
      key_prefix: key.key_prefix,
      status: key.status,
      created: key.created,
      lastUsed: key.last_used || null,
      usageCount: 0, // Backend doesn't track this yet
      scopes: key.scopes
    }))
  } catch (err: any) {
    console.error('Failed to load API keys:', err)
    error.value = err.message || 'Failed to load API keys'
  } finally {
    loading.value = false
  }
}

const createAPIKey = async () => {
  if (!newKeyName.value.trim()) return

  try {
    loading.value = true
    error.value = null
    
    const response = await createApiKey({
      name: newKeyName.value,
      scopes: ['webhooks:*'] // Default scopes
    })

    // Add to list with full key
    const keyPrefix = response.api_key.key_prefix ?? `${response.api_key.key.substring(0, 8)}...`

    const newKey: UIApiKey = {
      id: response.api_key.id,
      name: response.api_key.name,
      key: keyPrefix,
      key_prefix: keyPrefix,
      status: 'active',
      created: response.api_key.created,
      lastUsed: null,
      usageCount: 0,
      scopes: response.api_key.scopes
    }

    apiKeys.value.unshift(newKey)
    newKeyName.value = ''

    // Show modal with the full key
    newlyCreatedKey.value = response.api_key.key
    showKeyModal.value = true
    modalCountdown.value = 30
    copiedFromModal.value = false
    
    // Start countdown
    countdownInterval = window.setInterval(() => {
      modalCountdown.value--
      if (modalCountdown.value <= 0) {
        closeKeyModal()
      }
    }, 1000)
    
    // Show success notification
    success(t('config.apiKeys.keyCreatedSuccess'))
  } catch (err: any) {
    console.error('Failed to create API key:', err)
    error.value = err.message || t('config.apiKeys.errorCreating')
    showError(error.value!)
  } finally {
    loading.value = false
  }
}

const maskAPIKey = (key: string): string => {
  if (key.length <= 20) return key
  return `${key.substring(0, 12)}...${key.substring(key.length - 8)}`
}

const closeKeyModal = () => {
  showKeyModal.value = false
  newlyCreatedKey.value = ''
  if (countdownInterval) {
    clearInterval(countdownInterval)
    countdownInterval = null
  }
}

const copyKeyFromModal = async () => {
  try {
    await navigator.clipboard.writeText(newlyCreatedKey.value)
    copiedFromModal.value = true
    success(t('config.apiKeys.actions.copied'))
    
    setTimeout(() => {
      copiedFromModal.value = false
    }, 2000)
  } catch (err) {
    console.error('Failed to copy to clipboard:', err)
    showError(t('config.apiKeys.errorCopying'))
  }
}

const revokeAPIKey = async (keyId: number) => {
  const confirmed = await dialog.confirm({
    title: t('config.apiKeys.confirmRevokeTitle'),
    message: t('config.apiKeys.confirmRevoke'),
    confirmText: t('config.apiKeys.actions.revoke'),
    cancelText: t('common.cancel'),
    danger: true
  })

  if (!confirmed) return

  try {
    await updateApiKey(keyId, { status: 'inactive' })
    const key = apiKeys.value.find(k => k.id === keyId)
    if (key) {
      key.status = 'inactive'
    }
    success(t('config.apiKeys.revokedSuccess'))
  } catch (err: any) {
    console.error('Failed to revoke API key:', err)
    showError(err.message || t('config.apiKeys.errorRevoking'))
  }
}

const activateAPIKey = async (keyId: number) => {
  try {
    await updateApiKey(keyId, { status: 'active' })
    const key = apiKeys.value.find(k => k.id === keyId)
    if (key) {
      key.status = 'active'
    }
    success(t('config.apiKeys.activatedSuccess'))
  } catch (err: any) {
    console.error('Failed to activate API key:', err)
    showError(err.message || t('config.apiKeys.errorActivating'))
  }
}

const deleteAPIKey = async (keyId: number) => {
  const confirmed = await dialog.confirm({
    title: t('config.apiKeys.confirmDeleteTitle'),
    message: t('config.apiKeys.confirmDelete'),
    confirmText: t('common.delete'),
    cancelText: t('common.cancel'),
    danger: true
  })

  if (!confirmed) return

  try {
    await deleteApiKeyApi(keyId)
    const index = apiKeys.value.findIndex(k => k.id === keyId)
    if (index !== -1) {
      apiKeys.value.splice(index, 1)
    }
    success(t('config.apiKeys.deletedSuccess'))
  } catch (err: any) {
    console.error('Failed to delete API key:', err)
    showError(err.message || t('config.apiKeys.errorDeleting'))
  }
}

const formatDate = (timestamp: number): string => {
  const date = new Date(timestamp * 1000) // Convert Unix timestamp to milliseconds
  return new Intl.DateTimeFormat('en-US', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  }).format(date)
}

onMounted(() => {
  loadAPIKeys()
})
</script>

