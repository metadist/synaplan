export interface SummaryConfig {
  summaryType: 'abstractive' | 'extractive' | 'bullet-points'
  length: 'short' | 'medium' | 'long' | 'custom'
  customLength?: number
  outputLanguage: string
  focusAreas: FocusArea[]
}

export type FocusArea = 'main-ideas' | 'key-facts' | 'conclusions' | 'action-items'

export interface SummaryResult {
  id: string
  originalText: string
  summary: string
  config: SummaryConfig
  createdAt: Date
  wordCount: number
  tokenCount: number
}

export const summaryTypes = [
  { value: 'abstractive', label: 'Abstractive Summary' },
  { value: 'extractive', label: 'Extractive Summary' },
  { value: 'bullet-points', label: 'Bullet Points' },
]

export const summaryLengths = [
  { value: 'short', label: 'Short (50-150 words)' },
  { value: 'medium', label: 'Medium (200-500 words)' },
  { value: 'long', label: 'Long (500-1000 words)' },
  { value: 'custom', label: 'Custom Length' },
]

export const outputLanguages = [
  { value: 'en', label: 'English' },
  { value: 'de', label: 'German' },
  { value: 'fr', label: 'French' },
  { value: 'es', label: 'Spanish' },
]

export const focusAreaOptions = [
  { value: 'main-ideas', label: 'Main Ideas' },
  { value: 'key-facts', label: 'Key Facts' },
  { value: 'conclusions', label: 'Conclusions' },
  { value: 'action-items', label: 'Action Items' },
]

export const mockSummaries: SummaryResult[] = []
