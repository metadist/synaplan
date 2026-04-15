import type { APIRequestContext } from '@playwright/test'
import { getAuthHeaders } from './auth'
import { getApiUrl } from '../config/config'

/**
 * Seeded in dev/test by ModelFixtures (negative IDs). Maps to TestProvider — deterministic
 * embeddings, no external API (see backend `TestProvider::embed` / `embedBatch`).
 */
export const E2E_TEST_VECTORIZE_MODEL_ID = -2

type Credentials = { user: string; pass: string }

/**
 * Set this user's DEFAULTMODEL/VECTORIZE to TestProvider so file uploads vectorize without
 * Ollama/real embedding APIs. Uses POST /api/v1/config/models/defaults (non-global — any
 * logged-in user). Call after worker registration, before or after browser login.
 */
export async function ensureUserVectorizeTestProvider(
  request: APIRequestContext,
  credentials: Credentials
): Promise<void> {
  const headers = await getAuthHeaders(request, credentials)
  const res = await request.post(`${getApiUrl()}/api/v1/config/models/defaults`, {
    headers,
    data: {
      defaults: { VECTORIZE: E2E_TEST_VECTORIZE_MODEL_ID },
      global: false,
    },
  })
  if (!res.ok()) {
    const text = await res.text()
    throw new Error(
      `ensureUserVectorizeTestProvider: POST /api/v1/config/models/defaults failed ` +
        `${res.status()}: ${text}`
    )
  }
}
