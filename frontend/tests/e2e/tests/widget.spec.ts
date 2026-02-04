import { test, expect } from '../test-setup'
import { login } from '../helpers/auth'
import {
  createTestWidget,
  createWidgetTestPage,
  waitForWidgetAnswer,
  countWidgetMessages,
  getApiUrl,
  updateWidgetSettings,
  setWidgetTaskPrompt,
} from '../helpers/widget'
import { selectors } from '../helpers/selectors'
import { URLS, TIMEOUTS } from '../config/config'
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

// Get __dirname equivalent in ES modules
const __filename = fileURLToPath(import.meta.url)
const __dirname = path.dirname(__filename)

test('@noci @smoke @widget @security User creates widget and receives response id=013', async ({ page }) => {
  await login(page)

  const widgetName = WIDGET_NAMES.unique(WIDGET_NAMES.FULL_FLOW)
  const widgetInfo = await createTestWidget(page, widgetName, URLS.TEST_PAGE_URL)
  await updateWidgetSettings(page, widgetName, {})
  
  const apiUrl = getApiUrl()
  const testPageHtml = createWidgetTestPage(widgetInfo.widgetId, apiUrl)
  await page.setContent(testPageHtml, { waitUntil: 'domcontentloaded' })

  const widgetButton = page.locator(selectors.widget.button)
  await widgetButton.waitFor({ state: 'visible', timeout: TIMEOUTS.LONG })
  await expect(widgetButton).toBeVisible()
  await widgetButton.click()

  const widgetHost = page.locator(selectors.widget.host)
  await widgetHost.waitFor({ state: 'attached', timeout: TIMEOUTS.LONG })
  const chatWindow = widgetHost.locator(selectors.widget.chatWindow)
  await chatWindow.waitFor({ state: 'visible', timeout: TIMEOUTS.LONG })
  await expect(chatWindow).toBeVisible()

  const input = widgetHost.locator(selectors.widget.input)
  await expect(input).toBeVisible({ timeout: TIMEOUTS.SHORT })

  const previousCount = await countWidgetMessages(page)
  await input.fill(PROMPTS.SMOKE_TEST)
  await widgetHost.locator(selectors.widget.sendButton).click()

  const aiText = await waitForWidgetAnswer(page, previousCount)
  expect(aiText).toContain('success')
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

  const testPageHtml = createWidgetTestPage(widgetInfo.widgetId, apiUrl)
  await page.setContent(testPageHtml, { waitUntil: 'domcontentloaded' })

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

  await page.setContent(testPageHtml, { waitUntil: 'domcontentloaded' })

  const configResponse = page.waitForResponse(
    (response) => response.url().includes(`/api/v1/widget/${widgetInfo.widgetId}/config`) && response.status() === 503,
    { timeout: TIMEOUTS.STANDARD }
  )
  await configResponse

  const inactiveWidgetButton = page.locator(selectors.widget.button)
  await expect(inactiveWidgetButton).not.toBeVisible({ timeout: TIMEOUTS.SHORT })
  const inactiveWidgetHost = page.locator(selectors.widget.host)
  await expect(inactiveWidgetHost).toHaveCount(0)
})

test('@smoke @widget @security Widget blocked on non-whitelisted domain id=015', async ({ page }) => {
  await login(page)

  const widgetName = WIDGET_NAMES.unique(WIDGET_NAMES.NOT_WHITELISTED)
  const widgetInfo = await createTestWidget(page, widgetName, WIDGET_TEST_URLS.EXAMPLE_DOMAIN)
  await updateWidgetSettings(page, widgetName, {})
  
  const apiUrl = getApiUrl()
  const testPageHtml = createWidgetTestPage(widgetInfo.widgetId, apiUrl)
  await page.setContent(testPageHtml, { waitUntil: 'domcontentloaded' })

  const configResponse = page.waitForResponse(
    (response) => response.url().includes(`/api/v1/widget/${widgetInfo.widgetId}/config`) && response.status() === 403,
    { timeout: TIMEOUTS.STANDARD }
  )
  await configResponse

  const widgetButton = page.locator(selectors.widget.button)
  await expect(widgetButton).not.toBeVisible({ timeout: TIMEOUTS.SHORT })
  const widgetHost = page.locator(selectors.widget.host)
  await expect(widgetHost).toHaveCount(0)

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
  
  await page.setContent(testPageHtml, { waitUntil: 'domcontentloaded' })
  
  const widgetButtonAfterWhitelist = page.locator(selectors.widget.button)
  await widgetButtonAfterWhitelist.waitFor({ state: 'visible', timeout: TIMEOUTS.LONG })
  await expect(widgetButtonAfterWhitelist).toBeVisible()
  
  await widgetButtonAfterWhitelist.click()
  const widgetHostAfterWhitelist = page.locator(selectors.widget.host)
  await widgetHostAfterWhitelist.waitFor({ state: 'attached', timeout: TIMEOUTS.LONG })
  const chatWindow = widgetHostAfterWhitelist.locator(selectors.widget.chatWindow)
  await chatWindow.waitFor({ state: 'visible', timeout: TIMEOUTS.LONG })
  await expect(chatWindow).toBeVisible()
})

test('@noci @smoke @widget Widget file upload works id=016', async ({ page }) => {
  await login(page)

  const widgetName = WIDGET_NAMES.unique('Test Widget File Upload')
  const widgetInfo = await createTestWidget(page, widgetName, URLS.TEST_PAGE_URL)
  await updateWidgetSettings(page, widgetName, {
    allowFileUpload: true,
  })

  await page.waitForSelector(selectors.widgets.page, { timeout: TIMEOUTS.SHORT })

  const apiUrl = getApiUrl()
  const testPageHtml = createWidgetTestPage(widgetInfo.widgetId, apiUrl)
  await page.setContent(testPageHtml, { waitUntil: 'domcontentloaded' })

  const widgetButton = page.locator(selectors.widget.button)
  await widgetButton.waitFor({ state: 'visible', timeout: TIMEOUTS.LONG })
  await widgetButton.click()

  const widgetHost = page.locator(selectors.widget.host)
  await widgetHost.waitFor({ state: 'attached', timeout: TIMEOUTS.LONG })
  const chatWindow = widgetHost.locator(selectors.widget.chatWindow)
  await chatWindow.waitFor({ state: 'visible', timeout: TIMEOUTS.LONG })

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
  const testPageHtml = createWidgetTestPage(widgetInfo.widgetId, apiUrl)
  await page.setContent(testPageHtml, { waitUntil: 'domcontentloaded' })

  const widgetButton = page.locator(selectors.widget.button)
  await widgetButton.waitFor({ state: 'visible', timeout: TIMEOUTS.LONG })
  await widgetButton.click()

  const widgetHost = page.locator(selectors.widget.host)
  await widgetHost.waitFor({ state: 'attached', timeout: TIMEOUTS.LONG })
  const chatWindow = widgetHost.locator(selectors.widget.chatWindow)
  await chatWindow.waitFor({ state: 'visible', timeout: TIMEOUTS.LONG })

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
  const testPageHtml = createWidgetTestPage(widgetInfo.widgetId, apiUrl)
  await page.setContent(testPageHtml, { waitUntil: 'domcontentloaded' })

  const widgetButton = page.locator(selectors.widget.button)
  await widgetButton.waitFor({ state: 'visible', timeout: TIMEOUTS.LONG })
  await widgetButton.click()

  const widgetHost = page.locator(selectors.widget.host)
  await widgetHost.waitFor({ state: 'attached', timeout: TIMEOUTS.LONG })
  const chatWindow = widgetHost.locator(selectors.widget.chatWindow)
  await chatWindow.waitFor({ state: 'visible', timeout: TIMEOUTS.LONG })

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
  const testPageHtml = createWidgetTestPage(widgetInfo.widgetId, apiUrl)
  await page.setContent(testPageHtml, { waitUntil: 'domcontentloaded' })

  const widgetButton = page.locator(selectors.widget.button)
  await widgetButton.waitFor({ state: 'visible', timeout: TIMEOUTS.LONG })
  await widgetButton.click()

  const widgetHost = page.locator(selectors.widget.host)
  await widgetHost.waitFor({ state: 'attached', timeout: TIMEOUTS.LONG })
  const chatWindow = widgetHost.locator(selectors.widget.chatWindow)
  await chatWindow.waitFor({ state: 'visible', timeout: TIMEOUTS.LONG })

  const input = widgetHost.locator(selectors.widget.input)
  await input.fill(WIDGET_TASK_PROMPT_QUESTION)
  await widgetHost.locator(selectors.widget.sendButton).click()

  const previousCount = await countWidgetMessages(page)
  const aiText = await waitForWidgetAnswer(page, previousCount)
  
  expect(aiText.length).toBeGreaterThan(0)
})

