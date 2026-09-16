import { test, expect } from '../test-setup'
import { login, openApp } from '../helpers/auth'
import { isAgentsEnabled } from '../helpers/features'
import { selectors } from '../helpers/selectors'
import { CREDENTIALS } from '../config/credentials'
import { TIMEOUTS } from '../config/config'

const PAGE = '/ai/instructions'
const SEL = selectors.taskPrompts
const LEGACY_PAGE_REPLACED =
  'AGENTS.ENABLED is on: /ai/instructions forwards to the Assistants gallery, so the legacy Instructions editor is not reachable'

test.describe('@ci Task Prompts', () => {
  /**
   * The Instructions editor only exists while the Assistants flag is off
   * (`instructionsRouteGuard`). With the flag on — the seeded default — the
   * one thing left to guard is that the legacy URL lands on the gallery
   * instead of a dead page.
   */
  test('with Assistants on, /ai/instructions lands on the Assistants gallery', async ({
    page,
    request,
    credentials,
  }) => {
    test.skip(
      !(await isAgentsEnabled(request, credentials)),
      'the legacy Instructions page is served directly'
    )

    await openApp(page)
    await page.goto(PAGE)
    await expect(page).toHaveURL(/\/ai\/assistants$/, { timeout: TIMEOUTS.STANDARD })
    await expect(page.locator(selectors.assistants.gallery)).toBeVisible({
      timeout: TIMEOUTS.STANDARD,
    })
  })

  test('admin can edit AI model, rules and content on system prompt', async ({ page, request }) => {
    // The flag resolves per user (per-user and group rows win over the global
    // row), so probe with the account this test actually browses as.
    const admin = CREDENTIALS.getAdminCredentials()
    test.skip(await isAgentsEnabled(request, admin), LEGACY_PAGE_REPLACED)

    await test.step('Arrange: login as admin and pick the first card', async () => {
      await login(page, admin)
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
    request,
    credentials,
  }) => {
    test.skip(await isAgentsEnabled(request, credentials), LEGACY_PAGE_REPLACED)

    await test.step('Arrange: login and pick the first card', async () => {
      await openApp(page)
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

  test('overview shows stats and search filters cards', async ({ page, request, credentials }) => {
    test.skip(await isAgentsEnabled(request, credentials), LEGACY_PAGE_REPLACED)

    await test.step('Arrange: login and open task prompts page', async () => {
      await openApp(page)
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
      await expect(page.locator(selectors.taskPrompts.noPromptsMatch)).toBeVisible({
        timeout: TIMEOUTS.SHORT,
      })
    })

    await test.step('Act: clear filters and pick the first card', async () => {
      await page.locator(selectors.taskPrompts.btnClearFilters).click()
      const firstCard = page.locator(SEL.cardAny).first()
      await expect(firstCard).toBeVisible({ timeout: TIMEOUTS.SHORT })
      await firstCard.click()
      await expect(page.locator(SEL.promptDetails)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    })
  })

  test('user can create a custom prompt, reload, and delete it', async ({
    page,
    request,
    credentials,
  }) => {
    test.skip(await isAgentsEnabled(request, credentials), LEGACY_PAGE_REPLACED)

    // Lowercase without spaces so the topic passes the create-normalization
    // unchanged and the card testid is predictable (card-prompt-<topic>).
    const topic = `e2e-prompt-${Date.now()}`
    const promptContent = 'You are a test prompt created by an E2E test. Answer briefly.'

    await test.step('Arrange: login and open task prompts page', async () => {
      await openApp(page)
      await page.goto(PAGE)
      await expect(page.locator(SEL.overview)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    })

    await test.step('Act: create a custom prompt via the modal', async () => {
      await page.locator(SEL.btnCreate).click()
      await page.locator(SEL.createModal).waitFor({ state: 'visible', timeout: TIMEOUTS.SHORT })
      await page.locator(SEL.inputNewTopic).fill(topic)
      await page.locator(SEL.inputNewName).fill(`E2E Prompt ${topic}`)
      await page.locator(SEL.inputNewContent).fill(promptContent)
      await page.locator(SEL.btnConfirmCreate).click()
      await page.locator(SEL.createModal).waitFor({ state: 'hidden', timeout: TIMEOUTS.STANDARD })
    })

    await test.step('Assert: the new prompt opens in the editor and appears in the list', async () => {
      await expect(page.locator(SEL.promptDetails)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
      await expect(page.locator(SEL.cardForTopic(topic))).toBeVisible({
        timeout: TIMEOUTS.STANDARD,
      })
    })

    await test.step('Assert: the prompt content survives a reload', async () => {
      await page.reload()
      await expect(page.locator(SEL.overview)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
      const card = page.locator(SEL.cardForTopic(topic))
      await expect(card).toBeVisible({ timeout: TIMEOUTS.STANDARD })
      await card.click()
      await expect(page.locator(SEL.promptDetails)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
      await page.locator(SEL.tabPrompt).click()
      await expect(page.locator(SEL.content)).toHaveValue(promptContent, {
        timeout: TIMEOUTS.STANDARD,
      })
    })

    await test.step('Act: delete the prompt from the danger tab', async () => {
      await page.locator(SEL.tabDanger).click()
      await expect(page.locator(SEL.sectionDanger)).toBeVisible({ timeout: TIMEOUTS.SHORT })
      await page.locator(SEL.btnDelete).click()

      const confirmBtn = page.locator(selectors.dialog.confirmBtn)
      await confirmBtn.waitFor({ state: 'visible', timeout: TIMEOUTS.SHORT })
      await confirmBtn.click()
    })

    await test.step('Assert: the prompt is gone from the list', async () => {
      await expect(page.locator(SEL.cardForTopic(topic))).toHaveCount(0, {
        timeout: TIMEOUTS.STANDARD,
      })
    })
  })
})
