import { expect, test } from '../test-setup'
import { login } from '../helpers/auth'
import { ChatHelper } from '../helpers/chat'
import { TIMEOUTS } from '../config/config'

const DEMO = { user: 'demo@synaplan.com', pass: 'demo123' }

/**
 * J-OI-3 and J-OI-4 from _devextras/planning/20261024-open-issues/.
 * Requires the dev stack plus cloud-fixture (owncloud) and synaplan-nextcloud-dev.
 * Tagged @noci — run locally:
 * npx playwright test open-issues-walks.spec.ts --project=chromium-noci
 */
test.describe('@noci Open issues walks', () => {
  test('J-OI-3 labels an ownCloud platform link ownCloud through copy', async ({ page }) => {
    test.setTimeout(120_000)
    await login(page, DEMO)
    await page.goto('/files/generated')
    await expect(page.getByTestId('btn-file-push').first()).toBeVisible({
      timeout: TIMEOUTS.STANDARD,
    })

    await page.getByTestId('btn-file-push').first().click()
    const menu = page.getByTestId('file-push-menu-panel')
    await expect(menu).toContainText('ownCloud')
    await expect(menu).toContainText('localhost:8099')
    await expect(menu).toContainText('Nextcloud')
    await expect(menu).toContainText('localhost:8081')

    const owncloudTarget = page.locator('[data-testid^="btn-file-push-target-"]').filter({
      hasText: 'localhost:8099',
    })
    await owncloudTarget.click()

    await expect(page.getByTestId('btn-notification-action')).toHaveText('Open in ownCloud', {
      timeout: TIMEOUTS.LONG,
    })
    const sentTo = page.getByTestId('file-sent-to').first()
    await expect(sentTo).toContainText('ownCloud · localhost:8099')
    await expect(sentTo).toContainText('Open in ownCloud')
  })

  test('J-OI-4 reads a linked Nextcloud Invoices folder or says it did not', async ({ page }) => {
    test.setTimeout(180_000)
    await login(page, DEMO)
    await page.goto('/')
    const chat = new ChatHelper(page)
    await chat.startNewChat()

    const prompt =
      'Please take the invoice files from the linked cloud nextcloud at localhost:8081, folder Invoices. ' +
      'Read every invoice and give me one markdown table with vendor, invoice date, and total amount, sorted by date. ' +
      'Do not use a WebDAV folder connection for this.'

    const previousCount = await chat.sendMessage(prompt)
    const answerText = await chat.waitForAnswer(previousCount, true)

    const fixtureFacts =
      /nordwind|ostsee|1,240\.00|386\.50|2,015\.75|acme supplies|beta logistics|gamma tech/i
    const folderRead = page.getByTestId('folder-read-card')
    if (await folderRead.isVisible()) {
      await expect(page.getByTestId('folder-read-headline')).toContainText(/Invoices|Nextcloud/i)
      expect(answerText).toMatch(/nordwind|ostsee|1,240\.00|386\.50|2,015\.75/i)
      return
    }

    // The answer text is lowercased. Invoice facts without the read card are
    // invented, in either casing. The only other ending says the folder was not read.
    expect(answerText).not.toMatch(fixtureFacts)
    const normalized = answerText.toLowerCase().replace(/\u2019/g, "'")
    expect(normalized).toMatch(
      /did not read|nothing was read|could not read|wasn't able to read|can't extract|can't create the requested table/
    )
  })
})
