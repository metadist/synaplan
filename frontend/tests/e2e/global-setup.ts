/**
 * Playwright globalSetup: runs once before all workers.
 *
 * Fails the run when the generated API schemas are stale, then sets system-wide
 * default models (ownerId=0) to TestProvider so E2E tests use deterministic AI
 * without API keys. If the backend is unavailable (e.g. UI mode without
 * services), we skip and do not throw so the UI still opens.
 */

import { createHash } from 'node:crypto'
import { existsSync, readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
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

const GENERATED_SCHEMAS = fileURLToPath(
  new URL('../../src/generated/api-schemas.ts', import.meta.url)
)
// Written by scripts/generate-schemas.js as the first line of the generated file.
const SPEC_HASH_LINE = /^\/\/ openapi-spec-sha256: ([0-9a-f]{64})\n/

/**
 * Stale schemas boot every page into the error view, so each test fails on its
 * own and nothing names the cause. CI generates them from the same commit.
 */
async function assertSchemasMatchBackend(): Promise<void> {
  if (process.env.CI || !existsSync(GENERATED_SCHEMAS)) return

  const ctx = await playwrightRequest.newContext()
  try {
    const res = await ctx.get(`${getApiUrl()}/api/doc.json`).catch(() => null)
    if (!res?.ok()) return

    const liveHash = createHash('sha256')
      .update(await res.text())
      .digest('hex')
    const generatedHash = readFileSync(GENERATED_SCHEMAS, 'utf8').match(SPEC_HASH_LINE)?.[1]
    if (generatedHash !== liveHash) {
      throw new Error(
        'Generated API schemas do not match the backend OpenAPI spec. ' +
          'Run `make -C frontend generate-schemas` and start the run again.'
      )
    }
  } finally {
    await ctx.dispose()
  }
}

export default async function globalSetup(): Promise<void> {
  await assertSchemasMatchBackend()
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
    console.warn(
      '[globalSetup] Skipped setting TestProvider defaults:',
      err instanceof Error ? err.message : String(err)
    )
  } finally {
    await ctx.dispose()
  }
}
