/**
 * Chat API - Message & Conversation Management
 */

import { httpClient, API_BASE_URL } from './httpClient'
import type { MessageResponse } from '@/types/ai-models'

export const chatApi = {
  async sendMessage(userId: number, message: string, trackId?: number): Promise<any> {
    // Mock data temporarily disabled - direct backend communication
    return httpClient<any>('/api/v1/messages/send', {
      method: 'POST',
      body: JSON.stringify({ userId, message, trackId })
    })
  },

  async getConversations(userId: number): Promise<any> {
    // Mock data temporarily disabled - direct backend communication
    return httpClient<any>(`/api/v1/conversations/${userId}`, {
      method: 'GET'
    })
  },

  async getMessages(conversationId: number): Promise<any> {
    // Mock data temporarily disabled - direct backend communication
    return httpClient<any>(`/api/v1/conversations/${conversationId}/messages`, {
      method: 'GET'
    })
  },

  streamMessage(
    userId: number,
    message: string,
    trackId: number | undefined,
    chatId: number,
    onUpdate: (data: any) => void,
    includeReasoning: boolean = false,
    webSearch: boolean = false
  ): () => void {
    const token = localStorage.getItem('auth_token')
    const params = new URLSearchParams({
      message,
      chatId: chatId.toString(),
      ...(trackId && { trackId: trackId.toString() }),
      ...(includeReasoning && { reasoning: '1' }),
      ...(webSearch && { webSearch: '1' })
    })

    // Build URL with token for authentication
    const url = `${API_BASE_URL}/api/v1/messages/stream?${params}&token=${token}`

    const eventSource = new EventSource(url)
    let completionReceived = false

    eventSource.onopen = () => {
      console.log('✅ SSE connection opened')
    }

    eventSource.onmessage = (event) => {
      try {
        const data = JSON.parse(event.data)
        
        // Debug logging for chunk events
        if (data.status === 'data') {
          console.log('📦 SSE chunk received:', data.chunk?.substring(0, 20) + '...')
        }
        
        onUpdate(data)
        
        if (data.status === 'complete') {
          completionReceived = true
          console.log('✅ Stream completed successfully')
          // Close immediately - all chunks have been received
          eventSource.close()
        } else if (data.status === 'error') {
          eventSource.close()
        }
      } catch (error) {
        console.error('Failed to parse SSE data:', error, 'Raw data:', event.data)
      }
    }

    eventSource.onerror = (error) => {
      console.log('SSE error event received, readyState:', eventSource.readyState, 'completionReceived:', completionReceived)
      
      // If we already received completion, this is just normal stream end
      if (completionReceived) {
        console.log('✅ Stream ended after completion (normal)')
        eventSource.close()
        return
      }
      
      // SSE CLOSED (2) or CONNECTING (0) - Connection closed by server
      // Usually means all data sent, treat as completion if we haven't received it
      if (eventSource.readyState === EventSource.CLOSED || eventSource.readyState === EventSource.CONNECTING) {
        console.log('⚠️ SSE connection closed by server (treating as completion)')
        eventSource.close()
        // Send a synthetic complete event to clean up UI
        onUpdate({ status: 'complete', message: 'Response complete', metadata: {} })
        return
      }
      
      // SSE OPEN (1) - Only treat as error if still open and something went wrong
      if (eventSource.readyState === EventSource.OPEN) {
        console.error('❌ SSE connection error during active stream')
        eventSource.close()
        onUpdate({ status: 'error', error: 'Connection interrupted' })
      }
    }

    return () => eventSource.close()
  },

  async getHistory(limit = 50, trackId?: number): Promise<any> {
    const params = new URLSearchParams({ limit: limit.toString() })
    if (trackId) {
      params.append('trackId', trackId.toString())
    }
    return httpClient<any>(`/api/v1/messages/history?${params}`, { method: 'GET' })
  },

  async sendAgainMessage(
    originalMessageId: number,
    modelId?: number,
    promptId?: string
  ): Promise<MessageResponse> {
    return httpClient<MessageResponse>('/api/v1/messages/again', {
      method: 'POST',
      body: JSON.stringify({ originalMessageId, modelId, promptId })
    })
  },

  async enhanceMessage(text: string): Promise<{ original: string; enhanced: string }> {
    return httpClient<{ original: string; enhanced: string }>('/api/v1/messages/enhance', {
      method: 'POST',
      body: JSON.stringify({ text })
    })
  },

  async getChatMessages(chatId: number, offset = 0, limit = 50): Promise<any> {
    return httpClient<any>(`/api/v1/chats/${chatId}/messages?offset=${offset}&limit=${limit}`, {
      method: 'GET'
    })
  }
}

