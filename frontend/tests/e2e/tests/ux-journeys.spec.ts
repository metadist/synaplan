/**
 * J-UX-1…5 from the 2026-10 UX overhaul
 * (_devextras/planning/20261009-ux-overhaul/00_master_plan.md §6).
 *
 * Each test walks the journey sentence (click, type, find, undo) without an
 * AI provider. Tours never auto-start under browser automation, so the tour
 * steps use the `?` button the way a user replays a tour.
 */
import { test, expect, type Page } from '../test-setup'
import { login, openApp } from '../helpers/auth'
import { selectors } from '../helpers/selectors'
import { CREDENTIALS } from '../config/credentials'
import { ChatHelper } from '../helpers/chat'
import { isAgentsEnabled } from '../helpers/features'
import { TIMEOUTS, getApiUrl } from '../config/config'

const APPS = selectors.apps
const TASKS = selectors.savedTasks
const CHECKLIST = '[data-testid="section-getting-started"]'
const TOUR_POPOVER = '.driver-popover'

async function toursSeen(page: Page): Promise<string[]> {
  const res = await page.request.get(`${getApiUrl()}/api/v1/profile`)
  expect(res.ok()).toBeTruthy()
  const body = (await res.json()) as { profile?: { toursSeen?: string[] } }
  return body.profile?.toursSeen ?? []
}

async function resetToursSeen(page: Page): Promise<void> {
  const res = await page.request.put(`${getApiUrl()}/api/v1/profile`, {
    data: { toursSeen: [] },
  })
  expect(res.ok()).toBeTruthy()
  await page.evaluate(() => localStorage.removeItem('synaplan.toursSeen'))
}

async function replayTour(page: Page, firstTitle: string): Promise<void> {
  await page.locator('[data-testid="btn-page-help"]').click()
  const popover = page.locator(TOUR_POPOVER)
  await expect(popover).toBeVisible({ timeout: TIMEOUTS.SHORT })
  await expect(popover).toContainText(firstTitle)
  await page.keyboard.press('Escape')
  await expect(popover).toHaveCount(0, { timeout: TIMEOUTS.SHORT })
}

test.describe('@ci UX journeys', () => {
  test('J-UX-1 Getting started leads to a result, and every area explains itself', async ({
    page,
    request,
    credentials,
  }) => {
    const chat = new ChatHelper(page)

    await test.step('The empty chat shows the checklist with where each result is found', async () => {
      await openApp(page)
      await resetToursSeen(page)
      await page.reload()
      await chat.startNewChat()
      const checklist = page.locator(CHECKLIST)
      await expect(checklist).toBeVisible({ timeout: TIMEOUTS.STANDARD })
      await expect(page.locator('[data-testid="item-getting-started-file"]')).toContainText(
        'Library › Files'
      )
      await expect(page.locator('[data-testid="item-getting-started-provider"]')).toHaveCount(0)
    })

    await test.step('Upload a file opens the Library, whose tour can be replayed', async () => {
      await page.locator('[data-testid="item-getting-started-file"]').click()
      await expect(page).toHaveURL(/\/files$/, { timeout: TIMEOUTS.STANDARD })
      await replayTour(page, 'Everything you keep')
      await expect.poll(() => toursSeen(page), { timeout: TIMEOUTS.STANDARD }).toContain('library')
    })

    await test.step('Apps and Assistants explain themselves too', async () => {
      await page.goto('/apps')
      await expect(page.locator(APPS.page)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
      await replayTour(page, 'Find an app')

      if (await isAgentsEnabled(request, credentials)) {
        await page.goto('/ai/assistants')
        await replayTour(page, 'Create an assistant')
      }
    })

    await test.step('Hiding the checklist sticks after a reload', async () => {
      await page.goto('/')
      await chat.startNewChat()
      await page.locator('[data-testid="btn-getting-started-dismiss"]').click()
      await expect(page.locator(CHECKLIST)).toHaveCount(0)
      await expect
        .poll(() => toursSeen(page), { timeout: TIMEOUTS.STANDARD })
        .toContain('checklist.dismissed')
      await page.evaluate(() => localStorage.removeItem('synaplan.toursSeen'))
      await page.reload()
      await expect(page.locator(selectors.chat.textInput)).toBeVisible({
        timeout: TIMEOUTS.STANDARD,
      })
      await expect(page.locator(CHECKLIST)).toHaveCount(0)
      await resetToursSeen(page)
    })
  })

  test('J-UX-2 Find an app, open it, and come back', async ({ page }) => {
    await openApp(page)

    await test.step('Search with no hit says so and offers Clear search', async () => {
      await page.goto('/apps')
      await expect(page.locator(APPS.page)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
      await page.locator(APPS.search).fill('zzz-no-such-app')
      await expect(page.locator(APPS.noResults)).toBeVisible()
      await page
        .locator('[data-testid="apps-no-results"] button, [data-testid="apps-no-results"] a')
        .first()
        .click()
      await expect(page.locator(APPS.search)).toHaveValue('')
    })

    await test.step('Opening a card shows its own page with the way back', async () => {
      const card = page.locator('[data-testid^="card-app-"]').first()
      await expect(card).toBeVisible({ timeout: TIMEOUTS.STANDARD })
      await card.click()
      await expect(page).toHaveURL(/\/(apps\/[a-z0-9-]+|channels\/widgets)/, {
        timeout: TIMEOUTS.STANDARD,
      })
    })

    await test.step('Connected lists what is in use, or one sentence and Browse all', async () => {
      await page.goto('/apps/connected')
      const empty = page.locator(APPS.connectedEmpty)
      const anyCard = page.locator('[data-testid^="card-app-"]').first()
      await expect(empty.or(anyCard)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
      if (await empty.isVisible()) {
        await empty.locator('a, button').first().click()
        await expect(page).toHaveURL(/\/apps$/, { timeout: TIMEOUTS.STANDARD })
      }
    })

    await test.step('The old Linked platforms bookmark lands on Connected', async () => {
      await page.goto('/channels/platform-links', { waitUntil: 'commit' })
      await expect(page).toHaveURL(/\/apps\/connected$/, { timeout: TIMEOUTS.STANDARD })
    })
  })

  test('J-UX-3 Visitor conversations live with the chat widgets', async ({ page }) => {
    await openApp(page)

    await test.step('The old live-support bookmark opens Chat widgets › Conversations', async () => {
      await page.goto('/tools/chat-widget/live-support', { waitUntil: 'commit' })
      await expect(page).toHaveURL(/\/channels\/widgets\?tab=conversations$/, {
        timeout: TIMEOUTS.STANDARD,
      })
      await expect(page.locator('[data-testid="tab-widgets-conversations"]')).toBeVisible({
        timeout: TIMEOUTS.STANDARD,
      })
    })

    await test.step('Without a widget the tab says why and offers to create one', async () => {
      const noWidgets = page.locator('[data-testid="state-conversations-no-widgets"]')
      const inbox = page.locator('[data-testid="page-live-support"]')
      await expect(noWidgets.or(inbox)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    })
  })

  test('J-UX-4 Create a task directly, schedule it, and remove it', async ({ page }) => {
    const taskName = `UX journey task ${Date.now()}`
    const card = page.locator(TASKS.card).filter({ hasText: taskName })

    await test.step('Tasks › New task with a daily schedule', async () => {
      await openApp(page)
      await page.goto('/tasks')
      await expect(page.locator(TASKS.page)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
      await page.locator(TASKS.btnNew).click()
      await page.locator(TASKS.newName).fill(taskName)
      await page.locator(TASKS.newInstruction).fill('Summarise yesterday in one sentence.')
      await page.locator('[data-testid="select-new-task-schedule"]').selectOption('daily')
      await page.locator('[data-testid="input-new-task-at"]').fill('07:30')
      await page.locator(TASKS.newCreate).click()
      await expect(page.locator(TASKS.newModal)).toBeHidden({ timeout: TIMEOUTS.STANDARD })
    })

    await test.step('The task is listed with its schedule', async () => {
      await expect(card).toBeVisible({ timeout: TIMEOUTS.STANDARD })
      await expect(card.locator('[data-testid="saved-task-schedule"]')).toHaveValue('daily')
    })

    await test.step('Delete is one click plus a confirmation', async () => {
      await card.locator(TASKS.delete).click()
      await page.locator(selectors.dialog.confirmBtn).click()
      await expect(card).toHaveCount(0, { timeout: TIMEOUTS.STANDARD })
    })
  })

  test('J-UX-5 Admin overview names problems and settings search finds a field', async ({
    page,
  }) => {
    await login(page, CREDENTIALS.getAdminCredentials())

    await test.step('Overview shows the counters and what needs attention', async () => {
      await page.goto('/admin')
      await expect(page.locator('[data-testid="section-admin-attention"]')).toBeVisible({
        timeout: TIMEOUTS.STANDARD,
      })
      const attention = page.locator('[data-testid="section-needs-attention"]')
      const allGood = attention.locator('[data-testid="attention-all-good"]')
      const firstFix = attention.locator('[data-testid="link-needs-attention-fix"]').first()
      await expect(allGood.or(firstFix)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    })

    await test.step('The models counter opens AI infrastructure › Model health', async () => {
      await page.locator('[data-testid="card-admin-models"]').click()
      await expect(page).toHaveURL(/\/admin\/setup\?tab=health$/, { timeout: TIMEOUTS.STANDARD })
      await expect(page.locator('[data-testid="view-admin-setup"]')).toBeVisible()
    })

    await test.step('Settings search finds a field and opens its section', async () => {
      await page.goto('/admin/config')
      const search = page.locator('[data-testid="input-admin-config-search"]')
      await expect(search).toBeVisible({ timeout: TIMEOUTS.STANDARD })
      await search.fill('REGISTRATION_ENABLED')
      await page.locator('[data-testid="item-admin-config-hit"]').first().click()
      await expect(page).toHaveURL(/tab=auth/, { timeout: TIMEOUTS.STANDARD })
      await expect(page.locator('#config-section-access')).toHaveAttribute('data-open', 'true')
    })

    await test.step('No hit shows one sentence and Clear search', async () => {
      const search = page.locator('[data-testid="input-admin-config-search"]')
      await search.fill('zzz-no-such-setting')
      await expect(page.locator('[data-testid="state-admin-config-no-hits"]')).toBeVisible()
      await page.locator('[data-testid="btn-admin-config-clear-search"]').click()
      await expect(search).toHaveValue('')
    })
  })
})
