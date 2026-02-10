import type { Page } from '@playwright/test'
import { expect } from '@playwright/test'
import { TIMEOUTS, INTERVALS, getApiUrl } from '../config/config'
import { WIDGET_DEFAULTS, WIDGET_TEST_URLS } from '../config/test-data'
import { selectors } from './selectors'
import path from 'path'

export { getApiUrl }

/** Go to widget-test.html with widgetId and apiUrl in query; works on dev (5173→8000) and test stack (8001). */
export async function gotoWidgetTestPage(page: Page, widgetId: string, apiUrl: string): Promise<void> {
  const url = `/widget-test.html?widgetId=${encodeURIComponent(widgetId)}&apiUrl=${encodeURIComponent(apiUrl)}`
  await page.goto(url, { waitUntil: 'networkidle' })
}

/** Open widget on test page: goto, click button, wait for chat window (Shadow DOM). */
export async function openWidgetOnTestPage(
  page: Page,
  widgetId: string,
  apiUrl: string
): Promise<void> {
  await gotoWidgetTestPage(page, widgetId, apiUrl)

  const widgetButton = page.locator(selectors.widget.button)
  await widgetButton.waitFor({ state: 'visible', timeout: TIMEOUTS.LONG })
  await widgetButton.click()

  const widgetHost = page.locator(selectors.widget.host)
  await widgetHost.waitFor({ state: 'attached', timeout: TIMEOUTS.LONG })
  const chatWindow = widgetHost.locator(selectors.widget.chatWindow)
  await chatWindow.waitFor({ state: 'visible', timeout: TIMEOUTS.LONG })
}

export async function countWidgetMessages(page: Page): Promise<number> {
  const widgetHost = page.locator(selectors.widget.host)
  const messagesContainer = widgetHost.locator(selectors.widget.messagesContainer)
  await messagesContainer.waitFor({ state: 'attached', timeout: TIMEOUTS.SHORT }).catch(() => {})
  return messagesContainer.locator(selectors.widget.messageContainers).count()
}

export async function waitForWidgetAnswer(page: Page, previousCount: number): Promise<string> {
  // Wait for a new message to appear
  await expect
    .poll(
      async () => {
        const currentCount = await countWidgetMessages(page)
        return currentCount > previousCount ? currentCount : null
      },
      { timeout: TIMEOUTS.VERY_LONG, intervals: INTERVALS.FAST() }
    )
    .not.toBeNull()

  const widgetHost = page.locator(selectors.widget.host)
  const aiTextElement = widgetHost.locator(selectors.widget.messageAiText).last()
  await aiTextElement.waitFor({ state: 'visible', timeout: TIMEOUTS.VERY_LONG })

  // Wait for stable text (tolerate empty reads from history reload flicker)
  let lastNonEmpty = ''
  let stableCount = 0
  await expect
    .poll(
      async () => {
        const host = page.locator(selectors.widget.host)
        const el = host.locator(selectors.widget.messageAiText).last()
        if ((await el.count()) === 0) return null
        const text = (await el.innerText()).trim().toLowerCase()
        if (text.length === 0) return null
        if (text === lastNonEmpty) {
          stableCount++
          if (stableCount >= 2) return text
          return null
        }
        lastNonEmpty = text
        stableCount = 1
        return null
      },
      { timeout: TIMEOUTS.VERY_LONG, intervals: INTERVALS.FAST() }
    )
    .not.toBeNull()

  return lastNonEmpty
}

export async function createTestWidget(
  page: Page,
  name: string,
  websiteUrl: string = WIDGET_TEST_URLS.EXAMPLE_DOMAIN
): Promise<{ widgetId: string; name: string }> {
  await page.goto('/tools/chat-widget')
  await page.waitForSelector(selectors.widgets.page, { timeout: TIMEOUTS.LONG })
  await page.click(selectors.widgets.createButton)
  await page.waitForSelector(selectors.widgets.simpleForm.modal, { timeout: TIMEOUTS.SHORT })

  await page.fill(selectors.widgets.simpleForm.nameInput, name)
  await page.fill(selectors.widgets.simpleForm.websiteInput, websiteUrl)
  await page.click(selectors.widgets.simpleForm.createButton)

  await page.waitForSelector(selectors.widgets.successModal.modal, { timeout: TIMEOUTS.STANDARD })

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
  await page.waitForSelector(selectors.widgets.advancedConfig.modal, { timeout: TIMEOUTS.STANDARD })
  
  const behaviorSection = page.locator(selectors.widgets.advancedConfig.behaviorTab)
  const isBehaviorTabVisible = await behaviorSection.isVisible().catch(() => false)
  if (!isBehaviorTabVisible) {
    await page.locator(selectors.widgets.advancedConfig.tabButtonBehavior).click()
    await behaviorSection.waitFor({ state: 'visible', timeout: TIMEOUTS.SHORT })
  }
  
  await page.locator(selectors.widgets.advancedConfig.autoMessageInput).fill(finalSettings.autoMessage)
  await page.locator(selectors.widgets.advancedConfig.messageLimitInput).fill(finalSettings.messageLimit.toString())
  // Toggle allowFileUpload – maxFileSize and fileUploadLimit are inside v-if="config.allowFileUpload"
  const fileUploadCheckbox = behaviorSection.locator(selectors.widgets.advancedConfig.allowFileUploadCheckbox)
  const fileUploadChecked = await fileUploadCheckbox.isChecked()
  if (fileUploadChecked !== finalSettings.allowFileUpload) {
    await behaviorSection.locator(selectors.widgets.advancedConfig.allowFileUploadLabel).click()
    if (finalSettings.allowFileUpload) {
      await behaviorSection.locator('[data-testid="input-file-limit"]').waitFor({ state: 'visible', timeout: TIMEOUTS.SHORT })
    }
  }

  if (finalSettings.allowFileUpload) {
    const fileUploadLimitInput = behaviorSection.locator('[data-testid="input-file-limit"]')
    await fileUploadLimitInput.waitFor({ state: 'visible', timeout: TIMEOUTS.SHORT })
    await fileUploadLimitInput.fill(finalSettings.fileUploadLimit.toString())
    await page.locator(selectors.widgets.advancedConfig.maxFileSizeInput).fill(finalSettings.maxFileSize.toString())
  }
  
  const autoOpenLabel = behaviorSection.locator('label').filter({ hasText: /auto.*open|automatisch.*öffnen/i })
  const autoOpenCheckbox = autoOpenLabel.locator('input[type="checkbox"]')
  const autoOpenChecked = await autoOpenCheckbox.isChecked()
  if (autoOpenChecked !== finalSettings.autoOpen) {
    await autoOpenLabel.click()
  }
  
  const activeLabel = behaviorSection.locator('label').filter({ hasText: /widget.*active|widget.*aktiv/i }).first()
  const activeCheckbox = activeLabel.locator('input[type="checkbox"]')
  const activeChecked = await activeCheckbox.isChecked()
  if (activeChecked !== finalSettings.isActive) {
    await activeLabel.click()
  }
  
  await page.locator(selectors.widgets.advancedConfig.saveButton).scrollIntoViewIfNeeded()
  await page.locator(selectors.widgets.advancedConfig.saveButton).click()
  await page.waitForSelector(selectors.widgets.advancedConfig.modal, { state: 'hidden', timeout: TIMEOUTS.STANDARD })
}


async function saveAdvancedConfig(page: Page): Promise<void> {
  await page.locator(selectors.widgets.advancedConfig.saveButton).scrollIntoViewIfNeeded()
  await page.locator(selectors.widgets.advancedConfig.saveButton).click()
  await page.waitForSelector(selectors.widgets.advancedConfig.modal, { state: 'hidden', timeout: TIMEOUTS.STANDARD })
}

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
  
  const assistantTab = page.locator(selectors.widgets.advancedConfig.assistantTab)
  const isAssistantTabVisible = await assistantTab.isVisible().catch(() => false)
  if (!isAssistantTabVisible) {
    await page.locator(selectors.widgets.advancedConfig.tabButtonAssistant).click()
    await assistantTab.waitFor({ state: 'visible', timeout: TIMEOUTS.SHORT })
  }
  
  const manualCreateButton = page.locator('[data-testid="btn-manual-create"]')
  const isManualCreateVisible = await manualCreateButton.isVisible().catch(() => false)
  
  if (isManualCreateVisible) {
    await manualCreateButton.click()
    // Wait for prompt content to appear (skip spinner – just wait for the target element)
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
  
  const needsSaveForFileUpload = isManualCreateVisible && filePath
  
  if (needsSaveForFileUpload) {
    await saveAdvancedConfig(page)
    
    await widgetCard.locator(selectors.widgets.widgetCard.advancedButton).click()
    await page.waitForSelector(selectors.widgets.advancedConfig.modal, { timeout: TIMEOUTS.STANDARD })
    const assistantTab = page.locator(selectors.widgets.advancedConfig.assistantTab)
    const isAssistantTabVisible = await assistantTab.isVisible().catch(() => false)
    if (!isAssistantTabVisible) {
      await page.locator(selectors.widgets.advancedConfig.tabButtonAssistant).click()
      await assistantTab.waitFor({ state: 'visible', timeout: TIMEOUTS.SHORT })
    }
  }
  
  if (filePath) {
    const assistantSection = page.locator(selectors.widgets.advancedConfig.assistantTab)
    const knowledgeBaseSection = assistantSection.locator('label').filter({ hasText: /knowledge.*base|wissensbasis/i }).first()
    await knowledgeBaseSection.scrollIntoViewIfNeeded()
    
    const fileInput = assistantSection.locator('input[type="file"]').first()
    await fileInput.waitFor({ state: 'attached', timeout: TIMEOUTS.SHORT })
    await fileInput.setInputFiles(filePath)
    
    // Wait for file to appear in the list (implicitly waits for upload/spinner to finish)
    const fileName = path.basename(filePath)
    await assistantSection.locator(`text=${fileName}`).waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })
  }
  
  if (!needsSaveForFileUpload || filePath) {
    await saveAdvancedConfig(page)
  }
}
