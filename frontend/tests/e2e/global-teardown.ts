/**
 * Playwright globalTeardown: runs once after all workers.
 *
 * Puts back the instance default models global-setup.ts replaced with
 * TestProvider ones, so a local dev stack does not keep answering with stubs
 * ("Test Image", test transcripts) after `make test-e2e`. CI skips it.
 */

import { request as playwrightRequest } from '@playwright/test'
import { URLS } from './config/config'
import { getAuthHeaders } from './helpers/auth'
import { restoreInstanceDefaults } from './helpers/model-defaults'
import { CREDENTIALS } from './config/credentials'

export default async function globalTeardown(): Promise<void> {
  if (process.env.CI) return

  const ctx = await playwrightRequest.newContext({ baseURL: URLS.BASE_URL })
  try {
    const authHeaders = await getAuthHeaders(ctx, CREDENTIALS.getAdminCredentials())
    await restoreInstanceDefaults(ctx, authHeaders)
  } catch (err) {
    console.warn(
      '[globalTeardown] Instance defaults were not restored; they still point at TestProvider:',
      err instanceof Error ? err.message : String(err)
    )
  } finally {
    await ctx.dispose()
  }
}
