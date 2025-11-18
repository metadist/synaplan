import { test, expect } from '@playwright/test';
import { login } from '../helpers/auth';
import { selectors } from '../helpers/selectors';

test.describe('Login Smoke Test', () => {
  test('@smoke sollte erfolgreich einloggen id=002', async ({ page }) => {
    await login(page);
    await expect(page.locator(selectors.chat.marker)).toBeVisible({ timeout: 10_000 });
  });
});
