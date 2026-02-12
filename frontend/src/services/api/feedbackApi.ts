import { z } from 'zod'
import { httpClient } from './httpClient'

export const FalsePositiveRequestSchema = z.object({
  summary: z.string(),
  messageId: z.number().optional(),
})

export const FalsePositivePreviewRequestSchema = z.object({
  text: z.string(),
  userMessage: z.string().optional(),
})

export const FalsePositivePreviewResponseSchema = z.object({
  classification: z.enum(['memory', 'feedback']),
  summaryOptions: z.array(z.string()),
  correctionOptions: z.array(z.string()),
  relatedMemoryIds: z.array(z.number()).optional().default([]),
})

export const FalsePositiveResponseSchema = z.object({
  success: z.boolean(),
  example: z.object({
    id: z.number(),
    category: z.string(),
    key: z.string(),
    value: z.string(),
    source: z.string(),
    messageId: z.number().nullable(),
    created: z.number(),
    updated: z.number(),
  }),
})

export const PositiveFeedbackRequestSchema = z.object({
  text: z.string(),
  messageId: z.number().optional(),
})

export const PositiveFeedbackResponseSchema = FalsePositiveResponseSchema

export const RegenerateCorrectionRequestSchema = z.object({
  falseClaim: z.string(),
  oldCorrection: z.string().optional(),
})

export const RegenerateCorrectionResponseSchema = z.object({
  correction: z.string(),
})

export const CheckContradictionsRequestSchema = z.object({
  text: z.string(),
  type: z.enum(['false_positive', 'positive']),
})

export const ContradictionSchema = z.object({
  id: z.number(),
  type: z.enum(['memory', 'false_positive', 'positive']),
  value: z.string(),
  reason: z.string(),
})

export const CheckContradictionsResponseSchema = z.object({
  hasContradictions: z.boolean(),
  contradictions: z.array(ContradictionSchema),
})

export type FalsePositiveRequest = z.infer<typeof FalsePositiveRequestSchema>
export type FalsePositivePreviewRequest = z.infer<typeof FalsePositivePreviewRequestSchema>
export type PositiveFeedbackRequest = z.infer<typeof PositiveFeedbackRequestSchema>
export type RegenerateCorrectionRequest = z.infer<typeof RegenerateCorrectionRequestSchema>
export type Contradiction = z.infer<typeof ContradictionSchema>

export async function checkContradictions(
  request: z.infer<typeof CheckContradictionsRequestSchema>
): Promise<z.infer<typeof CheckContradictionsResponseSchema>> {
  return await httpClient('/api/v1/feedback/check-contradictions', {
    method: 'POST',
    body: JSON.stringify(request),
    schema: CheckContradictionsResponseSchema,
  })
}

export const CheckContradictionsBatchRequestSchema = z.object({
  summary: z.string(),
  correction: z.string(),
})

/**
 * Batch check contradictions for both summary + correction in a single API call.
 * Uses one vector search + one AI call instead of two separate checks.
 */
export async function checkContradictionsBatch(
  request: z.infer<typeof CheckContradictionsBatchRequestSchema>
): Promise<z.infer<typeof CheckContradictionsResponseSchema>> {
  return await httpClient('/api/v1/feedback/check-contradictions-batch', {
    method: 'POST',
    body: JSON.stringify(request),
    schema: CheckContradictionsResponseSchema,
  })
}

// Research sources - knowledge base (documents, feedbacks, memories)
export const KbSourceSchema = z.object({
  id: z.number(),
  sourceType: z.enum(['file', 'feedback_false', 'feedback_correct', 'memory']),
  fileName: z.string(),
  excerpt: z.string(),
  summary: z.string(),
  score: z.number(),
})

export const KbSourcesResponseSchema = z.object({
  sources: z.array(KbSourceSchema),
})

export type KbSource = z.infer<typeof KbSourceSchema>

export async function researchKbSources(text: string): Promise<{ sources: KbSource[] }> {
  return await httpClient('/api/v1/feedback/false-positive/research', {
    method: 'POST',
    body: JSON.stringify({ text }),
    schema: KbSourcesResponseSchema,
  })
}

// Research sources - web (Brave Search)
export const WebSourceSchema = z.object({
  id: z.number(),
  title: z.string(),
  url: z.string(),
  summary: z.string(),
  snippet: z.string(),
})

export const WebSourcesResponseSchema = z.object({
  sources: z.array(WebSourceSchema),
})

export type WebSource = z.infer<typeof WebSourceSchema>

export async function researchWebSources(text: string): Promise<{ sources: WebSource[] }> {
  return await httpClient('/api/v1/feedback/false-positive/web-research', {
    method: 'POST',
    body: JSON.stringify({ text }),
    schema: WebSourcesResponseSchema,
  })
}

export interface FalsePositivePreview {
  classification: 'memory' | 'feedback'
  summaryOptions: string[]
  correctionOptions: string[]
  relatedMemoryIds: number[]
}

export async function previewFalsePositive(
  request: FalsePositivePreviewRequest
): Promise<FalsePositivePreview> {
  return await httpClient('/api/v1/feedback/false-positive/preview', {
    method: 'POST',
    body: JSON.stringify(request),
    schema: FalsePositivePreviewResponseSchema,
  })
}

export async function submitFalsePositive(request: FalsePositiveRequest): Promise<void> {
  await httpClient('/api/v1/feedback/false-positive', {
    method: 'POST',
    body: JSON.stringify(request),
    schema: FalsePositiveResponseSchema,
  })
}

export async function submitPositiveFeedback(request: PositiveFeedbackRequest): Promise<void> {
  await httpClient('/api/v1/feedback/positive', {
    method: 'POST',
    body: JSON.stringify(request),
    schema: PositiveFeedbackResponseSchema,
  })
}

/**
 * Regenerate a correction based on a false claim and optional previous incorrect correction.
 * The backend handles the prompt - no prompt injection from frontend.
 */
export async function regenerateCorrection(
  request: RegenerateCorrectionRequest
): Promise<{ correction: string }> {
  return await httpClient('/api/v1/feedback/false-positive/regenerate', {
    method: 'POST',
    body: JSON.stringify(request),
    schema: RegenerateCorrectionResponseSchema,
  })
}
