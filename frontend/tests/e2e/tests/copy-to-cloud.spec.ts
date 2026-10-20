import { expect, test, type Page } from '../test-setup'
import { openApp } from '../helpers/auth'
import { TIMEOUTS } from '../config/config'

const CHART = {
  id: 42,
  filename: 'chart.png',
  display_name: 'chart.png',
  path: 'chart.png',
  file_type: 'png',
  file_size: 1200,
  mime: 'image/png',
  status: 'complete',
  source: 'generated',
  origin_kind: 'image',
  text_preview: '',
  uploaded_at: 1_700_000_000,
  uploaded_date: '2026-01-01',
  message_id: null,
  chat_id: null,
}

async function mockCopyMenu(page: Page): Promise<void> {
  await page.route('**/api/v1/connections', async (route) => {
    if (route.request().method() !== 'GET' || route.request().url().includes('/connections/')) {
      return route.continue()
    }
    await route.fulfill({ json: { success: true, connections: [] } })
  })
  await page.route('**/api/v1/me/platform-links', async (route) => {
    if (route.request().method() !== 'GET') return route.continue()
    await route.fulfill({
      json: {
        success: true,
        links: [
          {
            id: 4,
            client: 'nextcloud',
            host: 'files.example.org',
            external_id: 'ada',
            key_id: 1,
            key_label: 'Nextcloud',
            created: 1,
            last_seen: 1,
            can_copy: true,
          },
          {
            id: 5,
            client: 'nextcloud',
            host: 'quiet.example.org',
            external_id: 'ada',
            key_id: 2,
            key_label: 'Quiet',
            created: 1,
            last_seen: 1,
            can_copy: false,
          },
        ],
      },
    })
  })
  await page.route('**/api/v1/files/copies**', (route) =>
    route.fulfill({
      json: {
        success: true,
        copies: [
          {
            id: 9,
            file_id: 42,
            state: 'delivered',
            remote_path: 'Synaplan/Images/chart.png',
            open_url: 'https://files.example.org/f/1',
            error_code: '',
            created: 1_700_000_100,
            client: 'nextcloud',
            host: 'files.example.org',
          },
        ],
      },
    })
  )
  await page.route(/\/api\/v1\/files\?/, async (route) => {
    if (!route.request().url().includes('source=generated')) return route.continue()
    await route.fulfill({
      json: {
        success: true,
        files: [CHART],
        pagination: { page: 1, limit: 24, total: 1, pages: 1 },
      },
    })
  })
}

test.describe('@ci Copy to cloud', () => {
  test('the menu offers a cloud that accepts copies and hides one that does not', async ({
    page,
  }) => {
    await openApp(page)
    await mockCopyMenu(page)
    await page.goto('/files/generated')
    await expect(page.getByTestId('file-sent-to')).toContainText('files.example.org', {
      timeout: TIMEOUTS.STANDARD,
    })
    await page.getByTestId('btn-file-push').click()
    const menu = page.getByTestId('file-push-menu-panel')
    await expect(menu).toContainText('files.example.org')
    await expect(menu).toContainText('Synaplan/Images')
    await expect(menu).not.toContainText('quiet.example.org')

    await page.route('**/api/v1/files/42/send', (route) =>
      route.fulfill({
        status: 200,
        json: {
          success: true,
          destination: 'platform',
          reference: 'Synaplan/Images/chart.png',
          context: {
            delivery_id: '9',
            state: 'delivered',
            open_url: 'https://files.example.org/f/1',
          },
        },
      })
    )
    await page.getByTestId('btn-file-push-target-4').click()
    await expect(page.getByTestId('btn-notification-action')).toHaveText('Open in Nextcloud')
  })
})
