import type { Page } from '@playwright/test'
import { TIMEOUTS } from '../config/config'
import { selectors } from './selectors'

/**
 * Open the memories page and wait until its list has loaded.
 *
 * `page.goto` reloads the app, so the list request only leaves after the
 * boot requests (runtime config, session, profile, memory-service check) have
 * answered one after another. Under shard load that chain alone can use most
 * of a 10s budget, so the create button is not a safe first wait. Same rule
 * as openApp(): wait for a usable list response with VERY_LONG, which returns
 * as soon as the list lands on a warm stack.
 */
export async function openMemories(page: Page, path = '/memories'): Promise<void> {
  const listLoaded = page.waitForResponse(
    (res) =>
      new URL(res.url()).pathname.endsWith('/api/v1/user/memories') &&
      res.request().method() === 'GET' &&
      res.ok(),
    { timeout: TIMEOUTS.VERY_LONG }
  )
  await page.goto(path)
  await listLoaded
  await page
    .locator(selectors.memories.btnCreate)
    .waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })
}
