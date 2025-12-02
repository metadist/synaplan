import { defineStore } from 'pinia'
import { ref } from 'vue'
import { configApi } from '@/services/api/configApi'
import type { AIModel } from '@/types/ai-models'

export interface ModelsList {
  [capability: string]: AIModel[]
}

export interface DefaultModels {
  [capability: string]: number | null
}

export const useAiConfigStore = defineStore('aiConfig', () => {
  const models = ref<ModelsList>({})
  const defaults = ref<DefaultModels>({})
  const loading = ref(false)

  const loadModels = async () => {
    loading.value = true
    try {
      const response = await configApi.getModels()
      if (response.success) {
        models.value = response.models as ModelsList
      }
    } catch (error) {
      console.error('Failed to load models:', error)
    } finally {
      loading.value = false
    }
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

  const getCurrentModel = (capability: string): AIModel | null => {
    const modelId = defaults.value[capability]
    if (!modelId || !models.value[capability]) return null
    
    return models.value[capability].find(m => m.id === modelId) || null
  }

  return {
    models,
    defaults,
    loading,
    loadModels,
    loadDefaults,
    saveDefaults,
    getCurrentModel
  }
})

