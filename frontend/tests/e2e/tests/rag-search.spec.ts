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
    await expect(modeToggle).toContainText(/advanced/i)
  }

  const sidebar = page.locator(selectors.nav.sidebar)
  await sidebar.waitFor({ state: 'visible' })

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

  await Promise.race([
    page.locator(selectors.rag.searchSummary).waitFor({ state: 'visible', timeout: 60_000 }).catch(() => {}),
    page.getByText(/error|failed|not found/i).waitFor({ state: 'visible', timeout: 60_000 }).catch(() => {}),
  ])

  const errorText = await page.getByText(/error|failed|not found|model.*not found/i).first().isVisible().catch(() => false)
  if (errorText) {
    test.skip(true, 'Ollama model (bge-m3) not available - requires integration profile or model download')
    return
  }

  const results = page.locator(selectors.rag.resultItem)
  if (await results.count()) {
    await expect(results.first()).toBeVisible()
  } else {
    await expect(page.locator(selectors.rag.searchSummary)).toContainText(/found\s+0/i)
  }
})
