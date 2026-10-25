import { test, expect } from '../test-setup'
import { selectors } from '../helpers/selectors'
import { login } from '../helpers/auth'
import { CREDENTIALS } from '../config/credentials'
import { TIMEOUTS } from '../config/config'

/**
 * J-AS-3 (admission sign-up, AS3): an administrator issues an invitation code
 * under Admin › People, sees it once, finds it on its row, revokes it with the
 * consequence stated, and restores it from the same row.
 *
 * Provider-free (@ci). Codes cannot be deleted (revoke keeps the row), so each
 * run uses its own label and ends with the code revoked.
 */
test.describe('@ci Admin invitation codes', () => {
  test('issue, find, revoke and restore a code from Admin › People', async ({ page }) => {
    const label = `E2E pilot ${Date.now()}`

    await login(page, CREDENTIALS.getAdminCredentials())
    await page.goto('/admin/people')
    await page.locator(selectors.people.tabSignupCodes).click()
    await expect(page).toHaveURL(/tab=signup-codes/)
    await expect(page.locator(selectors.signupCodes.section)).toBeVisible({
      timeout: TIMEOUTS.STANDARD,
    })

    let prefix = ''
    let shown = ''
    await test.step('Create shows the code once in groups of four', async () => {
      await page.locator(selectors.signupCodes.create).first().click()
      const modal = page.locator(selectors.signupCodes.modal)
      await expect(modal).toBeVisible()
      await modal.locator(selectors.signupCodes.labelInput).fill(label)
      await modal.locator(selectors.signupCodes.levelSelect).selectOption('TEAM')
      await modal.locator(selectors.signupCodes.validitySelect).selectOption('fiveMonths')
      await modal.locator(selectors.signupCodes.maxUsesInput).fill('5')
      await modal.locator(selectors.signupCodes.submit).click()

      const plain = modal.locator(selectors.signupCodes.plainCode)
      await expect(plain).toHaveText(/^[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/, {
        timeout: TIMEOUTS.STANDARD,
      })
      shown = ((await plain.textContent()) ?? '').trim()
      prefix = shown.slice(0, 4)
      await modal.locator(selectors.signupCodes.done).click()
      await expect(modal).toBeHidden()
    })

    const row = page.locator(selectors.signupCodes.row).filter({ hasText: label })
    await test.step('The row names the prefix, the grant and the limit, never the code', async () => {
      await expect(row).toHaveCount(1)
      await expect(row.locator(selectors.signupCodes.rowPrefix)).toHaveText(`${prefix}…`)
      await expect(row.locator(selectors.signupCodes.rowFacts)).toContainText('0 of 5 uses')
      await expect(row.locator(selectors.signupCodes.rowState)).toHaveText('Usable')
    })

    await test.step('A click on the code shows it again, unchanged', async () => {
      await row.locator(selectors.signupCodes.rowPrefix).click()
      await expect(row.locator(selectors.signupCodes.rowFullCode)).toHaveText(shown)
    })

    await test.step('Revoke states the consequence and marks the row', async () => {
      await row.locator(selectors.signupCodes.revoke).click()
      await expect(page.getByText('Nobody can use this code any more.')).toBeVisible()
      await page.locator(selectors.dialog.confirmBtn).click()
      await expect(row.locator(selectors.signupCodes.rowState)).toHaveText('Revoked')
    })

    await test.step('Restore is on the same row', async () => {
      await row.locator(selectors.signupCodes.restore).click()
      await expect(row.locator(selectors.signupCodes.rowState)).toHaveText('Usable')
      await row.locator(selectors.signupCodes.revoke).click()
      await page.locator(selectors.dialog.confirmBtn).click()
      await expect(row.locator(selectors.signupCodes.rowState)).toHaveText('Revoked')
    })
  })
})
