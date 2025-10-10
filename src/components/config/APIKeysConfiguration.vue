<template>
  <div class="space-y-6">
    <div class="surface-card p-6">
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
        />
        <button
          @click="createAPIKey"
          :disabled="!newKeyName.trim()"
          class="btn-primary px-6 py-2.5 rounded flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed"
        >
          <PlusIcon class="w-5 h-5" />
          {{ $t('config.apiKeys.createKey') }}
        </button>
      </div>
    </div>

    <div v-if="apiKeys.length === 0" class="surface-card p-12 text-center">
      <KeyIcon class="w-16 h-16 mx-auto txt-secondary mb-4" />
      <p class="txt-secondary text-lg">{{ $t('config.apiKeys.noKeys') }}</p>
    </div>

    <div v-else class="surface-card overflow-hidden">
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
            >
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
                  <button
                    @click="copyToClipboard(apiKey.key, apiKey.id)"
                    class="p-1.5 rounded hover:bg-black/10 dark:hover:bg-white/10 txt-secondary transition-colors"
                    :title="$t('config.apiKeys.actions.copy')"
                  >
                    <CheckIcon v-if="copiedKeyId === apiKey.id" class="w-4 h-4 text-green-500" />
                    <ClipboardDocumentIcon v-else class="w-4 h-4" />
                  </button>
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
                  >
                    {{ $t('config.apiKeys.actions.revoke') }}
                  </button>
                  <button
                    v-else
                    @click="activateAPIKey(apiKey.id)"
                    class="text-sm text-green-500 hover:text-green-600 font-medium"
                  >
                    {{ $t('config.apiKeys.actions.activate') }}
                  </button>
                  <button
                    @click="deleteAPIKey(apiKey.id)"
                    class="text-sm text-red-500 hover:text-red-600 font-medium"
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
import type { APIKey } from '@/mocks/apiKeys'
import { mockAPIKeys } from '@/mocks/apiKeys'

const apiKeys = ref<APIKey[]>([])
const newKeyName = ref('')
const copiedKeyId = ref<string | null>(null)

const loadAPIKeys = async () => {
  apiKeys.value = [...mockAPIKeys]
}

const createAPIKey = () => {
  if (!newKeyName.value.trim()) return

  const newKey: APIKey = {
    id: `key-${Date.now()}`,
    name: newKeyName.value,
    key: `sk_live_${Math.random().toString(36).substring(2, 15)}${Math.random().toString(36).substring(2, 15)}${Math.random().toString(36).substring(2, 15)}`,
    status: 'active',
    created: new Date(),
    lastUsed: null,
    usageCount: 0
  }

  apiKeys.value.unshift(newKey)
  newKeyName.value = ''

  console.log('Created API key:', newKey)
}

const maskAPIKey = (key: string): string => {
  if (key.length <= 20) return key
  return `${key.substring(0, 12)}...${key.substring(key.length - 8)}`
}

const copyToClipboard = async (key: string, keyId: string) => {
  try {
    await navigator.clipboard.writeText(key)
    copiedKeyId.value = keyId
    setTimeout(() => {
      copiedKeyId.value = null
    }, 2000)
  } catch (err) {
    console.error('Failed to copy:', err)
  }
}

const revokeAPIKey = (keyId: string) => {
  const key = apiKeys.value.find(k => k.id === keyId)
  if (key) {
    key.status = 'inactive'
    console.log('Revoked API key:', keyId)
  }
}

const activateAPIKey = (keyId: string) => {
  const key = apiKeys.value.find(k => k.id === keyId)
  if (key) {
    key.status = 'active'
    console.log('Activated API key:', keyId)
  }
}

const deleteAPIKey = (keyId: string) => {
  const index = apiKeys.value.findIndex(k => k.id === keyId)
  if (index !== -1) {
    apiKeys.value.splice(index, 1)
    console.log('Deleted API key:', keyId)
  }
}

const formatDate = (date: Date): string => {
  return new Intl.DateTimeFormat('en-US', {
    year: 'numeric',
    month: 'short',
    day: 'numeric'
  }).format(date)
}

onMounted(() => {
  loadAPIKeys()
})
</script>

