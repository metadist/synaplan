/**
 * AI Model Types
 */

export type Capability =
  | 'SORT'
  | 'CHAT'
  | 'VECTORIZE'
  | 'PIC2TEXT'
  | 'TEXT2PIC'
  | 'TEXT2VID'
  | 'SOUND2TEXT'
  | 'TEXT2SOUND'
  | 'ANALYZE'

export interface AIModel {
  id: number
  service: string
  name: string
  tag: string
  providerId: string
  quality: number
  rating: number
  priceIn?: number | null // Not returned by backend getModels
  priceOut?: number | null // Not returned by backend getModels
  selectable?: boolean // Not returned by backend getModels
  description: string | null
  isSystemModel: boolean
  features: string[]
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
