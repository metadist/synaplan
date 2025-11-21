import type { Page } from '@playwright/test';
import { selectors } from '../helpers/selectors';

export async function login(
  page: Page,
  credentials?: { user: string; pass: string }
) {
  const user = credentials?.user ?? process.env.AUTH_USER;
  const pass = credentials?.pass ?? process.env.AUTH_PASS;

  if (!user || !pass) {
    throw new Error(
      'Login-Credentials fehlen. Setze AUTH_USER und AUTH_PASS in ENV oder übergebe credentials.'
    );
  }

  await page.goto('/login');

  await page.fill(selectors.login.email, user);
  await page.fill(selectors.login.password, pass);
  await page.click(selectors.login.submit);

  try {
    await page.waitForSelector(selectors.chat.textInput, { timeout: 10_000 });
  } catch {
    throw new Error(`Login fehlgeschlagen. Aktuelle URL: ${page.url()}`);
  }
}



