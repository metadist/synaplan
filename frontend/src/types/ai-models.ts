/**
 * AI Model Types
 */

export interface AIModel {
  id: number
  service: string
  name: string
  tag?: string | null
  providerId?: string | null
  quality?: number | null
  rating?: number | null
  priceIn?: number | null
  priceOut?: number | null
  selectable?: boolean
  description?: string | null
  isSystemModel?: boolean
  features?: string[]
}

export interface AgainData {
  eligible: AIModel[]
  predictedNext: AIModel | null
  tag: string
  current_model_id?: number | null
  currentModelId?: number | null
}

export interface MessageResponse {
  success: boolean
  message: {
    id: number
    text: string
    hasFile: boolean
    filePath: string
    fileType: string
    provider: string
    timestamp: number
    trackId: number
    topic: string
  }
}

