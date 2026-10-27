import { test, expect } from '../test-setup'
import { openApp } from '../helpers/auth'

/**
 * Notion app card. The OAuth flag is off on the dev stack, so the status
 * endpoint is mocked: a real connect would leave the app for Notion.
 */
test.describe('@ci Notion app', () => {
  test('connect state, writing switch, and disconnect', async ({ page }) => {
    let body = {
      success: true,
      status: 'not_connected' as 'not_connected' | 'connected',
      server_id: null as number | null,
      allow_write: false,
      write_auto: false,
    }

    await page.route('**/api/v1/apps/notion**', async (route) => {
      if (route.request().method() === 'PATCH') {
        const payload = route.request().postDataJSON() as { allow_write?: boolean }
        body = {
          ...body,
          status: 'connected',
          server_id: 9,
          allow_write: payload.allow_write === true,
          write_auto: payload.allow_write === true,
        }
      }
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(body),
      })
    })
    await page.route('**/api/v1/apps/notion/account', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          success: true,
          workspace_name: 'Acme',
          user_name: 'Ada Lovelace',
          user_email: 'ada@example.com',
        }),
      })
    })
    await page.route('**/api/v1/mcp-servers/*/oauth/disconnect', async (route) => {
      body = { ...body, status: 'not_connected', allow_write: false, write_auto: false }
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ success: true }),
      })
    })

    await openApp(page)
    await page.goto('/apps/notion?lang=en')

    await expect(page.getByTestId('btn-notion-connect')).toBeVisible()
    await expect(page.getByTestId('section-notion-connect')).toContainText(
      'Connect Notion so the AI can read your pages in chat.'
    )

    body = {
      success: true,
      status: 'connected',
      server_id: 9,
      allow_write: false,
      write_auto: false,
    }
    await page.reload()
    await expect(page.getByTestId('toggle-notion-write')).toBeVisible()
    await expect(page.getByTestId('text-notion-account')).toHaveText(
      'Signed in to the Notion workspace Acme as Ada Lovelace (ada@example.com).'
    )
    await expect(page.getByTestId('text-notion-write-consequence')).toContainText(
      'will not change anything'
    )

    await page
      .locator('label')
      .filter({ has: page.getByTestId('toggle-notion-write') })
      .click()
    await expect(page.getByTestId('text-notion-write-consequence')).toContainText(
      'cannot delete, move or overwrite pages'
    )

    await page.getByTestId('btn-notion-disconnect').click()
    await page.getByTestId('btn-dialog-confirm').click()
    await expect(page.getByTestId('btn-notion-connect')).toBeVisible()
  })
})
