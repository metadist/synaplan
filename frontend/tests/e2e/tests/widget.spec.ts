import { test, expect } from '../test-setup'
import { openApp } from '../helpers/auth'
import {
  createTestWidget,
  createWidgetViaApi,
  updateWidgetViaApi,
  gotoWidgetTestPage,
  openWidgetOnTestPage,
  waitForWidgetAnswer,
  countWidgetMessages,
  getApiUrl,
  updateWidgetSettings,
  setWidgetTaskPrompt,
  deleteWidgetViaApi,
} from '../helpers/widget'
import { selectors } from '../helpers/selectors'
import { URLS, TIMEOUTS, INTERVALS } from '../config/config'
import {
  PROMPTS,
  WIDGET_NAMES,
  WIDGET_MESSAGES,
  WIDGET_TEST_URLS,
  WIDGET_TASK_PROMPT_KNOWLEDGE_BASE,
  WIDGET_TASK_PROMPT_QUESTION,
} from '../config/test-data'
import path from 'path'
import { fileURLToPath } from 'url'

const __filename = fileURLToPath(import.meta.url)
const __dirname = path.dirname(__filename)

test.describe('@ci @smoke Widget', () => {
  test('applies settings and auto-open', async ({ page }) => {
    await openApp(page)

    const widgetName = WIDGET_NAMES.unique('Test Widget Settings')
    const widgetInfo = await createTestWidget(page, widgetName, URLS.TEST_PAGE_URL)
    const apiUrl = getApiUrl()

    await updateWidgetSettings(page, widgetName, {
      autoMessage: WIDGET_MESSAGES.AUTO_MESSAGE,
      autoOpen: true,
    })

    await gotoWidgetTestPage(page, widgetInfo.widgetId, apiUrl)

    // autoOpen: true → widget opens itself, no button click needed
    const widgetHost = page.locator(selectors.widget.host)
    const chatWindow = widgetHost.locator(selectors.widget.chatWindow)
    await chatWindow.waitFor({ state: 'visible', timeout: TIMEOUTS.LONG })

    const autoMessage = widgetHost.locator(selectors.widget.messageAutoText)
    await expect(autoMessage).toHaveText(WIDGET_MESSAGES.AUTO_MESSAGE, {
      timeout: TIMEOUTS.STANDARD,
    })

    await page.goto('/channels/widgets')
    await page.waitForSelector(selectors.widgets.page, { timeout: TIMEOUTS.STANDARD })
    await updateWidgetSettings(page, widgetName, {
      isActive: false,
    })

    const configResponse = page.waitForResponse(
      (response) =>
        response.url().includes(`/api/v1/widget/${widgetInfo.widgetId}/config`) &&
        response.status() === 503,
      { timeout: TIMEOUTS.STANDARD }
    )
    await gotoWidgetTestPage(page, widgetInfo.widgetId, apiUrl)
    await configResponse

    const inactiveWidgetButton = page.locator(selectors.widget.button)
    await expect(inactiveWidgetButton).not.toBeVisible({ timeout: TIMEOUTS.SHORT })
    const inactiveWidgetHost = page.locator(selectors.widget.host)
    await expect(inactiveWidgetHost).toHaveCount(0)
  })

  test('@security blocked on non-whitelisted domain', async ({ page, request, credentials }) => {
    const widgetName = WIDGET_NAMES.unique(WIDGET_NAMES.NOT_WHITELISTED)
    const widgetInfo = await createWidgetViaApi(request, credentials, widgetName, {
      websiteUrl: WIDGET_TEST_URLS.EXAMPLE_DOMAIN,
    })

    const apiUrl = getApiUrl()

    await test.step('Act: open test page → Assert: 403 or console 403, widget not shown', async () => {
      const consoleDomainForbidden = new Promise<void>((resolve) => {
        page.on('console', (msg) => {
          const text = msg.text()
          if (text.includes('Domain not allowed') && text.includes('403')) resolve()
        })
      })

      const configResponse = page.waitForResponse(
        (res) =>
          res.url().includes(`/api/v1/widget/${widgetInfo.widgetId}/config`) &&
          res.status() === 403,
        { timeout: TIMEOUTS.STANDARD }
      )

      await gotoWidgetTestPage(page, widgetInfo.widgetId, apiUrl)

      const result = await Promise.race([
        configResponse.then((r) => ({ type: 'response' as const, response: r })),
        consoleDomainForbidden.then(() => ({ type: 'console' as const })),
      ])

      if (result.type === 'response') {
        const body = await result.response.json()
        expect(body.reason).toBe('domain_not_whitelisted')
      }

      await expect(page.locator(selectors.widget.button)).not.toBeVisible({
        timeout: TIMEOUTS.SHORT,
      })
      await expect(page.locator(selectors.widget.host)).toHaveCount(0)
    })

    await test.step('Arrange: add localhost to whitelist (API — modal editing is covered above)', async () => {
      await updateWidgetViaApi(request, credentials, widgetInfo.widgetId, {
        config: { allowedDomains: ['example.com', 'localhost'] },
      })
    })

    await test.step('Act: open widget → Assert: chat window visible', async () => {
      await openWidgetOnTestPage(page, widgetInfo.widgetId, apiUrl)
      const chatWindow = page.locator(selectors.widget.host).locator(selectors.widget.chatWindow)
      await expect(chatWindow).toBeVisible()
    })
  })

  test('keeps the host page visible while open', async ({ page, request, credentials }) => {
    const widgetName = WIDGET_NAMES.unique('Widget Host Transparency')
    const widgetInfo = await createWidgetViaApi(request, credentials, widgetName, {
      websiteUrl: URLS.TEST_PAGE_URL,
    })

    const apiUrl = getApiUrl()
    await test.step('Arrange: open widget on test page', async () => {
      await openWidgetOnTestPage(page, widgetInfo.widgetId, apiUrl)
    })

    await test.step('Assert: the full-viewport host layer paints nothing', async () => {
      // The host spans the whole viewport, so any background on it hides the
      // embedding page. Only the chat chrome inside the Shadow DOM may paint.
      const hostSurface = await page.locator(selectors.widget.host).evaluate((element) => {
        const style = getComputedStyle(element)
        return { color: style.backgroundColor, image: style.backgroundImage }
      })

      expect(hostSurface.color).toBe('rgba(0, 0, 0, 0)')
      expect(hostSurface.image).toBe('none')
    })
  })

  // @crossbrowser: the embeddable widget runs on unknown customer sites in
  // whatever browser their visitors use. This exercises Shadow DOM mount +
  // cross-origin SSE response — the highest-value Gecko path in the product.
  test('@crossbrowser embedded chat receives response', async ({ page, request, credentials }) => {
    const widgetName = WIDGET_NAMES.unique('Embedded Widget Flow')
    const widgetInfo = await createWidgetViaApi(request, credentials, widgetName, {
      websiteUrl: URLS.TEST_PAGE_URL,
    })

    const apiUrl = getApiUrl()
    await test.step('Arrange: open widget on test page', async () => {
      await openWidgetOnTestPage(page, widgetInfo.widgetId, apiUrl)
    })

    const widgetHost = page.locator(selectors.widget.host)
    const messagesContainer = widgetHost.locator(selectors.widget.messagesContainer)
    const messageContainers = messagesContainer.locator(selectors.widget.messageContainers)
    const input = widgetHost.locator(selectors.widget.input)
    await expect(input).toBeVisible({ timeout: TIMEOUTS.SHORT })

    // The widget seeds its configured welcome message asynchronously after
    // opening. Wait for it before taking the base count — otherwise
    // nth(previousCount) can point at the late-arriving welcome bubble
    // instead of the user message (CI-speed race, chromium-only flake).
    await expect(messageContainers.first()).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    const previousCount = await countWidgetMessages(page)

    await test.step('Act: send smoke test message', async () => {
      await input.fill(PROMPTS.SMOKE_TEST)
      await widgetHost.locator(selectors.widget.sendButton).click()
    })

    const userContainer = messageContainers.nth(previousCount)
    await expect(userContainer.locator(selectors.widget.messageUserText)).toBeVisible({
      timeout: TIMEOUTS.STANDARD,
    })
    await expect(userContainer).toContainText('smoke test')

    const assistantIndex = previousCount + 1
    const newBubble = messageContainers.nth(assistantIndex)
    // Wait straight for 'visible'. The attached → scrollIntoViewIfNeeded →
    // visible sequence snapshots the element handle and throws "Element is not
    // attached to the DOM" when the bubble is re-rendered mid-call; waitFor
    // re-resolves the locator instead. ChatHelper.waitForAnswer() dropped this
    // sequence for the same reason, and this was the last @ci test still
    // carrying it (it runs in both the chromium and firefox jobs).
    await newBubble.waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })

    await newBubble
      .locator(selectors.widget.messageDone)
      .waitFor({ state: 'visible', timeout: TIMEOUTS.VERY_LONG })

    const aiText = (await newBubble.locator(selectors.widget.messageAiText).innerText())
      .trim()
      .toLowerCase()

    await test.step('Assert: stream ended (message-done) and assistant reply non-empty', async () => {
      expect(aiText.length).toBeGreaterThan(0)
    })
  })
})

test.describe('@ci Widget visitor chrome', () => {
  const created: string[] = []

  test.afterEach(async ({ request, credentials }) => {
    while (created.length > 0) {
      const widgetId = created.pop()
      if (widgetId) await deleteWidgetViaApi(request, credentials, widgetId)
    }
  })

  test('@crossbrowser close and reopen keeps the earlier messages', async ({
    page,
    request,
    credentials,
  }) => {
    const widgetName = WIDGET_NAMES.unique('Widget Close Reopen')
    const widgetInfo = await createWidgetViaApi(request, credentials, widgetName, {
      websiteUrl: URLS.TEST_PAGE_URL,
    })
    created.push(widgetInfo.widgetId)
    const apiUrl = getApiUrl()
    const widgetHost = page.locator(selectors.widget.host)

    await test.step('Arrange: a finished reply is on screen', async () => {
      await openWidgetOnTestPage(page, widgetInfo.widgetId, apiUrl)
      const previousCount = await countWidgetMessages(page)
      await widgetHost.locator(selectors.widget.input).fill(PROMPTS.SMOKE_TEST)
      await widgetHost.locator(selectors.widget.sendButton).click()
      await waitForWidgetAnswer(page, previousCount)
    })

    await test.step('Act: close the chat, then open it from the launcher', async () => {
      await widgetHost.locator(selectors.widget.closeButton).click()
      await expect(widgetHost.locator(selectors.widget.chatWindow)).toBeHidden()
      await page.locator(selectors.widget.button).click()
      await expect(widgetHost.locator(selectors.widget.chatWindow)).toBeVisible()
    })

    await test.step('Assert: the earlier reply is still in the thread', async () => {
      await expect(widgetHost.locator(selectors.widget.messageAiText).last()).toBeVisible()
      await expect(widgetHost.locator(selectors.widget.messageUserText).last()).toContainText(
        'smoke test'
      )
    })
  })

  test('theme toggle changes the widget and leaves the host page alone', async ({
    page,
    request,
    credentials,
  }) => {
    const widgetName = WIDGET_NAMES.unique('Widget Theme')
    const widgetInfo = await createWidgetViaApi(request, credentials, widgetName, {
      websiteUrl: URLS.TEST_PAGE_URL,
    })
    created.push(widgetInfo.widgetId)
    const apiUrl = getApiUrl()

    await test.step('Arrange: the widget is open on a light host page', async () => {
      await openWidgetOnTestPage(page, widgetInfo.widgetId, apiUrl)
    })

    const hostBackground = () =>
      page.evaluate(() => getComputedStyle(document.body).backgroundColor)
    const widgetRoot = page.locator(selectors.widget.host).locator(selectors.widget.root)
    const before = await hostBackground()

    await test.step('Act: switch the widget theme twice', async () => {
      const theme = page.locator(selectors.widget.host).locator(selectors.widget.themeButton)
      await theme.click()
      await expect(widgetRoot).toHaveClass(/dark/)
      await theme.click()
      await expect(widgetRoot).not.toHaveClass(/\bdark\b/)
    })

    await test.step('Assert: the host page background did not change', async () => {
      expect(await hostBackground()).toBe(before)
    })
  })

  test('fullscreen covers the page and then gives it back', async ({
    page,
    request,
    credentials,
  }) => {
    const widgetName = WIDGET_NAMES.unique('Widget Fullscreen')
    const widgetInfo = await createWidgetViaApi(request, credentials, widgetName, {
      websiteUrl: URLS.TEST_PAGE_URL,
    })
    created.push(widgetInfo.widgetId)
    const apiUrl = getApiUrl()
    const widgetHost = page.locator(selectors.widget.host)

    await test.step('Arrange: open a widget that can go fullscreen', async () => {
      await openWidgetOnTestPage(page, widgetInfo.widgetId, apiUrl, { allowFullscreen: 'true' })
    })

    await test.step('Act: enter fullscreen, then leave it', async () => {
      const fullscreen = widgetHost.locator(selectors.widget.fullscreenButton)
      await fullscreen.click()
      await expect(widgetHost.locator(selectors.widget.fullscreenBackdrop)).toBeVisible()
      await fullscreen.click()
      await expect(widgetHost.locator(selectors.widget.fullscreenBackdrop)).toBeHidden()
    })

    await test.step('Assert: the host page is visible around the widget', async () => {
      await expect(page.getByRole('heading', { name: 'Widget Test Page' })).toBeVisible()
      const hostSurface = await widgetHost.evaluate((element) => {
        const style = getComputedStyle(element)
        return { color: style.backgroundColor, image: style.backgroundImage }
      })
      expect(hostSurface.color).toBe('rgba(0, 0, 0, 0)')
      expect(hostSurface.image).toBe('none')
    })
  })

  test('message limit warns at four of five and then stops sending', async ({ page }) => {
    await openApp(page)
    const widgetName = WIDGET_NAMES.unique('Widget Message Limit')
    const widgetInfo = await createTestWidget(page, widgetName, URLS.TEST_PAGE_URL)
    created.push(widgetInfo.widgetId)
    await updateWidgetSettings(page, widgetName, { messageLimit: 5 })

    const apiUrl = getApiUrl()
    const widgetHost = page.locator(selectors.widget.host)

    await test.step('Arrange: open the widget and let its session load', async () => {
      const history = page.waitForResponse(
        (response) => response.url().includes('/history') && response.ok(),
        { timeout: TIMEOUTS.STANDARD }
      )
      await openWidgetOnTestPage(page, widgetInfo.widgetId, apiUrl)
      await history
    })

    await test.step('Act: send until four replies have finished', async () => {
      for (let sent = 0; sent < 4; sent += 1) {
        const previousCount = await countWidgetMessages(page)
        await widgetHost.locator(selectors.widget.input).fill(`limit check ${sent + 1}`)
        await widgetHost.locator(selectors.widget.sendButton).click()
        await waitForWidgetAnswer(page, previousCount)
      }
    })

    await test.step('Assert: the warning names 4 of 5', async () => {
      const warning = widgetHost.locator(selectors.widget.warningMessageLimit)
      await expect(warning).toBeVisible()
      await expect(warning).toContainText('4 of 5')
    })

    await test.step('Act: send the fifth message', async () => {
      const previousCount = await countWidgetMessages(page)
      await widgetHost.locator(selectors.widget.input).fill('limit check 5')
      await widgetHost.locator(selectors.widget.sendButton).click()
      await waitForWidgetAnswer(page, previousCount)
    })

    await test.step('Assert: the composer cannot send another message', async () => {
      await expect(widgetHost.locator(selectors.widget.errorMessageLimitReached)).toBeVisible()
      await expect(widgetHost.locator(selectors.widget.input)).toBeDisabled()
    })
  })

  test('removing a staged file leaves the composer usable', async ({ page }) => {
    await openApp(page)
    const widgetName = WIDGET_NAMES.unique('Widget Remove File')
    const widgetInfo = await createTestWidget(page, widgetName, URLS.TEST_PAGE_URL)
    created.push(widgetInfo.widgetId)
    await updateWidgetSettings(page, widgetName, { allowFileUpload: true })

    const apiUrl = getApiUrl()
    const widgetHost = page.locator(selectors.widget.host)

    await test.step('Arrange: a file is staged and not sent', async () => {
      await openWidgetOnTestPage(page, widgetInfo.widgetId, apiUrl)
      const filePath = path.join(__dirname, '../test_data/most_important_thing.txt')
      await widgetHost.locator(selectors.widget.fileInput).setInputFiles(filePath)
      await expect(widgetHost.locator('text=most_important_thing.txt')).toBeVisible()
    })

    await test.step('Act: remove the file', async () => {
      await widgetHost.locator(selectors.widget.removeFileButton(0)).click()
    })

    await test.step('Assert: the file is gone and the composer can still be typed in', async () => {
      await expect(widgetHost.locator('text=most_important_thing.txt')).toHaveCount(0)
      const input = widgetHost.locator(selectors.widget.input)
      await expect(input).toBeEnabled()
      await input.fill('still here')
      await expect(input).toHaveValue('still here')
    })
  })
})

test.describe('@noci @smoke Widget — full flow', () => {
  test('@security overlay receives response', async ({ page }) => {
    await openApp(page)

    const widgetName = WIDGET_NAMES.unique(WIDGET_NAMES.FULL_FLOW)
    await createTestWidget(page, widgetName, URLS.TEST_PAGE_URL)
    await updateWidgetSettings(page, widgetName, {})

    await page.goto('/channels/widgets')
    await page.waitForSelector(selectors.widgets.page, { timeout: TIMEOUTS.LONG })

    const testButton = page.locator(selectors.widgets.widgetCard.testButton).first()
    await testButton.waitFor({ state: 'visible', timeout: TIMEOUTS.LONG })
    await testButton.click()

    const overlay = page.locator(selectors.widgets.testChatOverlay)
    await overlay.waitFor({ state: 'visible', timeout: TIMEOUTS.LONG })

    const chatWindow = overlay.locator(selectors.widget.chatWindow)
    await chatWindow.waitFor({ state: 'visible', timeout: TIMEOUTS.LONG })

    const input = overlay.locator(selectors.widget.input)
    await expect(input).toBeVisible({ timeout: TIMEOUTS.SHORT })

    // The messages container mounts with the first message — when it is not
    // in the DOM yet there are simply no previous messages.
    const messagesContainer = overlay.locator(selectors.widget.messagesContainer)
    const previousCount = (await messagesContainer.count())
      ? await messagesContainer.locator(selectors.widget.messageContainers).count()
      : 0

    await input.fill(PROMPTS.SMOKE_TEST)
    await overlay.locator(selectors.widget.sendButton).click()

    const userBubble = overlay.locator(selectors.widget.messageUserText).last()
    await expect(userBubble).toBeVisible({ timeout: TIMEOUTS.SHORT })
    await expect(userBubble).toContainText('smoke test')

    await expect
      .poll(
        async () => {
          const count = await messagesContainer.locator(selectors.widget.messageContainers).count()
          return count > previousCount ? count : null
        },
        { timeout: TIMEOUTS.VERY_LONG, intervals: INTERVALS.FAST() }
      )
      .not.toBeNull()

    const aiTextElement = overlay.locator(selectors.widget.messageAiText).last()
    await aiTextElement.waitFor({ state: 'visible', timeout: TIMEOUTS.VERY_LONG })

    let lastText = ''
    let stableCount = 0
    await expect
      .poll(
        async () => {
          const el = overlay.locator(selectors.widget.messageAiText).last()
          if ((await el.count()) === 0) return null
          const text = (await el.innerText()).trim().toLowerCase()
          if (text.length === 0) return null
          if (text === lastText) {
            stableCount++
            if (stableCount >= 2) return text
            return null
          }
          lastText = text
          stableCount = 1
          return null
        },
        { timeout: TIMEOUTS.VERY_LONG, intervals: INTERVALS.FAST() }
      )
      .not.toBeNull()

    const aiText = (await overlay.locator(selectors.widget.messageAiText).last().innerText()).trim()
    expect(aiText.length).toBeGreaterThan(0)
  })

  test('file upload returns answer', async ({ page }) => {
    await openApp(page)

    const widgetName = WIDGET_NAMES.unique('Test Widget File Upload')
    const widgetInfo = await createTestWidget(page, widgetName, URLS.TEST_PAGE_URL)
    await updateWidgetSettings(page, widgetName, {
      allowFileUpload: true,
    })

    const apiUrl = getApiUrl()
    await openWidgetOnTestPage(page, widgetInfo.widgetId, apiUrl)

    const widgetHost = page.locator(selectors.widget.host)

    const attachButton = widgetHost.locator(selectors.widget.attachButton)
    await expect(attachButton).toBeVisible({ timeout: TIMEOUTS.SHORT })

    const fileInput = widgetHost.locator(selectors.widget.fileInput)
    const filePath = path.join(__dirname, '../test_data/most_important_thing.txt')
    await fileInput.setInputFiles(filePath)

    const fileName = widgetHost.locator('text=most_important_thing.txt')
    await expect(fileName).toBeVisible({ timeout: TIMEOUTS.STANDARD })

    await expect(widgetHost.locator(selectors.widget.errorFileUpload)).toHaveCount(0)
    await expect(widgetHost.locator(selectors.widget.errorFileSize)).toHaveCount(0)
    await expect(widgetHost.locator(selectors.widget.errorFileUploadLimit)).toHaveCount(0)

    const inputEl = widgetHost.locator(selectors.widget.input)
    await inputEl.fill('Please read this file')
    await widgetHost.locator(selectors.widget.sendButton).click()

    const previousCount = await countWidgetMessages(page)
    const aiText = await waitForWidgetAnswer(page, previousCount)
    await expect(widgetHost.locator(selectors.widget.messageDone).last()).toBeVisible()
    expect(aiText.length).toBeGreaterThan(0)

    await expect(widgetHost.locator(selectors.widget.errorFileUpload)).toHaveCount(0)
    await expect(widgetHost.locator(selectors.widget.errorFileSize)).toHaveCount(0)
    await expect(widgetHost.locator(selectors.widget.errorFileUploadLimit)).toHaveCount(0)
  })

  test('enforces file upload limit', async ({ page }) => {
    await openApp(page)

    const widgetName = WIDGET_NAMES.unique('Test Widget File Upload Limit')
    const widgetInfo = await createTestWidget(page, widgetName, URLS.TEST_PAGE_URL)
    await updateWidgetSettings(page, widgetName, {
      allowFileUpload: true,
      fileUploadLimit: 1,
    })

    const apiUrl = getApiUrl()
    await openWidgetOnTestPage(page, widgetInfo.widgetId, apiUrl)

    const widgetHost = page.locator(selectors.widget.host)

    const fileInput = widgetHost.locator(selectors.widget.fileInput)
    const firstFilePath = path.join(__dirname, '../test_data/most_important_thing.txt')
    const secondFilePath = path.join(__dirname, '../test_data/second_most_important_thing.txt')

    await fileInput.setInputFiles([firstFilePath, secondFilePath])

    const errorMessageUpload = widgetHost.locator(selectors.widget.errorFileUpload)
    await expect(errorMessageUpload).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    const errorTextUpload = await errorMessageUpload.textContent()
    expect(errorTextUpload?.toLowerCase()).toMatch(/file.*upload.*limit|limit.*reached/i)

    await fileInput.setInputFiles([])

    await fileInput.setInputFiles(firstFilePath)
    const firstFileName = widgetHost.locator('text=most_important_thing.txt')
    await expect(firstFileName).toBeVisible({ timeout: TIMEOUTS.STANDARD })

    const inputEl = widgetHost.locator(selectors.widget.input)
    await inputEl.fill('Test message with file')
    await widgetHost.locator(selectors.widget.sendButton).click()

    const previousCount = await countWidgetMessages(page)
    await waitForWidgetAnswer(page, previousCount)
    await expect(widgetHost.locator(selectors.widget.messageDone).last()).toBeVisible()

    await fileInput.setInputFiles(secondFilePath)

    const errorMessageLimit = widgetHost.locator(selectors.widget.errorFileUploadLimit)
    await expect(errorMessageLimit).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    const errorTextLimit = await errorMessageLimit.textContent()
    expect(errorTextLimit?.toLowerCase()).toMatch(/file.*upload.*limit|limit.*reached/i)

    const fileCount = await widgetHost.locator('text=most_important_thing.txt').count()
    expect(fileCount).toBe(1)
  })

  test('enforces max file size', async ({ page }) => {
    await openApp(page)

    const widgetName = WIDGET_NAMES.unique('Test Widget Max File Size')
    const widgetInfo = await createTestWidget(page, widgetName, URLS.TEST_PAGE_URL)
    await updateWidgetSettings(page, widgetName, {
      allowFileUpload: true,
      maxFileSize: 1,
    })

    const apiUrl = getApiUrl()
    await openWidgetOnTestPage(page, widgetInfo.widgetId, apiUrl)

    const widgetHost = page.locator(selectors.widget.host)

    const fileInput = widgetHost.locator(selectors.widget.fileInput)
    const largeFilePath = path.join(__dirname, '../test_data/sky_scrapers.jpg')
    await fileInput.setInputFiles(largeFilePath)

    const fileSizeError = widgetHost.locator(selectors.widget.errorFileSize)
    await expect(fileSizeError).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    const errorText = await fileSizeError.textContent()
    expect(errorText?.toLowerCase()).toMatch(/exceeds|too.*large|file.*size.*limit|limit/i)

    const fileNameEl = widgetHost.locator('text=sky_scrapers.jpg')
    await expect(fileNameEl).not.toBeVisible({ timeout: TIMEOUTS.SHORT })
  })

  test('task prompt returns answer', async ({ page }) => {
    await openApp(page)

    const widgetName = WIDGET_NAMES.unique('Test Widget Task Prompt')
    const widgetInfo = await createTestWidget(page, widgetName, URLS.TEST_PAGE_URL)

    const filePath = path.join(__dirname, '../test_data/most_important_thing.txt')
    await setWidgetTaskPrompt(
      page,
      widgetName,
      WIDGET_TASK_PROMPT_KNOWLEDGE_BASE,
      undefined,
      filePath
    )

    const apiUrl = getApiUrl()
    await openWidgetOnTestPage(page, widgetInfo.widgetId, apiUrl)

    const widgetHost = page.locator(selectors.widget.host)
    const input = widgetHost.locator(selectors.widget.input)
    const previousCount = await countWidgetMessages(page)
    await input.fill(WIDGET_TASK_PROMPT_QUESTION)
    await widgetHost.locator(selectors.widget.sendButton).click()

    const aiText = await waitForWidgetAnswer(page, previousCount)
    await expect(widgetHost.locator(selectors.widget.messageDone).last()).toBeVisible()
    expect(aiText.length).toBeGreaterThan(0)
  })
})
