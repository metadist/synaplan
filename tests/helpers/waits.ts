import { Page } from '@playwright/test';

/**
 * Wartet auf networkidle und optional auf einen Loader-Selektor
 * @param page Playwright Page-Objekt
 * @param loaderSelector Optional: CSS-Selektor für Loader (z.B. '[data-testid="loader"]')
 */
export async function waitForIdle(
  page: Page,
  loaderSelector?: string
): Promise<void> {
  // Warte auf networkidle (keine Netzwerkaktivität für 500ms)
  await page.waitForLoadState('networkidle');

  // Optional: Warte bis Loader verschwindet
  if (loaderSelector) {
    try {
      await page.waitForSelector(loaderSelector, { state: 'hidden', timeout: 5000 });
    } catch {
      // Loader nicht gefunden oder bereits verschwunden - ok
    }
  }
}

