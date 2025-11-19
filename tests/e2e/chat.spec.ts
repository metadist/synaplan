import { test, expect } from '@playwright/test';
import { login } from '../helpers/auth';
import { selectors } from '../helpers/selectors';

  test('Chat generates valid answer "success" id=003', async ({ page }) => {
    await login(page);
    await page.locator(selectors.nav.newChatButton).click();
    await page.locator(selectors.chat.textInput)
      .fill('hi, this is a smoke test. Answer with "success" add nothing else');
    await page.locator(selectors.chat.sendBtn).click();

    const loadingIndicator = page.locator(selectors.chat.loadIndicator);
    await loadingIndicator.waitFor({ state: 'visible', timeout: 5000 }).catch(() => {});
    await loadingIndicator.waitFor({ state: 'hidden' });

    const aiBubble = page.locator(selectors.chat.aiAnswerBubble).last();
    const aiAnswer = aiBubble.locator(selectors.chat.messageText);
    const aiText = (await aiAnswer.innerText()).trim().toLowerCase();

    await expect(aiText).toBe('success');
  });

   test('All models can generate a valid answer "success" id=004', async ({ page }) => {

  });


