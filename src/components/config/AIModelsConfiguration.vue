<template>
  <div class="space-y-6">
    <div class="surface-card p-6">
      <h2 class="text-2xl font-semibold txt-primary mb-6 flex items-center gap-2">
        <CpuChipIcon class="w-6 h-6 text-[var(--brand)]" />
        Default Model Configuration
      </h2>

      <div v-if="loading" class="text-center py-8">
        <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-[var(--brand)]"></div>
        <p class="mt-2 txt-secondary">Loading models...</p>
      </div>

      <div v-else class="grid grid-cols-1 md:grid-cols-2 gap-5">
        <div
          v-for="(label, capability) in purposeLabels"
          :key="capability"
          class="space-y-2"
        >
          <label class="flex items-center gap-2 text-sm font-semibold txt-primary">
            <CpuChipIcon class="w-4 h-4 text-[var(--brand)]" />
            {{ label }}
            <div v-if="isSystemModel(capability)" class="ml-auto flex items-center gap-1 px-2 py-0.5 rounded-full bg-yellow-500/10 border border-yellow-500/30">
              <LockClosedIcon class="w-3 h-3 text-yellow-500" />
              <span class="text-xs font-medium text-yellow-500">System</span>
            </div>
          </label>
          <div class="relative">
            <select
              v-model="defaultConfig[capability as Capability]"
              :disabled="isSystemModel(capability)"
              :class="[
                'w-full px-4 py-3 pl-10 rounded-lg surface-card border txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)] transition-all appearance-none',
                isSystemModel(capability)
                  ? 'border-yellow-500/30 bg-yellow-500/5 cursor-not-allowed opacity-75'
                  : 'border-light-border/30 dark:border-dark-border/20 hover:border-[var(--brand)]/50'
              ]"
            >
              <option :value="null">-- Select Model --</option>
              <option
                v-for="model in getModelsByPurpose(capability as Capability)"
                :key="model.id"
                :value="model.id"
              >
                {{ model.providerId || model.name }} ({{ model.service }})
              </option>
            </select>
            <div class="absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none">
              <Icon 
                :icon="getProviderIcon(getSelectedModelService(capability as Capability))" 
                class="w-4 h-4" 
              />
            </div>
            <ChevronDownIcon class="absolute right-3 top-1/2 -translate-y-1/2 w-4 h-4 txt-secondary pointer-events-none" />
          </div>
        </div>
      </div>

      <div class="flex gap-3 justify-end mt-6">
        <button
          @click="resetForm"
          class="px-6 py-2.5 rounded-lg border-2 border-light-border/30 dark:border-dark-border/20 txt-primary hover:bg-black/5 dark:hover:bg-white/5 transition-all text-sm font-medium"
        >
          Reset Form
        </button>
        <button
          @click="saveConfiguration"
          :disabled="saving"
          class="btn-primary px-6 py-2.5 rounded-lg flex items-center gap-2 text-sm font-medium disabled:opacity-50 disabled:cursor-not-allowed"
        >
          <div v-if="saving" class="animate-spin rounded-full h-5 w-5 border-b-2 border-white"></div>
          <CheckIcon v-else class="w-5 h-5" />
          {{ saving ? 'Saving...' : 'Save Configuration' }}
        </button>
      </div>

      <div class="mt-4 flex items-start gap-2 p-3 rounded-lg bg-blue-500/5 border border-blue-500/20">
        <InformationCircleIcon class="w-5 h-5 text-blue-500 flex-shrink-0 mt-0.5" />
        <span class="text-sm txt-primary">System models are automatically locked and cannot be changed. These are core models required for specific functionality.</span>
      </div>
    </div>

    <div class="surface-card p-6">
      <h2 class="text-xl font-semibold txt-primary mb-4 flex items-center gap-2">
        <FunnelIcon class="w-5 h-5" />
        Models & Purposes
      </h2>

      <div class="flex flex-wrap gap-2">
        <button
          @click="selectedPurpose = null"
          :class="[
            'px-4 py-2 rounded-lg text-sm font-medium transition-all',
            selectedPurpose === null
              ? 'bg-[var(--brand)] text-white'
              : 'border border-light-border/30 dark:border-dark-border/20 txt-secondary hover:bg-black/5 dark:hover:bg-white/5'
          ]"
        >
          All Models
        </button>
        <button
          v-for="(label, capability) in purposeLabels"
          :key="capability"
          @click="selectedPurpose = capability as Capability"
          :class="[
            'px-4 py-2 rounded-lg text-sm font-medium transition-all',
            selectedPurpose === capability
              ? 'bg-[var(--brand)] text-white'
              : 'border border-light-border/30 dark:border-dark-border/20 txt-secondary hover:bg-black/5 dark:hover:bg-white/5'
          ]"
        >
          {{ capability }}
        </button>
      </div>
    </div>

    <div class="surface-card p-6">
      <h2 class="text-xl font-semibold txt-primary mb-4 flex items-center gap-2">
        <ListBulletIcon class="w-5 h-5" />
        Available Models
      </h2>

      <div v-if="filteredModels.length === 0" class="text-center py-12 txt-secondary">
        No models available for this purpose
      </div>

      <div v-else class="overflow-x-auto">
        <table class="w-full">
          <thead>
            <tr class="border-b-2 border-light-border/30 dark:border-dark-border/20">
              <th class="text-left py-3 px-3 txt-secondary text-xs font-semibold uppercase tracking-wide">ID</th>
              <th class="text-left py-3 px-3 txt-secondary text-xs font-semibold uppercase tracking-wide">Purpose</th>
              <th class="text-left py-3 px-3 txt-secondary text-xs font-semibold uppercase tracking-wide">Service</th>
              <th class="text-left py-3 px-3 txt-secondary text-xs font-semibold uppercase tracking-wide">Name</th>
              <th class="text-left py-3 px-3 txt-secondary text-xs font-semibold uppercase tracking-wide">Description</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="model in filteredModels"
              :key="model.id"
              class="border-b border-light-border/10 dark:border-dark-border/10 hover:bg-black/5 dark:hover:bg-white/5 transition-colors"
            >
              <td class="py-3 px-3">
                <span class="pill text-xs">{{ model.id }}</span>
              </td>
              <td class="py-3 px-3">
                <span class="pill pill--active text-xs">{{ model.purpose }}</span>
              </td>
              <td class="py-3 px-3">
                <div class="flex items-center gap-2">
                  <Icon :icon="getProviderIcon(model.service)" class="w-4 h-4 flex-shrink-0" />
                  <span
                    :class="[
                      'px-3 py-1 rounded-full text-xs font-medium text-white',
                      serviceColors[model.service] || 'bg-gray-500'
                    ]"
                  >
                    {{ model.service }}
                  </span>
                </div>
              </td>
              <td class="py-3 px-3 txt-primary text-sm font-medium">
                {{ model.name }}
              </td>
              <td class="py-3 px-3 txt-secondary text-sm">
                {{ model.description }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import {
  CpuChipIcon,
  CheckIcon,
  InformationCircleIcon,
  LockClosedIcon,
  FunnelIcon,
  ListBulletIcon,
  ChevronDownIcon
} from '@heroicons/vue/24/outline'
import { Icon } from '@iconify/vue'
import { getModels, getDefaultModels, saveDefaultModels, type ModelInfo } from '@/services/api/configApi'
import { serviceColors } from '@/mocks/aiModels'
import { getProviderIcon } from '@/utils/providerIcons'
import { useNotification } from '@/composables/useNotification'

type Capability = 'SORT' | 'CHAT' | 'VECTORIZE' | 'PIC2TEXT' | 'TEXT2PIC' | 'SOUND2TEXT' | 'TEXT2SOUND' | 'ANALYZE'

interface ModelsData {
  [key: string]: ModelInfo[]
}

const purposeLabels: Record<Capability, string> = {
  SORT: 'Message Sorting',
  CHAT: 'Chat / General AI',
  VECTORIZE: 'Embedding / Vectorization',
  PIC2TEXT: 'Vision (Image → Text)',
  TEXT2PIC: 'Image Generation (Text → Image)',
  SOUND2TEXT: 'Speech-to-Text',
  TEXT2SOUND: 'Text-to-Speech',
  ANALYZE: 'File Analysis'
}

const loading = ref(false)
const saving = ref(false)
const availableModels = ref<ModelsData>({})
const defaultConfig = ref<Record<Capability, number | null>>({
  SORT: null,
  CHAT: null,
  VECTORIZE: null,
  PIC2TEXT: null,
  TEXT2PIC: null,
  SOUND2TEXT: null,
  TEXT2SOUND: null,
  ANALYZE: null
})
const originalConfig = ref<Record<Capability, number | null>>({ ...defaultConfig.value })
const selectedPurpose = ref<Capability | null>(null)

const { success, error: showError } = useNotification()

onMounted(async () => {
  await loadData()
})

const loadData = async () => {
  loading.value = true
  try {
    const [modelsRes, defaultsRes] = await Promise.all([
      getModels(),
      getDefaultModels()
    ])

    if (modelsRes.success) {
      availableModels.value = modelsRes.models
    }

    if (defaultsRes.success) {
      defaultConfig.value = { ...defaultsRes.defaults }
      originalConfig.value = { ...defaultsRes.defaults }
    }
  } catch (error) {
    console.error('Failed to load models:', error)
  } finally {
    loading.value = false
  }
}

const getModelsByPurpose = (purpose: Capability): ModelInfo[] => {
  return availableModels.value[purpose] || []
}

const isSystemModel = (purpose: string): boolean => {
  const models = getModelsByPurpose(purpose as Capability)
  const selectedModelId = defaultConfig.value[purpose as Capability]
  const selectedModel = models.find(m => m.id === selectedModelId)
  return selectedModel?.isSystemModel || false
}

const getSelectedModelService = (purpose: Capability): string => {
  const models = getModelsByPurpose(purpose)
  const selectedModelId = defaultConfig.value[purpose]
  const selectedModel = models.find(m => m.id === selectedModelId)
  return selectedModel?.service || 'unknown'
}

const allModels = computed(() => {
  const all: Array<ModelInfo & { purpose: Capability }> = []
  for (const [cap, models] of Object.entries(availableModels.value)) {
    models.forEach(model => {
      all.push({ ...model, purpose: cap as Capability })
    })
  }
  return all
})

const filteredModels = computed(() => {
  if (selectedPurpose.value === null) {
    return allModels.value
  }
  return allModels.value.filter(model => model.purpose === selectedPurpose.value)
})

const saveConfiguration = async () => {
  saving.value = true
  try {
    // Filter out null values
    const defaults: Record<string, number> = {}
    for (const [key, value] of Object.entries(defaultConfig.value)) {
      if (value !== null) {
        defaults[key] = value
      }
    }

    const response = await saveDefaultModels({ defaults })
    
    if (response.success) {
      originalConfig.value = { ...defaultConfig.value }
      success('Configuration saved successfully!')
    }
  } catch (error: any) {
    console.error('Failed to save configuration:', error)
    showError(error.response?.data?.error || 'Failed to save configuration')
  } finally {
    saving.value = false
  }
}

const resetForm = () => {
  defaultConfig.value = { ...originalConfig.value }
}
</script>

