import { defineStore } from 'pinia'
import { ref } from 'vue'

export interface ModelOption {
  provider: string
  model: string
  label: string
}

export interface AIModel {
  id: number
  purpose: string
  service: string
  name: string
  description: string
  isSystemModel?: boolean
}

export const useModelsStore = defineStore('models', () => {
  const selectedProvider = ref('openai')
  const selectedModel = ref('gpt-4')
  const availableModels = ref<AIModel[]>([])
  const chatModels = ref<ModelOption[]>([])

  const setModel = (provider: string, model: string) => {
    selectedProvider.value = provider
    selectedModel.value = model
  }

  const loadModels = async () => {
    // TODO: API call würde hier hin
    // const response = await fetch('/api/models')
    // availableModels.value = await response.json()
  }

  const setChatModels = (models: ModelOption[]) => {
    chatModels.value = models
  }

  const setAvailableModels = (models: AIModel[]) => {
    availableModels.value = models
  }

  return {
    selectedProvider,
    selectedModel,
    availableModels,
    chatModels,
    setModel,
    loadModels,
    setChatModels,
    setAvailableModels,
  }
})
