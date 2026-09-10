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

const mountCard = (value: Approval, canAlwaysAllow = true) =>
  mount(ApprovalCard, {
    props: { approval: value, canAlwaysAllow },
    global: { stubs: { MessageText: { template: '<div />', props: ['content'] } } },
  })

describe('ApprovalCard', () => {
  it('shows Approve, Reject, Always allow and the nothing-created sentence', () => {
    const wrapper = mountCard(approval())
    expect(wrapper.text()).toContain('Nothing has been created yet')
    expect(wrapper.text()).toContain('Create ticket in Helpdesk')
    expect(wrapper.get('[data-testid="approval-approve"]').text()).toBe('Approve')
    expect(wrapper.get('[data-testid="approval-reject"]').text()).toBe('Reject')
    expect(wrapper.get('[data-testid="approval-always-allow"]').text()).toContain('Always allow')
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
