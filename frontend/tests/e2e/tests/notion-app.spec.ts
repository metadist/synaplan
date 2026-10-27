import { test, expect } from '../test-setup'
import type { Page } from '@playwright/test'
import { openApp } from '../helpers/auth'

const noSharing = { shared_by: null as string | null, can_share: false, shared_on: false }

const json = (data: unknown, status = 200) => ({
  status,
  contentType: 'application/json',
  body: JSON.stringify(data),
})

async function mockLists(page: Page, watches: unknown[] = []) {
  await page.route('**/api/v1/apps/notion/activity', (route) =>
    route.fulfill(
      json({
        success: true,
        items: [
          {
            id: 1,
            action: 'created',
            title: 'Q3 summary',
            url: 'https://www.notion.so/39797ad60eb3806db59eef7653838664',
            created_at: 1793091600,
          },
        ],
      })
    )
  )
  await page.route('**/api/v1/apps/notion/watches', (route) =>
    route.fulfill(json({ success: true, watches, tasks: [], limit: 20 }))
  )
}

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
      sharing: noSharing,
    }

    await page.route('**/api/v1/apps/notion**', async (route) => {
      if (new URL(route.request().url()).pathname !== '/api/v1/apps/notion') {
        return route.fallback()
      }
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
      sharing: noSharing,
    }
    await mockLists(page)
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

  test('follow a page for search, pause it, and stop following', async ({ page }) => {
    const status = {
      success: true,
      status: 'connected',
      server_id: 9,
      allow_write: false,
      write_auto: false,
      sharing: noSharing,
    }
    const watch = {
      id: 5,
      page_url: 'https://www.notion.so/39797ad60eb3806db59eef7653838664',
      title: 'Roadmap',
      action: 'searchable',
      folder: 'Notion',
      task_id: null,
      task_name: null,
      file_id: 77,
      enabled: true,
      checked_at: 1793091600,
      changed_at: null,
      last_error: null,
    }
    let watches: (typeof watch)[] = []

    await page.route('**/api/v1/apps/notion**', (route) => {
      if (new URL(route.request().url()).pathname !== '/api/v1/apps/notion') {
        return route.fallback()
      }
      return route.fulfill(json(status))
    })
    await page.route('**/api/v1/apps/notion/account', (route) =>
      route.fulfill(
        json({ success: true, workspace_name: 'Acme', user_name: null, user_email: null })
      )
    )
    await mockLists(page)
    await page.route('**/api/v1/apps/notion/watches', async (route) => {
      if (route.request().method() === 'POST') {
        watches = [watch]
        return route.fulfill(json({ success: true, watch }, 201))
      }
      return route.fulfill(json({ success: true, watches, tasks: [], limit: 20 }))
    })
    await page.route('**/api/v1/apps/notion/watches/5', (route) => {
      if (route.request().method() === 'PATCH') {
        watches = [{ ...watch, enabled: false }]
        return route.fulfill(json({ success: true, watch: watches[0] }))
      }
      watches = []
      return route.fulfill(json({ success: true }))
    })

    await openApp(page)
    await page.goto('/apps/notion?lang=en')

    await expect(page.getByTestId('item-notion-activity')).toContainText('Q3 summary')
    await expect(page.getByTestId('text-notion-watches-empty')).toBeVisible()

    await page.getByTestId('input-notion-watch-page').fill(watch.page_url)
    await page.getByTestId('btn-notion-watch-add').click()

    const row = page.getByTestId('item-notion-watch')
    await expect(row).toContainText('Roadmap')
    await expect(row).toContainText('Kept searchable in the folder “Notion”.')
    await expect(page.getByTestId('link-notion-watch-file')).toHaveAttribute('href', /file=77/)

    await page.getByTestId('btn-notion-watch-toggle').click()
    await expect(row).toContainText('Paused. Synaplan does not check this page.')

    await page.getByTestId('btn-notion-watch-remove').click()
    await page.getByTestId('btn-dialog-confirm').click()
    await expect(page.getByTestId('text-notion-watches-empty')).toBeVisible()
  })

  test('a member can read through the shared connection', async ({ page }) => {
    const status = {
      success: true,
      status: 'not_connected',
      server_id: null as number | null,
      allow_write: false,
      write_auto: false,
      sharing: { shared_by: 'Ada', can_share: false, shared_on: false },
    }
    await page.route('**/api/v1/apps/notion**', (route) => {
      if (new URL(route.request().url()).pathname !== '/api/v1/apps/notion') {
        return route.fallback()
      }
      return route.fulfill(json(status))
    })
    await page.route('**/api/v1/apps/notion/shared/use', (route) =>
      route.fulfill(json({ success: true }))
    )

    await openApp(page)
    await page.goto('/apps/notion?lang=en')

    await expect(page.getByTestId('text-notion-shared')).toContainText(
      'Ada shares a Notion connection.'
    )
    await expect(page.getByTestId('btn-notion-connect')).toBeVisible()
    await page.getByTestId('btn-notion-use-shared').click()
    await expect(
      page.getByText('Chat can now read Notion through the shared connection.')
    ).toBeVisible()
  })
})
