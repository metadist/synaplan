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
import { PROMPTS, WIDGET_NAMES, WIDGET_MESSAGES } from '../config/test-data'
import path from 'path'
import { fileURLToPath } from 'url'

// Get __dirname equivalent in ES modules
const __filename = fileURLToPath(import.meta.url)
const __dirname = path.dirname(__filename)

test('@smoke @widget @security Widget script loads, embeds and can send messages id=013', async ({ page }) => {
  await login(page)

  // Create unique widget name for idempotency
  const widgetName = WIDGET_NAMES.unique(WIDGET_NAMES.FULL_FLOW)

  const widgetInfo = await createTestWidget(page, widgetName, URLS.TEST_PAGE_URL)
  
  // Ensure defaults are set (helper automatically applies all defaults)
  // This test requires: autoOpen=false (button visible), isActive=true (widget loads)
  await updateWidgetSettings(page, widgetName, {})
  
  const apiUrl = getApiUrl()
  const testPageHtml = createWidgetTestPage(widgetInfo.widgetId, apiUrl)

  // Use setContent with domcontentloaded (more reliable than networkidle)
  // This simulates embedding the widget on a real page
  await page.setContent(testPageHtml, { waitUntil: 'domcontentloaded' })

  // Step 1: Verify script loaded by checking if button appears (UI-based)
  const widgetButton = page.locator(selectors.widget.button)
  await widgetButton.waitFor({ state: 'visible', timeout: TIMEOUTS.LONG })
  await expect(widgetButton).toBeVisible()

  // Step 2: Verify widget can be embedded and opened
  await widgetButton.click()

  // Wait for widget host (Shadow DOM container) to be created after button click
  const widgetHost = page.locator(selectors.widget.host)
  await widgetHost.waitFor({ state: 'attached', timeout: TIMEOUTS.LONG })

  // Access elements inside Shadow DOM via host.locator()
  const chatWindow = widgetHost.locator(selectors.widget.chatWindow)
  await chatWindow.waitFor({ state: 'visible', timeout: TIMEOUTS.LONG })
  await expect(chatWindow).toBeVisible()

  const input = widgetHost.locator(selectors.widget.input)
  await expect(input).toBeVisible({ timeout: TIMEOUTS.SHORT })

  // Step 3: Verify widget can send messages and receive responses
  const previousCount = await countWidgetMessages(page)
  await input.fill(PROMPTS.SMOKE_TEST)
  await widgetHost.locator(selectors.widget.sendButton).click()

  const aiText = await waitForWidgetAnswer(page, previousCount)
  expect(aiText).toContain('success')
})

test('@smoke @widget Widget important settings via gear icon are correctly applied id=014', async ({ page }) => {
  await login(page)

  const widgetName = WIDGET_NAMES.unique('Test Widget Settings')
  const widgetInfo = await createTestWidget(page, widgetName, URLS.TEST_PAGE_URL)
  const apiUrl = getApiUrl()

  // Update important settings via advanced config (gear icon)
  // Helper automatically applies all defaults, we only set what we want to change
  await updateWidgetSettings(page, widgetName, {
    autoMessage: WIDGET_MESSAGES.AUTO_MESSAGE,
    autoOpen: true, // Test requires autoOpen=true
  })

  // Use separate test page to simulate real embedding scenario
  const testPageHtml = createWidgetTestPage(widgetInfo.widgetId, apiUrl)
  await page.setContent(testPageHtml, { waitUntil: 'domcontentloaded' })

  // With autoOpen=true, button should be hidden and widget opens automatically after ~3 seconds
  // Wait for chat window directly (button may be hidden, host created when chat loads)
  const widgetHost = page.locator(selectors.widget.host)
  await widgetHost.waitFor({ state: 'attached', timeout: TIMEOUTS.VERY_LONG })
  
  const chatWindow = widgetHost.locator(selectors.widget.chatWindow)
  await chatWindow.waitFor({ state: 'visible', timeout: TIMEOUTS.LONG })
  await expect(chatWindow).toBeVisible()

  // Verify auto message appears - check only first <p> tag (there may be multiple)
  const messagesContainer = widgetHost.locator(selectors.widget.messagesContainer)
  const firstMessage = messagesContainer.locator('p').first()
  await expect(firstMessage).toHaveText(WIDGET_MESSAGES.AUTO_MESSAGE, { timeout: TIMEOUTS.STANDARD })

  // Navigate back to admin page to update settings
  await page.goto('/tools/chat-widget')
  await page.waitForSelector(selectors.widgets.page, { timeout: TIMEOUTS.SHORT })
  
  await updateWidgetSettings(page, widgetName, {
    isActive: false,
  })

  // Widget should not appear when inactive - verify on admin page
  // Load widget on a test page to verify inactive state
  await page.setContent(testPageHtml, { waitUntil: 'domcontentloaded' })

  // Wait for config request to fail with 503 (more reliable than console messages)
  const configResponse = page.waitForResponse(
    (response) => response.url().includes(`/api/v1/widget/${widgetInfo.widgetId}/config`) && response.status() === 503,
    { timeout: TIMEOUTS.STANDARD }
  )
  await configResponse

  // Widget button should not appear when inactive
  const inactiveWidgetButton = page.locator(selectors.widget.button)
  await expect(inactiveWidgetButton).not.toBeVisible({ timeout: TIMEOUTS.SHORT })
  const inactiveWidgetHost = page.locator(selectors.widget.host)
  await expect(inactiveWidgetHost).toHaveCount(0)
})

test('@smoke @widget @security Widget does not appear when domain is not whitelisted id=015', async ({ page }) => {
  await login(page)

  // Create widget with different domain whitelisted (not the test page domain)
  const widgetName = WIDGET_NAMES.unique(WIDGET_NAMES.NOT_WHITELISTED)
  const widgetInfo = await createTestWidget(page, widgetName, 'https://example.com')
  
  // Ensure defaults are set (helper automatically applies all defaults)
  // This test requires: isActive=true (widget should work after whitelisting)
  await updateWidgetSettings(page, widgetName, {})
  
  const apiUrl = getApiUrl()
  const testPageHtml = createWidgetTestPage(widgetInfo.widgetId, apiUrl)

  // Load test page
  await page.setContent(testPageHtml, { waitUntil: 'domcontentloaded' })

  // Wait for config request to fail with 403 (domain not allowed) - more reliable than console messages
  const configResponse = page.waitForResponse(
    (response) => response.url().includes(`/api/v1/widget/${widgetInfo.widgetId}/config`) && response.status() === 403,
    { timeout: TIMEOUTS.STANDARD }
  )
  await configResponse

  // Verify widget button does NOT appear (UI-based assertion)
  const widgetButton = page.locator(selectors.widget.button)
  await expect(widgetButton).not.toBeVisible({ timeout: TIMEOUTS.SHORT })

  // Verify widget host is not created
  const widgetHost = page.locator(selectors.widget.host)
  await expect(widgetHost).toHaveCount(0)

  // Verify it's not a false positive: update widget to allow test domain and verify it works
  // Go back to widgets page and update domain whitelist
  await page.goto('/tools/chat-widget')
  await page.waitForSelector(selectors.widgets.page, { timeout: TIMEOUTS.SHORT })
  
  // Find widget and open advanced config
  const widgetCard = page.locator(selectors.widgets.widgetCard.item).filter({ hasText: widgetName }).first()
  await widgetCard.locator(selectors.widgets.widgetCard.advancedButton).click()
  await page.waitForSelector(selectors.widgets.advancedConfig.modal, { timeout: TIMEOUTS.STANDARD })
  
  // Navigate to security tab (domain whitelist is in security tab)
  const tabs = page.locator(selectors.widgets.advancedConfig.tabButton)
  const tabCount = await tabs.count()
  for (let i = 0; i < tabCount; i++) {
    const tab = tabs.nth(i)
    const tabText = (await tab.innerText()).toLowerCase().trim()
    if (tabText.includes('security') || tabText.includes('sicherheit')) {
      await tab.click()
      break
    }
  }
  
  // Wait for security tab content
  await page.waitForSelector(selectors.widgets.advancedConfig.securityTab, { timeout: TIMEOUTS.SHORT })
  
  // Add test domain (localhost for local testing)
  await page.locator(selectors.widgets.advancedConfig.domainInput).fill('localhost')
  await page.locator(selectors.widgets.advancedConfig.addDomainButton).click()
  
  // Save changes (button is in modal footer)
  const modal = page.locator(selectors.widgets.advancedConfig.modal)
  const saveButton = modal.locator(selectors.widgets.advancedConfig.saveButton)
  await saveButton.scrollIntoViewIfNeeded()
  await saveButton.click()
  
  // Wait for modal to close
  await page.waitForSelector(selectors.widgets.advancedConfig.modal, { state: 'hidden', timeout: TIMEOUTS.STANDARD })
  
  // Reload widget page and verify it works (button appears, chat opens)
  await page.setContent(testPageHtml, { waitUntil: 'domcontentloaded' })
  
  // Widget should now appear
  const widgetButtonAfterWhitelist = page.locator(selectors.widget.button)
  await widgetButtonAfterWhitelist.waitFor({ state: 'visible', timeout: TIMEOUTS.LONG })
  await expect(widgetButtonAfterWhitelist).toBeVisible()
  
  // Verify widget can be opened
  await widgetButtonAfterWhitelist.click()
  const widgetHostAfterWhitelist = page.locator(selectors.widget.host)
  await widgetHostAfterWhitelist.waitFor({ state: 'attached', timeout: TIMEOUTS.LONG })
  const chatWindow = widgetHostAfterWhitelist.locator(selectors.widget.chatWindow)
  await chatWindow.waitFor({ state: 'visible', timeout: TIMEOUTS.LONG })
  await expect(chatWindow).toBeVisible()
})

test('@smoke @widget Widget file upload id=016', async ({ page }) => {
  await login(page)

  const widgetName = WIDGET_NAMES.unique('Test Widget File Upload')
  const widgetInfo = await createTestWidget(page, widgetName, URLS.TEST_PAGE_URL)
  
  // Enable file upload via advanced config (gear icon)
  // Helper automatically applies all defaults, we only set what we want to change
  await updateWidgetSettings(page, widgetName, {
    allowFileUpload: true, // Test requires allowFileUpload=true - must be enabled in settings
  })

  // Verify settings were saved by checking widget is still on page
  await page.waitForSelector(selectors.widgets.page, { timeout: TIMEOUTS.SHORT })

  const apiUrl = getApiUrl()
  const testPageHtml = createWidgetTestPage(widgetInfo.widgetId, apiUrl)
  await page.setContent(testPageHtml, { waitUntil: 'domcontentloaded' })

  // Open widget
  const widgetButton = page.locator(selectors.widget.button)
  await widgetButton.waitFor({ state: 'visible', timeout: TIMEOUTS.LONG })
  await widgetButton.click()

  const widgetHost = page.locator(selectors.widget.host)
  await widgetHost.waitFor({ state: 'attached', timeout: TIMEOUTS.LONG })
  const chatWindow = widgetHost.locator(selectors.widget.chatWindow)
  await chatWindow.waitFor({ state: 'visible', timeout: TIMEOUTS.LONG })

  // Verify file upload button is visible
  const attachButton = widgetHost.locator(selectors.widget.attachButton)
  await expect(attachButton).toBeVisible({ timeout: TIMEOUTS.SHORT })

  // Set file directly (no need to click button - setInputFiles works directly on input)
  const fileInput = widgetHost.locator(selectors.widget.fileInput)
  const filePath = path.join(__dirname, '../test_fixtures/most_important_thing.txt')
  await fileInput.setInputFiles(filePath)

  // Verify file appears in chat (file name should be visible)
  const fileName = widgetHost.locator('text=most_important_thing.txt')
  await expect(fileName).toBeVisible({ timeout: TIMEOUTS.STANDARD })

  // TODO: Add data-testid attributes to error containers in ChatWidget.vue
  // - fileUploadError container (line ~295)
  // - fileSizeError container (line ~348)
  // - fileUploadLimitReached container (line ~305)
  // Then update selectors to use data-testid instead of CSS classes

  // Verify no error messages are shown (red text indicates errors)
  const errorMessages = widgetHost.locator('.text-red-600, .text-red-400')
  await expect(errorMessages).toHaveCount(0)

  // Send message with file attached
  const input = widgetHost.locator(selectors.widget.input)
  await input.fill('Please read this file')
  await widgetHost.locator(selectors.widget.sendButton).click()

  // Wait for AI response
  const previousCount = await countWidgetMessages(page)
  const aiText = await waitForWidgetAnswer(page, previousCount)
  expect(aiText.length).toBeGreaterThan(0)
  
  // Verify no error messages after sending
  await expect(errorMessages).toHaveCount(0)
})

test('@smoke @widget Widget file upload limit enforced id=018', async ({ page }) => {
  await login(page)

  const widgetName = WIDGET_NAMES.unique('Test Widget File Upload Limit')
  const widgetInfo = await createTestWidget(page, widgetName, URLS.TEST_PAGE_URL)
  
  // Set file upload limit to 1
  await updateWidgetSettings(page, widgetName, {
    allowFileUpload: true,
    fileUploadLimit: 1,
  })

  const apiUrl = getApiUrl()
  const testPageHtml = createWidgetTestPage(widgetInfo.widgetId, apiUrl)
  await page.setContent(testPageHtml, { waitUntil: 'domcontentloaded' })

  // Open widget
  const widgetButton = page.locator(selectors.widget.button)
  await widgetButton.waitFor({ state: 'visible', timeout: TIMEOUTS.LONG })
  await widgetButton.click()

  const widgetHost = page.locator(selectors.widget.host)
  await widgetHost.waitFor({ state: 'attached', timeout: TIMEOUTS.LONG })
  const chatWindow = widgetHost.locator(selectors.widget.chatWindow)
  await chatWindow.waitFor({ state: 'visible', timeout: TIMEOUTS.LONG })

  // Upload first file (should succeed)
  const fileInput = widgetHost.locator(selectors.widget.fileInput)
  const firstFilePath = path.join(__dirname, '../test_fixtures/most_important_thing.txt')
  await fileInput.setInputFiles(firstFilePath)

  // Verify first file appears
  const firstFileName = widgetHost.locator('text=most_important_thing.txt')
  await expect(firstFileName).toBeVisible({ timeout: TIMEOUTS.STANDARD })

  // TODO: Add data-testid attributes to error containers in ChatWidget.vue
  // - fileUploadError container (line ~295)
  // - fileSizeError container (line ~348)
  // - fileUploadLimitReached container (line ~305)
  // Then update selectors to use data-testid instead of CSS classes

  // Verify no error messages after first upload (red/amber text indicates errors)
  const errorMessages = widgetHost.locator('.text-red-600, .text-red-400, .text-amber-600, .text-amber-400')
  await expect(errorMessages).toHaveCount(0)

  // Try to upload second file (should fail with limit error)
  // Set files directly without clicking button (CI-compatible)
  const secondFilePath1 = path.join(__dirname, '../test_fixtures/most_important_thing.txt')
  const secondFilePath2 = path.join(__dirname, '../test_fixtures/second_most_important_thing.txt')
  await fileInput.setInputFiles([secondFilePath1, secondFilePath2])

  // Verify error message appears (fileUploadLimitReached - shown as red/amber text)
  const errorMessage = errorMessages.filter({ hasText: /file.*upload.*limit|limit.*reached/i })
  await expect(errorMessage).toBeVisible({ timeout: TIMEOUTS.STANDARD })
  
  // Verify error message text contains limit information
  const errorText = await errorMessage.textContent()
  expect(errorText?.toLowerCase()).toMatch(/file.*upload.*limit|limit.*reached/i)

  // Verify only one file is shown (second file should not be added)
  const fileCount = await widgetHost.locator('text=most_important_thing.txt').count()
  expect(fileCount).toBe(1)
})

test('@smoke @widget Widget max file size enforced id=019', async ({ page }) => {
  await login(page)

  const widgetName = WIDGET_NAMES.unique('Test Widget Max File Size')
  const widgetInfo = await createTestWidget(page, widgetName, URLS.TEST_PAGE_URL)
  
  // Set max file size to 1MB
  await updateWidgetSettings(page, widgetName, {
    allowFileUpload: true,
    maxFileSize: 1, // 1MB
  })

  const apiUrl = getApiUrl()
  const testPageHtml = createWidgetTestPage(widgetInfo.widgetId, apiUrl)
  await page.setContent(testPageHtml, { waitUntil: 'domcontentloaded' })

  // Open widget
  const widgetButton = page.locator(selectors.widget.button)
  await widgetButton.waitFor({ state: 'visible', timeout: TIMEOUTS.LONG })
  await widgetButton.click()

  const widgetHost = page.locator(selectors.widget.host)
  await widgetHost.waitFor({ state: 'attached', timeout: TIMEOUTS.LONG })
  const chatWindow = widgetHost.locator(selectors.widget.chatWindow)
  await chatWindow.waitFor({ state: 'visible', timeout: TIMEOUTS.LONG })

  // Try to upload file larger than 1MB (sky_scrapers.jpg is ~1.38MB)
  // Set file directly without clicking button (CI-compatible)
  const fileInput = widgetHost.locator(selectors.widget.fileInput)
  const largeFilePath = path.join(__dirname, '../test_fixtures/sky_scrapers.jpg')
  await fileInput.setInputFiles(largeFilePath)

  // TODO: Add data-testid attributes to error containers in ChatWidget.vue
  // - fileUploadError container (line ~295)
  // - fileSizeError container (line ~348)
  // - fileUploadLimitReached container (line ~305)
  // Then update selectors to use data-testid instead of CSS classes

  // Verify file size error message appears (red text indicates error)
  // Error text is: "File size exceeds {max}MB limit" (e.g. "File size exceeds 1MB limit")
  // Error disappears after 3 seconds, so we need to check quickly
  const errorMessages = widgetHost.locator('.text-red-600, .text-red-400')
  const fileSizeError = errorMessages.filter({ hasText: /exceeds|too.*large|file.*size.*limit|limit/i })
  await expect(fileSizeError).toBeVisible({ timeout: TIMEOUTS.STANDARD })
  
  // Verify error message contains "exceeds" or "limit" (actual error text format)
  const errorText = await fileSizeError.textContent()
  expect(errorText?.toLowerCase()).toMatch(/exceeds|too.*large|file.*size.*limit|limit/i)
  
  // Verify file is not added to the selection
  const fileName = widgetHost.locator('text=sky_scrapers.jpg')
  await expect(fileName).not.toBeVisible({ timeout: TIMEOUTS.SHORT })
})

test('@noci @smoke @widget Widget task prompt is applied id=017', async ({ page }) => {
  await login(page)

  const widgetName = WIDGET_NAMES.unique('Test Widget Task Prompt')
  const widgetInfo = await createTestWidget(page, widgetName, URLS.TEST_PAGE_URL)

  const filePath = path.join(__dirname, '../test_fixtures/most_important_thing.txt')
  const customTaskPrompt = `You are a helpful assistant. 

When users ask about the most important thing in the world, you should reference the information from the uploaded files in the knowledge base. Be helpful and provide accurate answers based on the information available in the knowledge base.`
  
  await setWidgetTaskPrompt(page, widgetName, customTaskPrompt, undefined, filePath)

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
  await input.fill('What is the most important thing in the world?')
  await widgetHost.locator(selectors.widget.sendButton).click()

  const previousCount = await countWidgetMessages(page)
  const aiText = await waitForWidgetAnswer(page, previousCount)
  
  expect(aiText.length).toBeGreaterThan(0)
})

