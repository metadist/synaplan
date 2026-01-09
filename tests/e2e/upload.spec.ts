import { test, expect } from '@playwright/test';
import { login } from '../helpers/auth';
import { selectors } from '../helpers/selectors';

test('@smoke upload file appears in list id=006', async ({ page }) => {
  await login(page);

  const modeToggle = page.locator(selectors.header.modeToggle);
  await modeToggle.waitFor({ state: 'visible' });
  const modeLabel = (await modeToggle.innerText()).toLowerCase();
  if (modeLabel.includes('easy')) {
    await modeToggle.click();
    await expect(modeToggle).toContainText(/advanced/i);
  }

  const sidebar = page.locator(selectors.nav.sidebar);
  await sidebar.waitFor({ state: 'visible' });

  const filesLink = sidebar.getByRole('link', { name: /files/i });
  if (await filesLink.count()) {
    await filesLink.first().click();
  } else {
    const filesToggle = sidebar.getByRole('button', { name: /files/i });
    await filesToggle.click();
    await sidebar.getByRole('link', { name: /file manager/i }).click();
  }

  await page.locator(selectors.files.page).waitFor({ state: 'visible' });

  const fileName = `upload-smoke-${Date.now()}.txt`;

  await page.locator(selectors.files.selectButton).click();
  await page.locator(selectors.files.fileInput).setInputFiles({
    name: fileName,
    mimeType: 'text/plain',
    buffer: Buffer.from('Smoke upload verification content.'),
  });

  const uploadButton = page.locator(selectors.files.uploadButton);
  await uploadButton.click();

  // Button stays disabled after upload because the file selection is cleared; rely on table update
  await page.locator(selectors.files.table).waitFor({ state: 'visible', timeout: 60_000 });

  const uploadedRow = page.locator(selectors.files.fileRow).filter({ hasText: fileName });
  await expect(uploadedRow).toBeVisible({ timeout: 30_000 });
  await expect.soft(uploadedRow).toContainText(/uploaded|extracted|vectorized/i);
});
