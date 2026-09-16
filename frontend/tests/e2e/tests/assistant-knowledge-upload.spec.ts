import { test, expect } from '../test-setup'
import { isAgentsEnabled } from '../helpers/features'
import { TIMEOUTS } from '../config/config'

test.describe('@ci Assistants knowledge upload', () => {
  test('V1 Add a file does not scroll the app shell', async ({ page, request, credentials }) => {
    test.skip(
      !(await isAgentsEnabled(request, credentials)),
      'Assistants are off; the builder is not reachable'
    )

    // Apply V1 before the first paint so useDesignVariant reads it on init.
    // This spec does not need the chat composer (openApp would wait for it).
    await page.addInitScript(() => localStorage.setItem('design-variant', 'v1'))
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

    // AssistantsView fallthrough overwrites MainLayout's root testid, so the
    // overflow-y-auto <main> is the shell that used to jump on sr-only focus.
    const shell = page.getByTestId('section-primary-content')
    await expect(shell).toBeVisible()
    const before = await shell.evaluate((el) => el.scrollTop)

    const chooserPromise = page.waitForEvent('filechooser')
    await page.getByTestId('btn-knowledge-file').click()
    const chooser = await chooserPromise
    await chooser.setFiles([])

    const after = await shell.evaluate((el) => el.scrollTop)
    expect(after).toBe(before)
  })
})
