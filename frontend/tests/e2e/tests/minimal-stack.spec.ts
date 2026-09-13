import type { APIRequestContext } from '@playwright/test'
import path from 'path'
import { fileURLToPath } from 'url'
import { test, expect } from '../test-setup'
import { ChatHelper } from '../helpers/chat'
import { login, openApp } from '../helpers/auth'
import { selectors } from '../helpers/selectors'
import { CREDENTIALS } from '../config/credentials'
import { FIXTURE_PATHS, PROMPTS } from '../config/test-data'
import { TIMEOUTS, INTERVALS, getApiUrl } from '../config/config'

const __filename = fileURLToPath(import.meta.url)
const e2eDir = path.join(path.dirname(__filename), '..')
const fixturePath = path.join(e2eDir, FIXTURE_PATHS.RAG_MOST_IMPORTANT)
const fixtureName = path.basename(fixturePath)

const FILES = selectors.files
const NAV = selectors.nav

type ModuleState = { configured: boolean; gated: boolean }

/**
 * Intermezzo S4 — the core stack with every optional feature module absent.
 *
 * Own Playwright project (`chromium-minimal`) and CI compose overlay
 * (`docker-compose.minimal.yml`). Excluded from the sharded chromium run.
 * Higgsfield's BYO card stays visible (S3); we assert the runtime flag, not
 * that ConfigView hides the provider page.
 */
test.describe('@minimal @ci Minimal stack', () => {
  test('standard model generates an answer', async ({ page }) => {
    await openApp(page)
    const chat = new ChatHelper(page)
    await chat.startNewChat()
    const previousCount = await chat.sendMessage(PROMPTS.CHAT_SMOKE)
    const aiText = await chat.waitForAnswer(previousCount)
    expect(aiText.length).toBeGreaterThan(0)
  })

  test('uploads a plain-text file without Tika', async ({ page }) => {
    test.setTimeout(TIMEOUTS.EXTREME + TIMEOUTS.VERY_LONG)
    await openApp(page)
    await page.locator(NAV.sidebarV2Files).click()
    await page.locator(FILES.page).waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })

    await page.locator(FILES.fileInput).setInputFiles(fixturePath)
    await page.locator(FILES.uploadButton).click()

    await page.locator(FILES.table).waitFor({ state: 'visible', timeout: TIMEOUTS.VERY_LONG })
    const rows = page.locator(FILES.fileRow)
    await expect
      .poll(
        async () => {
          const n = await rows.count()
          for (let i = 0; i < n; i += 1) {
            if ((await rows.nth(i).innerText()).includes(fixtureName)) return true
          }
          return false
        },
        { timeout: TIMEOUTS.VERY_LONG, intervals: INTERVALS.STANDARD() }
      )
      .toBe(true)
  })

  test('Feature status lists every module as absent', async ({ page, request }) => {
    await login(page, CREDENTIALS.getAdminCredentials())
    await page.goto('/admin/features')
    await page.locator(selectors.pages.featureStatus).waitFor({
      state: 'visible',
      timeout: TIMEOUTS.STANDARD,
    })

    const modules = await runtimeModules(request)
    const expectedIds = Object.keys(modules).sort()
    expect(expectedIds).toHaveLength(12)

    await expect(page.locator(selectors.featureStatus.summary)).toBeVisible({
      timeout: TIMEOUTS.EXTREME,
    })
    const section = page.locator(selectors.featureStatus.modulesSection)
    await expect(section).toBeVisible({ timeout: TIMEOUTS.STANDARD })

    const rows = section.locator(selectors.featureStatus.moduleItem)
    await expect(rows).toHaveCount(expectedIds.length)

    for (const id of expectedIds) {
      const row = section.locator(`${selectors.featureStatus.moduleItem}[data-module="${id}"]`)
      await expect(row).toHaveAttribute('data-state', 'absent')
      expect(modules[id]?.configured, `runtime ${id} must be unconfigured`).toBe(false)
    }
  })

  test('WhatsApp webhook answers the uniform 404', async ({ request }) => {
    const res = await request.post(`${getApiUrl()}/api/v1/webhooks/whatsapp`, {
      data: { entry: [] },
    })
    expect(res.status()).toBe(404)
    expect(await res.json()).toEqual({
      error: 'feature_not_configured',
      module: 'whatsapp',
      docs: 'modules/whatsapp',
    })
  })

  test('Channels shows the WhatsApp notice and hides the card', async ({ page }) => {
    await openApp(page)
    await page.goto('/channels')
    await expect(page.locator(selectors.inboundConfig.page)).toBeVisible({
      timeout: TIMEOUTS.STANDARD,
    })
    await expect(page.locator(selectors.inboundConfig.whatsappNotice)).toBeVisible()
    await expect(page.locator(selectors.inboundConfig.whatsappSection)).toHaveCount(0)
    await expect(page.locator(selectors.inboundConfig.emailSection)).toBeVisible()
  })

  test('runtime config reports every module unconfigured and gated', async ({ request }) => {
    const modules = await runtimeModules(request)
    const ids = Object.keys(modules).sort()
    expect(ids).toHaveLength(12)
    expect(modules.tika?.configured).toBe(false)
    expect(modules.higgsfield?.configured).toBe(false)
    expect(modules.whatsapp?.configured).toBe(false)
    for (const id of ids) {
      expect(modules[id]?.configured, `${id} configured`).toBe(false)
      expect(modules[id]?.gated, `${id} gated`).toBe(true)
    }
  })
})

async function runtimeModules(request: APIRequestContext): Promise<Record<string, ModuleState>> {
  const res = await request.get(`${getApiUrl()}/api/v1/config/runtime`)
  expect(res.ok()).toBeTruthy()
  const body = (await res.json()) as { modules?: Record<string, ModuleState> }
  return body.modules ?? {}
}
