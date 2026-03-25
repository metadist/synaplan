import type { APIRequestContext } from '@playwright/test'
import { getApiUrl } from '../config/config'

export interface ModelInfo {
  id: number
  name: string
  service: string
  tag: string
  rating: number
}

type Capability = string

function apiUrl(): string {
  return getApiUrl()
}

export async function loginAndGetCookie(
  request: APIRequestContext,
  credentials: { user: string; pass: string }
): Promise<string> {
  const res = await request.post(`${apiUrl()}/api/v1/auth/login`, {
    data: { email: credentials.user, password: credentials.pass },
  })
  if (!res.ok()) {
    throw new Error(`API login failed: ${res.status()}`)
  }
  const raw = res.headers()['set-cookie'] ?? ''
  const headers = Array.isArray(raw) ? raw : [raw]
  return headers
    .map((h) => h.match(/^([^=]+)=([^;]+)/))
    .filter(Boolean)
    .map((m) => `${m![1]}=${m![2]}`)
    .join('; ')
}

export async function fetchModelsByCapability(
  request: APIRequestContext,
  cookie: string
): Promise<Record<Capability, ModelInfo[]>> {
  const res = await request.get(`${apiUrl()}/api/v1/config/models`, {
    headers: { Cookie: cookie },
  })
  if (!res.ok()) {
    throw new Error(`Failed to fetch models: ${res.status()}`)
  }
  const body = await res.json()
  if (!body.success) {
    throw new Error('Models API returned success=false')
  }
  return body.models as Record<Capability, ModelInfo[]>
}

export async function getDefaultModels(
  request: APIRequestContext,
  cookie: string
): Promise<Record<Capability, number | null>> {
  const res = await request.get(`${apiUrl()}/api/v1/config/models/defaults`, {
    headers: { Cookie: cookie },
  })
  if (!res.ok()) {
    throw new Error(`Failed to fetch defaults: ${res.status()}`)
  }
  const body = await res.json()
  return body.defaults ?? {}
}

export async function setDefaultModel(
  request: APIRequestContext,
  cookie: string,
  capability: Capability,
  modelId: number
): Promise<void> {
  const res = await request.post(`${apiUrl()}/api/v1/config/models/defaults`, {
    headers: { Cookie: cookie, 'Content-Type': 'application/json' },
    data: JSON.stringify({ defaults: { [capability]: modelId } }),
  })
  if (!res.ok()) {
    const text = await res.text()
    throw new Error(`Failed to set default for ${capability}=${modelId}: ${res.status()} ${text}`)
  }
}

export async function restoreDefaults(
  request: APIRequestContext,
  cookie: string,
  original: Record<Capability, number | null>
): Promise<void> {
  const payload: Record<string, number> = {}
  for (const [cap, id] of Object.entries(original)) {
    if (id !== null && id !== undefined) {
      payload[cap] = id
    }
  }
  if (Object.keys(payload).length === 0) return

  await request.post(`${apiUrl()}/api/v1/config/models/defaults`, {
    headers: { Cookie: cookie, 'Content-Type': 'application/json' },
    data: JSON.stringify({ defaults: payload }),
  })
}

export function isOllama(model: ModelInfo): boolean {
  return model.service.toLowerCase().includes('ollama')
}

/** Services that run locally / self-hosted — everything else is treated as cloud. */
const LOCAL_SERVICES = new Set(['ollama', 'triton', 'piper', 'test'])

export function isCloudProvider(model: ModelInfo): boolean {
  return !LOCAL_SERVICES.has(model.service.toLowerCase())
}
