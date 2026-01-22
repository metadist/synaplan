import { httpClient } from './httpClient'
import { z } from 'zod'

// Zod Schemas (will be auto-generated from OpenAPI later)
export const UserMemorySchema = z.object({
  id: z.number(),
  category: z.string(),
  key: z.string(),
  value: z.string(),
  source: z.enum(['auto_detected', 'user_created', 'user_edited', 'ai_edited']),
  messageId: z.number().nullable(),
  created: z.number(),
  updated: z.number(),
})

export const GetMemoriesResponseSchema = z.object({
  memories: z.array(UserMemorySchema),
  total: z.number(),
})

export const GetCategoriesResponseSchema = z.object({
  categories: z.array(
    z.object({
      category: z.string(),
      count: z.number(),
    })
  ),
})

export const CreateMemoryRequestSchema = z.object({
  category: z.string(),
  key: z.string(),
  value: z.string(),
})

export const CreateMemoryResponseSchema = z.object({
  memory: UserMemorySchema,
})

export const UpdateMemoryRequestSchema = z.object({
  value: z.string(),
})

export const UpdateMemoryResponseSchema = z.object({
  memory: UserMemorySchema,
})

export const DeleteMemoryResponseSchema = z.object({
  success: z.boolean(),
  message: z.string(),
})

export const SearchMemoriesRequestSchema = z.object({
  query: z.string(),
  category: z.string().optional(),
  limit: z.number().optional(),
})

export const SearchMemoriesResponseSchema = z.object({
  memories: z.array(UserMemorySchema),
})

// Types
export type UserMemory = z.infer<typeof UserMemorySchema>
export type CreateMemoryRequest = z.infer<typeof CreateMemoryRequestSchema>
export type UpdateMemoryRequest = z.infer<typeof UpdateMemoryRequestSchema>
export type SearchMemoriesRequest = z.infer<typeof SearchMemoriesRequestSchema>

/**
 * Get all user memories (optionally filtered by category).
 */
export async function getMemories(category?: string): Promise<UserMemory[]> {
  const params = new URLSearchParams()
  if (category) {
    params.append('category', category)
  }

  const url = `/api/v1/user/memories${params.toString() ? `?${params.toString()}` : ''}`

  const data = await httpClient(url, {
    schema: GetMemoriesResponseSchema,
  })

  return data.memories
}

/**
 * Get categories with memory counts.
 */
export async function getCategories(): Promise<Array<{ category: string; count: number }>> {
  const data = await httpClient('/api/v1/user/memories/categories', {
    schema: GetCategoriesResponseSchema,
  })

  return data.categories
}

/**
 * Create a new memory.
 */
export async function createMemory(request: CreateMemoryRequest): Promise<UserMemory> {
  const data = await httpClient('/api/v1/user/memories', {
    method: 'POST',
    body: JSON.stringify(request),
    schema: CreateMemoryResponseSchema,
  })

  return data.memory
}

/**
 * Update a memory.
 */
export async function updateMemory(id: number, request: UpdateMemoryRequest): Promise<UserMemory> {
  const data = await httpClient(`/api/v1/user/memories/${id}`, {
    method: 'PUT',
    body: JSON.stringify(request),
    schema: UpdateMemoryResponseSchema,
  })

  return data.memory
}

/**
 * Delete a memory.
 */
export async function deleteMemory(id: number): Promise<void> {
  await httpClient(`/api/v1/user/memories/${id}`, {
    method: 'DELETE',
    schema: DeleteMemoryResponseSchema,
  })
}

/**
 * Search memories semantically.
 */
export async function searchMemories(request: SearchMemoriesRequest): Promise<UserMemory[]> {
  const data = await httpClient('/api/v1/user/memories/search', {
    method: 'POST',
    body: JSON.stringify(request),
    schema: SearchMemoriesResponseSchema,
  })

  return data.memories
}
