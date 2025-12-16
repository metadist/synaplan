import { httpClient } from './httpClient'

export interface TaskPrompt {
  id: number
  topic: string
  name: string
  shortDescription: string
  prompt: string
  language: string
  isDefault: boolean
  isUserOverride?: boolean
  selectionRules?: string | null
  metadata?: Record<string, any>
}

export interface CreatePromptRequest {
  topic: string
  shortDescription: string
  prompt: string
  language?: string
  selectionRules?: string | null
  metadata?: Record<string, any>
}

export interface UpdatePromptRequest {
  shortDescription?: string
  prompt?: string
  selectionRules?: string | null
  metadata?: Record<string, any>
}

export interface PromptFile {
  messageId: number
  fileName: string
  chunks: number
  uploadedAt: string | null
  currentGroupKey?: string // For available files
}

export interface AvailableFile {
  messageId: number
  fileName: string
  chunks: number
  currentGroupKey: string
  uploadedAt: string | null
}

class PromptsApi {
  /**
   * Get all accessible prompts (system + user-specific)
   */
  async getPrompts(language: string = 'en'): Promise<TaskPrompt[]> {
    const data = await httpClient<{ success: boolean; prompts: TaskPrompt[] }>(
      `/api/v1/prompts?language=${language}`,
      {
        method: 'GET',
      }
    )
    return data.prompts
  }

  /**
   * Get a specific prompt by ID
   */
  async getPrompt(id: number): Promise<TaskPrompt> {
    const data = await httpClient<{ success: boolean; prompt: TaskPrompt }>(
      `/api/v1/prompts/${id}`,
      {
        method: 'GET',
      }
    )
    return data.prompt
  }

  /**
   * Create a new user-specific prompt
   */
  async createPrompt(request: CreatePromptRequest): Promise<TaskPrompt> {
    console.log('📤 promptsApi.createPrompt called with:', request)
    try {
      const data = await httpClient<{ success: boolean; prompt: TaskPrompt }>('/api/v1/prompts', {
        method: 'POST',
        body: JSON.stringify(request),
      })
      console.log('✅ promptsApi.createPrompt response:', data)
      return data.prompt
    } catch (error) {
      console.error('❌ promptsApi.createPrompt error:', error)
      throw error
    }
  }

  /**
   * Update an existing user-specific prompt
   */
  async updatePrompt(id: number, request: UpdatePromptRequest): Promise<TaskPrompt> {
    const data = await httpClient<{ success: boolean; prompt: TaskPrompt }>(
      `/api/v1/prompts/${id}`,
      {
        method: 'PUT',
        body: JSON.stringify(request),
      }
    )
    return data.prompt
  }

  /**
   * Delete a user-specific prompt
   */
  async deletePrompt(id: number): Promise<void> {
    await httpClient<{ success: boolean; message: string }>(`/api/v1/prompts/${id}`, {
      method: 'DELETE',
    })
  }

  /**
   * Get files associated with a task prompt
   */
  async getPromptFiles(topic: string): Promise<PromptFile[]> {
    const data = await httpClient<{ success: boolean; files: PromptFile[]; groupKey: string }>(
      `/api/v1/prompts/${topic}/files`,
      {
        method: 'GET',
      }
    )
    return data.files
  }

  /**
   * Upload file for task prompt
   */
  async uploadPromptFile(topic: string, file: File): Promise<void> {
    const formData = new FormData()
    formData.append('files[]', file)
    formData.append('group_key', `TASKPROMPT:${topic}`)
    formData.append('process_level', 'vectorize')

    // httpClient automatically detects FormData and omits Content-Type header
    await httpClient('/api/v1/files/upload', {
      method: 'POST',
      body: formData,
    })
  }

  /**
   * Delete file from task prompt
   */
  async deletePromptFile(topic: string, messageId: number): Promise<void> {
    await httpClient<{ success: boolean; chunksDeleted: number }>(
      `/api/v1/prompts/${topic}/files/${messageId}`,
      {
        method: 'DELETE',
      }
    )
  }

  /**
   * Get all available files (vectorized) for linking
   */
  async getAvailableFiles(search?: string): Promise<AvailableFile[]> {
    const params = search ? `?search=${encodeURIComponent(search)}` : ''
    const data = await httpClient<{ success: boolean; files: AvailableFile[] }>(
      `/api/v1/prompts/available-files${params}`,
      {
        method: 'GET',
      }
    )
    return data.files
  }

  /**
   * Link existing file to task prompt
   */
  async linkFileToPrompt(topic: string, messageId: number): Promise<void> {
    await httpClient<{ success: boolean; chunksLinked: number }>(
      `/api/v1/prompts/${topic}/files/link`,
      {
        method: 'POST',
        body: JSON.stringify({ messageId }),
      }
    )
  }
  /**
   * List all prompts (alias for getPrompts for consistency)
   */
  async listPrompts(language: string = 'en'): Promise<TaskPrompt[]> {
    return this.getPrompts(language)
  }
}

export const promptsApi = new PromptsApi()
