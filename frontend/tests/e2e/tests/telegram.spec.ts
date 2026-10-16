/**
 * Telegram channel: per-user bot, pairing, text reply, disconnect.
 * The Bot API is the telegram-stub. The test posts the webhook itself.
 */

import { test, expect } from '../test-setup'
import { getApiUrl, TIMEOUTS } from '../config/config'
import { CREDENTIALS } from '../config/credentials'
import { getAuthHeaders, login } from '../helpers/auth'
import { selectors } from '../helpers/selectors'
import {
  getTelegramStubRequests,
  resetTelegramStub,
  setTelegramStubBlocked,
  type TelegramStubRequest,
} from '../helpers/telegram-stub'

const VALID_TOKEN = '123456789:AAHexampleToken'
const UNREADABLE =
  'I cannot read this kind of message. Send text, a photo, a file or a voice message.'
const INVALID_TOKEN = '123456789:AAHINVALIDTOKEN'

test.describe('@ci @telegram Telegram channel', () => {
  test.describe.configure({ mode: 'serial' })

  test.beforeEach(async ({ request }, testInfo) => {
    const auth = await getAuthHeaders(request, CREDENTIALS.getAdminCredentials())
    const cleared = await request.delete(`${getApiUrl()}/api/v1/channels/telegram`, {
      headers: auth,
    })
    expect(cleared.status()).toBe(200)
    await resetTelegramStub(request, testInfo.testId)
  })

  test('a regular user can connect Telegram too', async ({ page, request, credentials }) => {
    await page.addInitScript(() => {
      localStorage.setItem('language', 'en')
    })
    await page.goto('/apps/telegram')
    await expect(page.locator(selectors.inboundConfig.telegramSection)).toBeVisible({
      timeout: TIMEOUTS.STANDARD,
    })
    await expect(page.getByTestId('input-telegram-token')).toBeVisible()
    await expect(page.getByTestId('badge-admin-preview')).toHaveCount(0)

    const auth = await getAuthHeaders(request, credentials)
    const channel = await request.get(`${getApiUrl()}/api/v1/channels/telegram`, { headers: auth })
    expect(channel.status()).toBe(200)
    expect(await channel.json()).toMatchObject({ success: true })
  })

  test('invalid token stays on the form as one sentence', async ({ page }) => {
    await openChannels(page)
    await page.getByTestId('input-telegram-token').fill(INVALID_TOKEN)
    await page.getByTestId('btn-telegram-connect').click()

    const error = page.getByTestId('text-telegram-error')
    await expect(error).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    await expect(error).toContainText('bot token')
    await expect(error).not.toContainText('401')
    await expect(error).not.toContainText('Unauthorized')
    await expect(page.getByTestId('input-telegram-token')).toBeVisible()
  })

  test('cancel while pairing returns to the form', async ({ page, request }, testInfo) => {
    await openChannels(page)
    await page.getByTestId('input-telegram-token').fill(VALID_TOKEN)
    await page.getByTestId('btn-telegram-connect').click()
    await expect(page.getByTestId('link-telegram-open')).toBeVisible({ timeout: TIMEOUTS.STANDARD })

    await page.getByTestId('btn-telegram-cancel').click()
    await expect(page.getByTestId('input-telegram-token')).toBeVisible({
      timeout: TIMEOUTS.STANDARD,
    })
    const recorded = await getTelegramStubRequests(request, testInfo.testId)
    expect(recorded.some((entry) => entry.path.endsWith('/deleteWebhook'))).toBe(true)
  })

  test('pair, reply, find the chat, then disconnect', async ({ page, request }, testInfo) => {
    test.setTimeout(120_000)
    await openChannels(page)
    await page.getByTestId('input-telegram-token').fill(VALID_TOKEN)
    await page.getByTestId('btn-telegram-connect').click()

    const link = page.getByTestId('link-telegram-open')
    await expect(link).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    const href = await link.getAttribute('href')
    expect(href).toContain('start=')

    const { botKey, secret } = await readWebhook(request, testInfo.testId)
    const start = new URL(href ?? 'https://t.me/x').searchParams.get('start') ?? ''
    // Handled updates are remembered per update id, so every run needs fresh ids.
    const firstUpdateId = Date.now()
    const id = (offset: number) => firstUpdateId + offset

    await postUpdate(request, botKey, secret, messageUpdate(id(1), 10, 555, `/start ${start}`))
    await expect(page.getByTestId('text-telegram-connected')).toBeVisible({
      timeout: TIMEOUTS.LONG,
    })
    await expect(page.getByTestId('btn-telegram-open-chat')).toBeVisible()

    await postUpdate(request, botKey, secret, messageUpdate(id(2), 11, 555, 'What is 2 + 2?'))
    const afterReply = await waitForTexts(
      request,
      testInfo.testId,
      (texts) => texts.length > 1 && (texts[texts.length - 1]?.length ?? 0) > 0
    )

    await postUpdate(request, botKey, secret, messageUpdate(id(2), 11, 555, 'What is 2 + 2?'))
    expect(await sendTexts(request, testInfo.testId)).toHaveLength(afterReply.length)

    await postUpdate(request, botKey, secret, unreadableUpdate(id(3), 12, 555))
    await waitForTexts(request, testInfo.testId, (texts) => texts.includes(UNREADABLE))

    await postUpdate(request, botKey, secret, messageUpdate(id(4), 13, 777, 'hello'))
    await waitForTexts(request, testInfo.testId, (texts) =>
      texts.includes('This bot only answers its owner.')
    )

    await expectTelegramThread(page)

    await page.goto('/apps/telegram')
    await page.getByTestId('btn-telegram-disconnect').click()
    await expect(page.getByText('Your chat history stays in Synaplan.')).toBeVisible()
    await page.getByTestId('btn-dialog-confirm').click()
    await expect(page.getByTestId('input-telegram-token')).toBeVisible({
      timeout: TIMEOUTS.STANDARD,
    })

    const beforeSilent = await sendTexts(request, testInfo.testId)
    await postUpdate(request, botKey, secret, messageUpdate(id(5), 14, 555, 'Are you still there?'))
    expect(await sendTexts(request, testInfo.testId)).toEqual(beforeSilent)

    await expectTelegramThread(page)
  })

  test('a blocked bot shows on the card and reconnects when the owner writes again', async ({
    page,
    request,
  }, testInfo) => {
    await openChannels(page)
    await page.getByTestId('input-telegram-token').fill(VALID_TOKEN)
    await page.getByTestId('btn-telegram-connect').click()
    const link = page.getByTestId('link-telegram-open')
    await expect(link).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    const start = new URL((await link.getAttribute('href')) ?? 'https://t.me/x').searchParams.get(
      'start'
    )
    const { botKey, secret } = await readWebhook(request, testInfo.testId)
    const firstUpdateId = Date.now()
    const id = (offset: number) => firstUpdateId + offset
    await postUpdate(request, botKey, secret, messageUpdate(id(1), 20, 555, `/start ${start}`))
    await expect(page.getByTestId('text-telegram-connected')).toBeVisible({
      timeout: TIMEOUTS.LONG,
    })

    await setTelegramStubBlocked(request, true)
    await postUpdate(request, botKey, secret, unreadableUpdate(id(2), 21, 555))
    await page.reload()
    const blocked = page.getByTestId('text-telegram-state-error')
    await expect(blocked).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    await expect(blocked).toContainText('You blocked this bot in Telegram')
    await expect(blocked.getByTestId('btn-telegram-disconnect')).toBeVisible()
    await expect(blocked.getByTestId('btn-telegram-open-chat')).toBeVisible()

    await setTelegramStubBlocked(request, false)
    await postUpdate(request, botKey, secret, unreadableUpdate(id(3), 22, 555))
    await waitForTexts(request, testInfo.testId, (texts) => texts.includes(UNREADABLE))
    await page.reload()
    await expect(page.getByTestId('text-telegram-connected')).toBeVisible({
      timeout: TIMEOUTS.STANDARD,
    })
  })

  test('photos, files, places, edits and buttons work like the web chat', async ({
    page,
    request,
  }, testInfo) => {
    test.setTimeout(180_000)
    const runId = testInfo.testId
    // Handled edits and button presses are remembered per update id, so every run needs fresh ids.
    const firstUpdateId = Date.now()
    const id = (offset: number) => firstUpdateId + offset
    const { botKey, secret } = await connectAndPair(page, request, runId, firstUpdateId)
    const post = (body: object) => postUpdate(request, botKey, secret, body)

    const commands = (await getTelegramStubRequests(request, runId)).filter((entry) =>
      entry.path.endsWith('/setMyCommands')
    )
    expect(commands.length).toBeGreaterThanOrEqual(5)

    await post(update(id(2), { message_id: 31, photo: [{ file_id: 'photo_cat' }] }))
    const photoReply = await waitForReply(request, runId, 1)
    expect(photoReply.text).not.toBe('')
    expect(callbackData(photoReply)).toEqual(
      expect.arrayContaining([expect.stringMatching(/^a:\d+$/), expect.stringMatching(/^f:\d+$/)])
    )
    const downloads = await getTelegramStubRequests(request, runId)
    expect(downloads.some((entry) => entry.path.startsWith('/file/bot'))).toBe(true)

    await post(
      update(id(3), {
        message_id: 32,
        caption: 'What is the total?',
        document: {
          file_id: 'doc_invoice',
          file_name: 'invoice.pdf',
          mime_type: 'application/pdf',
        },
      })
    )
    await waitForReply(request, runId, 2)

    await post(
      update(id(4), { message_id: 33, document: { file_id: 'huge_video', file_size: 30_000_000 } })
    )
    await waitForTexts(request, runId, (texts) =>
      texts.some((text) => text.startsWith('This file is larger than 20 MB'))
    )

    await post(update(id(5), { message_id: 34, location: { latitude: 52.52, longitude: 13.405 } }))
    await waitForReply(request, runId, 4)

    await post(update(id(6), { message_id: 35, text: 'Say hello' }))
    await waitForReply(request, runId, 5)
    await post({
      update_id: id(7),
      edited_message: {
        message_id: 35,
        from: { id: 555 },
        chat: { id: 555, type: 'private' },
        text: 'Say goodbye',
      },
    })
    await expect
      .poll(async () => (await calls(request, runId, 'editMessageText')).length, {
        timeout: 90_000,
      })
      .toBeGreaterThanOrEqual(1)

    const edited = (await calls(request, runId, 'editMessageText')).at(-1)
    const editedMarkup = (edited?.body as { reply_markup?: unknown } | null)?.reply_markup ?? null
    const again =
      callbackData({ text: '', markup: editedMarkup }).find((data) => data.startsWith('a:')) ?? ''
    expect(again).not.toBe('')
    await post(callbackUpdate(id(8), 'cb-stranger', again, 777))
    await expect
      .poll(async () => answeredTexts(request, runId), { timeout: TIMEOUTS.STANDARD })
      .toContain('This button no longer works here.')

    const editsBefore = (await calls(request, runId, 'editMessageText')).length
    await post(callbackUpdate(id(9), 'cb-owner', again, 555))
    await expect
      .poll(async () => (await calls(request, runId, 'editMessageText')).length, {
        timeout: 90_000,
      })
      .toBeGreaterThan(editsBefore)

    await post(update(id(10), { message_id: 36, text: '/help' }))
    await waitForTexts(request, runId, (texts) =>
      texts.some((text) => text.startsWith('Send text, photos, files'))
    )

    await page.goto('/')
    await telegramThreadRow(page).click()
    await expect(page.getByText('What is the total?').first()).toBeVisible({
      timeout: TIMEOUTS.STANDARD,
    })
    await expect(page.getByText('Say goodbye').first()).toBeVisible()
  })
})

async function connectAndPair(
  page: import('@playwright/test').Page,
  request: import('@playwright/test').APIRequestContext,
  runId: string,
  firstUpdateId: number
): Promise<{ botKey: string; secret: string }> {
  await openChannels(page)
  await page.getByTestId('input-telegram-token').fill(VALID_TOKEN)
  await page.getByTestId('btn-telegram-connect').click()
  const link = page.getByTestId('link-telegram-open')
  await expect(link).toBeVisible({ timeout: TIMEOUTS.STANDARD })
  const start = new URL((await link.getAttribute('href')) ?? 'https://t.me/x').searchParams.get(
    'start'
  )
  const webhook = await readWebhook(request, runId)
  await postUpdate(
    request,
    webhook.botKey,
    webhook.secret,
    messageUpdate(firstUpdateId, 30, 555, `/start ${start}`)
  )
  await expect(page.getByTestId('text-telegram-connected')).toBeVisible({
    timeout: TIMEOUTS.LONG,
  })
  return webhook
}

function callbackUpdate(updateId: number, id: string, data: string, userId: number): object {
  return {
    update_id: updateId,
    callback_query: {
      id,
      from: { id: userId },
      data,
      message: { message_id: 1, chat: { id: userId, type: 'private' } },
    },
  }
}

type Reply = { text: string; markup: unknown }

/** The nth answer after pairing: a text message or an upload, with its buttons. */
async function waitForReply(
  request: import('@playwright/test').APIRequestContext,
  runId: string,
  nth: number
): Promise<Reply> {
  let replies: Reply[] = []
  await expect
    .poll(
      async () => {
        const recorded = await getTelegramStubRequests(request, runId)
        replies = recorded
          .filter((entry) =>
            /\/(sendMessage|sendPhoto|sendDocument|sendVideo|sendVoice|sendAudio)$/.test(entry.path)
          )
          .slice(1)
          .map((entry) => {
            const body = (entry.body ?? {}) as Record<string, unknown>
            const markup =
              typeof body.reply_markup === 'string'
                ? JSON.parse(body.reply_markup)
                : (body.reply_markup ?? null)
            return { text: String(body.text ?? body.caption ?? ''), markup }
          })
        return replies.length >= nth
      },
      { timeout: 90_000, intervals: [500, 1000, 2000] }
    )
    .toBe(true)
  return replies[nth - 1] ?? { text: '', markup: null }
}

function callbackData(reply: Reply): string[] {
  const rows = (reply.markup as { inline_keyboard?: { callback_data?: string }[][] } | null)
    ?.inline_keyboard
  return (rows ?? []).flat().map((button) => button.callback_data ?? '')
}

async function calls(
  request: import('@playwright/test').APIRequestContext,
  runId: string,
  method: string
): Promise<TelegramStubRequest[]> {
  return (await getTelegramStubRequests(request, runId)).filter((entry) =>
    entry.path.endsWith(`/${method}`)
  )
}

async function answeredTexts(
  request: import('@playwright/test').APIRequestContext,
  runId: string
): Promise<string[]> {
  return (await calls(request, runId, 'answerCallbackQuery')).map((entry) =>
    String((entry.body as { text?: unknown } | null)?.text ?? '')
  )
}

function unreadableUpdate(updateId: number, messageId: number, userId: number): object {
  return update(
    updateId,
    { message_id: messageId, from: { id: userId }, game: { title: 'x' } },
    userId
  )
}

function update(updateId: number, message: Record<string, unknown>, userId = 555): object {
  return {
    update_id: updateId,
    message: { chat: { id: userId, type: 'private' }, from: { id: userId }, ...message },
  }
}

async function expectTelegramThread(page: import('@playwright/test').Page): Promise<void> {
  await page.goto('/')
  await expect(telegramThreadRow(page)).toBeVisible({ timeout: TIMEOUTS.STANDARD })
}

function telegramThreadRow(page: import('@playwright/test').Page) {
  return page
    .locator(selectors.nav.sidebarChats)
    .locator(selectors.nav.chatV2Row)
    .filter({ hasText: 'Telegram: @synaplan_test_bot' })
    .first()
}

async function openChannels(page: import('@playwright/test').Page): Promise<void> {
  await page.addInitScript(() => {
    localStorage.setItem('language', 'en')
  })
  await login(page, CREDENTIALS.getAdminCredentials())
  await page.goto('/apps/telegram')
  await page.evaluate(() => localStorage.setItem('language', 'en'))
  await page.reload()
  await expect(page.locator(selectors.inboundConfig.telegramSection)).toBeVisible({
    timeout: TIMEOUTS.STANDARD,
  })
}

async function readWebhook(
  request: import('@playwright/test').APIRequestContext,
  runId: string
): Promise<{ botKey: string; secret: string }> {
  const recorded = await getTelegramStubRequests(request, runId)
  const call = [...recorded].reverse().find((entry) => entry.path.endsWith('/setWebhook'))
  expect(call, 'setWebhook was recorded').toBeTruthy()
  const body = call?.body as { url?: string; secret_token?: string }
  const botKey = new URL(body.url ?? 'https://example.invalid').pathname
    .split('/')
    .filter(Boolean)
    .pop()
  expect(botKey).toBeTruthy()
  expect(body.secret_token).toBeTruthy()
  return { botKey: botKey ?? '', secret: body.secret_token ?? '' }
}

async function postUpdate(
  request: import('@playwright/test').APIRequestContext,
  botKey: string,
  secret: string,
  update: object
): Promise<void> {
  const res = await request.post(`${getApiUrl()}/api/v1/webhooks/telegram/${botKey}`, {
    headers: { 'X-Telegram-Bot-Api-Secret-Token': secret },
    data: update,
    timeout: 90_000,
  })
  expect(res.status()).toBe(200)
}

function messageUpdate(updateId: number, messageId: number, userId: number, text: string): object {
  return {
    update_id: updateId,
    message: {
      message_id: messageId,
      from: { id: userId },
      chat: { id: userId, type: 'private' },
      text,
    },
  }
}

async function waitForTexts(
  request: import('@playwright/test').APIRequestContext,
  runId: string,
  ready: (texts: string[]) => boolean
): Promise<string[]> {
  let latest: string[] = []
  await expect
    .poll(
      async () => {
        latest = await sendTexts(request, runId)
        return ready(latest)
      },
      { timeout: 90_000, intervals: [500, 1000, 2000] }
    )
    .toBe(true)
  return latest
}

async function sendTexts(
  request: import('@playwright/test').APIRequestContext,
  runId: string
): Promise<string[]> {
  const recorded = await getTelegramStubRequests(request, runId)
  return recorded
    .filter((entry) => entry.path.endsWith('/sendMessage'))
    .map((entry) => textOf(entry))
}

function textOf(entry: TelegramStubRequest): string {
  if (entry.body && typeof entry.body === 'object' && 'text' in entry.body) {
    const text = (entry.body as { text?: unknown }).text
    return typeof text === 'string' ? text : ''
  }
  return ''
}
