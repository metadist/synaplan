import { test, expect } from '@playwright/test';
import { login } from '../helpers/auth';
import { selectors } from '../helpers/selectors';

  test('@auth should successfully login id=002', async ({ page }) => {
    await login(page);
    await expect(page.locator(selectors.chat.textInput)).toBeVisible({ timeout: 10_000 });
  });
  
  test('@auth logout should clear session id=005', async ({ page }) => {
    await login(page);

    await page.locator(selectors.userMenu.button).waitFor({ state: 'visible' });
    await page.locator(selectors.userMenu.button).click();
    await page.locator(selectors.userMenu.logoutBtn).click();

    await expect(page.locator(selectors.login.email)).toBeVisible({ timeout: 10_000 });
    await expect(page).toHaveURL(/login/);

    await page.goBack();
    await expect(page.locator(selectors.login.email)).toBeVisible({ timeout: 10_000 });
    await expect(page).toHaveURL(/login/);

    await page.goto('/profile');
    await expect(page.locator(selectors.login.email)).toBeVisible({ timeout: 10_000 });
    await expect(page).toHaveURL(/login/);
  });
