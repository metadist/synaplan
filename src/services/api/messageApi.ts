import { httpClient } from './httpClient'

export interface AgainModel {
  id: number
  service: string
  name: string
  providerId: string
  description: string | null
  quality: number
  rating: number
  tag: string
  label: string
}

export interface AgainOptions {
  success: boolean
  message_id: number
  topic: string
  capability: string
  eligible_models: AgainModel[]
  predicted_next: AgainModel | null
  current_model_id: number | null
}

/**
 * Get eligible models for "Again" functionality
 */
export const getAgainOptions = async (messageId: number): Promise<AgainOptions> => {
  const response = await httpClient.get<AgainOptions>(`/api/v1/messages/${messageId}/again-options`)
  return response.data
}

