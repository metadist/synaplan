import type { Page } from '@playwright/test'
import { expect } from '@playwright/test'
import { TIMEOUTS, INTERVALS, getApiUrl } from '../config/config'
import { WIDGET_DEFAULTS, WIDGET_TEST_URLS } from '../config/test-data'
import { selectors } from './selectors'
import path from 'path'

export { getApiUrl }

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
      position: '${WIDGET_DEFAULTS.POSITION}',
      primaryColor: '${WIDGET_DEFAULTS.PRIMARY_COLOR}',
      iconColor: '${WIDGET_DEFAULTS.ICON_COLOR}',
      defaultTheme: '${WIDGET_DEFAULTS.DEFAULT_THEME}',
      lazy: ${WIDGET_DEFAULTS.LAZY_LOAD},
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
  return messagesContainer.locator(selectors.widget.messageContainers).count()
}

export async function waitForWidgetAnswer(page: Page, previousCount: number): Promise<string> {
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

  let previousText = ''
  let stableText = ''
  await expect
    .poll(
      async () => {
        const widgetHost = page.locator(selectors.widget.host)
        const aiTextElement = widgetHost.locator(selectors.widget.messageAiText).last()
        
        const exists = await aiTextElement.count() > 0
        if (!exists) return null
        
        const currentText = (await aiTextElement.innerText()).trim().toLowerCase()
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
  await page.locator(selectors.widgets.advancedConfig.maxFileSizeInput).fill(finalSettings.maxFileSize.toString())
  
  const fileUploadLabel = behaviorSection.locator('label').filter({ hasText: /file.*upload|datei.*upload/i })
  const fileUploadCheckbox = fileUploadLabel.locator('input[type="checkbox"]')
  const fileUploadChecked = await fileUploadCheckbox.isChecked()
  if (fileUploadChecked !== finalSettings.allowFileUpload) {
    await fileUploadLabel.click()
    if (finalSettings.allowFileUpload) {
      await behaviorSection.locator('[data-testid="input-file-limit"]').waitFor({ state: 'visible', timeout: TIMEOUTS.SHORT })
    }
  }
  
  if (finalSettings.allowFileUpload) {
    const fileUploadLimitInput = behaviorSection.locator('[data-testid="input-file-limit"]')
    await fileUploadLimitInput.waitFor({ state: 'visible', timeout: TIMEOUTS.SHORT })
    await fileUploadLimitInput.fill(finalSettings.fileUploadLimit.toString())
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
    
    await assistantSection.locator('.animate-spin').waitFor({ state: 'hidden', timeout: TIMEOUTS.VERY_LONG }).catch(() => {})
    
    const fileName = path.basename(filePath)
    await assistantSection.locator('text=' + fileName).waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })
  }
  
  if (!needsSaveForFileUpload || filePath) {
    await saveAdvancedConfig(page)
  }
}
