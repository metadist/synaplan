/**
 * NV09 — J-NV-1…5 from the navigation consolidation plan.
 *
 * Each test is the journey sentence (click, type, find, undo). Provider-free:
 * Summarize stops at "tool armed and attachment shown" and does not send.
 * Higgsfield is opened and the Test control is asserted; no key is saved.
 */
import path from 'path'
import { fileURLToPath } from 'url'
import { test, expect, type Page } from '../test-setup'
import { login, loginViaApi, openApp } from '../helpers/auth'
import { selectors } from '../helpers/selectors'
import { CREDENTIALS } from '../config/credentials'
import { getRuntimeFeatures } from '../helpers/features'
import { ChatHelper, nameActiveChat, openChatManager } from '../helpers/chat'
import { FIXTURE_PATHS } from '../config/test-data'
import { TIMEOUTS, getApiUrl } from '../config/config'

const NAV = selectors.nav
const USR = selectors.userMenu
const CHAT = selectors.chat
const MEM = selectors.memories
const ADMIN = selectors.admin

// Plain text: chat upload extracts natively. A PDF would wait on Tika, which
// is integration-profile only and is not started in the default CI stack —
// the chip then stays on "processing" until the request times out and drops.
const summarizeDocument = path.join(
  path.dirname(fileURLToPath(import.meta.url)),
  '..',
  FIXTURE_PATHS.RAG_MOST_IMPORTANT
)
const summarizeDocumentName = path.basename(summarizeDocument)

async function openOperateLink(page: Page, linkSelector: string) {
  await page.locator(NAV.sidebarV2Admin).click()
  const flyout = page.locator(NAV.navDropdown)
  await expect(flyout).toBeVisible({ timeout: TIMEOUTS.SHORT })
  await flyout.locator(linkSelector).click()
}

async function openManageGroup(page: Page, groupKey: string) {
  await page.locator(NAV.sidebarV2Manage).click()
  await expect(page.locator(NAV.navDropdown)).toBeVisible({ timeout: TIMEOUTS.SHORT })
  await page.locator(NAV.flyoutGroup(groupKey)).click()
  const sub = page.locator(NAV.navSubDropdown)
  await expect(sub).toBeVisible({ timeout: TIMEOUTS.SHORT })
  return sub
}

/**
 * Same rule as `isAiAccountsEnabled()` in the app: Higgsfield counts as on
 * when the module key is missing; Anthropic BYO follows GET /messages-gateway.
 */
async function isAiAccountsNavEnabled(page: Page): Promise<boolean> {
  const runtime = (await page.request.get('/api/v1/config/runtime').then((r) => r.json())) as {
    modules?: { higgsfield?: { configured?: boolean } }
  }
  const higgsfield = runtime.modules?.higgsfield?.configured
  const higgsfieldOn = typeof higgsfield === 'boolean' ? higgsfield : true
  const gatewayRes = await page.request.get('/api/v1/messages-gateway')
  if (!gatewayRes.ok()) {
    return higgsfieldOn
  }
  const gateway = (await gatewayRes.json()) as { enabled?: boolean }
  return higgsfieldOn || gateway.enabled === true
}

test.describe('@ci Navigation journeys', () => {
  test('J-NV-1 One place for people', async ({ page, request, credentials }) => {
    await login(page, CREDENTIALS.getAdminCredentials())

    await test.step('Operate › People opens the users home', async () => {
      await openOperateLink(page, NAV.flyoutLinkAdminPeople)
      await expect(page).toHaveURL(/\/admin\/people/, { timeout: TIMEOUTS.STANDARD })
      await expect(page.locator(selectors.pages.people)).toBeVisible()
      await expect(page.locator(ADMIN.sectionUsers)).toBeVisible()
    })

    const adminCookie = await loginViaApi(request, CREDENTIALS.getAdminCredentials())
    const usersRes = await request.get(
      `${getApiUrl()}/api/v1/admin/users?search=${encodeURIComponent(credentials.user)}`,
      { headers: { Cookie: adminCookie } }
    )
    expect(usersRes.ok()).toBeTruthy()
    const body = (await usersRes.json()) as { users?: { id: number; email: string }[] }
    const worker = body.users?.find((u) => u.email === credentials.user)
    expect(worker, `Worker ${credentials.user} must exist in People`).toBeTruthy()
    const workerId = worker!.id
    const levelSelect = page.locator(ADMIN.userLevelSelect(workerId))

    await test.step('Find the user and change the level, then undo', async () => {
      await page.locator(ADMIN.userSearch).fill(credentials.user)
      await expect(levelSelect).toBeVisible({ timeout: TIMEOUTS.STANDARD })
      await expect(page.locator(ADMIN.userLevelSelectAny)).toHaveCount(1)

      const original = await levelSelect.inputValue()
      const next = original === 'PRO' ? 'TEAM' : 'PRO'
      await levelSelect.selectOption(next)
      await expect(levelSelect).toHaveValue(next)
      await levelSelect.selectOption(original)
      await expect(levelSelect).toHaveValue(original)
    })

    await test.step('Bookmark /admin?tab=users lands on the same page', async () => {
      await page.goto('/admin?tab=users', { waitUntil: 'commit' })
      await expect(page).toHaveURL(/\/admin\/people/, { timeout: TIMEOUTS.STANDARD })
      await expect(page.locator(selectors.pages.people)).toBeVisible()
      await expect(page.locator(ADMIN.sectionUsers)).toBeVisible()
    })

    await test.step('Operate dashboard has no Users tab', async () => {
      await page.goto('/admin')
      await expect(page.locator(selectors.pages.admin)).toBeVisible({
        timeout: TIMEOUTS.STANDARD,
      })
      await expect(page.locator(ADMIN.tabUsers)).toHaveCount(0)
    })
  })

  test('J-NV-2 Find an old chat', async ({ page, request }) => {
    const title = `NV09 chat ${Date.now()}`
    const chat = new ChatHelper(page)
    const sharing = (await getRuntimeFeatures(request)).iamSharing === true

    await test.step('History › Show all opens All chats', async () => {
      await openApp(page)
      await chat.startNewChat()
      // Name the chat through the API. The journey is find-and-open, not
      // rename; the row-menu click races the sheet's loadChats() refresh
      // (detached btn-chat-v2-row-menu, then a title that never appears).
      await nameActiveChat(page, title)
      const modal = await openChatManager(page)
      await expect(modal.locator(NAV.chatV2Row).filter({ hasText: title })).toHaveCount(1, {
        timeout: TIMEOUTS.STANDARD,
      })
      await page.locator(NAV.chatV2ShowAll).click()
      await expect(page).toHaveURL(/\/chats$/, { timeout: TIMEOUTS.STANDARD })
      await expect(page.locator(selectors.pages.chats)).toBeVisible()
    })

    await test.step('Search and open the chat', async () => {
      await page.locator('[data-testid="input-search-chats"]').fill(title)
      const row = page.locator('[data-testid="chat-item"]').filter({ hasText: title })
      await expect(row).toBeVisible({ timeout: TIMEOUTS.STANDARD })
      // The Open control is hover-only on desktop. The title is always
      // clickable and opens the same chat.
      await row.locator('h3').click()
      await expect(page).toHaveURL(/\/$/, { timeout: TIMEOUTS.STANDARD })
      await expect(page.locator(CHAT.textInput)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    })

    if (sharing) {
      await test.step('Incoming is a sibling tab and an Account entry', async () => {
        await page.goto('/chats')
        await expect(page.locator('[data-testid="tab-chats-incoming"]')).toBeVisible({
          timeout: TIMEOUTS.STANDARD,
        })
        await page.locator('[data-testid="tab-chats-incoming"]').click()
        await expect(page).toHaveURL(/\/chats\/incoming/, { timeout: TIMEOUTS.STANDARD })
        await expect(page.locator('[data-testid="page-chats-incoming"]')).toBeVisible()

        await page.locator(USR.button).click()
        await expect(page.locator(USR.dropdown)).toBeVisible({ timeout: TIMEOUTS.SHORT })
        await page.locator(USR.incomingBtn).click()
        await expect(page).toHaveURL(/\/chats\/incoming/, { timeout: TIMEOUTS.STANDARD })
      })
    } else {
      await test.step('IAM sharing off ⇒ no Incoming tab', async () => {
        await page.goto('/chats')
        await expect(page.locator(selectors.pages.chats)).toBeVisible({
          timeout: TIMEOUTS.STANDARD,
        })
        await expect(page.locator('[data-testid="tab-chats-incoming"]')).toHaveCount(0)
        await page.locator(USR.button).click()
        await expect(page.locator(USR.dropdown)).toBeVisible({ timeout: TIMEOUTS.SHORT })
        await expect(page.locator(USR.incomingBtn)).toHaveCount(0)
      })
    }

    await test.step('Usage stays usage-only; /statistics#chats lands on All chats', async () => {
      await page.goto('/statistics')
      await expect(page.locator(selectors.pages.statistics)).toBeVisible({
        timeout: TIMEOUTS.STANDARD,
      })
      await expect(page.locator('[data-testid="section-chat-browser"]')).toHaveCount(0)
      await expect(page.locator('[data-testid="comp-chat-browser"]')).toHaveCount(0)

      await page.goto('/statistics#chats', { waitUntil: 'commit' })
      await expect(page).toHaveURL(/\/chats$/, { timeout: TIMEOUTS.STANDARD })
      await expect(page.locator(selectors.pages.chats)).toBeVisible()
    })
  })

  test('J-NV-3 One key, one place', async ({ page, credentials }) => {
    await login(page, CREDENTIALS.getAdminCredentials())

    await test.step('Operate › Models & keys is the instance editor', async () => {
      await openOperateLink(page, NAV.flyoutLinkAdminSetup)
      await expect(page).toHaveURL(/\/admin\/setup/, { timeout: TIMEOUTS.STANDARD })
      await expect(page.locator('[data-testid="view-admin-setup"]')).toBeVisible()
      await expect(page.locator('[data-testid="admin-setup-tab-models"]')).toBeVisible()
    })

    await test.step('System configuration › AI services has status, not password fields', async () => {
      await openOperateLink(page, NAV.flyoutLinkAdminConfig)
      await expect(page.locator('[data-testid="view-admin-config"]')).toBeVisible({
        timeout: TIMEOUTS.STANDARD,
      })
      await page.locator('[data-testid="btn-config-group-ai-data"]').click()
      await page.locator('[data-testid="btn-config-tab-ai"]').click()
      await page.locator('[data-testid="btn-jump-section-cloud"]').click()
      const openaiChip = page.locator('[data-testid="managed-key-OPENAI_API_KEY"]')
      await expect(openaiChip).toBeVisible({ timeout: TIMEOUTS.STANDARD })
      const section = page.locator('#config-section-cloud')
      await expect(section).toBeVisible()
      for (const key of ['OPENAI_API_KEY', 'ANTHROPIC_API_KEY', 'GROQ_API_KEY']) {
        await expect(section.locator(`input[name="${key}"], #${key}`)).toHaveCount(0)
      }
      await expect(section.getByText('AI infrastructure › Models & keys')).toBeVisible()
      await expect(section.getByText(/chart install does not need this page/)).toBeVisible()
    })

    await login(page, credentials)
    if (!(await isAiAccountsNavEnabled(page))) {
      return
    }

    await test.step('Manage › Your AI accounts; bookmark lands on Higgsfield', async () => {
      const sub = await openManageGroup(page, 'assistants')
      await sub.locator(NAV.flyoutLinkAiAccounts).click()
      await expect(page).toHaveURL(/\/ai\/providers/, { timeout: TIMEOUTS.STANDARD })
      await expect(page.locator(selectors.pages.aiAccounts)).toBeVisible()
      await expect(page.locator('[data-testid="section-higgsfield"]')).toBeVisible()
      await page.locator('[data-testid="btn-ai-accounts-higgsfield"]').click()
      await expect(page.locator('[data-testid="btn-higgsfield-test"]')).toBeVisible()

      await page.goto('/ai/providers/higgsfield', { waitUntil: 'commit' })
      await expect(page).toHaveURL(/\/ai\/providers\?section=higgsfield/, {
        timeout: TIMEOUTS.STANDARD,
      })
      await expect(page.locator('[data-testid="btn-higgsfield-test"]')).toBeVisible()
    })
  })

  test('J-NV-4 Summarize in the chat', async ({ page }) => {
    await openApp(page)
    await expect(page.locator(CHAT.textInput)).toBeVisible({ timeout: TIMEOUTS.STANDARD })

    await test.step('Tools › Summarize a document arms the composer', async () => {
      await page.locator(CHAT.plusToggle).click()
      await expect(page.locator(CHAT.plusPanel)).toBeVisible({ timeout: TIMEOUTS.SHORT })
      await page.locator(CHAT.toolsToggle).click()
      await expect(page.locator(CHAT.toolsPanel)).toBeVisible({ timeout: TIMEOUTS.SHORT })
      await page.locator(CHAT.toolSummarize).click()
      await expect(page.locator(CHAT.summarizeOptions)).toBeVisible({
        timeout: TIMEOUTS.STANDARD,
      })
    })

    await test.step('Attach a document — do not send', async () => {
      await page.locator(CHAT.fileInput).setInputFiles(summarizeDocument)
      const chip = page.locator('[data-testid="comp-chat-input"]').getByText(summarizeDocumentName)
      await expect(chip).toBeVisible({ timeout: TIMEOUTS.STANDARD })
      await expect(page.locator('[data-testid="btn-remove-chat-file"]')).toBeEnabled({
        timeout: TIMEOUTS.LONG,
      })
      await expect(page.locator(CHAT.summarizeOptions)).toBeVisible()
    })

    await test.step('Bookmark /ai/summarizer arms the tool; no Summarizer menu', async () => {
      await page.goto('/ai/summarizer', { waitUntil: 'commit' })
      await expect(page.locator(CHAT.summarizeOptions)).toBeVisible({
        timeout: TIMEOUTS.STANDARD,
      })
      const manage = page.locator(NAV.sidebarV2Manage)
      await expect(manage).toBeVisible({ timeout: TIMEOUTS.STANDARD })
      await manage.click()
      await page.locator(NAV.flyoutGroup('assistants')).click()
      const sub = page.locator(NAV.navSubDropdown)
      await expect(sub).toBeVisible({ timeout: TIMEOUTS.SHORT })
      await expect(sub.locator('[data-testid="link-sidebar-v2-doc-summary"]')).toHaveCount(0)
    })
  })

  test('J-NV-5 Memories one way', async ({ page }) => {
    const key = `nv09_mem_${Date.now()}`
    const chat = new ChatHelper(page)

    await test.step('Account › Memories opens the page, not a dialog', async () => {
      await openApp(page)
      await page.locator(USR.button).click()
      await expect(page.locator(USR.dropdown)).toBeVisible({ timeout: TIMEOUTS.SHORT })
      await page.locator(USR.memoriesBtn).click()
      await expect(page).toHaveURL(/\/memories/, { timeout: TIMEOUTS.STANDARD })
      await expect(page.locator(MEM.page)).toBeVisible()
      await expect(page.locator('[data-testid="modal-memories-dialog"]')).toHaveCount(0)
    })

    await test.step('Highlight a memory and Back returns to the chat', async () => {
      await page.locator(MEM.btnCreate).waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })
      await page.locator(MEM.btnCreate).click()
      await page.locator(MEM.formModal).waitFor({ state: 'visible', timeout: TIMEOUTS.SHORT })
      await page.locator(MEM.btnModeAdvanced).click()
      await page.locator(MEM.inputCategory).fill('preferences')
      await page.locator(MEM.inputKey).fill(key)
      await page.locator(MEM.inputValue).fill('nv09-highlight')
      await page.locator(MEM.btnSave).click()
      await page.locator(MEM.formModal).waitFor({ state: 'hidden', timeout: TIMEOUTS.STANDARD })

      const row = page.locator(MEM.item).filter({ hasText: key }).filter({ visible: true })
      const memoryId = await row.first().getAttribute('data-memory-id')
      expect(memoryId).toBeTruthy()

      await chat.startNewChat()
      await expect(page.locator(CHAT.textInput)).toBeVisible()
      await page.goto(`/memories?highlight=${memoryId}`)
      await expect(page.locator(MEM.page)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
      await expect(
        page.locator(`[data-memory-id="${memoryId}"][data-memory-highlighted="true"]`).first()
      ).toBeVisible()
      await page.goBack()
      await expect(page.locator(CHAT.textInput)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    })

    await test.step('Mobile More › Memories is the same page', async () => {
      await page.setViewportSize({ width: 320, height: 800 })
      await page.locator(NAV.mobileDrawerToggle).click()
      await expect(page.locator(NAV.mobileDrawer)).toBeVisible({ timeout: TIMEOUTS.SHORT })
      await page.locator(NAV.mobileMore).click()
      await expect(page.locator(NAV.mobileMoreSheet)).toBeVisible({ timeout: TIMEOUTS.SHORT })
      await page.locator('[data-testid="btn-mobile-more-memories"]').click()
      await expect(page).toHaveURL(/\/memories/, { timeout: TIMEOUTS.STANDARD })
      await expect(page.locator(MEM.page)).toBeVisible()
      await expect(page.locator('[data-testid="modal-memories-dialog"]')).toHaveCount(0)
    })
  })
})
