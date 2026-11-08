import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createRouter, createMemoryHistory } from 'vue-router'

const listAudit = vi.fn()
const approvalsEnabled = { value: true }

vi.mock('@/services/api/iamApi', () => ({
  iamApi: {
    listAudit: (...args: unknown[]) => listAudit(...args),
  },
}))

vi.mock('@/composables/useApprovalsFeature', () => ({
  isApprovalsEnabled: () => approvalsEnabled.value,
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ error: vi.fn() }),
}))

vi.mock('@iconify/vue', () => ({
  Icon: { template: '<i />' },
}))

import AuditTab from '@/components/people/AuditTab.vue'

const documentEdit = {
  id: 4,
  actorId: 7,
  actorName: 'Ben Weber',
  action: 'tool.executed',
  kind: 'tool',
  resourceId: 'set_cells',
  resourceName: null,
  subjectName: null,
  subject: {
    tool: 'set_cells',
    source: 'document',
    kind: 'write',
    outcome: 'done',
    arguments: { value: 'secret-argument' },
    result: 'secret-result',
  },
  ip: '',
  created: 1_700_000_000,
}

const mcpWrite = {
  id: 5,
  actorId: 7,
  actorName: 'Ben Weber',
  action: 'tool.executed',
  kind: 'tool',
  resourceId: 'mcp__1__create_issue',
  resourceName: null,
  subjectName: null,
  subject: {
    tool: 'mcp__1__create_issue',
    source: 'mcp',
    kind: 'write',
    outcome: 'done',
    approvalId: 812,
    arguments: { title: 'secret-issue' },
  },
  ip: '',
  created: 1_700_000_100,
}

async function mountTab() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/approvals', name: 'approvals', component: { template: '<div />' } },
    ],
  })
  await router.push('/')
  const wrapper = mount(AuditTab, { global: { plugins: [router] } })
  await flushPromises()
  return wrapper
}

describe('AuditTab tool calls', () => {
  beforeEach(() => {
    approvalsEnabled.value = true
    listAudit.mockReset()
    listAudit.mockResolvedValue({ entries: [documentEdit, mcpWrite], nextCursor: null })
  })

  it('puts Tool calls second and says what each source did', async () => {
    const wrapper = await mountTab()
    const chips = wrapper.findAll('[data-testid^="audit-filter-"]')
    expect(chips[0]?.attributes('data-testid')).toBe('audit-filter-all')
    expect(chips[1]?.text()).toBe('Tool calls')

    const text = wrapper.text()
    expect(text).toContain('Ben Weber edited a document with set_cells. Done.')
    expect(text).toContain('Ben Weber ran create_issue on a connected server. Done.')
    expect(text).not.toContain('secret-argument')
    expect(text).not.toContain('secret-result')
    expect(text).not.toContain('secret-issue')
    expect(text).not.toContain('mcp__1__')

    const link = wrapper.get('[data-testid="audit-approval-5"]')
    expect(link.text()).toBe('Approval #812')
    expect(link.attributes('href')).toContain('/approvals')
    expect(link.attributes('href')).toContain('approval=812')
  })

  it('offers Approvals when the tool-call list is empty', async () => {
    listAudit.mockImplementation(async (params?: { action?: string }) => {
      if (params?.action === 'tool.executed') return { entries: [], nextCursor: null }
      return { entries: [documentEdit], nextCursor: null }
    })
    const wrapper = await mountTab()
    await wrapper.get('[data-testid="audit-filter-tool.executed"]').trigger('click')
    await flushPromises()
    expect(wrapper.get('[data-testid="audit-empty"]').text()).toContain(
      'No tool calls are recorded yet.'
    )
    expect(wrapper.get('[data-testid="audit-empty-approvals"]').text()).toBe('Open Approvals')
  })
})
