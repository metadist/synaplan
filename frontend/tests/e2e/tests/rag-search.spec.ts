import { test, expect } from '../test-setup'
import { login } from '../helpers/auth'
import { selectors } from '../helpers/selectors'
import { FIXTURE_PATHS } from '../config/test-data'
import { isTestStack } from '../config/config'
import { TIMEOUTS } from '../config/config'
import { readFileSync } from 'fs'
import path from 'path'
import { fileURLToPath } from 'url'

const __filename = fileURLToPath(import.meta.url)
const __dirname = path.dirname(__filename)
const e2eDir = path.join(__dirname, '..')
const ragFixturePath = path.join(e2eDir, FIXTURE_PATHS.RAG_MOST_IMPORTANT)
const RAG_SEARCH_PHRASE = readFileSync(ragFixturePath, 'utf-8').trim()

/**
 * Full semantic search E2E: upload fixture file, search for same phrase, assert at least one result.
 * Requires real AI (embeddings); excluded from CI via @noci. Skip on test stack (TestProvider may return 0).
 */
test('@007 @noci @smoke semantic search finds uploaded content (real AI)', async ({ page }) => {
  test.skip(isTestStack(), 'Real-AI RAG test requires embedding API; run without E2E_STACK=test')
  await login(page)

  await test.step('Arrange: navigate to Files page', async () => {
    const filesBtn = page.locator('[data-testid="btn-sidebar-v2--files"]')
    await filesBtn.waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })
    await filesBtn.click()
    await page.locator(selectors.files.page).waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })
  })

  const fileName = path.basename(ragFixturePath)
  await test.step('Act: upload fixture file (most_important_thing.txt)', async () => {
    await page.locator(selectors.files.fileInput).setInputFiles(ragFixturePath)
    await page.locator(selectors.files.uploadButton).click()
  })

  await test.step('Assert: file appears in file list', async () => {
    await page.locator(selectors.files.table).waitFor({ state: 'visible', timeout: TIMEOUTS.VERY_LONG })
    await expect(
      page.getByRole('row', { name: fileName }),
    ).toBeVisible({ timeout: TIMEOUTS.VERY_LONG })
  })

  await test.step('Act: navigate to Semantic Search and run query', async () => {
    await page.goto('/rag')
    await page.locator(selectors.rag.page).waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })
    await page.locator(selectors.rag.queryInput).fill(RAG_SEARCH_PHRASE)
    await page.locator(selectors.rag.searchButton).click()
  })

  await test.step('Assert: search completes with at least one result, no error', async () => {
    const summary = page.locator(selectors.rag.searchSummary)
    const errorBanner = page.getByText(/error|failed|not found|model.*not found/i)
    const result = await Promise.race([
      summary
        .waitFor({ state: 'visible', timeout: TIMEOUTS.EXTREME })
        .then(() => 'done' as const),
      errorBanner
        .waitFor({ state: 'visible', timeout: TIMEOUTS.EXTREME })
        .then(() => 'error' as const),
    ])
    if (result === 'error') {
      const text = await errorBanner.textContent()
      throw new Error(`RAG search ended in error: ${text?.trim() ?? 'error banner visible'}`)
    }
    await expect(summary).toBeVisible()
    const results = page.locator(selectors.rag.resultItem)
    const count = await results.count()
    expect(
      count,
      'Semantic search should find at least one chunk for the uploaded phrase',
    ).toBeGreaterThan(0)
  })
})
