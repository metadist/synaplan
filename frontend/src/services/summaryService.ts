/**
 * Summary Service - Document Summarization API
 */

import { httpClient } from './api/httpClient'

export interface SummaryConfig {
  summaryType: 'abstractive' | 'extractive' | 'bullet-points'
  length: 'short' | 'medium' | 'long' | 'custom'
  customLength?: number
  outputLanguage: string
  focusAreas: FocusArea[]
}

export type FocusArea =
  | 'main-ideas'
  | 'key-facts'
  | 'conclusions'
  | 'action-items'
  | 'numbers-dates'

export interface SummaryRequest {
  text: string
  summaryType: 'abstractive' | 'extractive' | 'bullet-points'
  length: 'short' | 'medium' | 'long' | 'custom'
  customLength?: number
  outputLanguage: string
  focusAreas: FocusArea[]
}

export interface SummaryMetadata {
  original_length: number
  summary_length: number
  compression_ratio: number
  processing_time_ms: number
  model: string
  provider: string
  configuration: {
    summary_type: string
    length: string
    output_language: string
    focus_areas: string[]
  }
}

export interface SummaryResponse {
  success: boolean
  summary?: string
  metadata?: SummaryMetadata
  error?: string
}

/**
 * Generate a summary for the provided document text
 */
export const generateSummary = async (request: SummaryRequest): Promise<SummaryResponse> => {
  try {
    const response = await httpClient<SummaryResponse>('/api/v1/summary/generate', {
      method: 'POST',
      body: JSON.stringify(request),
    })

    return response
  } catch (error: any) {
    console.error('Summary generation failed:', error)
    throw new Error(error.message || 'Failed to generate summary')
  }
}

export default {
  generateSummary,
}
