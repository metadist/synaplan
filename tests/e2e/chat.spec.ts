import { test, expect } from '@playwright/test';
import { login } from '../helpers/auth';
import { selectors } from '../helpers/selectors';

test.describe('Dashboard Load Smoke Test', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
  });

  test('@smoke sollte Chat anzeigen und antworten können id=003', async ({ page }) => {
    await page.locator(selectors.nav.newChatButton).click();
    await page.locator(selectors.chat.textInput).fill('hi, this is a smoke test. Answer with "success" add nothing else');
    await expect()
  });


  test('@smoke alle Modelle generieren eine Antwort id=004', async ({ page }) => {     
  });

});

