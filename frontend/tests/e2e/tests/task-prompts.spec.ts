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

    await test.step('Arrange: login as admin and pick the first card', async () => {
      await login(page, CREDENTIALS.getAdminCredentials())
      await page.goto(PAGE)

      // Wait until at least one topic card is rendered before interacting
      const firstCard = page.locator(SEL.cardAny).first()
      await expect(firstCard).toBeVisible({ timeout: TIMEOUTS.STANDARD })
      await firstCard.click()

      // Editor opens on the Routing tab — switch to Prompt to reach AI/content
      await expect(page.locator(SEL.promptDetails)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
      await page.locator(SEL.tabPrompt).click()
      await expect(page.locator(SEL.aiModel)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    })

    await test.step('Assert: AI model, rules and content are enabled', async () => {
      // Rules live on the Routing tab, AI model and content on the Prompt tab.
      await expect(page.locator(SEL.aiModel)).toBeEnabled({ timeout: TIMEOUTS.SHORT })
      await expect(page.locator(SEL.content)).toBeEnabled({ timeout: TIMEOUTS.SHORT })
      await page.locator(SEL.tabRouting).click()
      await expect(page.locator(SEL.rules)).toBeEnabled({ timeout: TIMEOUTS.SHORT })
    })
  })

  test('non-admin can edit AI model, rules and content on system prompt', async ({
    page,
    credentials,
  }) => {
    await test.step('Arrange: login and pick the first card', async () => {
      await login(page, credentials)
      await page.goto(PAGE)

      const firstCard = page.locator(SEL.cardAny).first()
      await expect(firstCard).toBeVisible({ timeout: TIMEOUTS.STANDARD })
      await firstCard.click()

      await expect(page.locator(SEL.promptDetails)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
      await page.locator(SEL.tabPrompt).click()
      await expect(page.locator(SEL.aiModel)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    })

    await test.step('Assert: AI model, rules and content are enabled', async () => {
      await expect(page.locator(SEL.aiModel)).toBeEnabled({ timeout: TIMEOUTS.SHORT })
      await expect(page.locator(SEL.content)).toBeEnabled({ timeout: TIMEOUTS.SHORT })
      await page.locator(SEL.tabRouting).click()
      await expect(page.locator(SEL.rules)).toBeEnabled({ timeout: TIMEOUTS.SHORT })
    })
  })

  test('overview shows stats, search filters cards and embedding preview reflects keywords', async ({
    page,
    credentials,
  }) => {
    await test.step('Arrange: login and open task prompts page', async () => {
      await login(page, credentials)
      await page.goto(PAGE)
      await expect(page.locator(SEL.overview)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    })

    await test.step('Assert: stat pills and at least one prompt card render', async () => {
      await expect(page.locator(SEL.statTotal)).toBeVisible()
      await expect(page.locator(SEL.statSystem)).toBeVisible()
      await expect(page.locator(SEL.cardAny).first()).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    })

    await test.step('Act: filter list with search that matches nothing', async () => {
      await page
        .locator(SEL.promptSearch)
        .fill('zzz_no_topic_should_match_this_arbitrary_string_xyz')
      await expect(page.locator('[data-testid="text-no-prompts-match"]')).toBeVisible({
        timeout: TIMEOUTS.SHORT,
      })
    })

    await test.step('Act: clear filters and pick the first card', async () => {
      await page.locator('[data-testid="btn-clear-filters"]').click()
      const firstCard = page.locator(SEL.cardAny).first()
      await expect(firstCard).toBeVisible({ timeout: TIMEOUTS.SHORT })
      await firstCard.click()
      await expect(page.locator(SEL.promptDetails)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    })

    await test.step('Assert: typing into Keywords updates the embedding preview', async () => {
      await page.locator(SEL.keywords).fill('e2e-keyword-marker, another-marker')
      await expect(page.locator(SEL.embeddingPreview)).toContainText('e2e-keyword-marker', {
        timeout: TIMEOUTS.SHORT,
      })
    })
  })
})
