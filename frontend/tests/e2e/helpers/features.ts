import type { APIRequestContext } from '@playwright/test'
import { getApiUrl } from '../config/config'
import { getAuthHeaders } from './auth'

/**
 * The `features` block of GET /api/v1/config/runtime — the same object the
 * SPA gates its routes on. Uses the context's own session (every test
 * context is signed in as its worker user) unless credentials are given.
 *
 * Feature flags are seeded ON and can be pinned per deployment
 * (docs/FEATURE_FLAGS.md), so a spec must not assume either state. Read the
 * flag and branch, the way `admin-panel.spec.ts` follows the IAM groups flag.
 */
export async function getRuntimeFeatures(
  request: APIRequestContext,
  credentials?: { user: string; pass: string }
): Promise<Record<string, boolean>> {
  const headers = credentials ? await getAuthHeaders(request, credentials) : undefined
  const res = await request.get(`${getApiUrl()}/api/v1/config/runtime`, { headers })
  if (!res.ok()) {
    throw new Error(`runtime config failed: ${res.status()} ${await res.text()}`)
  }
  const body = (await res.json()) as { features?: Record<string, boolean> }
  return body.features ?? {}
}

/**
 * With AGENTS.ENABLED on, /ai/instructions redirects to the Assistants gallery
 * (`instructionsRouteGuard`), so the legacy Instructions page is unreachable.
 */
export async function isAgentsEnabled(
  request: APIRequestContext,
  credentials?: { user: string; pass: string }
): Promise<boolean> {
  return (await getRuntimeFeatures(request, credentials)).agentsEnabled === true
}
