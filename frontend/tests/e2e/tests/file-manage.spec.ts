import path from 'path'
import { fileURLToPath } from 'url'
import { test, expect } from '../test-setup'
import { selectors } from '../helpers/selectors'
import { openApp } from '../helpers/auth'
import { FIXTURE_PATHS } from '../config/test-data'
import { TIMEOUTS } from '../config/config'

const __filename = fileURLToPath(import.meta.url)
const e2eDir = path.join(path.dirname(__filename), '..')
const fixturePath = path.join(e2eDir, FIXTURE_PATHS.RAG_MOST_IMPORTANT)
const fixtureName = path.basename(fixturePath)

const FILES = selectors.files
const CHAT = selectors.chat

/**
 * Knowledge-folder management: create a folder, upload into it, scope a chat
 * to it ("Use in chat"), delete the contained file. Complements
 * rag-search.spec.ts, which covers upload + semantic search but no
 * management actions.
 */
test.describe('@ci File Management', () => {
  test('user can create a folder, upload into it, use it in chat and delete the file', async ({
    page,
  }) => {
    // Upload + vectorization plus create/open/use-in-chat/delete navigations
    // routinely push this past the 60s default under CI load — the recorded
    // flake was always "Test timeout of 60000ms exceeded". Give the same
    // headroom rag-search.spec.ts uses for the upload path.
    test.setTimeout(TIMEOUTS.EXTREME + TIMEOUTS.VERY_LONG)
    const folderName = `e2e-folder-${Date.now()}`

    await test.step('Arrange: open the Files page', async () => {
      await openApp(page)
      await page.locator(selectors.nav.sidebarV2Files).click()
      await page.locator(FILES.page).waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })
    })

    await test.step('Act: create a new knowledge folder', async () => {
      await page.locator(FILES.btnNewFolder).click()
      const nameInput = page.locator(FILES.inputNewFolderToolbar)
      await nameInput.waitFor({ state: 'visible', timeout: TIMEOUTS.SHORT })
      await nameInput.fill(folderName)
      await page.locator(FILES.btnNewFolderCreate).click()
      // The new (empty) folder appears as a pending card and is preselected
      // as the upload target.
      await expect(page.locator(FILES.folderCard(folderName))).toBeVisible({
        timeout: TIMEOUTS.SHORT,
      })
    })

    await test.step('Act: upload the fixture into the folder', async () => {
      await page.locator(FILES.fileInput).setInputFiles(fixturePath)
      await page.locator(FILES.uploadButton).click()
    })

    await test.step('Assert: the folder contains the uploaded file', async () => {
      await page.locator(FILES.folderCard(folderName)).click()
      await page.locator(FILES.table).waitFor({ state: 'visible', timeout: TIMEOUTS.VERY_LONG })
      // Each file renders twice (mobile card + desktop table row, one hidden
      // via CSS) — count only the visible representation. VERY_LONG because the
      // row only appears once the upload+vectorize round-trip finishes, which
      // can lag well past STANDARD under CI load.
      await expect(
        page.locator(FILES.fileRow).filter({ hasText: fixtureName }).filter({ visible: true })
      ).toHaveCount(1, { timeout: TIMEOUTS.VERY_LONG })
    })

    await test.step('Act: "Use in chat" opens a chat scoped to the folder', async () => {
      await page.locator(FILES.btnBackToRoot).click()
      const card = page.locator(FILES.folderCard(folderName))
      await card.waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })
      await card.hover()
      await page.locator(FILES.btnUseInChat(folderName)).click({ force: true })
      await page.locator(CHAT.textInput).waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })
    })

    await test.step('Assert: the knowledge-folder pill shows the folder', async () => {
      await page.locator(CHAT.plusToggle).click()
      const panel = page.locator(CHAT.plusPanel)
      await panel.waitFor({ state: 'visible', timeout: TIMEOUTS.SHORT })
      await expect(panel.locator(CHAT.knowledgeFolderBtn)).toContainText(folderName, {
        timeout: TIMEOUTS.STANDARD,
      })
    })

    await test.step('Act: delete the file inside the folder', async () => {
      await page.goto('/files')
      await page.locator(FILES.page).waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })
      await page.locator(FILES.folderCard(folderName)).click()
      const row = page
        .locator(FILES.fileRow)
        .filter({ hasText: fixtureName })
        .filter({ visible: true })
      await row.waitFor({ state: 'visible', timeout: TIMEOUTS.VERY_LONG })
      await row.locator(FILES.btnDeleteFile).click()
      await page.locator(selectors.confirmDialog.accept).click()
    })

    await test.step('Assert: the folder is empty and disappears from the root grid', async () => {
      await page
        .locator(FILES.stateEmptyFolder)
        .waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })
      await page.locator(FILES.btnBackToRoot).click()
      await expect(page.locator(FILES.folderCard(folderName))).toHaveCount(0, {
        timeout: TIMEOUTS.STANDARD,
      })
    })
  })
})
