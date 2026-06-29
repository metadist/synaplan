import { z } from 'zod'
import { httpClient } from './httpClient'

export const VectorGroupSchema = z.object({
  name: z.string(),
  chunks: z.number(),
})

export const MyVectorStatsSchema = z.object({
  success: z.boolean(),
  provider: z.string(),
  available: z.boolean(),
  totalFiles: z.number(),
  totalChunks: z.number(),
  totalGroups: z.number(),
  groups: z.array(VectorGroupSchema),
})

export type MyVectorStats = z.infer<typeof MyVectorStatsSchema>

export const VectorTopUserSchema = z.object({
  userId: z.number(),
  email: z.string().nullable(),
  level: z.string().nullable(),
  files: z.number(),
  chunks: z.number(),
})

export const AdminVectorStatsSchema = z.object({
  success: z.boolean(),
  provider: z.string(),
  available: z.boolean(),
  totalUsers: z.number(),
  totalFiles: z.number(),
  totalChunks: z.number(),
  topUsers: z.array(VectorTopUserSchema),
})

export type AdminVectorStats = z.infer<typeof AdminVectorStatsSchema>

export const vectorStatsApi = {
  getMine: async (): Promise<MyVectorStats> => {
    return httpClient('/api/v1/vector-stats/me', { schema: MyVectorStatsSchema })
  },
  getAdmin: async (): Promise<AdminVectorStats> => {
    return httpClient('/api/v1/vector-stats/admin', { schema: AdminVectorStatsSchema })
  },
}
