import { test, expect } from '../test-setup'
import { login } from '../helpers/auth'
import { selectors } from '../helpers/selectors'
import { FIXTURE_PATHS } from '../config/test-data'
import { TIMEOUTS, INTERVALS } from '../config/config'
import { readFileSync } from 'fs'
import path from 'path'
import { fileURLToPath } from 'url'

const __filename = fileURLToPath(import.meta.url)
const __dirname = path.dirname(__filename)
const e2eDir = path.join(__dirname, '..')
const ragFixturePath = path.join(e2eDir, FIXTURE_PATHS.RAG_MOST_IMPORTANT)
const RAG_SEARCH_PHRASE = readFileSync(ragFixturePath, 'utf-8').trim()

const FILES = selectors.files
const RAG = selectors.rag
const NAV = selectors.nav

/**
 * Full flow: upload vectorized fixture, semantic search with same phrase.
 * Vectorization is async — we poll search until chunks appear.
 * Requires embeddings provider; tagged @noci (see playwright project chromium-noci).
 */
test.describe('@noci @smoke RAG Semantic Search', () => {
  test.setTimeout(TIMEOUTS.EXTREME + TIMEOUTS.VERY_LONG + TIMEOUTS.STANDARD)

  test('semantic search finds uploaded content (real AI)', async ({ page, credentials }) => {
    const fileName = path.basename(ragFixturePath)

    await login(page, credentials)

    await test.step('Arrange: Files page', async () => {
      await page.locator(NAV.sidebarV2Files).click()
      await page.locator(FILES.page).waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })
    })

    await test.step('Act: upload fixture (vectorize)', async () => {
      await page.locator(FILES.fileInput).setInputFiles(ragFixturePath)
      await page.locator(FILES.uploadButton).click()
    })

    await test.step('Assert: file visible in list (cards or table — use item-file, not table row role)', async () => {
      await page.locator(FILES.table).waitFor({ state: 'visible', timeout: TIMEOUTS.VERY_LONG })
      const rows = page.locator(FILES.fileRow)
      await expect
        .poll(
          async () => {
            const n = await rows.count()
            for (let i = 0; i < n; i += 1) {
              if ((await rows.nth(i).innerText()).includes(fileName)) return true
            }
            return false
          },
          { timeout: TIMEOUTS.VERY_LONG, intervals: INTERVALS.STANDARD() }
        )
        .toBe(true)
    })

    await test.step('Act: open /rag and run query', async () => {
      await page.goto('/rag')
      await page.locator(RAG.page).waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })
      await page.locator(RAG.queryInput).fill(RAG_SEARCH_PHRASE)
    })

    await test.step('Assert: search eventually returns chunks (wait for vectorization)', async () => {
      const errorToast = page.locator(selectors.notification.error)

      await expect
        .poll(
          async () => {
            await page.locator(RAG.searchButton).click()
            await page.locator(RAG.searchSummary).waitFor({
              state: 'visible',
              timeout: TIMEOUTS.STANDARD,
            })

            const fatal = errorToast.filter({
              hasNotText: /no results found/i,
            })
            if ((await fatal.count()) > 0) {
              const t = await fatal.first().textContent()
              throw new Error(`RAG search failed: ${t?.trim() ?? 'error toast'}`)
            }

            return page.locator(RAG.resultItem).count()
          },
          {
            timeout: TIMEOUTS.EXTREME,
            intervals: INTERVALS.STANDARD(),
          }
        )
        .toBeGreaterThan(0)
    })
  })
})
