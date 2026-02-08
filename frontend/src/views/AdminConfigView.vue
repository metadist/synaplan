<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import MainLayout from '@/components/MainLayout.vue'
import ConfigField from '@/components/admin/ConfigField.vue'
import { useAuthStore } from '@/stores/auth'
import { useNotification } from '@/composables/useNotification'
import {
  getConfigSchema,
  getConfigValues,
  updateConfigValue,
  testConnection,
  type ConfigSchema,
  type ConfigValue,
  type TestConnectionResult,
} from '@/services/api/adminConfigApi'

const { t } = useI18n()
const router = useRouter()
const authStore = useAuthStore()
const { success, error: showError } = useNotification()

// State
const loading = ref(true)
const schema = ref<ConfigSchema | null>(null)
const values = ref<Record<string, ConfigValue>>({})
const activeTab = ref('ai')
const showRestartBanner = ref(false)
const testingService = ref<string | null>(null)

// Tab icons
const tabIcons: Record<string, string> = {
  ai: 'mdi:robot',
  email: 'mdi:email-outline',
  auth: 'mdi:shield-key',
  channels: 'mdi:message-text',
  processing: 'mdi:file-document-outline',
  vectordb: 'mdi:database-search',
}

// Computed
const tabs = computed(() => {
  if (!schema.value) return []
  return Object.entries(schema.value.tabs).map(([id, tab]) => ({
    id,
    label: tab.label,
    icon: tabIcons[id] || 'mdi:cog',
  }))
})

const currentTab = computed(() => {
  if (!schema.value) return null
  return schema.value.tabs[activeTab.value]
})

const currentSections = computed(() => {
  if (!currentTab.value || !schema.value) return []
  return Object.entries(currentTab.value.sections).map(([id, section]) => ({
    id,
    label: section.label,
    fields: section.fields.map((fieldKey) => ({
      key: fieldKey,
      schema: schema.value!.fields[fieldKey],
      value: values.value[fieldKey] || { value: '', isSet: false, isMasked: false },
    })),
  }))
})

// Service test mapping (multiple services per tab are tested sequentially)
const testableServices: Record<string, string[]> = {
  ai: ['ollama', 'piper'],
  processing: ['tika'],
  vectordb: ['qdrant'],
  email: ['mailer'],
}

const canTestCurrentTab = computed(() => {
  return testableServices[activeTab.value]?.length > 0
})

// Methods
async function loadConfig() {
  loading.value = true
  try {
    const [schemaData, valuesData] = await Promise.all([getConfigSchema(), getConfigValues()])
    schema.value = schemaData
    values.value = valuesData
  } catch (err) {
    console.error('Failed to load config:', err)
    showError(t('admin.config.loadError'))
  } finally {
    loading.value = false
  }
}

async function handleUpdate(key: string, value: string) {
  try {
    const result = await updateConfigValue(key, value)
    if (result.success) {
      success(t('admin.config.saved'))
      // Update local value
      values.value[key] = {
        value: schema.value?.fields[key]?.sensitive ? '' : value,
        isSet: true,
        isMasked: schema.value?.fields[key]?.sensitive || false,
      }
      // Show restart banner
      if (result.requiresRestart) {
        showRestartBanner.value = true
      }
    }
  } catch (err) {
    console.error('Failed to update config:', err)
    showError(t('admin.config.saveError'))
  }
}

async function handleTestConnection() {
  const services = testableServices[activeTab.value]
  if (!services?.length) return

  testingService.value = services[0]

  try {
    const results = await Promise.allSettled(
      services.map((svc) => testConnection(svc)),
    )

    const succeeded: string[] = []
    const failed: string[] = []

    results.forEach((result, i) => {
      const svc = services[i]
      if (result.status === 'fulfilled' && result.value.success) {
        succeeded.push(result.value.message)
      } else {
        const msg =
          result.status === 'fulfilled'
            ? result.value.message
            : t('admin.config.testFailed')
        failed.push(`${svc}: ${msg}`)
      }
    })

    if (failed.length === 0) {
      success(succeeded.join(' | '))
    } else if (succeeded.length === 0) {
      showError(failed.join(' | '))
    } else {
      success(succeeded.join(' | '))
      showError(failed.join(' | '))
    }
  } catch (err) {
    console.error('Connection test failed:', err)
    showError(t('admin.config.testFailed'))
  } finally {
    testingService.value = null
  }
}

function dismissRestartBanner() {
  showRestartBanner.value = false
}

function copyRestartCommand() {
  navigator.clipboard.writeText('docker compose restart backend')
  success(t('admin.config.commandCopied'))
}

// Check admin access
onMounted(async () => {
  if (!authStore.isAdmin) {
    router.push('/admin')
    return
  }
  await loadConfig()
})
</script>

<template>
  <MainLayout data-testid="view-admin-config">
    <div class="min-h-screen bg-chat p-4 md:p-8 overflow-y-auto scroll-thin">
      <div class="container mx-auto max-w-5xl">
        <!-- Restart Banner -->
        <Transition
          enter-active-class="transition-all duration-300 ease-out"
          enter-from-class="opacity-0 -translate-y-4"
          enter-to-class="opacity-100 translate-y-0"
          leave-active-class="transition-all duration-200 ease-in"
          leave-from-class="opacity-100 translate-y-0"
          leave-to-class="opacity-0 -translate-y-4"
        >
          <div
            v-if="showRestartBanner"
            class="mb-6 p-4 rounded-lg bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800/30"
          >
            <div class="flex items-start gap-3">
              <Icon
                icon="mdi:alert"
                class="w-6 h-6 text-yellow-600 dark:text-yellow-400 flex-shrink-0 mt-0.5"
              />
              <div class="flex-1">
                <h4 class="font-semibold text-yellow-800 dark:text-yellow-200">
                  {{ $t('admin.config.restartBanner.title') }}
                </h4>
                <p class="text-sm text-yellow-700 dark:text-yellow-300 mt-1">
                  {{ $t('admin.config.restartBanner.message') }}
                </p>
                <div class="mt-3 flex items-center gap-2">
                  <code
                    class="flex-1 px-3 py-2 bg-yellow-100 dark:bg-yellow-900/40 rounded text-sm font-mono text-yellow-900 dark:text-yellow-100"
                  >
                    docker compose restart backend
                  </code>
                  <button
                    type="button"
                    class="p-2 rounded-lg hover:bg-yellow-100 dark:hover:bg-yellow-900/40"
                    :title="$t('admin.config.restartBanner.copyCommand')"
                    @click="copyRestartCommand"
                  >
                    <Icon
                      icon="mdi:content-copy"
                      class="w-5 h-5 text-yellow-700 dark:text-yellow-300"
                    />
                  </button>
                </div>
              </div>
              <button
                type="button"
                class="p-1 rounded hover:bg-yellow-100 dark:hover:bg-yellow-900/40"
                @click="dismissRestartBanner"
              >
                <Icon icon="mdi:close" class="w-5 h-5 text-yellow-600 dark:text-yellow-400" />
              </button>
            </div>
          </div>
        </Transition>

        <!-- Header -->
        <div class="mb-8">
          <div class="flex items-center gap-3 mb-2">
            <Icon icon="mdi:cog" class="w-8 h-8 text-[var(--brand)]" />
            <h1 class="text-3xl font-bold txt-primary">{{ $t('admin.config.title') }}</h1>
          </div>
          <p class="txt-secondary">{{ $t('admin.config.description') }}</p>
        </div>

        <!-- Loading State -->
        <div v-if="loading" class="flex items-center justify-center py-20">
          <Icon icon="mdi:loading" class="w-8 h-8 animate-spin txt-secondary" />
        </div>

        <!-- Content -->
        <div v-else-if="schema" class="space-y-6">
          <!-- Tabs -->
          <div
            class="flex gap-2 overflow-x-auto pb-2 border-b border-light-border/30 dark:border-dark-border/20"
          >
            <button
              v-for="tab in tabs"
              :key="tab.id"
              :class="[
                'flex items-center gap-2 px-4 py-3 font-medium transition-colors whitespace-nowrap rounded-t-lg',
                activeTab === tab.id
                  ? 'txt-primary bg-card border-b-2 border-[var(--brand)]'
                  : 'txt-secondary hover:txt-primary hover:bg-black/5 dark:hover:bg-white/5',
              ]"
              @click="activeTab = tab.id"
            >
              <Icon :icon="tab.icon" class="w-5 h-5" />
              {{ tab.label }}
            </button>
          </div>

          <!-- Tab Actions -->
          <div v-if="canTestCurrentTab" class="flex justify-end">
            <button
              type="button"
              :disabled="!!testingService"
              class="btn-secondary px-4 py-2 rounded-lg flex items-center gap-2"
              @click="handleTestConnection"
            >
              <Icon
                :icon="testingService ? 'mdi:loading' : 'mdi:connection'"
                :class="['w-5 h-5', testingService && 'animate-spin']"
              />
              {{ $t('admin.config.testConnection') }}
            </button>
          </div>

          <!-- Sections -->
          <div class="space-y-8">
            <div
              v-for="section in currentSections"
              :key="section.id"
              class="surface-card rounded-xl p-6"
            >
              <h3 class="text-lg font-semibold txt-primary mb-4 flex items-center gap-2">
                <Icon icon="mdi:folder-cog" class="w-5 h-5 txt-secondary" />
                {{ section.label }}
              </h3>
              <div class="space-y-4">
                <ConfigField
                  v-for="field in section.fields"
                  :key="field.key"
                  :field-key="field.key"
                  :schema="field.schema"
                  :value="field.value"
                  @update="handleUpdate"
                />
              </div>
            </div>
          </div>
        </div>

        <!-- Error State -->
        <div v-else class="text-center py-20">
          <Icon icon="mdi:alert-circle" class="w-12 h-12 txt-secondary mx-auto mb-4" />
          <p class="txt-secondary">{{ $t('admin.config.loadError') }}</p>
          <button type="button" class="btn-primary mt-4 px-6 py-2 rounded-lg" @click="loadConfig">
            {{ $t('common.retry') }}
          </button>
        </div>
      </div>
    </div>
  </MainLayout>
</template>
