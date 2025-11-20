import { test, expect } from '@playwright/test';
import { selectors } from '../helpers/selectors';
import { login } from '../helpers/auth';

const PROMPT = 'hi, this is a smoke test. Answer with "success" add nothing else';

test('@smoke Standard model generates valid answer "success" id=003', async ({ page }) => {

  await login;

  await page.locator(selectors.nav.newChatButton).waitFor({ state: 'visible' });
  await page.click(selectors.nav.newChatButton);
  await page.fill(selectors.chat.textInput, PROMPT);
  await page.click(selectors.chat.sendBtn);

  const loader = page.locator(selectors.chat.loadIndicator);
  await loader.waitFor({ state: 'visible', timeout: 5000 }).catch(() => {});
  await loader.waitFor({ state: 'hidden' });

  const aiBubble = page.locator(selectors.chat.aiAnswerBubble).last();
  const aiAnswer = aiBubble.locator(selectors.chat.messageText);
  const aiText = (await aiAnswer.innerText()).trim().toLowerCase();

  await expect(aiText).toContain('success');
});


test('@smoke All models can generate a valid answer "success" id=004', async ({ page }) => {
  await login(page);
  await page.locator(selectors.nav.newChatButton).waitFor({ state: 'visible' });
  await page.click(selectors.nav.newChatButton);
  await page.fill(selectors.chat.textInput, PROMPT);
  await page.click(selectors.chat.sendBtn);

  await page.locator(selectors.chat.loadIndicator)
    .waitFor({ state: 'visible', timeout: 5000 }).catch(() => {});
  await page.locator(selectors.chat.loadIndicator)
    .waitFor({ state: 'hidden' });

  let aiText = (await page
    .locator(selectors.chat.aiAnswerBubble)
    .last()
    .locator(selectors.chat.messageText)
    .innerText()
  ).trim().toLowerCase();

  await expect(aiText).toContain('success');

await page.locator(selectors.chat.againDropdown).click();

const modelCount = await page.locator(selectors.chat.againDropdownItem).count();

await page.locator(selectors.chat.againDropdown).click();

for (let i = 0; i < modelCount; i += 1) {

  const toggle = page
    .locator(selectors.chat.aiAnswerBubble)
    .last()
    .locator(selectors.chat.againDropdown);

  await toggle.click();

  const model = page.locator('button.dropdown-item').nth(i);

  await model.waitFor({ state: 'visible' });

  const labelText = (await model.innerText()).toLowerCase().trim();
  console.log('model', i, '| Label:', labelText);

  if (labelText.includes('ollama')) {
    await toggle.click();
    continue;
  }

  await model.click();
  await page.locator(selectors.chat.loadIndicator)
    .waitFor({ state: 'visible', timeout: 5000 }).catch(() => {});
  await page.locator(selectors.chat.loadIndicator)
    .waitFor({ state: 'hidden' });

  const aiText = (
    await page
      .locator(selectors.chat.aiAnswerBubble)
      .last()
      .locator(selectors.chat.messageText)
      .innerText()
  ).trim().toLowerCase();

  await expect.soft(aiText).toContain('success');
}

});

