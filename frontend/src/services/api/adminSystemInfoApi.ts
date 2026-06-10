import { z } from 'zod'
import { httpClient } from './httpClient'

export const SystemInfoSchema = z.object({
  php: z.object({
    version: z.string(),
    sapi: z.string(),
    opcacheEnabled: z.boolean(),
  }),
  memory: z.object({
    limit: z.string(),
    limitBytes: z.number(),
    currentUsageBytes: z.number(),
    peakUsageBytes: z.number(),
  }),
  limits: z.object({
    uploadMaxFilesize: z.string(),
    postMaxSize: z.string(),
    maxExecutionTime: z.number(),
  }),
  disk: z.object({
    freeBytes: z.number().nullable(),
    totalBytes: z.number().nullable(),
    usedBytes: z.number().nullable(),
    usedPercent: z.number().nullable(),
  }),
  server: z.object({
    os: z.string(),
    software: z.string().nullable(),
    hostname: z.string().nullable(),
  }),
  serverTime: z.string(),
})

export type SystemInfo = z.infer<typeof SystemInfoSchema>

export const SystemInfoResponseSchema = z.object({
  success: z.boolean(),
  system: SystemInfoSchema,
})

export type SystemInfoResponse = z.infer<typeof SystemInfoResponseSchema>

export const adminSystemInfoApi = {
  get: async (): Promise<SystemInfoResponse> => {
    return httpClient('/api/v1/admin/system-info', { schema: SystemInfoResponseSchema })
  },
}
