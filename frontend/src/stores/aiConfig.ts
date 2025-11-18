import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { configApi } from '@/services/api/configApi'

export interface AIModel {
  id: number
  service: string
  name: string
  providerId: string
  description?: string
  quality: number
  rating: number
  tag: string
  isSystemModel?: boolean
  features?: string[]
}

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
        models.value = response.models
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
      const response = await configApi.saveDefaultModels(newDefaults)
      if (response.success) {
        defaults.value = newDefaults
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

