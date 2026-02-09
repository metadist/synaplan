import { test, expect } from '../test-setup'
import { login } from '../helpers/auth'
import {
  createTestWidget,
  gotoWidgetTestPage,
  openWidgetOnTestPage,
  waitForWidgetAnswer,
  countWidgetMessages,
  getApiUrl,
  updateWidgetSettings,
  setWidgetTaskPrompt,
} from '../helpers/widget'
import { selectors } from '../helpers/selectors'
import { URLS, TIMEOUTS, INTERVALS, isTestStack } from '../config/config'
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

// In-app Test Widget overlay (no cross-origin). Test stack: expect "success"; dev: any non-empty reply.
test('@noci @smoke @widget @security User creates widget and receives response in test-widget-chat id=013', async ({ page }) => {
  await login(page)

  const widgetName = WIDGET_NAMES.unique(WIDGET_NAMES.FULL_FLOW)
  await createTestWidget(page, widgetName, URLS.TEST_PAGE_URL)
  await updateWidgetSettings(page, widgetName, {})

  await page.goto('/tools/chat-widget')
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

  const messagesContainer = overlay.locator(selectors.widget.messagesContainer)
  await messagesContainer.waitFor({ state: 'attached', timeout: TIMEOUTS.SHORT }).catch(() => {})
  const previousCount = await messagesContainer.locator(selectors.widget.messageContainers).count()

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

  const aiText = (await overlay.locator(selectors.widget.messageAiText).last().innerText()).trim().toLowerCase()

  const expectSuccess = isTestStack()
  if (expectSuccess) {
    expect(aiText).toContain('success')
  } else {
    expect(aiText.length).toBeGreaterThan(0)
  }
})

test('@smoke @widget Widget settings are correctly applied id=014', async ({ page }) => {
  await login(page)

  const widgetName = WIDGET_NAMES.unique('Test Widget Settings')
  const widgetInfo = await createTestWidget(page, widgetName, URLS.TEST_PAGE_URL)
  const apiUrl = getApiUrl()

  await updateWidgetSettings(page, widgetName, {
    autoMessage: WIDGET_MESSAGES.AUTO_MESSAGE,
    autoOpen: true,
  })

  await gotoWidgetTestPage(page, widgetInfo.widgetId, apiUrl)

  const widgetHost = page.locator(selectors.widget.host)
  await widgetHost.waitFor({ state: 'attached', timeout: TIMEOUTS.VERY_LONG })
  const chatWindow = widgetHost.locator(selectors.widget.chatWindow)
  await chatWindow.waitFor({ state: 'visible', timeout: TIMEOUTS.LONG })
  await expect(chatWindow).toBeVisible()

  const autoMessage = widgetHost.locator(selectors.widget.messageAutoText)
  await expect(autoMessage).toHaveText(WIDGET_MESSAGES.AUTO_MESSAGE, { timeout: TIMEOUTS.STANDARD })

  await page.goto('/tools/chat-widget')
  await page.waitForSelector(selectors.widgets.page, { timeout: TIMEOUTS.SHORT })
  await updateWidgetSettings(page, widgetName, {
    isActive: false,
  })

  const configResponse = page.waitForResponse(
    (response) => response.url().includes(`/api/v1/widget/${widgetInfo.widgetId}/config`) && response.status() === 503,
    { timeout: TIMEOUTS.STANDARD }
  )
  await gotoWidgetTestPage(page, widgetInfo.widgetId, apiUrl)
  await configResponse

  const inactiveWidgetButton = page.locator(selectors.widget.button)
  await expect(inactiveWidgetButton).not.toBeVisible({ timeout: TIMEOUTS.SHORT })
  const inactiveWidgetHost = page.locator(selectors.widget.host)
  await expect(inactiveWidgetHost).toHaveCount(0)
})

// Widget allows only example.com; page is localhost → backend returns 403 domain_not_whitelisted.
test('@smoke @widget @security Widget blocked on non-whitelisted domain id=015', async ({ page }) => {
  await login(page)

  const widgetName = WIDGET_NAMES.unique(WIDGET_NAMES.NOT_WHITELISTED)
  const widgetInfo = await createTestWidget(page, widgetName, WIDGET_TEST_URLS.EXAMPLE_DOMAIN)
  await updateWidgetSettings(page, widgetName, {})

  const apiUrl = getApiUrl()

  const configResponse = page.waitForResponse(
    (response) =>
      response.url().includes(`/api/v1/widget/${widgetInfo.widgetId}/config`) && response.status() === 403,
    { timeout: TIMEOUTS.STANDARD }
  )
  await gotoWidgetTestPage(page, widgetInfo.widgetId, apiUrl)
  const response = await configResponse
  const body = await response.json().catch(() => ({}))
  expect(body.reason).toBe('domain_not_whitelisted')

  const widgetButton = page.locator(selectors.widget.button)
  await expect(widgetButton).not.toBeVisible({ timeout: TIMEOUTS.SHORT })
  const widgetHost = page.locator(selectors.widget.host)
  await expect(widgetHost).toHaveCount(0)

  // Add localhost to whitelist and verify widget loads
  await page.goto('/tools/chat-widget')
  await page.waitForSelector(selectors.widgets.page, { timeout: TIMEOUTS.SHORT })
  
  const widgetCard = page.locator(selectors.widgets.widgetCard.item).filter({ hasText: widgetName }).first()
  await widgetCard.locator(selectors.widgets.widgetCard.advancedButton).click()
  await page.waitForSelector(selectors.widgets.advancedConfig.modal, { timeout: TIMEOUTS.STANDARD })
  
  const securityTab = page.locator(selectors.widgets.advancedConfig.securityTab)
  const isSecurityTabVisible = await securityTab.isVisible().catch(() => false)
  if (!isSecurityTabVisible) {
    await page.locator(selectors.widgets.advancedConfig.tabButtonSecurity).click()
    await securityTab.waitFor({ state: 'visible', timeout: TIMEOUTS.SHORT })
  }
  await page.locator(selectors.widgets.advancedConfig.domainInput).fill('localhost')
  await page.locator(selectors.widgets.advancedConfig.addDomainButton).click()
  
  const modal = page.locator(selectors.widgets.advancedConfig.modal)
  const saveButton = modal.locator(selectors.widgets.advancedConfig.saveButton)
  await saveButton.scrollIntoViewIfNeeded()
  await saveButton.click()
  await page.waitForSelector(selectors.widgets.advancedConfig.modal, { state: 'hidden', timeout: TIMEOUTS.STANDARD })

  await openWidgetOnTestPage(page, widgetInfo.widgetId, apiUrl)

  const chatWindow = page.locator(selectors.widget.host).locator(selectors.widget.chatWindow)
  await expect(chatWindow).toBeVisible()
})

test('@noci @smoke @widget Widget file upload works id=016', async ({ page }) => {
  await login(page)

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

  const input = widgetHost.locator(selectors.widget.input)
  await input.fill('Please read this file')
  await widgetHost.locator(selectors.widget.sendButton).click()

  const previousCount = await countWidgetMessages(page)
  const aiText = await waitForWidgetAnswer(page, previousCount)
  expect(aiText.length).toBeGreaterThan(0)
  
  await expect(widgetHost.locator(selectors.widget.errorFileUpload)).toHaveCount(0)
  await expect(widgetHost.locator(selectors.widget.errorFileSize)).toHaveCount(0)
  await expect(widgetHost.locator(selectors.widget.errorFileUploadLimit)).toHaveCount(0)
})

test('@noci @smoke @widget Widget file upload limit enforced id=018', async ({ page }) => {
  await login(page)

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

  const input = widgetHost.locator(selectors.widget.input)
  await input.fill('Test message with file')
  await widgetHost.locator(selectors.widget.sendButton).click()

  const previousCount = await countWidgetMessages(page)
  await waitForWidgetAnswer(page, previousCount)

  await fileInput.setInputFiles(secondFilePath)

  const errorMessageLimit = widgetHost.locator(selectors.widget.errorFileUploadLimit)
  await expect(errorMessageLimit).toBeVisible({ timeout: TIMEOUTS.STANDARD })
  const errorTextLimit = await errorMessageLimit.textContent()
  expect(errorTextLimit?.toLowerCase()).toMatch(/file.*upload.*limit|limit.*reached/i)

  const fileCount = await widgetHost.locator('text=most_important_thing.txt').count()
  expect(fileCount).toBe(1)
})

test('@noci @smoke @widget Widget max file size enforced id=019', async ({ page }) => {
  await login(page)

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
  
  const fileName = widgetHost.locator('text=sky_scrapers.jpg')
  await expect(fileName).not.toBeVisible({ timeout: TIMEOUTS.SHORT })
})

test('@noci @smoke @widget Widget task prompt works id=017', async ({ page }) => {
  await login(page)

  const widgetName = WIDGET_NAMES.unique('Test Widget Task Prompt')
  const widgetInfo = await createTestWidget(page, widgetName, URLS.TEST_PAGE_URL)

  const filePath = path.join(__dirname, '../test_data/most_important_thing.txt')
  await setWidgetTaskPrompt(page, widgetName, WIDGET_TASK_PROMPT_KNOWLEDGE_BASE, undefined, filePath)

  const apiUrl = getApiUrl()
  await openWidgetOnTestPage(page, widgetInfo.widgetId, apiUrl)

  const widgetHost = page.locator(selectors.widget.host)
  const input = widgetHost.locator(selectors.widget.input)
  const previousCount = await countWidgetMessages(page)
  await input.fill(WIDGET_TASK_PROMPT_QUESTION)
  await widgetHost.locator(selectors.widget.sendButton).click()

  const aiText = await waitForWidgetAnswer(page, previousCount)
  
  expect(aiText.length).toBeGreaterThan(0)
})

// Like 013 but with the real embedded widget (script loaded on external page, Shadow DOM).
// Uses widget-test.html — the real user flow. Run on test stack to verify CI behavior.
// @noci: On dev stack, cross-origin (5173→8000) makes widget loading flaky.
test(' @smoke @widget User sends message via embedded widget and receives response id=020', async ({ page }) => {
  await login(page)

  const widgetName = WIDGET_NAMES.unique('Embedded Widget Flow')
  const widgetInfo = await createTestWidget(page, widgetName, URLS.TEST_PAGE_URL)
  await updateWidgetSettings(page, widgetName, {})

  const apiUrl = getApiUrl()
  await openWidgetOnTestPage(page, widgetInfo.widgetId, apiUrl)

  const widgetHost = page.locator(selectors.widget.host)
  const input = widgetHost.locator(selectors.widget.input)
  await expect(input).toBeVisible({ timeout: TIMEOUTS.SHORT })

  const previousCount = await countWidgetMessages(page)
  await input.fill(PROMPTS.SMOKE_TEST)
  await widgetHost.locator(selectors.widget.sendButton).click()

  const userBubble = widgetHost.locator(selectors.widget.messageUserText).last()
  await expect(userBubble).toBeVisible({ timeout: TIMEOUTS.SHORT })
  await expect(userBubble).toContainText('smoke test')

  const aiText = await waitForWidgetAnswer(page, previousCount)

  const expectSuccess = isTestStack()
  if (expectSuccess) {
    expect(aiText).toContain('success')
  } else {
    expect(aiText.length).toBeGreaterThan(0)
  }
})