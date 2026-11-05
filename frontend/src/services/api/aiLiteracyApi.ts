import { z } from 'zod'
import { httpClient } from './httpClient'
import { useConfigStore } from '@/stores/config'

const meSchema = z.object({
  success: z.boolean(),
  required: z.boolean(),
  version: z.string(),
  due: z.boolean(),
})

const adminSchema = z.object({
  success: z.boolean(),
  required: z.boolean(),
  version: z.string(),
  confirmed: z.number(),
  total: z.number(),
  users: z.record(z.string(), z.boolean()),
})

export interface LiteracyMe {
  required: boolean
  version: string
  due: boolean
}

export interface LiteracyAdmin {
  required: boolean
  version: string
  confirmed: number
  total: number
  users: Record<string, boolean>
}

export async function myAiLiteracy(): Promise<LiteracyMe> {
  const data = await httpClient('/api/v1/me/ai-literacy', { schema: meSchema })
  return { required: data.required, version: data.version, due: data.due }
}

export async function confirmAiLiteracy(): Promise<void> {
  await httpClient('/api/v1/me/ai-literacy/confirm', { method: 'POST' })
}

export async function remindAiLiteracy(): Promise<void> {
  await httpClient('/api/v1/me/ai-literacy/remind', { method: 'POST' })
}

export async function adminAiLiteracy(ids: number[]): Promise<LiteracyAdmin> {
  const query = ids.length > 0 ? `?ids=${ids.join(',')}` : ''
  const data = await httpClient(`/api/v1/admin/ai-literacy${query}`, { schema: adminSchema })
  return {
    required: data.required,
    version: data.version,
    confirmed: data.confirmed,
    total: data.total,
    users: data.users,
  }
}

export async function setAiLiteracyRequired(required: boolean): Promise<boolean> {
  const data = await httpClient('/api/v1/admin/ai-literacy', {
    method: 'PUT',
    body: JSON.stringify({ required }),
    schema: z.object({ success: z.boolean(), required: z.boolean() }),
  })
  return data.required
}

export async function downloadAiLiteracyCsv(): Promise<void> {
  const base = useConfigStore().apiBaseUrl.replace(/\/$/, '')
  const response = await fetch(`${base}/api/v1/admin/ai-literacy/export`, {
    credentials: 'include',
  })
  if (!response.ok) {
    throw new Error('export failed')
  }
  const blob = await response.blob()
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = 'ai-literacy.csv'
  link.click()
  URL.revokeObjectURL(url)
}
