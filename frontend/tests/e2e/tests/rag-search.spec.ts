import { test, expect } from '../test-setup'
import { login } from '../helpers/auth'
import { selectors } from '../helpers/selectors'
import { FIXTURE_PATHS } from '../config/test-data'
import { isTestStack } from '../config/config'
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

  await test.step('Arrange: switch to advanced mode and open Files', async () => {
    const modeToggle = page.locator(selectors.header.modeToggle)
    await modeToggle.waitFor({ state: 'visible' })
    const modeLabel = (await modeToggle.innerText()).toLowerCase()
    if (modeLabel.includes('easy')) {
      await modeToggle.click()
      await expect(modeToggle).toContainText(/advanced/i)
    }
    const sidebar = page.locator(selectors.nav.sidebar)
    await sidebar.waitFor({ state: 'visible' })
    const filesLink = sidebar.getByRole('link', { name: /files/i })
    if ((await filesLink.count()) > 0) {
      await filesLink.click()
    } else {
      await sidebar.getByRole('button', { name: /files/i }).click()
      await sidebar.getByRole('link', { name: /file manager/i }).click()
    }
    await page.locator(selectors.files.page).waitFor({ state: 'visible' })
  })

  const fileName = path.basename(ragFixturePath)
  await test.step('Act: upload fixture file (most_important_thing.txt)', async () => {
    await page.locator(selectors.files.selectButton).click()
    await page.locator(selectors.files.fileInput).setInputFiles(ragFixturePath)
    await page.locator(selectors.files.uploadButton).click()
  })

  await test.step('Assert: file uploaded and vectorized', async () => {
    await page.locator(selectors.files.table).waitFor({ state: 'visible', timeout: 60_000 })
    const uploadedRow = page.locator(selectors.files.fileRow).filter({ hasText: fileName })
    await expect(uploadedRow).toBeVisible({ timeout: 30_000 })
    await expect(uploadedRow).toContainText(/uploaded|extracted|vectorized/i)
  })

  await test.step('Act: open Semantic Search and run query', async () => {
    const sidebar = page.locator(selectors.nav.sidebar)
    let ragLink = sidebar.getByRole('link', { name: /semantic search/i })
    if ((await ragLink.count()) === 0) {
      await sidebar.getByRole('button', { name: /files/i }).click()
      ragLink = sidebar.getByRole('link', { name: /semantic search/i })
    }
    await ragLink.click()
    await page.locator(selectors.rag.page).waitFor({ state: 'visible' })
    await page.locator(selectors.rag.queryInput).fill(RAG_SEARCH_PHRASE)
    await page.locator(selectors.rag.searchButton).click()
  })

  await test.step('Assert: search completes with at least one result, no error', async () => {
    const summary = page.locator(selectors.rag.searchSummary)
    const errorBanner = page.getByText(/error|failed|not found|model.*not found/i)
    const summaryVisible = summary
      .waitFor({ state: 'visible', timeout: 60_000 })
      .then(() => 'done' as const)
    const errorVisible = errorBanner
      .waitFor({ state: 'visible', timeout: 60_000 })
      .then(() => 'error' as const)
    const result = await Promise.race([summaryVisible, errorVisible])
    if (result === 'error') {
      const text = await errorBanner.textContent()
      throw new Error(`RAG search ended in error: ${text?.trim() ?? 'error banner visible'}`)
    }
    await expect(errorBanner).toBeHidden()
    await expect(summary).toBeVisible()
    const results = page.locator(selectors.rag.resultItem)
    const count = await results.count()
    expect(
      count,
      'Semantic search should find at least one chunk for the uploaded phrase'
    ).toBeGreaterThan(0)
  })
})
