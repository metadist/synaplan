import { test, expect } from '@playwright/test';
import { login } from '../helpers/auth';
import { selectors } from '../helpers/selectors';

test.describe('Dashboard Load Smoke Test', () => {
  test.beforeEach(async ({ page }) => {
    // Login vor jedem Test
    await login(page);
  });

  test('@smoke sollte Chat anzeigen und antworten können id=003', async ({ page }) => {
    await page.getByRole('textbox', { name: 'Type your message...' }).click();
    await page.getByRole('textbox', { name: 'Type your message...' }).fill('hi');
    await page.getByText('Hello! How can I assist you').nth(1).click();
    await expect(page.locator(selectors.chat.widget)).toBeVisible({ timeout: 5_000 });
  });


  test('@smoke alle Modelle generieren eine Antwort id=004', async ({ page }) => {     
  });

});

