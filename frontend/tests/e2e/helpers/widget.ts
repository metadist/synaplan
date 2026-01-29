import type { Page } from '@playwright/test'
import { expect } from '@playwright/test'
import { TIMEOUTS, INTERVALS, getApiUrl } from '../config/config'
import { WIDGET_DEFAULTS } from '../config/test-data'
import { selectors } from './selectors'
import path from 'path'

// Re-export getApiUrl from config for backwards compatibility
export { getApiUrl }

/**
 * Create HTML test page for widget embedding
 * Uses domcontentloaded instead of networkidle for better reliability
 */
export function createWidgetTestPage(widgetId: string, apiUrl: string): string {
  return `<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><title>Widget Test</title></head>
<body>
  <h1>Widget Test Page</h1>
  <script type="module">
    import SynaplanWidget from '${apiUrl}/widget.js'
    SynaplanWidget.init({
      widgetId: '${widgetId}',
      position: 'bottom-right',
      primaryColor: '#007bff',
      iconColor: '#ffffff',
      defaultTheme: 'light',
      lazy: true,
      apiUrl: '${apiUrl}'
    })
  </script>
</body>
</html>`
}

export async function countWidgetMessages(page: Page): Promise<number> {
  const widgetHost = page.locator(selectors.widget.host)
  const messagesContainer = widgetHost.locator(selectors.widget.messagesContainer)
  await messagesContainer.waitFor({ state: 'attached', timeout: TIMEOUTS.SHORT }).catch(() => {})
  return await messagesContainer.locator('> div').count()
}

export async function waitForWidgetAnswer(page: Page, previousCount: number): Promise<string> {
  // Wait for a new message to appear (count increases)
  // Always create locators fresh inside poll to avoid stale references
  await expect
    .poll(
      async () => {
        const widgetHost = page.locator(selectors.widget.host)
        const messagesContainer = widgetHost.locator(selectors.widget.messagesContainer)
        const currentCount = await messagesContainer.locator('> div').count()
        return currentCount > previousCount ? currentCount : null
      },
      { timeout: TIMEOUTS.VERY_LONG, intervals: INTERVALS.FAST() }
    )
    .not.toBeNull()

  // Get the last message (should be the new AI response) - create fresh locator
  const widgetHost = page.locator(selectors.widget.host)
  const messagesContainer = widgetHost.locator(selectors.widget.messagesContainer)
  const newMessage = messagesContainer.locator('> div').last()
  await newMessage.waitFor({ state: 'visible', timeout: TIMEOUTS.SHORT })
  
  // Wait for typing indicator to disappear
  await newMessage.locator('.animate-bounce').waitFor({ state: 'hidden', timeout: TIMEOUTS.VERY_LONG }).catch(() => {})

  // Wait for text to stabilize (no more changes)
  // Always create locators fresh inside poll to avoid stale references
  let previousText = ''
  let stableText = ''
  await expect
    .poll(
      async () => {
        const widgetHost = page.locator(selectors.widget.host)
        const messagesContainer = widgetHost.locator(selectors.widget.messagesContainer)
        const lastMessage = messagesContainer.locator('> div').last()
        const textElement = lastMessage.locator('p').first()
        const exists = await textElement.count() > 0
        if (!exists) return null
        
        const currentText = (await textElement.innerText()).trim().toLowerCase()
        if (currentText.length > 0 && currentText === previousText) {
          stableText = currentText
          return currentText
        }
        previousText = currentText
        return null
      },
      { timeout: TIMEOUTS.STANDARD, intervals: INTERVALS.FAST() }
    )
    .not.toBeNull()

  return stableText
}

export async function createTestWidget(
  page: Page,
  name: string,
  websiteUrl: string = 'https://example.com'
): Promise<{ widgetId: string; name: string }> {
  await page.goto('/tools/chat-widget')
  await page.waitForSelector(selectors.widgets.page, { timeout: TIMEOUTS.LONG })
  await page.click(selectors.widgets.createButton)
  await page.waitForSelector(selectors.widgets.simpleForm.modal, { timeout: TIMEOUTS.SHORT })

  await page.fill(selectors.widgets.simpleForm.nameInput, name)
  await page.fill(selectors.widgets.simpleForm.websiteInput, websiteUrl)
  await page.click(selectors.widgets.simpleForm.createButton)

  await page.waitForSelector(selectors.widgets.successModal.modal, { timeout: TIMEOUTS.STANDARD })

  // Wait for embed code to load (it's loaded asynchronously via API in onMounted)
  const embedCodeElement = page.locator(selectors.widgets.successModal.modal).locator('pre code')
  await expect
    .poll(
      async () => {
        const text = await embedCodeElement.textContent()
        return text && text.includes('widgetId') && text.trim().length > 0 ? text : null
      },
      { timeout: TIMEOUTS.STANDARD, intervals: INTERVALS.FAST() }
    )
    .not.toBeNull()

  const embedCode = await embedCodeElement.textContent()
  const widgetIdMatch = embedCode?.match(/widgetId:\s*['"]([^'"]+)['"]/)
  if (!widgetIdMatch?.[1]) {
    throw new Error('Could not extract widgetId from embed code')
  }

  const closeButton = page.locator(selectors.widgets.successModal.modal).locator(selectors.widgets.successModal.closeButton).last()
  await closeButton.scrollIntoViewIfNeeded()
  await closeButton.click()
  await page.waitForSelector(selectors.widgets.successModal.modal, { state: 'hidden', timeout: TIMEOUTS.SHORT })

  return { widgetId: widgetIdMatch[1], name }
}

/**
 * Open widget advanced config modal (gear icon) and update settings
 * Navigates to the behavior tab and updates settings like a user would
 * 
 * Automatically sets all default values for fields not explicitly provided.
 * This makes tests resilient against backend default changes - tests only
 * need to specify values they want to change from defaults.
 */
export async function updateWidgetSettings(
  page: Page,
  widgetName: string,
  settings: {
    autoMessage?: string
    autoOpen?: boolean
    allowFileUpload?: boolean
    isActive?: boolean
    messageLimit?: number
    maxFileSize?: number
    fileUploadLimit?: number
  }
): Promise<void> {
  // Merge provided settings with defaults (provided settings take precedence)
  const finalSettings = {
    autoOpen: settings.autoOpen ?? WIDGET_DEFAULTS.AUTO_OPEN,
    allowFileUpload: settings.allowFileUpload ?? WIDGET_DEFAULTS.ALLOW_FILE_UPLOAD,
    isActive: settings.isActive ?? WIDGET_DEFAULTS.IS_ACTIVE,
    autoMessage: settings.autoMessage ?? WIDGET_DEFAULTS.AUTO_MESSAGE,
    messageLimit: settings.messageLimit ?? WIDGET_DEFAULTS.MESSAGE_LIMIT,
    maxFileSize: settings.maxFileSize ?? WIDGET_DEFAULTS.MAX_FILE_SIZE,
    fileUploadLimit: settings.fileUploadLimit ?? WIDGET_DEFAULTS.FILE_UPLOAD_LIMIT,
  }

  // Find widget by name and open advanced config
  const widgetCard = page.locator(selectors.widgets.widgetCard.item).filter({ hasText: widgetName }).first()
  await widgetCard.waitFor({ state: 'visible', timeout: TIMEOUTS.SHORT })
  
  await widgetCard.locator(selectors.widgets.widgetCard.advancedButton).click()
  
  // Wait for modal to open
  await page.waitForSelector(selectors.widgets.advancedConfig.modal, { timeout: TIMEOUTS.STANDARD })
  
  // Navigate to behavior tab (all settings are in behavior tab)
  // Tabs are: branding, behavior, security, assistant - behavior is the second tab
  const tabs = page.locator(selectors.widgets.advancedConfig.tabButton)
  const tabCount = await tabs.count()
  for (let i = 0; i < tabCount; i++) {
    const tab = tabs.nth(i)
    const tabText = (await tab.innerText()).toLowerCase().trim()
    if (tabText.includes('behavior') || tabText.includes('verhalten')) {
      await tab.click()
      break
    }
  }
  
  // Wait for behavior tab content
  await page.waitForSelector(selectors.widgets.advancedConfig.behaviorTab, { timeout: TIMEOUTS.SHORT })
  
  // Get behavior section locator (used for multiple settings)
  const behaviorSection = page.locator(selectors.widgets.advancedConfig.behaviorTab)
  
  // Update autoMessage (always set to ensure default is applied)
  await page.locator(selectors.widgets.advancedConfig.autoMessageInput).fill(finalSettings.autoMessage)
  
  // Update messageLimit (always set to ensure default is applied)
  await page.locator(selectors.widgets.advancedConfig.messageLimitInput).fill(finalSettings.messageLimit.toString())
  
  // Update maxFileSize (always set to ensure default is applied)
  await page.locator(selectors.widgets.advancedConfig.maxFileSizeInput).fill(finalSettings.maxFileSize.toString())
  
  // Update allowFileUpload first (must be enabled before fileUploadLimit can be set)
  const fileUploadLabel = behaviorSection.locator('label').filter({ hasText: /file.*upload|datei.*upload/i })
  const fileUploadCheckbox = fileUploadLabel.locator('input[type="checkbox"]')
  const fileUploadChecked = await fileUploadCheckbox.isChecked()
  if (fileUploadChecked !== finalSettings.allowFileUpload) {
    await fileUploadLabel.click()
    // Wait for fileUploadLimit input to become visible after enabling file upload
    if (finalSettings.allowFileUpload) {
      await behaviorSection.locator('[data-testid="input-file-limit"]').waitFor({ state: 'visible', timeout: TIMEOUTS.SHORT })
    }
  }
  
  // Update fileUploadLimit (only if allowFileUpload is enabled - input is only visible when enabled)
  if (finalSettings.allowFileUpload) {
    const fileUploadLimitInput = behaviorSection.locator('[data-testid="input-file-limit"]')
    await fileUploadLimitInput.waitFor({ state: 'visible', timeout: TIMEOUTS.SHORT })
    await fileUploadLimitInput.fill(finalSettings.fileUploadLimit.toString())
  }
  
  // Update autoOpen: click the label (native checkbox is sr-only, visual toggle is a styled div)
  const autoOpenLabel = behaviorSection.locator('label').filter({ hasText: /auto.*open|automatisch.*öffnen/i })
  const autoOpenCheckbox = autoOpenLabel.locator('input[type="checkbox"]')
  const autoOpenChecked = await autoOpenCheckbox.isChecked()
  if (autoOpenChecked !== finalSettings.autoOpen) {
    await autoOpenLabel.click()
  }
  
  // Update isActive: click the label (native checkbox is sr-only, visual toggle is a styled div)
  const activeLabel = behaviorSection.locator('label').filter({ hasText: /widget.*active|widget.*aktiv/i }).first()
  const activeCheckbox = activeLabel.locator('input[type="checkbox"]')
  const activeChecked = await activeCheckbox.isChecked()
  if (activeChecked !== finalSettings.isActive) {
    await activeLabel.click()
  }
  
  // Save changes
  await page.locator(selectors.widgets.advancedConfig.saveButton).scrollIntoViewIfNeeded()
  await page.locator(selectors.widgets.advancedConfig.saveButton).click()
  
  // Wait for modal to close (indicates save completed)
  await page.waitForSelector(selectors.widgets.advancedConfig.modal, { state: 'hidden', timeout: TIMEOUTS.STANDARD })
}

/**
 * Navigate to assistant tab in advanced config modal
 */
async function navigateToAssistantTab(page: Page): Promise<void> {
  const tabs = page.locator(selectors.widgets.advancedConfig.tabButton)
  const tabCount = await tabs.count()
  for (let i = 0; i < tabCount; i++) {
    const tab = tabs.nth(i)
    const tabText = (await tab.innerText()).toLowerCase().trim()
    if (tabText.includes('assistant') || tabText.includes('assistent')) {
      await tab.click()
      break
    }
  }
  await page.waitForSelector(selectors.widgets.advancedConfig.assistantTab, { timeout: TIMEOUTS.SHORT })
}

/**
 * Save advanced config and wait for modal to close
 */
async function saveAdvancedConfig(page: Page): Promise<void> {
  await page.locator(selectors.widgets.advancedConfig.saveButton).scrollIntoViewIfNeeded()
  await page.locator(selectors.widgets.advancedConfig.saveButton).click()
  await page.waitForSelector(selectors.widgets.advancedConfig.modal, { state: 'hidden', timeout: TIMEOUTS.STANDARD })
}

/**
 * Set custom task prompt for a widget
 * Optionally uploads a file to the knowledge base
 */
export async function setWidgetTaskPrompt(
  page: Page,
  widgetName: string,
  promptContent: string,
  selectionRules?: string,
  filePath?: string
): Promise<void> {
  const widgetCard = page.locator(selectors.widgets.widgetCard.item).filter({ hasText: widgetName }).first()
  await widgetCard.waitFor({ state: 'visible', timeout: TIMEOUTS.SHORT })
  
  await widgetCard.locator(selectors.widgets.widgetCard.advancedButton).click()
  await page.waitForSelector(selectors.widgets.advancedConfig.modal, { timeout: TIMEOUTS.STANDARD })
  
  await navigateToAssistantTab(page)
  
  const manualCreateButton = page.locator('[data-testid="btn-manual-create"]')
  const isManualCreateVisible = await manualCreateButton.isVisible().catch(() => false)
  
  if (isManualCreateVisible) {
    await manualCreateButton.click()
    await page.locator('[data-testid="section-assistant"]').locator('.animate-spin').waitFor({ state: 'hidden', timeout: TIMEOUTS.STANDARD }).catch(() => {})
    await page.locator(selectors.widgets.advancedConfig.promptContentInput).waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })
  }
  
  const promptContentInput = page.locator(selectors.widgets.advancedConfig.promptContentInput)
  await promptContentInput.waitFor({ state: 'visible', timeout: TIMEOUTS.SHORT })
  await promptContentInput.fill(promptContent)
  
  if (selectionRules) {
    const selectionRulesInput = page.locator(selectors.widgets.advancedConfig.selectionRulesInput)
    await selectionRulesInput.waitFor({ state: 'visible', timeout: TIMEOUTS.SHORT })
    await selectionRulesInput.fill(selectionRules)
  }
  
  // Save first if we created a manual prompt AND need to upload a file
  // File upload requires taskPromptTopic to be set, which happens on save
  const needsSaveForFileUpload = isManualCreateVisible && filePath
  
  if (needsSaveForFileUpload) {
    await saveAdvancedConfig(page)
    
    await widgetCard.locator(selectors.widgets.widgetCard.advancedButton).click()
    await page.waitForSelector(selectors.widgets.advancedConfig.modal, { timeout: TIMEOUTS.STANDARD })
    await navigateToAssistantTab(page)
  }
  
  if (filePath) {
    const assistantSection = page.locator(selectors.widgets.advancedConfig.assistantTab)
    const knowledgeBaseSection = assistantSection.locator('label').filter({ hasText: /knowledge.*base|wissensbasis/i }).first()
    await knowledgeBaseSection.scrollIntoViewIfNeeded()
    
    const fileInput = assistantSection.locator('input[type="file"]').first()
    await fileInput.waitFor({ state: 'attached', timeout: TIMEOUTS.SHORT })
    await fileInput.setInputFiles(filePath)
    
    await assistantSection.locator('.animate-spin').waitFor({ state: 'hidden', timeout: TIMEOUTS.VERY_LONG }).catch(() => {})
    
    const fileName = path.basename(filePath)
    await assistantSection.locator('text=' + fileName).waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })
  }
  
  if (!needsSaveForFileUpload || filePath) {
    await saveAdvancedConfig(page)
  }
}
