import { test, expect } from '../test-setup'
import { selectors } from '../helpers/selectors'
import { login, getAuthHeaders } from '../helpers/auth'
import { TIMEOUTS, getApiUrl } from '../config/config'

const HANDLER_PREFIX = 'E2E Handler'

test.describe('@ci @smoke Inbound-Email-Handler UI', () => {
  test.afterEach(async ({ request }) => {
    const auth = await getAuthHeaders(request)
    const res = await request.get(`${getApiUrl()}/api/v1/inbound-email-handlers`, {
      headers: auth,
    })
    if (!res.ok()) return

    const { handlers } = await res.json()
    for (const h of handlers ?? []) {
      if (typeof h.name === 'string' && h.name.startsWith(HANDLER_PREFIX)) {
        await request.delete(`${getApiUrl()}/api/v1/inbound-email-handlers/${h.id}`, {
          headers: auth,
        })
      }
    }
  })

  test('create handler via wizard, verify in list, delete via UI', async ({ page }) => {
    const handlerName = `${HANDLER_PREFIX} ${Date.now()}`
    const updatedName = `${handlerName} Updated`

    await test.step('Arrange: login and navigate to mail handler page', async () => {
      await login(page)
      await page.goto('/channels/email')
      await page
        .locator(selectors.pages.tools)
        .waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })
    })

    await test.step('Act: open create wizard and fill connection step', async () => {
      await page
        .locator(selectors.mailHandler.createBtn)
        .waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })
      await page.locator(selectors.mailHandler.createBtn).click()

      await page
        .locator(selectors.mailHandler.config)
        .waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })

      await page.locator(selectors.mailHandler.inputName).fill(handlerName)
      await page.locator(selectors.mailHandler.inputMailServer).fill('imap.e2e-test.invalid')
      await page.locator(selectors.mailHandler.inputPort).fill('993')
      await page.locator(selectors.mailHandler.inputUsername).fill('e2e@test.invalid')
      await page.locator(selectors.mailHandler.inputPassword).fill('e2e-test-password')

      await page.locator(selectors.mailHandler.inputSmtpServer).fill('smtp.e2e-test.invalid')
      await page.locator(selectors.mailHandler.inputSmtpPort).fill('587')
      await page.locator(selectors.mailHandler.inputSmtpUsername).fill('e2e@test.invalid')
      await page.locator(selectors.mailHandler.inputSmtpPassword).fill('e2e-smtp-password')
    })

    await test.step('Act: advance to departments step and add a department', async () => {
      await page.locator(selectors.mailHandler.btnNext).click()

      await page
        .locator(selectors.mailHandler.sectionDepartments)
        .waitFor({ state: 'visible', timeout: TIMEOUTS.SHORT })

      await page.locator(selectors.mailHandler.btnAdd).click()

      await page
        .locator(selectors.mailHandler.inputDeptEmail)
        .waitFor({ state: 'visible', timeout: TIMEOUTS.SHORT })
      await page.locator(selectors.mailHandler.inputDeptEmail).fill('support@e2e.invalid')
      await page.locator(selectors.mailHandler.inputDeptRules).fill('E2E test department')
    })

    await test.step('Act: advance to test step and save', async () => {
      await page.locator(selectors.mailHandler.btnNext).click()
      await page.locator(selectors.mailHandler.btnSave).waitFor({
        state: 'visible',
        timeout: TIMEOUTS.SHORT,
      })
      await page.locator(selectors.mailHandler.btnSave).click()
    })

    await test.step('Assert: handler appears in the list', async () => {
      await page
        .locator(selectors.mailHandler.list)
        .waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })

      const names = page
        .locator(selectors.mailHandler.list)
        .locator(selectors.mailHandler.handlerName)
      await expect(names.filter({ hasText: handlerName })).toBeVisible({
        timeout: TIMEOUTS.STANDARD,
      })
    })

    await test.step('Act: click handler card to edit and change name', async () => {
      await page
        .locator(selectors.mailHandler.list)
        .locator(selectors.mailHandler.handlerName, { hasText: handlerName })
        .click()

      await page
        .locator(selectors.mailHandler.config)
        .waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })

      await page.locator(selectors.mailHandler.inputName).clear()
      await page.locator(selectors.mailHandler.inputName).fill(updatedName)

      await page.locator(selectors.mailHandler.btnNext).click()
      await page
        .locator(selectors.mailHandler.sectionDepartments)
        .waitFor({ state: 'visible', timeout: TIMEOUTS.SHORT })

      await page.locator(selectors.mailHandler.btnNext).click()
      await page.locator(selectors.mailHandler.btnSave).waitFor({
        state: 'visible',
        timeout: TIMEOUTS.SHORT,
      })
      await page.locator(selectors.mailHandler.btnSave).click()
    })

    await test.step('Assert: updated name visible in list', async () => {
      await page
        .locator(selectors.mailHandler.list)
        .waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })

      const names = page
        .locator(selectors.mailHandler.list)
        .locator(selectors.mailHandler.handlerName)
      await expect(names.filter({ hasText: updatedName })).toBeVisible({
        timeout: TIMEOUTS.STANDARD,
      })
    })

    await test.step('Act: delete handler via UI', async () => {
      const card = page
        .locator(selectors.mailHandler.anyHandlerCard)
        .filter({ hasText: updatedName })

      await card.hover()

      const deleteBtn = card.locator(selectors.mailHandler.anyDeleteBtn)
      await deleteBtn.waitFor({ state: 'visible', timeout: TIMEOUTS.SHORT })
      await deleteBtn.click()

      await page
        .locator(selectors.dialog.confirmBtn)
        .waitFor({ state: 'visible', timeout: TIMEOUTS.SHORT })
      await page.locator(selectors.dialog.confirmBtn).click()
    })

    await test.step('Assert: handler removed from list', async () => {
      const names = page
        .locator(selectors.mailHandler.list)
        .locator(selectors.mailHandler.handlerName)
      await expect(names.filter({ hasText: updatedName })).toBeHidden({
        timeout: TIMEOUTS.STANDARD,
      })
    })
  })
})
