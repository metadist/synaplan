import { test, expect } from '../test-setup'
import { login } from '../helpers/auth'
import { selectors } from '../helpers/selectors'

test('@007 @noci @smoke semantic search completes and shows results summary', async ({
  page,
}) => {
  await login(page)

  // RAG search is in sidebar only in advanced mode
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
  if (await filesLink.count()) {
    await filesLink.first().click()
  } else {
    const filesToggle = sidebar.getByRole('button', { name: /files/i })
    await filesToggle.click()
    await sidebar.getByRole('link', { name: /file manager/i }).click()
  }

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
  await expect(uploadedRow).toBeVisible({ timeout: 30_000 })
  await expect(uploadedRow).toContainText(/uploaded|extracted|vectorized/i)

  let ragLink = sidebar.getByRole('link', { name: /semantic search/i })
  if ((await ragLink.count()) === 0) {
    const filesToggle = sidebar.getByRole('button', { name: /files/i })
    await filesToggle.click()
    ragLink = sidebar.getByRole('link', { name: /semantic search/i })
  }
  await ragLink.first().click()

  await page.locator(selectors.rag.page).waitFor({ state: 'visible' })

  const query = 'the most important thing in the world is smoke test'
  await page.locator(selectors.rag.queryInput).fill(query)
  await page.locator(selectors.rag.searchButton).click()

  const errorBanner = page.getByText(/error|failed|not found|model.*not found/i)
  await page.locator(selectors.rag.searchSummary).waitFor({ state: 'visible', timeout: 60_000 })
  await expect(errorBanner).toBeHidden()

  const results = page.locator(selectors.rag.resultItem)
  if (await results.count()) {
    await expect(results.first()).toBeVisible()
  } else {
    await expect(page.locator(selectors.rag.searchSummary)).toContainText(/found\s+0/i)
  }
})
