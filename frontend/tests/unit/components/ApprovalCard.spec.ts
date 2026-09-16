import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import ApprovalCard from '@/components/chat/ApprovalCard.vue'
import type { Approval } from '@/services/api/approvalsApi'

const mockPrompt = vi.fn()

vi.mock('@/composables/useDialog', () => ({
  useDialog: () => ({ prompt: (...args: unknown[]) => mockPrompt(...args) }),
}))

function approval(overrides: Partial<Approval> = {}): Approval {
  return {
    id: 3,
    tool: 'mcp:1:create_ticket',
    sideEffect: 'write',
    preview: 'Create ticket in Helpdesk',
    status: 'pending',
    expiresAt: Math.floor(Date.now() / 1000) + 3600,
    created: Math.floor(Date.now() / 1000),
    requestedBy: { kind: 'chat', chatId: 12, messageId: 99 },
    canAlwaysAllow: true,
    ...overrides,
  }
}

const mountCard = (value: Approval, canAlwaysAllow = true, showOpenContext = false) =>
  mount(ApprovalCard, {
    props: { approval: value, canAlwaysAllow, showOpenContext },
    global: { stubs: { MessageText: { template: '<div />', props: ['content'] } } },
  })

describe('ApprovalCard', () => {
  it('shows Approve, Reject, Always allow and that the write has not happened yet', () => {
    const wrapper = mountCard(approval())
    expect(wrapper.text()).toContain('This has not happened yet')
    expect(wrapper.text()).toContain('Create ticket in Helpdesk')
    expect(wrapper.get('[data-testid="approval-approve"]').text()).toBe('Approve')
    expect(wrapper.get('[data-testid="approval-reject"]').text()).toBe('Reject')
    expect(wrapper.get('[data-testid="approval-always-allow"]').text()).toContain('Always allow')
    expect(wrapper.find('[data-testid="approval-open-context"]').exists()).toBe(false)
  })

  it('uses the singular expiry branch for one hour', () => {
    const wrapper = mountCard(approval({ expiresAt: Math.floor(Date.now() / 1000) + 3600 + 30 }))
    expect(wrapper.get('[data-testid="approval-expires"]').text()).toBe('Expires in 1 hour')
  })

  it('uses the plural expiry branch for many hours', () => {
    const wrapper = mountCard(
      approval({ expiresAt: Math.floor(Date.now() / 1000) + 72 * 3600 + 30 })
    )
    expect(wrapper.get('[data-testid="approval-expires"]').text()).toBe('Expires in 72 hours')
  })

  it('emits openContext from the in-card source link', async () => {
    const wrapper = mountCard(approval(), true, true)
    await wrapper.get('[data-testid="approval-open-context"]').trigger('click')
    expect(wrapper.emitted('openContext')?.[0]).toEqual([3])
  })

  it('hides Approve for destructive tools', () => {
    const wrapper = mountCard(approval({ sideEffect: 'destructive', canAlwaysAllow: false }))
    expect(wrapper.find('[data-testid="approval-approve"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="approval-always-allow"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="approval-reject"]').exists()).toBe(true)
  })

  it('emits rejected with the prompt reason', async () => {
    mockPrompt.mockResolvedValueOnce('not this one')
    const wrapper = mountCard(approval())
    await wrapper.get('[data-testid="approval-reject"]').trigger('click')
    expect(wrapper.emitted('rejected')?.[0]).toEqual([3, 'not this one'])
  })
})
