import { test, expect } from '../test-setup'
import { openApp } from '../helpers/auth'
import { isAgentsEnabled } from '../helpers/features'
import { TIMEOUTS } from '../config/config'

test.describe('@ci Assistants knowledge upload', () => {
  test('V1 Add a file does not scroll the app shell', async ({ page, request, credentials }) => {
    test.skip(
      !(await isAgentsEnabled(request, credentials)),
      'Assistants are off; the builder is not reachable'
    )

    await openApp(page)
    await page.evaluate(() => localStorage.setItem('design-variant', 'v1'))
    await page.reload()
    await page.goto('/ai/assistants')
    await expect(page.locator('[data-testid="view-assistants"]')).toBeVisible({
      timeout: TIMEOUTS.STANDARD,
    })

    await page.getByTestId('btn-create-assistant-header').click()
    await expect(page).toHaveURL(/\/ai\/assistants\/\d+/, { timeout: TIMEOUTS.STANDARD })
    await expect(page.getByTestId('section-builder-knowledge')).toBeVisible({
      timeout: TIMEOUTS.STANDARD,
    })

    const knowledge = page.getByTestId('section-builder-knowledge')
    await knowledge.scrollIntoViewIfNeeded()

    const shell = page.getByTestId('comp-main-layout')
    const before = await shell.evaluate((el) => el.scrollTop)

    const chooserPromise = page.waitForEvent('filechooser')
    await page.getByTestId('btn-knowledge-file').click()
    const chooser = await chooserPromise
    await chooser.setFiles([])

    const after = await shell.evaluate((el) => el.scrollTop)
    expect(after).toBe(before)
  })
})
