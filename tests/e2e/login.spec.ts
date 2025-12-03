import { test, expect } from '@playwright/test';
import { login } from '../helpers/auth';
import { selectors } from '../helpers/selectors';

test.describe('Login', () => {
  test('@smoke should successfully login id=002', async ({ page }) => {
    await login(page);
    await expect(page.locator(selectors.chat.textInput)).toBeVisible({ timeout: 10_000 });
  });
});
