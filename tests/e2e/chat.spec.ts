import { test, expect, type Page } from '@playwright/test';
import { selectors } from '../helpers/selectors';
import { login } from '../helpers/auth';

const PROMPT = 'Ai, this is a smoke test. Answer with "success" add nothing else';

async function waitForAnswer(page: Page, previousCount: number): Promise<string> {
  const loader = page.locator(selectors.chat.loadIndicator);
  await loader.waitFor({ state: 'visible', timeout: 5_000 }).catch(() => {});
  await loader.waitFor({ state: 'hidden' });

  const bubbles = page.locator(selectors.chat.aiAnswerBubble);
  await expect(bubbles).toHaveCount(previousCount + 1, { timeout: 30_000 });

  const answer = bubbles.nth(previousCount).locator(selectors.chat.messageText);
  return (await answer.innerText()).trim().toLowerCase();
}

test('@smoke Standard model generates valid answer "success" id=003', async ({ page }) => {
  await login(page);

  await page.locator(selectors.nav.newChatButton).waitFor({ state: 'visible' });
  await page.locator(selectors.nav.newChatButton).click();
  await page.locator(selectors.chat.textInput).waitFor({ state: 'visible' });

  const previousCount = await page.locator(selectors.chat.aiAnswerBubble).count();
  await page.locator(selectors.chat.textInput).fill(PROMPT);
  await page.locator(selectors.chat.sendBtn).click();

  const aiText = await waitForAnswer(page, previousCount);
  await expect.soft(aiText).toContain('success');
});

test('@smoke All models can generate a valid answer "success" id=004', async ({ page }) => {
  const failures: string[] = [];

  await login(page);

  await page.locator(selectors.nav.newChatButton).waitFor({ state: 'visible' });
  await page.locator(selectors.nav.newChatButton).click();
  await page.locator(selectors.chat.textInput).waitFor({ state: 'visible' });

  try {
    const previousCount = await page.locator(selectors.chat.aiAnswerBubble).count();
    await page.locator(selectors.chat.textInput).fill(PROMPT);
    await page.locator(selectors.chat.sendBtn).click();

    const aiText = await waitForAnswer(page, previousCount);
    await expect.soft(aiText, 'Initial model should answer').toContain('success');
  } catch (error) {
    failures.push(
      `Initial message failed: ${error instanceof Error ? error.message : String(error)}`
    );
  }

  await page.locator(selectors.chat.againDropdown).waitFor({ state: 'visible' });
  await page.locator(selectors.chat.againDropdown).click();

  const modelCount = await page.locator(selectors.chat.againDropdownItem).count();
  await expect(modelCount).toBeGreaterThan(0);

  await page.locator(selectors.chat.againDropdown).click();

  for (let i = 0; i < modelCount; i += 1) {
    let labelText = '';

    try {
      const toggle = page
        .locator(selectors.chat.aiAnswerBubble)
        .last()
        .locator(selectors.chat.againDropdown);

      await toggle.waitFor({ state: 'visible', timeout: 10_000 });
      await toggle.click();

      const option = page.locator(selectors.chat.againDropdownItem).nth(i);
      await option.waitFor({ state: 'visible' });

      labelText = (await option.innerText()).toLowerCase().trim();
      if (labelText.includes('ollama')) {
        await toggle.click();
        continue;
      }

      const previousCount = await page.locator(selectors.chat.aiAnswerBubble).count();
      await option.click();

      const aiText = await waitForAnswer(page, previousCount);
      await expect.soft(aiText, `Model ${labelText} should answer`).toContain('success');
    } catch (error) {
      failures.push(
        `Model ${i} (${labelText || 'unknown'}): ${
          error instanceof Error ? error.message : String(error)
        }`
      );
    }
  }

  if (failures.length > 0) {
    console.warn('Model run issues:', failures);
    await expect.soft(failures, 'All models should respond without errors').toEqual([]);
  }
});
