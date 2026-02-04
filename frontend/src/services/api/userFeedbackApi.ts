import { z } from 'zod'
import { httpClient } from './httpClient'

// Schemas
export const FeedbackSchema = z.object({
  id: z.number(),
  type: z.enum(['false_positive', 'positive']),
  value: z.string(),
  messageId: z.number().nullable(),
  created: z.number(),
  updated: z.number(),
})

export const ListFeedbacksResponseSchema = z.object({
  feedbacks: z.array(FeedbackSchema),
})

export const UpdateFeedbackRequestSchema = z.object({
  value: z.string().min(5),
})

export const UpdateFeedbackResponseSchema = z.object({
  success: z.boolean(),
  feedback: FeedbackSchema,
})

export const DeleteFeedbackResponseSchema = z.object({
  success: z.boolean(),
})

// Types
export type Feedback = z.infer<typeof FeedbackSchema>
export type UpdateFeedbackRequest = z.infer<typeof UpdateFeedbackRequestSchema>

/**
 * Get all feedback examples for the current user
 */
export async function getFeedbacks(
  type: 'false_positive' | 'positive' | 'all' = 'all'
): Promise<Feedback[]> {
  const params = new URLSearchParams()
  if (type !== 'all') {
    params.set('type', type)
  }

  const url = `/api/v1/feedback${params.toString() ? `?${params.toString()}` : ''}`
  const data = await httpClient(url, {
    schema: ListFeedbacksResponseSchema,
  })

  return data.feedbacks
}

/**
 * Update a feedback example
 */
export async function updateFeedback(
  id: number,
  request: UpdateFeedbackRequest
): Promise<Feedback> {
  const data = await httpClient(`/api/v1/feedback/${id}`, {
    method: 'PUT',
    body: JSON.stringify(request),
    schema: UpdateFeedbackResponseSchema,
  })

  return data.feedback
}

/**
 * Delete a feedback example
 */
export async function deleteFeedback(id: number): Promise<void> {
  await httpClient(`/api/v1/feedback/${id}`, {
    method: 'DELETE',
    schema: DeleteFeedbackResponseSchema,
  })
}
