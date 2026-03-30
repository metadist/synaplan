<template>
  <div class="space-y-6" data-testid="page-config-ai-models">
    <div
      class="surface-card p-6 relative"
      :class="openDropdown ? 'z-20' : 'z-0'"
      data-testid="section-default-config"
    >
      <h2 class="text-2xl font-semibold txt-primary mb-6 flex items-center gap-2">
        <CpuChipIcon class="w-6 h-6 text-[var(--brand)]" />
        {{ $t('config.aiModels.defaultConfigTitle') }}
      </h2>

      <div v-if="loading" class="text-center py-8" data-testid="section-loading">
        <div
          class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-[var(--brand)]"
        ></div>
        <p class="mt-2 txt-secondary">{{ $t('config.aiModels.loadingModels') }}</p>
      </div>

      <div v-else class="grid grid-cols-1 md:grid-cols-2 gap-5" data-testid="section-capabilities">
        <div
          v-for="capability in Object.keys(purposeLabels)"
          :key="capability"
          :ref="
            (el: any) => {
              if (el) capabilityRefs[capability as Capability] = el as HTMLElement
            }
          "
          class="space-y-2 transition-all duration-300 relative"
          :class="[
            highlightedCapability === capability || highlightedCapability === 'ALL'
              ? 'ring-4 ring-[var(--brand)] ring-offset-4 rounded-xl p-3 bg-[var(--brand)]/5'
              : '',
            openDropdown === capability ? 'z-10' : 'z-0',
          ]"
          data-testid="item-capability"
        >
          <label class="flex flex-wrap items-center gap-2 text-sm font-semibold txt-primary">
            <CpuChipIcon class="w-4 h-4 text-[var(--brand)]" />
            <span class="flex-1 min-w-0">{{ purposeLabels[capability as Capability] }}</span>
          </label>
          <div class="relative">
            <button
              type="button"
              :class="[
                'w-full px-4 py-3 pl-10 pr-10 rounded-lg surface-card border txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)] transition-all text-left',
                'border-light-border/30 dark:border-dark-border/20 hover:border-[var(--brand)]/50',
                openDropdown === capability && 'ring-2 ring-[var(--brand)]',
              ]"
              data-testid="btn-model-dropdown"
              @click="toggleDropdown(capability as Capability)"
            >
              <span class="flex items-center gap-2 truncate">
                <span class="truncate">{{ getSelectedModelLabel(capability as Capability) }}</span>
                <ModelCostBadge
                  v-if="getSelectedModelObj(capability as Capability)"
                  :model="getSelectedModelObj(capability as Capability)!"
                  :peers="getModelsByPurpose(capability as Capability)"
                />
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
                <span class="txt-secondary italic">{{ $t('config.aiModels.selectModel') }}</span>
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
                  <div class="flex items-center gap-2">
                    <span class="font-medium truncate">{{ model.name }}</span>
                    <ModelCostBadge
                      :model="model"
                      :peers="getModelsByPurpose(capability as Capability)"
                    />
                  </div>
                  <div class="text-xs txt-secondary truncate">{{ model.service }}</div>
                </div>
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="surface-card p-6" data-testid="section-purpose-filters">
      <h2 class="text-xl font-semibold txt-primary mb-4 flex items-center gap-2">
        <FunnelIcon class="w-5 h-5" />
        {{ $t('config.aiModels.modelsPurposesTitle') }}
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
          {{ $t('config.aiModels.allModels') }}
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
          {{ purposeLabels[capability as Capability] }}
        </button>
      </div>
    </div>

    <div class="surface-card p-6" data-testid="section-models-table">
      <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 mb-4">
        <h2 class="text-xl font-semibold txt-primary flex items-center gap-2">
          <ListBulletIcon class="w-5 h-5" />
          {{ $t('config.aiModels.availableModelsTitle') }}
        </h2>

        <div class="flex items-center gap-3">
          <!-- Show Rating Toggle -->
          <label class="flex items-center gap-2 cursor-pointer group">
            <input
              v-model="showRatings"
              type="checkbox"
              class="w-4 h-4 rounded border-light-border/30 dark:border-dark-border/20 text-[var(--brand)] focus:ring-[var(--brand)] cursor-pointer"
            />
            <span class="text-sm txt-secondary group-hover:txt-primary transition-colors">{{
              $t('config.aiModels.showRating')
            }}</span>
          </label>

          <!-- Sort Dropdown -->
          <div class="relative">
            <select
              v-model="sortBy"
              class="px-3 py-2 pr-8 rounded-lg border border-light-border/30 dark:border-dark-border/20 bg-light-surface dark:bg-dark-surface txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)] cursor-pointer appearance-none"
              @change="sortDirection = sortBy === 'quality' || sortBy === 'rating' ? 'desc' : 'asc'"
            >
              <option value="alphabet">{{ $t('config.aiModels.sortAlphabet') }}</option>
              <option value="service">{{ $t('config.aiModels.sortService') }}</option>
              <option value="quality">{{ $t('config.aiModels.sortQuality') }}</option>
              <option value="purpose">{{ $t('config.aiModels.sortPurpose') }}</option>
            </select>
            <ChevronDownIcon
              class="absolute right-2 top-1/2 -translate-y-1/2 w-4 h-4 txt-secondary pointer-events-none"
            />
          </div>
        </div>
      </div>

      <div
        v-if="filteredModels.length === 0"
        class="text-center py-12 txt-secondary"
        data-testid="section-models-empty"
      >
        {{ $t('config.aiModels.noModelsAvailable') }}
      </div>

      <div v-else class="overflow-x-auto scroll-thin">
        <table class="w-full min-w-[640px]">
          <thead>
            <tr class="border-b-2 border-light-border/30 dark:border-dark-border/20">
              <th
                class="text-left py-3 px-2 sm:px-3 txt-secondary text-xs font-semibold uppercase tracking-wide cursor-pointer hover:txt-primary transition-colors select-none"
                @click="toggleSort('alphabet')"
              >
                <div class="flex items-center gap-1">
                  {{ $t('config.aiModels.tableName') }}
                  <SortIndicator :active="sortBy === 'alphabet'" :direction="sortDirection" />
                </div>
              </th>
              <th
                class="text-left py-3 px-2 sm:px-3 txt-secondary text-xs font-semibold uppercase tracking-wide cursor-pointer hover:txt-primary transition-colors select-none"
                @click="toggleSort('service')"
              >
                <div class="flex items-center gap-1">
                  {{ $t('config.aiModels.tableService') }}
                  <SortIndicator :active="sortBy === 'service'" :direction="sortDirection" />
                </div>
              </th>
              <th
                v-if="showRatings"
                class="text-center py-3 px-2 sm:px-3 txt-secondary text-xs font-semibold uppercase tracking-wide cursor-pointer hover:txt-primary transition-colors select-none"
                @click="toggleSort('quality')"
              >
                <div class="flex items-center justify-center gap-1">
                  {{ $t('config.aiModels.tableRating') }}
                  <SortIndicator :active="sortBy === 'quality'" :direction="sortDirection" />
                </div>
              </th>
              <th
                class="text-left py-3 px-2 sm:px-3 txt-secondary text-xs font-semibold uppercase tracking-wide hidden sm:table-cell cursor-pointer hover:txt-primary transition-colors select-none"
                @click="toggleSort('purpose')"
              >
                <div class="flex items-center gap-1">
                  {{ $t('config.aiModels.tablePurpose') }}
                  <SortIndicator :active="sortBy === 'purpose'" :direction="sortDirection" />
                </div>
              </th>
              <th
                class="text-left py-3 px-2 sm:px-3 txt-secondary text-xs font-semibold uppercase tracking-wide hidden lg:table-cell"
              >
                {{ $t('config.aiModels.tableDescription') }}
              </th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="model in paginatedModels"
              :key="`${model.id}-${model.purpose}`"
              class="border-b border-light-border/10 dark:border-dark-border/10 hover:bg-black/5 dark:hover:bg-white/5 transition-colors"
              data-testid="item-model"
            >
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
                  <span class="txt-primary text-sm font-medium">{{ model.name }}</span>
                </div>
              </td>
              <td class="py-3 px-2 sm:px-3">
                <span
                  :class="[
                    'px-2 sm:px-3 py-1 rounded-full text-xs font-medium text-white',
                    serviceColors[model.service] || 'bg-gray-500',
                  ]"
                >
                  {{ model.service }}
                </span>
              </td>
              <td v-if="showRatings" class="py-3 px-2 sm:px-3">
                <div
                  class="flex items-center justify-center gap-0.5"
                  :title="$t('config.aiModels.qualityScore', { score: model.quality.toFixed(1) })"
                >
                  <svg
                    v-for="star in 5"
                    :key="star"
                    class="w-4 h-4"
                    :class="
                      star <= getStarRating(model.quality)
                        ? 'text-yellow-500'
                        : 'text-gray-300 dark:text-gray-600'
                    "
                    fill="currentColor"
                    viewBox="0 0 20 20"
                  >
                    <path
                      d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"
                    />
                  </svg>
                </div>
              </td>
              <td class="py-3 px-2 sm:px-3 hidden sm:table-cell">
                <span class="pill pill--active text-xs">{{ model.purpose }}</span>
              </td>
              <td class="py-3 px-2 sm:px-3 txt-secondary text-sm hidden lg:table-cell">
                {{ model.description }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div
        v-if="modelsTotalPages > 1"
        class="flex items-center justify-between pt-4 border-t border-light-border/10 dark:border-dark-border/10 mt-4"
      >
        <span class="text-sm txt-secondary">
          {{
            $t('config.aiModels.pagination.showing', {
              start: (modelsPage - 1) * MODELS_PER_PAGE + 1,
              end: Math.min(modelsPage * MODELS_PER_PAGE, sortedModels.length),
              total: sortedModels.length,
            })
          }}
        </span>
        <div class="flex items-center gap-1">
          <button
            class="p-1.5 rounded-lg border border-light-border/30 dark:border-dark-border/20 txt-secondary hover:txt-primary transition-colors disabled:opacity-30 disabled:cursor-not-allowed"
            :disabled="modelsPage <= 1"
            @click="modelsPage--"
          >
            <ChevronLeftIcon class="w-4 h-4" />
          </button>
          <span class="px-3 text-sm txt-primary font-medium">
            {{ modelsPage }} / {{ modelsTotalPages }}
          </span>
          <button
            class="p-1.5 rounded-lg border border-light-border/30 dark:border-dark-border/20 txt-secondary hover:txt-primary transition-colors disabled:opacity-30 disabled:cursor-not-allowed"
            :disabled="modelsPage >= modelsTotalPages"
            @click="modelsPage++"
          >
            <ChevronRightIcon class="w-4 h-4" />
          </button>
        </div>
      </div>
    </div>

    <AIModelsAdminPanel v-if="authStore.isAdmin" />
  </div>
</template>

<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { ChevronLeftIcon, ChevronRightIcon } from '@heroicons/vue/20/solid'
import { useRoute } from 'vue-router'
import { ChevronDownIcon, CpuChipIcon, FunnelIcon, ListBulletIcon } from '@heroicons/vue/24/outline'
import { Icon } from '@iconify/vue'
import AIModelsAdminPanel from '@/components/config/AIModelsAdminPanel.vue'
import SortIndicator from '@/components/config/SortIndicator.vue'
import GroqIcon from '@/components/icons/GroqIcon.vue'
import ModelCostBadge from '@/components/ModelCostBadge.vue'
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
import { useI18n } from 'vue-i18n'

type ModelsData = Partial<Record<Capability, AIModel[]>>

const authStore = useAuthStore()
const route = useRoute()
const { t } = useI18n()

const purposeLabels = computed<Record<Capability, string>>(() => ({
  SORT: t('config.aiModels.capabilities.sort'),
  CHAT: t('config.aiModels.capabilities.chat'),
  ANALYZE: t('config.aiModels.capabilities.analyze'),
  VECTORIZE: t('config.aiModels.capabilities.vectorize'),
  PIC2TEXT: t('config.aiModels.capabilities.pic2text'),
  TEXT2PIC: t('config.aiModels.capabilities.text2pic'),
  PIC2PIC: t('config.aiModels.capabilities.pic2pic'),
  TEXT2VID: t('config.aiModels.capabilities.text2vid'),
  SOUND2TEXT: t('config.aiModels.capabilities.sound2text'),
  TEXT2SOUND: t('config.aiModels.capabilities.text2sound'),
}))

const loading = ref(false)
const saving = ref(false)
const availableModels = ref<ModelsData>({})
const defaultConfig = ref<Record<Capability, number | null>>({
  SORT: null,
  CHAT: null,
  ANALYZE: null,
  VECTORIZE: null,
  PIC2TEXT: null,
  TEXT2PIC: null,
  PIC2PIC: null,
  TEXT2VID: null,
  SOUND2TEXT: null,
  TEXT2SOUND: null,
})
const originalConfig = ref<Record<Capability, number | null>>({ ...defaultConfig.value })
const selectedPurpose = ref<Capability | null>(null)
const highlightedCapability = ref<Capability | 'ALL' | null>(null)
const capabilityRefs = ref<Record<Capability, HTMLElement | null>>(
  {} as Record<Capability, HTMLElement | null>
)
const openDropdown = ref<Capability | null>(null)
const showRatings = ref(false)
const sortBy = ref<'alphabet' | 'service' | 'rating' | 'quality' | 'purpose'>('alphabet')
const sortDirection = ref<'asc' | 'desc'>('asc')
const modelsPage = ref(1)
const MODELS_PER_PAGE = 20

const { success, error: showError, warning } = useNotification()

// Map URL parameter to actual capability name
const normalizeHighlight = (highlight: string): Capability | 'ALL' | null => {
  // Direct match
  if (highlight === 'ALL') return 'ALL'
  if (highlight in purposeLabels.value) return highlight as Capability

  // Alias mapping (URL-friendly names to actual capability names)
  const aliasMap: Record<string, Capability> = {
    SORTING: 'SORT',
    CHAT: 'CHAT',
    EMBEDDING: 'VECTORIZE',
    VECTORIZATION: 'VECTORIZE',
    VISION: 'PIC2TEXT',
    IMAGE: 'TEXT2PIC',
    PIC2PIC: 'PIC2PIC',
    IMAGE2IMAGE: 'PIC2PIC',
    VIDEO: 'TEXT2VID',
    TRANSCRIPTION: 'SOUND2TEXT',
    TTS: 'TEXT2SOUND',
    VOICE: 'TEXT2SOUND',
    ANALYZE: 'ANALYZE',
    ANALYSIS: 'ANALYZE',
    FILE_ANALYSIS: 'ANALYZE',
  }

  return aliasMap[highlight] || null
}

onMounted(async () => {
  await loadData()
  document.addEventListener('click', handleClickOutside)
  document.addEventListener('keydown', handleKeydown)

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
  document.removeEventListener('keydown', handleKeydown)
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

const modelsByPurpose = computed<Record<string, AIModel[]>>(() => {
  const result: Record<string, AIModel[]> = {}
  for (const [purpose, models] of Object.entries(availableModels.value)) {
    result[purpose] = [...models].sort((a, b) => a.name.localeCompare(b.name))
  }
  return result
})

const getModelsByPurpose = (purpose: Capability): AIModel[] => {
  return modelsByPurpose.value[purpose] || []
}

const selectedModelInfo = computed<Record<string, { label: string; service: string }>>(() => {
  const info: Record<string, { label: string; service: string }> = {}
  for (const purpose of Object.keys(purposeLabels.value)) {
    const models = modelsByPurpose.value[purpose] || []
    const selectedId = defaultConfig.value[purpose as Capability]
    const selected = selectedId ? models.find((m) => m.id === selectedId) : null
    info[purpose] = {
      label: selected
        ? `${selected.providerId || selected.name} (${selected.service})`
        : '-- Select Model --',
      service: selected?.service || 'unknown',
    }
  }
  return info
})

const getSelectedModelService = (purpose: Capability): string => {
  return selectedModelInfo.value[purpose]?.service || 'unknown'
}

const getSelectedModelLabel = (purpose: Capability): string => {
  return selectedModelInfo.value[purpose]?.label || '-- Select Model --'
}

const getSelectedModelObj = (purpose: Capability): AIModel | null => {
  const models = modelsByPurpose.value[purpose] || []
  const selectedId = defaultConfig.value[purpose]
  return selectedId ? (models.find((m) => m.id === selectedId) ?? null) : null
}

const toggleDropdown = (capability: Capability) => {
  openDropdown.value = openDropdown.value === capability ? null : capability
}

const selectModel = async (capability: Capability, modelId: number | null) => {
  openDropdown.value = null
  const previousModelId = defaultConfig.value[capability]
  defaultConfig.value[capability] = modelId

  // Check availability if a model was selected
  if (modelId !== null) {
    try {
      const check = await checkModelAvailability(modelId)

      if (!check.available) {
        // Revert selection — model cannot be used
        defaultConfig.value[capability] = previousModelId
        const modelName =
          getModelsByPurpose(capability).find((m) => m.id === modelId)?.name || `ID ${modelId}`
        if (check.env_var) {
          warning(
            t('config.aiModels.modelNotConfigured', {
              model: modelName,
              envVar: check.env_var,
            })
          )
        } else {
          showError(t('config.aiModels.modelNotAvailable', { model: modelName }))
        }
        return
      }
    } catch (error) {
      console.error('Failed to check model availability:', error)
    }
  }

  // Auto-save after selection
  await saveConfiguration()
}

const handleClickOutside = (event: MouseEvent) => {
  if (!openDropdown.value) return
  const target = event.target as HTMLElement
  const openRef = capabilityRefs.value[openDropdown.value]
  if (openRef && !openRef.contains(target)) {
    openDropdown.value = null
  }
}

const handleKeydown = (event: KeyboardEvent) => {
  if (event.key === 'Escape' && openDropdown.value) {
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

/**
 * Toggle sort column - if same column, toggle direction; if new column, set ascending
 */
const toggleSort = (column: 'alphabet' | 'service' | 'rating' | 'quality' | 'purpose') => {
  if (sortBy.value === column) {
    sortDirection.value = sortDirection.value === 'asc' ? 'desc' : 'asc'
  } else {
    sortBy.value = column
    // Default to descending for quality/rating (best first), ascending for others
    sortDirection.value = column === 'quality' || column === 'rating' ? 'desc' : 'asc'
  }
}

/**
 * Convert quality score (0-10) to star rating (1-5)
 */
const getStarRating = (quality: number): number => {
  // Quality is 0-10, convert to 0-5 stars
  return Math.round(quality / 2)
}

type ModelWithPurpose = AIModel & { purpose: Capability }

const sortedModels = computed(() => {
  const dir = sortDirection.value === 'asc' ? 1 : -1
  const col = sortBy.value

  const compareFn = (a: ModelWithPurpose, b: ModelWithPurpose): number => {
    let cmp = 0
    switch (col) {
      case 'alphabet':
        cmp = a.name.localeCompare(b.name)
        break
      case 'service':
        cmp = a.service.localeCompare(b.service)
        break
      case 'rating':
      case 'quality':
        cmp = a.quality - b.quality
        break
      case 'purpose':
        cmp = a.purpose.localeCompare(b.purpose)
        break
    }
    return cmp !== 0 ? dir * cmp : dir * a.name.localeCompare(b.name)
  }

  return [...filteredModels.value].sort(compareFn)
})

const modelsTotalPages = computed(() =>
  Math.max(1, Math.ceil(sortedModels.value.length / MODELS_PER_PAGE))
)

const paginatedModels = computed(() => {
  const start = (modelsPage.value - 1) * MODELS_PER_PAGE
  return sortedModels.value.slice(start, start + MODELS_PER_PAGE)
})

watch([selectedPurpose, sortBy, sortDirection], () => {
  modelsPage.value = 1
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
      success(t('config.aiModels.saveSuccess'))
    }
  } catch (err: unknown) {
    console.error('Failed to save configuration:', err)
    showError(t('config.aiModels.saveError'))
  } finally {
    saving.value = false
  }
}
</script>
