import { defineStore } from 'pinia'
import { ref } from 'vue'
import { configApi, ModelsResponse } from '@/services/api/configApi'
import type { AIModel, Capability } from '@/types/ai-models'

// Local state can have nulls (no model selected for a capability)
export type DefaultModels = Partial<Record<Capability, number | null>>

export const useAiConfigStore = defineStore('aiConfig', () => {
  const models = ref<ModelsResponse['models']>({})
  const defaults = ref<DefaultModels>({})
  const loading = ref(false)

  // Booting the chat asks for the catalog twice in the same tick: ChatView
  // loads it directly, and the model-mix store's ensureLoaded() checks for a
  // populated catalog, still finds it empty and asks again. The backend
  // serialises the two identical requests, so the second one answered seconds
  // late and held back everything ChatView awaits behind it — the chat list,
  // the first chat and with it the composer. Racing callers now share the one
  // request that is already in flight.
  let modelsRequest: Promise<void> | null = null

  const loadModels = async (): Promise<void> => {
    if (modelsRequest) {
      return modelsRequest
    }
    loading.value = true
    modelsRequest = (async () => {
      try {
        const response = await configApi.getModels()
        if (response.success) {
          models.value = response.models
        }
      } catch (error) {
        console.error('Failed to load models:', error)
      } finally {
        loading.value = false
        modelsRequest = null
      }
    })()
    return modelsRequest
  }

  const loadDefaults = async () => {
    loading.value = true
    try {
      const response = await configApi.getDefaultModels()
      if (response.success) {
        defaults.value = response.defaults
      }
    } catch (error) {
      console.error('Failed to load default models:', error)
    } finally {
      loading.value = false
    }
  }

  const saveDefaults = async (newDefaults: DefaultModels) => {
    loading.value = true
    try {
      const payload: Record<string, number> = {}
      Object.entries(newDefaults).forEach(([capability, value]) => {
        if (value !== null) {
          payload[capability] = value
        }
      })

      const response = await configApi.saveDefaultModels({ defaults: payload })
      if (response.success) {
        defaults.value = { ...newDefaults }
      }
      return response
    } catch (error) {
      console.error('Failed to save default models:', error)
      throw error
    } finally {
      loading.value = false
    }
  }

  const getCurrentModel = (capability: Capability): AIModel | null => {
    const modelId = defaults.value[capability]
    const capabilityModels = models.value[capability]
    if (!modelId || !capabilityModels) return null

    return capabilityModels.find((m) => m.id === modelId) || null
  }

  return {
    models,
    defaults,
    loading,
    loadModels,
    loadDefaults,
    saveDefaults,
    getCurrentModel,
  }
})
