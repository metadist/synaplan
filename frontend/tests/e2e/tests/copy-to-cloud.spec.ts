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

async function mockCopyMenu(page: Page): Promise<{ copies: unknown[] }> {
  const history: { copies: unknown[] } = { copies: [] }
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
            mode: 'link',
            status: 'active',
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
            mode: 'link',
            status: 'active',
            can_copy: false,
          },
        ],
      },
    })
  })
  await page.route('**/api/v1/files/copies**', (route) =>
    route.fulfill({ json: { success: true, copies: history.copies } })
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
  return history
}

test.describe('@ci Copy to cloud', () => {
  test('the menu offers a cloud that accepts copies and hides one that does not', async ({
    page,
  }) => {
    await openApp(page)
    const history = await mockCopyMenu(page)
    await page.goto('/files/generated')
    await expect(page.getByTestId('btn-file-push')).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    await expect(page.getByTestId('file-sent-to')).toHaveCount(0)
    await page.getByTestId('btn-file-push').click()
    const menu = page.getByTestId('file-push-menu-panel')
    await expect(menu).toContainText('files.example.org')
    await expect(menu).toContainText('Synaplan/Images')
    await expect(menu).not.toContainText('quiet.example.org')

    await page.route('**/api/v1/files/42/send', (route) => {
      history.copies = [
        {
          id: 9,
          file_id: 42,
          state: 'delivered',
          remote_path: 'Synaplan/Images/chart (2).png',
          open_url: 'https://files.example.org/f/1',
          error_code: '',
          created: 1_700_000_100,
          client: 'nextcloud',
          host: 'files.example.org',
        },
      ]
      return route.fulfill({
        status: 200,
        json: {
          success: true,
          destination: 'platform',
          reference: 'Synaplan/Images/chart (2).png',
          context: {
            delivery_id: '9',
            state: 'delivered',
            open_url: 'https://files.example.org/f/1',
          },
        },
      })
    })
    await page.getByTestId('btn-file-push-target-4').click()
    await expect(page.getByTestId('btn-notification-action')).toHaveText('Open in Nextcloud')
    await expect(page.getByText('chart (2).png')).toBeVisible()
    await expect(page.getByTestId('file-sent-to')).toContainText('files.example.org')
  })

  test('an ownCloud link is labeled ownCloud in the menu, toast, and Sent to line', async ({
    page,
  }) => {
    await openApp(page)
    const history = await mockCopyMenu(page)
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
              mode: 'link',
              status: 'active',
              can_copy: true,
            },
            {
              id: 6,
              client: 'owncloud',
              host: 'cloud.example.org',
              external_id: 'ada',
              key_id: 3,
              key_label: 'ownCloud',
              created: 1,
              last_seen: 1,
              mode: 'link',
              status: 'active',
              can_copy: true,
            },
          ],
        },
      })
    })
    await page.goto('/files/generated')
    await page.getByTestId('btn-file-push').click()
    const menu = page.getByTestId('file-push-menu-panel')
    await expect(menu).toContainText('ownCloud')
    await expect(menu).toContainText('cloud.example.org')
    await expect(menu).not.toContainText('Nextcloud · cloud.example.org')

    await page.route('**/api/v1/files/42/send', (route) => {
      history.copies = [
        {
          id: 10,
          file_id: 42,
          state: 'delivered',
          remote_path: 'Synaplan/Images/chart (2).png',
          open_url: 'https://cloud.example.org/f/1',
          error_code: '',
          created: 1_700_000_100,
          client: 'owncloud',
          host: 'cloud.example.org',
        },
      ]
      return route.fulfill({
        status: 200,
        json: {
          success: true,
          destination: 'platform',
          reference: 'Synaplan/Images/chart (2).png',
          context: {
            delivery_id: '10',
            state: 'delivered',
            open_url: 'https://cloud.example.org/f/1',
          },
        },
      })
    })
    await page.getByTestId('btn-file-push-target-6').click()
    await expect(page.getByTestId('btn-notification-action')).toHaveText('Open in ownCloud')
    await expect(page.getByTestId('file-sent-to')).toContainText('ownCloud · cloud.example.org')
    await expect(page.getByTestId('file-sent-to')).not.toContainText('Nextcloud')
  })
})
