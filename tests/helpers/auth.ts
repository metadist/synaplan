import { Page } from '@playwright/test';
import { selectors } from '../helpers/selectors';
import { waitForIdle } from '../helpers/waits';

/**
 * Login-Helper mit ENV-Fallback
 * @param page Playwright Page-Objekt
 * @param credentials Optional: {user, pass} - falls nicht gesetzt, wird ENV verwendet
 * @returns Promise die resolved wenn Login erfolgreich
 */
export async function login(
  page: Page,
  credentials?: { user: string; pass: string }
): Promise<void> {
  const user = credentials?.user || process.env.AUTH_USER || '';
  const pass = credentials?.pass || process.env.AUTH_PASS || '';

  if (!user || !pass) {
    throw new Error('Login-Credentials fehlen. Setze AUTH_USER und AUTH_PASS in ENV oder übergebe credentials.');
  }

  // Navigiere zur Login-Seite
  await page.goto('/login');
  await waitForIdle(page);

  // Fülle Credentials
  await page.fill(selectors.login.email, user);
  await page.fill(selectors.login.password, pass);
  await page.click(selectors.login.submit);

  // Warte auf Login-Erfolg
  // Prüfe URL-Change ODER Dashboard-Marker (beide parallel)
  await Promise.race([
    page.waitForURL(/dashboard|home|app/, { timeout: 10_000 }),
    page.waitForSelector(selectors.chat.marker, { timeout: 10_000 }),
  ]).catch(async () => {
    // Wenn beide fehlschlagen, warte kurz und prüfe ob wir noch auf Login-Seite sind
    await page.waitForTimeout(2000);
    const url = page.url();
    if (url.includes('login')) {
      throw new Error(`Login fehlgeschlagen. Aktuelle URL: ${url}`);
    }
    // Nicht mehr auf Login-Seite = Login wahrscheinlich erfolgreich
  });

  await waitForIdle(page);
}

