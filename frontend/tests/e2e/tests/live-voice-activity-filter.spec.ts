/**
 * LV4 — the statistics filter names a live conversation and a transcription
 * without a voice gateway. The module stays off in CI, so this does not look
 * for the waveform button.
 */
import { test, expect } from '../test-setup'
import { openApp } from '../helpers/auth'

test('@ci statistics lists live conversation and transcription in the activity filter', async ({
  page,
}) => {
  await page.addInitScript(() => {
    localStorage.setItem('language', 'en')
  })
  await openApp(page)
  await page.goto('/statistics?lang=en')

  const select = page.getByTestId('select-activity-action')
  await expect(select).toBeVisible()
  await expect(select.locator('option[value="VOICE_LIVE"]')).toHaveText('Live conversation')
  await expect(select.locator('option[value="TRANSCRIPTION"]')).toHaveText('Transcription')
})
