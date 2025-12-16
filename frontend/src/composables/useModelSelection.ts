import { computed, type ComputedRef } from 'vue'
import { useAiConfigStore } from '@/stores/aiConfig'
import type { AIModel, Capability } from '@/types/ai-models'
import type { AgainData as BackendAgainData } from '@/types/ai-models'

export interface ModelOption {
  provider: string
  model: string
  label: string
  id: number
  quality: number
  rating: number
  description: string | null
}

/**
 * Composable for model selection logic
 * Provides unified model selection for Again functionality
 *
 * Now generates againData in frontend based on message files and available models:
 * - For images: Uses TEXT2PIC models
 * - For videos: Uses TEXT2VID models
 * - For audio: Uses TEXT2SOUND models
 * - Otherwise: Uses CHAT models
 *
 * Round-Robin: Recommends next model in BRANKING-sorted list (not the current one)
 */
export function useModelSelection(
  againData?: ComputedRef<BackendAgainData | null | undefined>,
  files?: ComputedRef<any[] | undefined>,
  currentProvider?: ComputedRef<string | undefined>,
  currentModelName?: ComputedRef<string | undefined>,
  mediaHint?: ComputedRef<'image' | 'video' | 'audio' | 'chat' | null | undefined>
) {
  const aiConfigStore = useAiConfigStore()

  /**
   * Resolve preferred tag based on backend-provided Again data
   * Falls back from direct tag → predicted model tag → first eligible tag
   */
  const preferredTag = computed((): string | undefined => {
    const tagSources: Array<string | undefined> = [
      againData?.value?.tag ?? undefined,
      againData?.value?.predictedNext?.tag ?? undefined,
      againData?.value?.eligible?.[0]?.tag ?? undefined,
    ]

    for (const tag of tagSources) {
      if (tag && tag.trim().length > 0) {
        return tag.toUpperCase()
      }
    }

    return undefined
  })

  /**
   * Detect media type from message files
   */
  const mediaType = computed((): 'image' | 'video' | 'audio' | 'chat' => {
    const tag = preferredTag.value
    if (tag) {
      if (tag === 'TEXT2PIC' || tag === 'IMAGE' || tag === 'TEXT_TO_IMAGE') return 'image'
      if (tag === 'TEXT2VID' || tag === 'VIDEO' || tag === 'TEXT_TO_VIDEO') return 'video'
      if (tag === 'TEXT2SOUND' || tag === 'AUDIO' || tag === 'TEXT_TO_AUDIO') return 'audio'
      if (tag === 'CHAT') return 'chat'
    }

    const hint = mediaHint?.value
    if (hint === 'image' || hint === 'video' || hint === 'audio' || hint === 'chat') {
      return hint
    }

    if (!files?.value || files.value.length === 0) {
      return 'chat'
    }

    const file = files.value[0]
    if (file.type?.startsWith('image/')) return 'image'
    if (file.type?.startsWith('video/')) return 'video'
    if (file.type?.startsWith('audio/')) return 'audio'

    return 'chat'
  })

  /**
   * Get the appropriate model tag based on media type
   */
  const modelTag = computed((): Capability => {
    switch (mediaType.value) {
      case 'image':
        return 'TEXT2PIC'
      case 'video':
        return 'TEXT2VID'
      case 'audio':
        return 'TEXT2SOUND'
      default:
        return 'CHAT'
    }
  })

  /**
   * Get current model ID by finding model in store based on provider + name
   * Searches across ALL tags to find the current model
   */
  const currentModelId = computed((): number | null => {
    const backendCurrentModelId =
      againData?.value?.currentModelId ??
      (againData?.value as unknown as { current_model_id?: number | null })?.current_model_id ??
      null

    if (typeof backendCurrentModelId === 'number' && backendCurrentModelId > 0) {
      return backendCurrentModelId
    }

    if (!currentProvider?.value || !currentModelName?.value) {
      return null
    }

    // Search across ALL model tags to find current model
    for (const models of Object.values(aiConfigStore.models)) {
      if (!models) continue
      const found = models.find(
        (m: AIModel) =>
          m.service.toLowerCase() === currentProvider.value?.toLowerCase() &&
          m.name.toLowerCase() === currentModelName.value?.toLowerCase()
      )
      if (found) {
        return found.id
      }
    }

    return null
  })

  /**
   * Get available model options for selection
   * Uses models from store based on media type (chat/image/video/audio)
   * Models are sorted by BRANKING (rating) - highest first
   */
  const modelOptions = computed((): ModelOption[] => {
    const tag = modelTag.value
    const models = aiConfigStore.models[tag] || []

    console.log('🔍 useModelSelection:', {
      mediaType: mediaType.value,
      modelTag: tag,
      filesCount: files?.value?.length || 0,
      availableModelsForTag: models.length,
    })

    if (models.length === 0) {
      console.warn('⚠️ No models available for tag:', tag)
      return []
    }

    return models
      .sort((a: AIModel, b: AIModel) => b.rating - a.rating) // Sort by BRANKING descending
      .map((model: AIModel) => ({
        provider: model.service,
        model: model.name,
        label: model.name,
        id: model.id,
        quality: model.quality,
        rating: model.rating,
        description: model.description,
      }))
  })

  const currentModelIndex = computed((): number => {
    const options = modelOptions.value
    if (options.length === 0) return -1

    const id = currentModelId.value
    if (id) {
      const byId = options.findIndex((m: ModelOption) => m.id === id)
      if (byId !== -1) return byId
    }

    if (currentProvider?.value && currentModelName?.value) {
      const providerLower = currentProvider.value.toLowerCase()
      const modelLower = currentModelName.value.toLowerCase()
      const byName = options.findIndex(
        (m: ModelOption) =>
          m.provider.toLowerCase() === providerLower && m.model.toLowerCase() === modelLower
      )
      if (byName !== -1) return byName
    }

    return -1
  })

  /**
   * Get the predicted/recommended next model using Round-Robin
   * - Finds current model in sorted list
   * - Returns NEXT model in list (wraps around to start)
   * - Ensures we don't always recommend the same model
   */
  const predictedModel = computed((): ModelOption | null => {
    const options = modelOptions.value
    if (options.length === 0) return null

    // Backend-provided recommendation
    const backendNextId = againData?.value?.predictedNext?.id
    if (backendNextId) {
      const backendMatch = options.find((m: ModelOption) => m.id === backendNextId)
      if (backendMatch) {
        return backendMatch
      }
    }

    if (options.length === 1) {
      return options[0] // Only one model available
    }

    const currentIndex = currentModelIndex.value
    if (currentIndex !== -1) {
      const nextIndex = (currentIndex + 1) % options.length
      console.log('🔄 Round-Robin: Current index', currentIndex, '→ Next index', nextIndex)
      return options[nextIndex]
    }

    // No match found for current model: fallback to second-best if available
    if (options.length > 1) {
      console.log('⭐ Fallback: Using second-highest-rated model')
      return options[1]
    }

    return options[0]
  })

  /**
   * Format model for display
   */
  const formatModelLabel = (model: ModelOption): string => {
    return model.label || model.model
  }

  /**
   * Check if model selection is available
   */
  const hasModels = computed(() => modelOptions.value.length > 0)

  return {
    modelOptions,
    predictedModel,
    formatModelLabel,
    hasModels,
    currentModelId,
    mediaType,
  }
}
