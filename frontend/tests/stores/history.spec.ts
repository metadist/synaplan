import { describe, it, expect, beforeEach } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useHistoryStore } from '@/stores/history'

describe('History Store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  it('should initialize with empty messages', () => {
    const store = useHistoryStore()
    expect(store.messages).toEqual([])
  })

  it('should add a message', () => {
    const store = useHistoryStore()
    
    store.addMessage('user', [{ type: 'text', content: 'Hello' }])
    
    expect(store.messages).toHaveLength(1)
    expect(store.messages[0].role).toBe('user')
    expect(store.messages[0].parts[0].content).toBe('Hello')
  })

  it('should clear all messages', () => {
    const store = useHistoryStore()
    store.addMessage('user', [{ type: 'text', content: 'Hello' }])
    store.addMessage('assistant', [{ type: 'text', content: 'Hi' }])
    
    store.clear()
    expect(store.messages).toEqual([])
  })

  it('should add streaming message', () => {
    const store = useHistoryStore()
    
    const id = store.addStreamingMessage('assistant', 'openai', 'GPT-4')
    
    expect(store.messages).toHaveLength(1)
    expect(store.messages[0].isStreaming).toBe(true)
    expect(store.messages[0].id).toBe(id)
  })

  it('should update streaming message', () => {
    const store = useHistoryStore()
    const id = store.addStreamingMessage('assistant')
    
    store.updateStreamingMessage(id, 'Hello')
    
    expect(store.messages[0].parts[0].content).toBe('Hello')
    expect(store.messages[0].isStreaming).toBe(true)
  })

  it('should finish streaming message', () => {
    const store = useHistoryStore()
    const id = store.addStreamingMessage('assistant')
    
    store.finishStreamingMessage(id)
    
    expect(store.messages[0].isStreaming).toBe(false)
  })

  it('should mark message as superseded', () => {
    const store = useHistoryStore()
    store.addMessage('assistant', [{ type: 'text', content: 'Old response' }])
    const messageId = store.messages[0].id
    
    store.markSuperseded(messageId)
    
    expect(store.messages[0].isSuperseded).toBe(true)
  })
})

