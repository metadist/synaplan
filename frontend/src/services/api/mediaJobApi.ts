import { z } from 'zod'
import { httpClient } from '@/services/api/httpClient'

const MediaJobStatusSchema = z.object({
  job_id: z.string(),
  status: z.string(),
  state: z.enum(['running', 'done', 'failed', 'cancelled']),
  type: z.string(),
  percent: z.number().nullable().optional(),
  provider_status: z.string().nullable().optional(),
  elapsed_seconds: z.number(),
  error: z.string().nullable().optional(),
  file: z
    .object({
      url: z.string(),
      type: z.string().optional(),
      mimeType: z.string().optional(),
    })
    .nullable()
    .optional(),
  finished: z.boolean(),
  created_at: z.number().optional(),
  updated_at: z.number().optional(),
  deadline_at: z.number().nullable().optional(),
  max_wait_seconds: z.number().optional(),
  remaining_seconds: z.number().nullable().optional(),
})

const MediaJobStatusResponseSchema = z.object({
  success: z.boolean(),
  job: MediaJobStatusSchema,
})

export type MediaJobPollResult = z.infer<typeof MediaJobStatusSchema>

export async function fetchMediaJobStatus(jobId: string): Promise<MediaJobPollResult> {
  const raw = await httpClient(`/api/v1/media-jobs/${encodeURIComponent(jobId)}`, {
    method: 'GET',
  })
  const parsed = MediaJobStatusResponseSchema.parse(raw)
  return parsed.job
}
