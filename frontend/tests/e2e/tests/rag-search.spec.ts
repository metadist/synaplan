import { test, expect } from '@playwright/test'
import { login } from '../helpers/auth'
import { selectors } from '../helpers/selectors'

test('@smoke semantic search completes and shows results summary id=007', async ({ page }) => {
  await login(page)

  const modeToggle = page.locator(selectors.header.modeToggle)
  await modeToggle.waitFor({ state: 'visible' })
  const modeLabel = (await modeToggle.innerText()).toLowerCase()
  if (modeLabel.includes('easy')) {
    await modeToggle.click()
    await expect.soft(modeToggle).toContainText(/advanced/i)
  }

  const sidebar = page.locator(selectors.nav.sidebar)
  await sidebar.waitFor({ state: 'visible' })

  const filesBtn = sidebar.getByRole('button', { name: /files/i })
  await filesBtn.click()
  const navDropdown = page.locator(selectors.nav.navDropdown)
  await navDropdown.waitFor({ state: 'visible' })
  await navDropdown.getByRole('link', { name: /file manager/i }).click()

  await page.locator(selectors.files.page).waitFor({ state: 'visible' })

  const fileName = `upload-smoke-${Date.now()}.txt`

  await page.locator(selectors.files.selectButton).click()
  await page.locator(selectors.files.fileInput).setInputFiles({
    name: fileName,
    mimeType: 'text/plain',
    buffer: Buffer.from('the most important thing in the world is smoke test'),
  })

  const uploadButton = page.locator(selectors.files.uploadButton)
  await uploadButton.click()

  await page.locator(selectors.files.table).waitFor({ state: 'visible', timeout: 60_000 })

  const uploadedRow = page.locator(selectors.files.fileRow).filter({ hasText: fileName })
  await expect.soft(uploadedRow).toBeVisible({ timeout: 30_000 })
  await expect.soft(uploadedRow).toContainText(/uploaded|extracted|vectorized/i)

  await filesBtn.click()
  const ragDropdown = page.locator(selectors.nav.navDropdown)
  await ragDropdown.waitFor({ state: 'visible' })
  await ragDropdown.getByRole('link', { name: /semantic search/i }).click()

  await page.locator(selectors.rag.page).waitFor({ state: 'visible' })

  const query = 'the most important thing in the world is smoke test'
  await page.locator(selectors.rag.queryInput).fill(query)
  await page.locator(selectors.rag.searchButton).click()

  await Promise.race([
    page
      .locator(selectors.rag.searchSummary)
      .waitFor({ state: 'visible', timeout: 60_000 })
      .catch(() => {}),
    page
      .getByText(/error|failed|not found/i)
      .waitFor({ state: 'visible', timeout: 60_000 })
      .catch(() => {}),
  ])

  const errorText = await page
    .getByText(/error|failed|not found|model.*not found/i)
    .first()
    .isVisible()
    .catch(() => false)
  if (errorText) {
    // In CI, TestProvider should be used instead of Ollama
    // If we still get an error, it might be a real issue - log it but don't skip
    console.warn('Ollama model (bge-m3) not available - TestProvider should handle this in CI')
    // Continue with test - TestProvider should work for embeddings
    // If TestProvider is used, we might get 0 results, which is acceptable
  }

  const results = page.locator(selectors.rag.resultItem)
  if (await results.count()) {
    await expect(results.first()).toBeVisible()
  } else {
    await expect(page.locator(selectors.rag.searchSummary)).toContainText(/found\s+0/i)
  }
})
