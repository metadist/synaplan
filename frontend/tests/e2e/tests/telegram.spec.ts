/**
 * Telegram channel: per-user bot, pairing, text reply, disconnect.
 * The Bot API is the telegram-stub. The test posts the webhook itself.
 */

import { test, expect } from '../test-setup'
import { getApiUrl, TIMEOUTS } from '../config/config'
import { getAuthHeaders } from '../helpers/auth'
import { selectors } from '../helpers/selectors'
import {
  getTelegramStubRequests,
  resetTelegramStub,
  setTelegramStubBlocked,
  type TelegramStubRequest,
} from '../helpers/telegram-stub'

const VALID_TOKEN = '123456789:AAHexampleToken'
const INVALID_TOKEN = '123456789:AAHINVALIDTOKEN'

test.describe('@ci @telegram Telegram channel', () => {
  test.describe.configure({ mode: 'serial' })

  test.beforeEach(async ({ request, credentials }, testInfo) => {
    const auth = await getAuthHeaders(request, credentials)
    const cleared = await request.delete(`${getApiUrl()}/api/v1/channels/telegram`, {
      headers: auth,
    })
    expect(cleared.status()).toBe(200)
    await resetTelegramStub(request, testInfo.testId)
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

    await postUpdate(request, botKey, secret, messageUpdate(1001, 10, 555, `/start ${start}`))
    await expect(page.getByTestId('text-telegram-connected')).toBeVisible({
      timeout: TIMEOUTS.LONG,
    })
    await expect(page.getByTestId('btn-telegram-open-chat')).toBeVisible()

    await postUpdate(request, botKey, secret, messageUpdate(1002, 11, 555, 'What is 2 + 2?'))
    const afterReply = await waitForTexts(
      request,
      testInfo.testId,
      (texts) => texts.length > 1 && (texts[texts.length - 1]?.length ?? 0) > 0
    )

    await postUpdate(request, botKey, secret, messageUpdate(1002, 11, 555, 'What is 2 + 2?'))
    expect(await sendTexts(request, testInfo.testId)).toHaveLength(afterReply.length)

    await postUpdate(request, botKey, secret, {
      update_id: 1003,
      message: {
        message_id: 12,
        from: { id: 555 },
        chat: { id: 555, type: 'private' },
        photo: [{ file_id: 'pic' }],
      },
    })
    await waitForTexts(request, testInfo.testId, (texts) =>
      texts.includes('Only text messages for now.')
    )

    await postUpdate(request, botKey, secret, messageUpdate(1004, 13, 777, 'hello'))
    await waitForTexts(request, testInfo.testId, (texts) =>
      texts.includes('This bot only answers its owner.')
    )

    await expectTelegramThread(page)

    await page.goto('/channels')
    await page.getByTestId('btn-telegram-disconnect').click()
    await expect(page.getByText('Your chat history stays in Synaplan.')).toBeVisible()
    await page.getByTestId('btn-dialog-confirm').click()
    await expect(page.getByTestId('input-telegram-token')).toBeVisible({
      timeout: TIMEOUTS.STANDARD,
    })

    const beforeSilent = await sendTexts(request, testInfo.testId)
    await postUpdate(request, botKey, secret, messageUpdate(1005, 14, 555, 'Are you still there?'))
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
    await postUpdate(request, botKey, secret, messageUpdate(2001, 20, 555, `/start ${start}`))
    await expect(page.getByTestId('text-telegram-connected')).toBeVisible({
      timeout: TIMEOUTS.LONG,
    })

    await setTelegramStubBlocked(request, true)
    await postUpdate(request, botKey, secret, photoUpdate(2002, 21, 555))
    await page.reload()
    const blocked = page.getByTestId('text-telegram-state-error')
    await expect(blocked).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    await expect(blocked).toContainText('You blocked this bot in Telegram')
    await expect(blocked.getByTestId('btn-telegram-disconnect')).toBeVisible()
    await expect(blocked.getByTestId('btn-telegram-open-chat')).toBeVisible()

    await setTelegramStubBlocked(request, false)
    await postUpdate(request, botKey, secret, photoUpdate(2003, 22, 555))
    await waitForTexts(request, testInfo.testId, (texts) =>
      texts.includes('Only text messages for now.')
    )
    await page.reload()
    await expect(page.getByTestId('text-telegram-connected')).toBeVisible({
      timeout: TIMEOUTS.STANDARD,
    })
  })
})

function photoUpdate(updateId: number, messageId: number, userId: number): object {
  return {
    update_id: updateId,
    message: {
      message_id: messageId,
      from: { id: userId },
      chat: { id: userId, type: 'private' },
      photo: [{ file_id: 'pic' }],
    },
  }
}

async function expectTelegramThread(page: import('@playwright/test').Page): Promise<void> {
  await page.goto('/')
  await page.getByRole('button', { name: 'History' }).click()
  await expect(page.getByText('Telegram: @synaplan_test_bot').first()).toBeVisible({
    timeout: TIMEOUTS.STANDARD,
  })
  await page.keyboard.press('Escape')
}

async function openChannels(page: import('@playwright/test').Page): Promise<void> {
  await page.addInitScript(() => {
    localStorage.setItem('language', 'en')
  })
  await page.goto('/channels')
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
