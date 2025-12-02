/**
 * AI Model Types
 */

export interface AIModel {
  id: number
  service: string
  name: string
  tag: string
  providerId: string
  quality: number
  rating: number
  priceIn: number
  priceOut: number
  selectable: boolean
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

