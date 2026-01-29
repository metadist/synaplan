import { z } from 'zod'
import { httpClient } from './httpClient'

export const FalsePositiveRequestSchema = z.object({
  summary: z.string(),
  messageId: z.number().optional(),
})

export const FalsePositivePreviewRequestSchema = z.object({
  text: z.string(),
})

export const FalsePositivePreviewResponseSchema = z.object({
  summary: z.string(),
  correction: z.string(),
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

export type FalsePositiveRequest = z.infer<typeof FalsePositiveRequestSchema>
export type FalsePositivePreviewRequest = z.infer<typeof FalsePositivePreviewRequestSchema>
export type PositiveFeedbackRequest = z.infer<typeof PositiveFeedbackRequestSchema>

export interface FalsePositivePreview {
  summary: string
  correction: string
}

export async function previewFalsePositive(
  request: FalsePositivePreviewRequest
): Promise<FalsePositivePreview> {
  const data = await httpClient('/api/v1/feedback/false-positive/preview', {
    method: 'POST',
    body: JSON.stringify(request),
    schema: FalsePositivePreviewResponseSchema,
  })

  return {
    summary: data.summary,
    correction: data.correction,
  }
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

