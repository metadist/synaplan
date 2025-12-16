<template>
  <div class="space-y-6" data-testid="page-config-ai-models">
    <div class="surface-card p-6" data-testid="section-default-config">
      <h2 class="text-2xl font-semibold txt-primary mb-6 flex items-center gap-2">
        <CpuChipIcon class="w-6 h-6 text-[var(--brand)]" />
        Default Model Configuration
      </h2>

      <div v-if="loading" class="text-center py-8" data-testid="section-loading">
        <div
          class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-[var(--brand)]"
        ></div>
        <p class="mt-2 txt-secondary">Loading models...</p>
      </div>

      <div v-else class="grid grid-cols-1 md:grid-cols-2 gap-5" data-testid="section-capabilities">
        <div
          v-for="(label, capability) in purposeLabels"
          :key="capability"
          :ref="
            (el: any) => {
              if (el) capabilityRefs[capability as Capability] = el as HTMLElement
            }
          "
          class="space-y-2 transition-all duration-300"
          :class="
            highlightedCapability === capability || highlightedCapability === 'ALL'
              ? 'ring-4 ring-[var(--brand)] ring-offset-4 rounded-xl p-3 bg-[var(--brand)]/5'
              : ''
          "
          data-testid="item-capability"
        >
          <label class="flex flex-wrap items-center gap-2 text-sm font-semibold txt-primary">
            <CpuChipIcon class="w-4 h-4 text-[var(--brand)]" />
            <span class="flex-1 min-w-0">{{ label }}</span>
            <div
              v-if="isSystemModel(capability)"
              class="flex items-center gap-1 px-2 py-0.5 rounded-full bg-yellow-500/10 border border-yellow-500/30"
            >
              <LockClosedIcon class="w-3 h-3 text-yellow-500" />
              <span class="text-xs font-medium text-yellow-500">System</span>
            </div>
          </label>
          <div class="relative">
            <button
              type="button"
              :disabled="isSystemModel(capability)"
              :class="[
                'w-full px-4 py-3 pl-10 pr-10 rounded-lg surface-card border txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)] transition-all text-left',
                isSystemModel(capability)
                  ? 'border-yellow-500/30 bg-yellow-500/5 cursor-not-allowed opacity-75'
                  : 'border-light-border/30 dark:border-dark-border/20 hover:border-[var(--brand)]/50',
                openDropdown === capability && 'ring-2 ring-[var(--brand)]',
              ]"
              data-testid="btn-model-dropdown"
              @click="toggleDropdown(capability as Capability)"
            >
              <span class="block truncate">
                {{ getSelectedModelLabel(capability as Capability) }}
              </span>
            </button>
            <div class="absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none">
              <GroqIcon
                v-if="
                  getSelectedModelService(capability as Capability)
                    .toLowerCase()
                    .includes('groq')
                "
                :size="16"
                class-name="txt-primary"
              />
              <Icon
                v-else
                :icon="getProviderIcon(getSelectedModelService(capability as Capability))"
                class="w-4 h-4"
              />
            </div>
            <ChevronDownIcon
              :class="[
                'absolute right-3 top-1/2 -translate-y-1/2 w-4 h-4 txt-secondary pointer-events-none transition-transform',
                openDropdown === capability && 'rotate-180',
              ]"
            />

            <!-- Custom Dropdown -->
            <div
              v-if="openDropdown === capability"
              class="absolute z-50 mt-2 w-full max-h-[60vh] overflow-y-auto dropdown-panel"
            >
              <button
                type="button"
                class="dropdown-item w-full"
                data-testid="btn-model-option"
                @click="selectModel(capability as Capability, null)"
              >
                <span class="txt-secondary italic">-- Select Model --</span>
              </button>
              <button
                v-for="model in getModelsByPurpose(capability as Capability)"
                :key="model.id"
                type="button"
                :class="[
                  'dropdown-item w-full',
                  defaultConfig[capability as Capability] === model.id && 'dropdown-item--active',
                ]"
                data-testid="btn-model-option"
                @click="selectModel(capability as Capability, model.id)"
              >
                <GroqIcon
                  v-if="model.service.toLowerCase().includes('groq')"
                  :size="20"
                  class-name="flex-shrink-0"
                />
                <Icon v-else :icon="getProviderIcon(model.service)" class="w-5 h-5 flex-shrink-0" />
                <div class="flex-1 min-w-0 text-left">
                  <div class="font-medium truncate">{{ model.name }}</div>
                  <div class="text-xs txt-secondary truncate">{{ model.service }}</div>
                </div>
              </button>
            </div>
          </div>
        </div>
      </div>

      <div
        class="mt-4 flex items-start gap-2 p-3 rounded-lg bg-blue-500/5 border border-blue-500/20"
      >
        <InformationCircleIcon class="w-5 h-5 text-blue-500 flex-shrink-0 mt-0.5" />
        <span class="text-sm txt-primary"
          >System models are automatically locked and cannot be changed. These are core models
          required for specific functionality.</span
        >
      </div>
    </div>

    <div class="surface-card p-6" data-testid="section-purpose-filters">
      <h2 class="text-xl font-semibold txt-primary mb-4 flex items-center gap-2">
        <FunnelIcon class="w-5 h-5" />
        Models & Purposes
      </h2>

      <div class="flex flex-wrap gap-2">
        <button
          :class="[
            'px-3 sm:px-4 py-2 rounded-lg text-xs sm:text-sm font-medium transition-all whitespace-nowrap',
            selectedPurpose === null
              ? 'bg-[var(--brand)] text-white'
              : 'border border-light-border/30 dark:border-dark-border/20 txt-secondary hover:bg-black/5 dark:hover:bg-white/5',
          ]"
          data-testid="btn-filter-all"
          @click="selectedPurpose = null"
        >
          All Models
        </button>
        <button
          v-for="capability in Object.keys(purposeLabels)"
          :key="capability"
          :class="[
            'px-3 sm:px-4 py-2 rounded-lg text-xs sm:text-sm font-medium transition-all whitespace-nowrap',
            selectedPurpose === capability
              ? 'bg-[var(--brand)] text-white'
              : 'border border-light-border/30 dark:border-dark-border/20 txt-secondary hover:bg-black/5 dark:hover:bg-white/5',
          ]"
          data-testid="btn-filter"
          @click="selectedPurpose = capability as Capability"
        >
          {{ capability }}
        </button>
      </div>
    </div>

    <div class="surface-card p-6" data-testid="section-models-table">
      <h2 class="text-xl font-semibold txt-primary mb-4 flex items-center gap-2">
        <ListBulletIcon class="w-5 h-5" />
        Available Models
      </h2>

      <div
        v-if="filteredModels.length === 0"
        class="text-center py-12 txt-secondary"
        data-testid="section-models-empty"
      >
        No models available for this purpose
      </div>

      <div v-else class="overflow-x-auto scroll-thin">
        <table class="w-full min-w-[640px]">
          <thead>
            <tr class="border-b-2 border-light-border/30 dark:border-dark-border/20">
              <th
                class="text-left py-3 px-2 sm:px-3 txt-secondary text-xs font-semibold uppercase tracking-wide"
              >
                ID
              </th>
              <th
                class="text-left py-3 px-2 sm:px-3 txt-secondary text-xs font-semibold uppercase tracking-wide"
              >
                Purpose
              </th>
              <th
                class="text-left py-3 px-2 sm:px-3 txt-secondary text-xs font-semibold uppercase tracking-wide"
              >
                Service
              </th>
              <th
                class="text-left py-3 px-2 sm:px-3 txt-secondary text-xs font-semibold uppercase tracking-wide hidden md:table-cell"
              >
                Name
              </th>
              <th
                class="text-left py-3 px-2 sm:px-3 txt-secondary text-xs font-semibold uppercase tracking-wide hidden lg:table-cell"
              >
                Description
              </th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="model in filteredModels"
              :key="model.id"
              class="border-b border-light-border/10 dark:border-dark-border/10 hover:bg-black/5 dark:hover:bg-white/5 transition-colors"
              data-testid="item-model"
            >
              <td class="py-3 px-2 sm:px-3">
                <span class="pill text-xs">{{ model.id }}</span>
              </td>
              <td class="py-3 px-2 sm:px-3">
                <span class="pill pill--active text-xs">{{ model.purpose }}</span>
              </td>
              <td class="py-3 px-2 sm:px-3">
                <div class="flex items-center gap-2">
                  <GroqIcon
                    v-if="model.service.toLowerCase().includes('groq')"
                    :size="16"
                    class-name="flex-shrink-0"
                  />
                  <Icon
                    v-else
                    :icon="getProviderIcon(model.service)"
                    class="w-4 h-4 flex-shrink-0"
                  />
                  <span
                    :class="[
                      'px-2 sm:px-3 py-1 rounded-full text-xs font-medium text-white',
                      serviceColors[model.service] || 'bg-gray-500',
                    ]"
                  >
                    {{ model.service }}
                  </span>
                </div>
              </td>
              <td class="py-3 px-2 sm:px-3 txt-primary text-sm font-medium hidden md:table-cell">
                {{ model.name }}
              </td>
              <td class="py-3 px-2 sm:px-3 txt-secondary text-sm hidden lg:table-cell">
                {{ model.description }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <AIModelsAdminPanel v-if="authStore.isAdmin" />
  </div>
</template>

<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import {
  ChevronDownIcon,
  CpuChipIcon,
  FunnelIcon,
  InformationCircleIcon,
  ListBulletIcon,
  LockClosedIcon,
} from '@heroicons/vue/24/outline'
import { Icon } from '@iconify/vue'
import AIModelsAdminPanel from '@/components/config/AIModelsAdminPanel.vue'
import GroqIcon from '@/components/icons/GroqIcon.vue'
import { useNotification } from '@/composables/useNotification'
import { serviceColors } from '@/mocks/aiModels'
import {
  getModels,
  getDefaultModels,
  saveDefaultModels,
  checkModelAvailability,
} from '@/services/api/configApi'
import { useAuthStore } from '@/stores/auth'
import type { AIModel, Capability } from '@/types/ai-models'
import { getProviderIcon } from '@/utils/providerIcons'

type ModelsData = Partial<Record<Capability, AIModel[]>>

const authStore = useAuthStore()
const route = useRoute()
const purposeLabels: Record<Capability, string> = {
  SORT: 'Message Sorting',
  CHAT: 'Chat / General AI',
  VECTORIZE: 'Embedding / Vectorization',
  PIC2TEXT: 'Vision (Image → Text)',
  TEXT2PIC: 'Image Generation (Text → Image)',
  TEXT2VID: 'Video Generation (Text → Video)',
  SOUND2TEXT: 'Speech-to-Text',
  TEXT2SOUND: 'Text-to-Speech',
  ANALYZE: 'File Analysis',
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
  TEXT2VID: null,
  SOUND2TEXT: null,
  TEXT2SOUND: null,
  ANALYZE: null,
})
const originalConfig = ref<Record<Capability, number | null>>({ ...defaultConfig.value })
const selectedPurpose = ref<Capability | null>(null)
const highlightedCapability = ref<Capability | 'ALL' | null>(null)
const capabilityRefs = ref<Record<Capability, HTMLElement | null>>(
  {} as Record<Capability, HTMLElement | null>
)
const openDropdown = ref<Capability | null>(null)

const { success, error: showError, warning } = useNotification()

// Map URL parameter to actual capability name
const normalizeHighlight = (highlight: string): Capability | 'ALL' | null => {
  // Direct match
  if (highlight === 'ALL') return 'ALL'
  if (highlight in purposeLabels) return highlight as Capability

  // Alias mapping (URL-friendly names to actual capability names)
  const aliasMap: Record<string, Capability> = {
    SORTING: 'SORT',
    CHAT: 'CHAT',
    EMBEDDING: 'VECTORIZE',
    VECTORIZATION: 'VECTORIZE',
    VISION: 'PIC2TEXT',
    IMAGE: 'TEXT2PIC',
    VIDEO: 'TEXT2VID',
    TRANSCRIPTION: 'SOUND2TEXT',
    TTS: 'TEXT2SOUND',
    VOICE: 'TEXT2SOUND',
    ANALYSIS: 'ANALYZE',
  }

  return aliasMap[highlight] || null
}

onMounted(async () => {
  await loadData()
  document.addEventListener('click', handleClickOutside)

  // Check for highlight query parameter
  const highlightParam = route.query.highlight as string | undefined
  if (!highlightParam) return

  const highlight = normalizeHighlight(highlightParam)
  if (!highlight) return

  if (highlight === 'ALL') {
    // Highlight all model dropdowns
    highlightedCapability.value = 'ALL'

    // Scroll to the top of the config section
    await nextTick()
    window.scrollTo({ top: 0, behavior: 'smooth' })

    // Remove highlight after 4 seconds (longer for multiple items)
    setTimeout(() => {
      highlightedCapability.value = null
    }, 4000)
  } else {
    // Highlight specific capability
    selectedPurpose.value = highlight
    highlightedCapability.value = highlight

    // Wait for DOM update and scroll to the highlighted field
    await nextTick()
    scrollToCapability(highlight)
  }
})

onBeforeUnmount(() => {
  document.removeEventListener('click', handleClickOutside)
})

// Watch for route changes to handle highlight parameter
watch(
  () => route.query.highlight as string | string[] | undefined,
  async (newHighlightParam) => {
    if (!newHighlightParam || typeof newHighlightParam !== 'string') return

    const newHighlight = normalizeHighlight(newHighlightParam)
    if (!newHighlight) return

    if (newHighlight === 'ALL') {
      highlightedCapability.value = 'ALL'

      await nextTick()
      window.scrollTo({ top: 0, behavior: 'smooth' })

      setTimeout(() => {
        highlightedCapability.value = null
      }, 4000)
    } else {
      selectedPurpose.value = newHighlight
      highlightedCapability.value = newHighlight

      await nextTick()
      scrollToCapability(newHighlight)
    }
  }
)

const scrollToCapability = (capability: Capability) => {
  // Use ref to find the container element
  const element = capabilityRefs.value[capability]

  if (element) {
    // Scroll to the container element
    element.scrollIntoView({ behavior: 'smooth', block: 'center' })

    // Set highlighted state (will trigger visual highlight via :class)
    highlightedCapability.value = capability

    // Remove highlight after 3 seconds
    setTimeout(() => {
      highlightedCapability.value = null
    }, 3000)
  }
}

const loadData = async () => {
  loading.value = true
  try {
    const [modelsRes, defaultsRes] = await Promise.all([getModels(), getDefaultModels()])

    if (modelsRes.success) {
      availableModels.value = modelsRes.models
    }

    if (defaultsRes.success) {
      const mergedDefaults: Record<Capability, number | null> = {
        ...defaultConfig.value,
        ...(defaultsRes.defaults as Partial<Record<Capability, number | null>>),
      }
      defaultConfig.value = mergedDefaults
      originalConfig.value = { ...mergedDefaults }
    }
  } catch (error) {
    console.error('Failed to load models:', error)
  } finally {
    loading.value = false
  }
}

const getModelsByPurpose = (purpose: Capability): AIModel[] => {
  return availableModels.value[purpose] || []
}

const isSystemModel = (purpose: string): boolean => {
  const models = getModelsByPurpose(purpose as Capability)
  const selectedModelId = defaultConfig.value[purpose as Capability]
  const selectedModel = models.find((m) => m.id === selectedModelId)
  return selectedModel?.isSystemModel || false
}

const getSelectedModelService = (purpose: Capability): string => {
  const models = getModelsByPurpose(purpose)
  const selectedModelId = defaultConfig.value[purpose]
  const selectedModel = models.find((m) => m.id === selectedModelId)
  return selectedModel?.service || 'unknown'
}

const getSelectedModelLabel = (purpose: Capability): string => {
  const models = getModelsByPurpose(purpose)
  const selectedModelId = defaultConfig.value[purpose]
  if (!selectedModelId) return '-- Select Model --'
  const selectedModel = models.find((m) => m.id === selectedModelId)
  if (!selectedModel) return '-- Select Model --'
  return `${selectedModel.providerId || selectedModel.name} (${selectedModel.service})`
}

const toggleDropdown = (capability: Capability) => {
  if (isSystemModel(capability)) return
  openDropdown.value = openDropdown.value === capability ? null : capability
}

const selectModel = async (capability: Capability, modelId: number | null) => {
  openDropdown.value = null
  defaultConfig.value[capability] = modelId

  // Check availability if a model was selected
  if (modelId !== null) {
    try {
      const check = await checkModelAvailability(modelId)

      if (!check.available) {
        if (check.setup_required) {
          warning(`Setup required: ${check.message}`)
        } else {
          showError(`Model not available: ${check.message}`)
        }
      }
    } catch (error: any) {
      console.error('Failed to check model availability:', error)
    }
  }

  // Auto-save after selection
  await saveConfiguration()
}

const handleClickOutside = (event: MouseEvent) => {
  const target = event.target as HTMLElement
  if (!target.closest('.relative')) {
    openDropdown.value = null
  }
}

const allModels = computed(() => {
  const all: Array<AIModel & { purpose: Capability }> = []
  for (const [cap, models] of Object.entries(availableModels.value)) {
    models.forEach((model) => {
      all.push({ ...model, purpose: cap as Capability })
    })
  }
  return all
})

const filteredModels = computed(() => {
  if (selectedPurpose.value === null) {
    return allModels.value
  }
  return allModels.value.filter((model) => model.purpose === selectedPurpose.value)
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
</script>
