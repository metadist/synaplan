/**
 * Playwright globalSetup: runs once before all workers.
 *
 * Sets system-wide default models (ownerId=0) to TestProvider so E2E tests use
 * deterministic AI without API keys. If the backend is unavailable (e.g. UI mode
 * without services), we skip and do not throw so the UI still opens.
 */

import { request as playwrightRequest } from '@playwright/test'
import { getApiUrl, URLS } from './config/config'
import { getAuthHeaders } from './helpers/auth'
import { CREDENTIALS } from './config/credentials'

const DEFAULTS_PATH = '/api/v1/config/models/defaults'

const TEST_PROVIDER_DEFAULTS: Record<string, number> = {
  CHAT: -1,
  SORT: -1,
  VECTORIZE: -2,
  PIC2TEXT: -3,
  TEXT2PIC: -4,
  TEXT2VID: -5,
  SOUND2TEXT: -6,
  TEXT2SOUND: -7,
  ANALYZE: -1,
}

export default async function globalSetup(): Promise<void> {
  const ctx = await playwrightRequest.newContext({ baseURL: URLS.BASE_URL })
  try {
    const authHeaders = await getAuthHeaders(ctx, CREDENTIALS.getAdminCredentials())
    const res = await ctx.post(`${getApiUrl()}${DEFAULTS_PATH}`, {
      headers: authHeaders,
      data: { defaults: TEST_PROVIDER_DEFAULTS, global: true },
    })
    if (!res.ok()) {
      throw new Error(
        `globalSetup: set TestProvider defaults failed: ${res.status()} ${await res.text()}`
      )
    }
  } catch (err) {
    // Do not throw: allow UI mode to open when backend is not running (e.g. local dev).
    console.warn(
      '[globalSetup] Skipped setting TestProvider defaults:',
      err instanceof Error ? err.message : String(err)
    )
  } finally {
    await ctx.dispose()
  }
}
