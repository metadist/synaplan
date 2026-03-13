import { test, expect } from '../test-setup'
import { login } from '../helpers/auth'
import { selectors } from '../helpers/selectors'
import { CREDENTIALS } from '../config/credentials'
import { TIMEOUTS } from '../config/config'

const PAGE = '/config/task-prompts'
const SEL = selectors.taskPrompts

test.describe('@ci Task Prompts', () => {
  test('admin can edit AI model, rules and content on system prompt', async ({
    page,
    credentials,
  }) => {
    void credentials

    await test.step('Arrange: login as admin and select a prompt', async () => {
      await login(page, CREDENTIALS.getAdminCredentials())
      await page.goto(PAGE)
      const select = page.locator(SEL.promptSelect)
      await expect(select).toBeVisible({ timeout: TIMEOUTS.STANDARD })
      await select.selectOption({ index: 1 })
      await expect(page.locator(SEL.aiModel)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    })

    await test.step('Assert: AI model, rules and content are enabled', async () => {
      await expect(page.locator(SEL.aiModel)).toBeEnabled({ timeout: TIMEOUTS.SHORT })
      await expect(page.locator(SEL.rules)).toBeEnabled({ timeout: TIMEOUTS.SHORT })
      await expect(page.locator(SEL.content)).toBeEnabled({ timeout: TIMEOUTS.SHORT })
    })
  })

  test('non-admin can edit AI model, rules and content on system prompt', async ({
    page,
    credentials,
  }) => {
    await test.step('Arrange: login and select a prompt', async () => {
      await login(page, credentials)
      await page.goto(PAGE)
      const select = page.locator(SEL.promptSelect)
      await expect(select).toBeVisible({ timeout: TIMEOUTS.STANDARD })
      await select.selectOption({ index: 1 })
      await expect(page.locator(SEL.aiModel)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    })

    await test.step('Assert: AI model, rules and content are enabled', async () => {
      await expect(page.locator(SEL.aiModel)).toBeEnabled({ timeout: TIMEOUTS.SHORT })
      await expect(page.locator(SEL.rules)).toBeEnabled({ timeout: TIMEOUTS.SHORT })
      await expect(page.locator(SEL.content)).toBeEnabled({ timeout: TIMEOUTS.SHORT })
    })
  })
})
