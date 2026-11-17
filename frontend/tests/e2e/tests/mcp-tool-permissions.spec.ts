import { test, expect } from '../test-setup'
import type { Page } from '@playwright/test'
import { openApp } from '../helpers/auth'

type Outcome = 'auto' | 'approve' | 'block'

type Tool = {
  name: string
  title: string
  description: string
  category: 'read' | 'write' | 'destructive'
  outcome: Outcome
  default_outcome: Outcome
}

const json = (data: unknown, status = 200) => ({
  status,
  contentType: 'application/json',
  body: JSON.stringify(data),
})

const SERVER_ID = 9

const freshTools = (): Tool[] => [
  {
    name: 'notion-search',
    title: 'Search',
    description: 'Find pages',
    category: 'read',
    outcome: 'auto',
    default_outcome: 'auto',
  },
  {
    name: 'notion-update-page',
    title: 'Update page',
    description: 'Change the properties or content of a page',
    category: 'write',
    outcome: 'auto',
    default_outcome: 'auto',
  },
  {
    name: 'notion-create-pages',
    title: 'Create pages',
    description: 'Create one or more pages',
    category: 'write',
    outcome: 'auto',
    default_outcome: 'auto',
  },
  {
    name: 'notion-delete-pages',
    title: 'Delete pages',
    description: 'Move pages to the trash',
    category: 'destructive',
    outcome: 'approve',
    default_outcome: 'approve',
  },
]

/**
 * A small stand-in for the permissions API: it applies PUT bodies the way the
 * server does (a choice, or `default`) so Undo and reset can be walked. The
 * list response must carry `notice`; the schema requires it.
 */
async function mockPermissionsApi(
  page: Page,
  options: { notice?: boolean; canEdit?: boolean } = {}
) {
  const state = {
    tools: freshTools(),
    notice: options.notice ?? false,
    canEdit: options.canEdit ?? true,
    puts: [] as unknown[],
  }
  const view = () => ({
    success: true,
    error: null,
    can_edit: state.canEdit,
    notice: state.notice,
    tools: state.tools.map((tool) => ({
      ...tool,
      input_count: 1,
      input_names: ['page_id'],
      hinted: true,
      is_new: false,
      locked_reason: null,
    })),
  })

  await page.route('**/api/v1/apps/notion**', (route) => {
    if (new URL(route.request().url()).pathname !== '/api/v1/apps/notion') {
      return route.fallback()
    }
    return route.fulfill(
      json({
        success: true,
        status: 'connected',
        server_id: SERVER_ID,
        sharing: { shared_by: null, can_share: false, shared_on: false },
      })
    )
  })
  await page.route('**/api/v1/apps/notion/account', (route) =>
    route.fulfill(json({ success: true, workspace_name: 'Acme', user_name: null, user_email: null }))
  )
  await page.route('**/api/v1/apps/notion/activity', (route) =>
    route.fulfill(json({ success: true, items: [] }))
  )
  await page.route('**/api/v1/apps/notion/watches', (route) =>
    route.fulfill(json({ success: true, watches: [], tasks: [], limit: 20 }))
  )
  await page.route(`**/api/v1/mcp-servers/${SERVER_ID}/tools`, (route) =>
    route.fulfill(json(view()))
  )
  await page.route(`**/api/v1/mcp-servers/${SERVER_ID}/tool-permissions`, (route) => {
    const body = route.request().postDataJSON() as { tools: Record<string, string> }
    state.puts.push(body)
    for (const [name, choice] of Object.entries(body.tools)) {
      const tool = state.tools.find((candidate) => candidate.name === name)
      if (tool) tool.outcome = choice === 'default' ? tool.default_outcome : (choice as Outcome)
    }
    return route.fulfill(json(view()))
  })
  await page.route(`**/api/v1/mcp-servers/${SERVER_ID}/tool-permissions/seen`, (route) =>
    route.fulfill(json({ success: true }))
  )
  await page.route(
    `**/api/v1/mcp-servers/${SERVER_ID}/tool-permissions/notice/dismiss`,
    (route) => {
      state.notice = false
      return route.fulfill(json(view()))
    }
  )
  return state
}

async function openPermissions(page: Page) {
  await openApp(page)
  await page.goto('/apps/notion?lang=en&tab=permissions')
  await expect(page.getByTestId('mcp-tool-notion-search')).toBeVisible()
}

const select = (page: Page, name: string) => page.getByTestId(`select-mcp-tool-${name}`)

test.describe('@ci MCP tool permissions', () => {
  test('J-TP-1: every tool shows what it does and what it is set to', async ({ page }) => {
    await mockPermissionsApi(page)
    await openPermissions(page)

    const row = page.getByTestId('mcp-tool-notion-delete-pages')
    await expect(row).toContainText('Delete pages')
    await expect(row).toContainText('Move pages to the trash')
    await expect(select(page, 'notion-delete-pages')).toHaveValue('approve')
    await expect(select(page, 'notion-search')).toHaveValue('auto')
  })

  test('J-TP-2: set a tool to Approve, then Undo', async ({ page }) => {
    const state = await mockPermissionsApi(page)
    await openPermissions(page)

    await select(page, 'notion-update-page').selectOption('approve')
    await expect(page.getByText('Update page set to Approve.')).toBeVisible()
    await expect(select(page, 'notion-update-page')).toHaveValue('approve')
    expect(state.puts).toEqual([{ tools: { 'notion-update-page': 'approve' } }])

    await page.getByTestId('btn-notification-action').click()
    await expect(page.getByText('Change undone.')).toBeVisible()
    await expect(select(page, 'notion-update-page')).toHaveValue('auto')
  })

  test('J-TP-3: a category sets all its tools, and can be set back', async ({ page }) => {
    await mockPermissionsApi(page)
    await openPermissions(page)

    await page.getByTestId('select-mcp-category-write').selectOption('block')
    await expect(select(page, 'notion-update-page')).toHaveValue('block')
    await expect(select(page, 'notion-create-pages')).toHaveValue('block')
    await expect(select(page, 'notion-search')).toHaveValue('auto')
    await expect(select(page, 'notion-delete-pages')).toHaveValue('approve')

    await page.getByTestId('select-mcp-category-write').selectOption('auto')
    await expect(select(page, 'notion-update-page')).toHaveValue('auto')
    await expect(select(page, 'notion-create-pages')).toHaveValue('auto')
  })

  test('J-TP-4: Auto on a destructive tool needs a confirmation', async ({ page }) => {
    const state = await mockPermissionsApi(page)
    await openPermissions(page)

    await select(page, 'notion-delete-pages').selectOption('auto')
    await expect(page.getByText('Let the AI delete without asking?')).toBeVisible()
    await page.getByTestId('btn-dialog-cancel').click()
    await expect(select(page, 'notion-delete-pages')).toHaveValue('approve')
    expect(state.puts).toHaveLength(0)

    await select(page, 'notion-delete-pages').selectOption('auto')
    await page.getByTestId('btn-dialog-confirm').click()
    await expect(select(page, 'notion-delete-pages')).toHaveValue('auto')
    expect(state.puts).toEqual([{ tools: { 'notion-delete-pages': 'auto' } }])

    await page.getByTestId('btn-mcp-tool-reset-notion-delete-pages').click()
    await expect(select(page, 'notion-delete-pages')).toHaveValue('approve')
  })

  test('one-time notice about the new defaults can be dismissed', async ({ page }) => {
    await mockPermissionsApi(page, { notice: true })
    await openPermissions(page)

    await expect(page.getByTestId('mcp-permissions-notice')).toBeVisible()
    await page.getByTestId('btn-mcp-notice-dismiss').click()
    await expect(page.getByTestId('mcp-permissions-notice')).toHaveCount(0)
  })

  test('a person who cannot edit the connection sees the list read-only', async ({ page }) => {
    const state = await mockPermissionsApi(page, { canEdit: false })
    await openPermissions(page)

    await expect(select(page, 'notion-update-page')).toBeDisabled()
    await expect(page.getByTestId('select-mcp-category-write')).toBeDisabled()
    expect(state.puts).toHaveLength(0)
  })
})
