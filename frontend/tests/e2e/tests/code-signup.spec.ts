import { test, expect, LOGGED_OUT } from '../test-setup'

// The page is for someone who does not have an account yet.
test.use(LOGGED_OUT)

test.describe('@ci invitation code', () => {
  test('the page is there, and says so when this server does not offer the flow', async ({
    page,
  }) => {
    await page.goto('/start/code?lang=en')
    await expect(page.getByTestId('page-code-signup')).toBeVisible()
    await expect(page.getByTestId('input-code')).toBeVisible()

    await page.getByTestId('input-email').fill('ada@example.com')
    await page.getByTestId('input-code').fill('NOT-A-REAL-CODE')
    await page.getByTestId('btn-continue').click()

    // Pay first is off unless an operator turns it on, so the honest answer
    // is that invitation codes are not available here.
    await expect(page.getByTestId('text-error')).toContainText('not available')
    await expect(page.getByTestId('link-register')).toBeVisible()
  })
})
