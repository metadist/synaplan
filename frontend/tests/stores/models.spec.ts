import { describe, it, expect, beforeEach } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useModelsStore } from '@/stores/models'
import type { AIModel } from '@/stores/models'
import type { ModelOption } from '@/composables/useModelSelection'

describe('Models Store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  it('should initialize with default values', () => {
    const store = useModelsStore()
    
    expect(store.selectedProvider).toBe('openai')
    expect(store.selectedModel).toBe('gpt-4')
    expect(store.availableModels).toEqual([])
    expect(store.chatModels).toEqual([])
  })

  it('should update selected model', () => {
    const store = useModelsStore()
    
    store.setModel('anthropic', 'claude-3')
    
    expect(store.selectedProvider).toBe('anthropic')
    expect(store.selectedModel).toBe('claude-3')
  })

  it('should set chat models', () => {
    const store = useModelsStore()
    const testModels: ModelOption[] = [
      { provider: 'openai', model: 'gpt-4', label: 'GPT-4', id: 1 },
      { provider: 'anthropic', model: 'claude-3', label: 'Claude 3', id: 2 },
    ]

    store.setChatModels(testModels)

    expect(store.chatModels).toEqual(testModels)
  })

  it('should set available models', () => {
    const store = useModelsStore()
    const testModels: AIModel[] = [
      { id: 1, purpose: 'CHAT', service: 'OpenAI', name: 'gpt-4', description: 'GPT-4 model' },
      { id: 2, purpose: 'CHAT', service: 'Anthropic', name: 'claude-3', description: 'Claude 3 model' },
    ]
    
    store.setAvailableModels(testModels)
    
    expect(store.availableModels).toEqual(testModels)
  })
})

