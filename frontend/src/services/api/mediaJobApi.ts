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
  stalled: z.boolean().optional(),
  stall_reason: z.string().nullable().optional(),
})

const MediaJobStatusResponseSchema = z.object({
  success: z.boolean(),
  job: MediaJobStatusSchema,
})

export type MediaJobPollResult = z.infer<typeof MediaJobStatusSchema>

/** One active job as listed by the global Jobs tray (status + chat context). */
const MediaJobTrayItemSchema = MediaJobStatusSchema.extend({
  chat_id: z.number().nullable().optional(),
  message_id: z.number().nullable().optional(),
  prompt: z.string().optional(),
})

const MediaJobListResponseSchema = z.object({
  success: z.boolean(),
  jobs: z.array(MediaJobTrayItemSchema),
})

export type MediaJobTrayItem = z.infer<typeof MediaJobTrayItemSchema>

export async function fetchMediaJobStatus(jobId: string): Promise<MediaJobPollResult> {
  const raw = await httpClient(`/api/v1/media-jobs/${encodeURIComponent(jobId)}`, {
    method: 'GET',
  })
  const parsed = MediaJobStatusResponseSchema.parse(raw)
  return parsed.job
}

/** List the current user's active background jobs (global Jobs tray). */
export async function fetchActiveMediaJobs(): Promise<MediaJobTrayItem[]> {
  const raw = await httpClient('/api/v1/media-jobs', { method: 'GET' })
  return MediaJobListResponseSchema.parse(raw).jobs
}

/** Cancel a running background job. Returns the resulting status snapshot. */
export async function cancelMediaJob(jobId: string): Promise<MediaJobPollResult> {
  const raw = await httpClient(`/api/v1/media-jobs/${encodeURIComponent(jobId)}/cancel`, {
    method: 'POST',
  })
  return MediaJobStatusResponseSchema.parse(raw).job
}
