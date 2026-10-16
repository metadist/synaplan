import { test, expect, type Page } from '../test-setup'
import { getAuthHeaders, openApp } from '../helpers/auth'
import { selectors } from '../helpers/selectors'
import { TIMEOUTS, getApiUrl } from '../config/config'

const NAV = selectors.nav
const CHAT = selectors.chat
const PROMPTS = selectors.savedPrompts
/** Every command this spec saves starts with it, so cleanup can find them. */
const COMMAND_PREFIX = 'e2enotiz'

/** Client-side navigation keeps the Pinia stores, unlike page.goto(). */
async function openPrompts(page: Page) {
  await page.locator(NAV.sidebarV2Assistants).click()
  const group = page.locator(NAV.panelGroup('assistants'))
  await expect(group).toBeVisible({ timeout: TIMEOUTS.SHORT })
  await group.locator(NAV.flyoutLinkSavedPrompts).click()
  await expect(page.locator(PROMPTS.page)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
}

/**
 * The Chats rail icon routes to the chat by itself. "New chat" would not do:
 * it navigates only after the chat list has loaded, so on a slow stack it can
 * pull the next step back from Prompts to the chat.
 */
async function backToChat(page: Page) {
  await page.locator(NAV.sidebarV2ChatNav).click()
  await expect(page).toHaveURL(/\/(\?.*)?$/, { timeout: TIMEOUTS.STANDARD })
  await expect(page.locator(CHAT.textInput)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
}

/** Type "/" into an empty composer and return the open menu. */
async function openSlashMenu(page: Page) {
  const input = page.locator(CHAT.textInput)
  await input.fill('')
  await input.pressSequentially('/')
  const palette = page.locator(CHAT.commandPalette)
  await expect(palette).toBeVisible({ timeout: TIMEOUTS.SHORT })
  return palette
}

async function closeSlashMenu(page: Page) {
  await page.locator(CHAT.textInput).fill('')
  await expect(page.locator(CHAT.commandPalette)).toBeHidden({ timeout: TIMEOUTS.SHORT })
}

async function fillPromptForm(page: Page, values: { name: string; command: string; body: string }) {
  const form = page.locator(PROMPTS.form)
  await expect(form).toBeVisible({ timeout: TIMEOUTS.SHORT })
  await form.locator(PROMPTS.nameInput).fill(values.name)
  await form.locator(PROMPTS.commandInput).fill(values.command)
  await form.locator(PROMPTS.bodyInput).fill(values.body)
  await form.locator(PROMPTS.saveBtn).click()
  await expect(form).toBeHidden({ timeout: TIMEOUTS.STANDARD })
}

async function createPrompt(page: Page, command: string) {
  await page.locator(PROMPTS.newBtn).click()
  await expect(page.locator(PROMPTS.saveBtn)).toHaveText('Save shortcut')
  await fillPromptForm(page, { name: `Note ${command}`, command, body: `Body of ${command}` })
  await expect(page.locator(PROMPTS.row).filter({ hasText: `/${command}` })).toBeVisible({
    timeout: TIMEOUTS.STANDARD,
  })
}

function promptRow(page: Page, command: string) {
  return page.locator(PROMPTS.row).filter({ hasText: `/${command}` })
}

test.describe('@ci Saved prompts and the / menu', () => {
  // Prompts belong to the worker user; clean up through its session so a
  // failed or retried run leaves nothing behind.
  test.afterEach(async ({ request, credentials }) => {
    const auth = await getAuthHeaders(request, credentials)
    const res = await request.get(`${getApiUrl()}/api/v1/saved-prompts`, { headers: auth })
    if (!res.ok()) return
    const { prompts } = (await res.json()) as { prompts?: { id: number; command: string }[] }
    for (const prompt of prompts ?? []) {
      if (prompt.command.startsWith(COMMAND_PREFIX)) {
        await request.delete(`${getApiUrl()}/api/v1/saved-prompts/${prompt.id}`, { headers: auth })
      }
    }
  })

  test('the / menu follows prompts saved, changed and deleted in the same session', async ({
    page,
  }) => {
    const stamp = Date.now().toString(36)
    const first = `${COMMAND_PREFIX}${stamp}`
    const second = `${COMMAND_PREFIX}b${stamp}`
    const renamed = `${COMMAND_PREFIX}c${stamp}`

    await test.step('Arrange: the menu has loaded once in this session', async () => {
      await openApp(page)
      const palette = await openSlashMenu(page)
      await expect(palette).not.toContainText(`/${first}`)
      await closeSlashMenu(page)
    })

    await test.step('A prompt saved on Prompts is in the next menu', async () => {
      await openPrompts(page)
      await createPrompt(page, first)
      await backToChat(page)
      await expect(await openSlashMenu(page)).toContainText(`/${first}`)
      await closeSlashMenu(page)
    })

    await test.step('A second prompt joins the first without a reload', async () => {
      await openPrompts(page)
      await createPrompt(page, second)
      await backToChat(page)
      const palette = await openSlashMenu(page)
      await expect(palette).toContainText(`/${first}`)
      await expect(palette).toContainText(`/${second}`)
      await closeSlashMenu(page)
    })

    await test.step('A changed command replaces the old one', async () => {
      await openPrompts(page)
      await promptRow(page, first).locator(PROMPTS.editBtn).click()
      await expect(page.locator(PROMPTS.commandInput)).toHaveValue(first)
      await fillPromptForm(page, {
        name: `Note ${renamed}`,
        command: renamed,
        body: 'Renamed body',
      })
      await expect(promptRow(page, renamed)).toContainText(`Note ${renamed}`)
      await expect(promptRow(page, first)).toHaveCount(0)
      await backToChat(page)
      const palette = await openSlashMenu(page)
      await expect(palette).toContainText(`/${renamed}`)
      await expect(palette).not.toContainText(`/${first}`)
      await closeSlashMenu(page)
    })

    await test.step('A deleted prompt leaves the menu', async () => {
      await openPrompts(page)
      await promptRow(page, renamed).locator(PROMPTS.deleteBtn).click()
      await page.getByTestId('btn-dialog-confirm').click()
      await expect(promptRow(page, renamed)).toHaveCount(0, { timeout: TIMEOUTS.STANDARD })
      await backToChat(page)
      const palette = await openSlashMenu(page)
      await expect(palette).toContainText(`/${second}`)
      await expect(palette).not.toContainText(`/${renamed}`)
      await closeSlashMenu(page)
    })

    await test.step('After a reload the changed prompt still inserts its text', async () => {
      await page.reload()
      await expect(page.locator(CHAT.textInput)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
      const palette = await openSlashMenu(page)
      await palette.getByText(`/${second}`).click()
      await expect(page.locator(CHAT.textInput)).toHaveValue(`Body of ${second}`)
      await page.locator(CHAT.textInput).fill('')
    })
  })
})
