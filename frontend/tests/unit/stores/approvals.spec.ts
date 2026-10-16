import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

const enabled = { value: true }
vi.mock('@/composables/useApprovalsFeature', () => ({
  isApprovalsEnabled: () => enabled.value,
}))

const { mockList, mockApprove, mockReject } = vi.hoisted(() => ({
  mockList: vi.fn(),
  mockApprove: vi.fn(),
  mockReject: vi.fn(),
}))

vi.mock('@/services/api/approvalsApi', () => ({
  approvalsApi: {
    list: mockList,
    approve: mockApprove,
    reject: mockReject,
    getNotifyMode: vi.fn(),
    setNotifyMode: vi.fn(),
  },
}))

vi.mock('@/services/api/httpClient', () => ({
  getConfigSync: () => ({ realtime: { enabled: false } }),
}))

import { useApprovalsStore, parseChatContinuation } from '@/stores/approvals'

const row = {
  id: 8,
  tool: 'mcp:1:create',
  preview: 'Create ticket in Helpdesk',
  status: 'pending',
  expiresAt: 1,
  created: 1,
  requestedBy: { kind: 'chat' as const },
  sideEffect: 'write' as const,
  canAlwaysAllow: true,
}

describe('approvals store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    enabled.value = true
    mockList.mockResolvedValue({ pendingCount: 1, approvals: [row] })
    mockApprove.mockResolvedValue(row)
    mockReject.mockResolvedValue(row)
  })

  it('stays empty when the flag is off', async () => {
    enabled.value = false
    const store = useApprovalsStore()
    await store.load('pending')
    expect(mockList).not.toHaveBeenCalled()
    expect(store.pending).toEqual([])
    expect(store.pendingCount).toBe(0)
  })

  it('loads pending cards and drops them after approve', async () => {
    const store = useApprovalsStore()
    await store.load('pending')
    expect(store.pending).toHaveLength(1)
    expect(store.pendingCount).toBe(1)
    await store.approve(8)
    expect(mockApprove).toHaveBeenCalledWith(8, false, undefined)
    expect(store.pending).toEqual([])
    expect(store.pendingCount).toBe(0)
  })

  it('ignores a duplicate stream card', () => {
    const store = useApprovalsStore()
    store.addFromStream(row)
    store.addFromStream(row)
    expect(store.pending).toHaveLength(1)
    expect(store.pendingCount).toBe(1)
  })

  it('parses chat continuation events and drops malformed ones', () => {
    expect(parseChatContinuation({ approvalId: 42, chatId: 5, outcome: 'executed' })).toEqual({
      approvalId: 42,
      chatId: 5,
      outcome: 'executed',
    })
    expect(parseChatContinuation(null)).toBeNull()
    expect(parseChatContinuation({ approvalId: '42', chatId: 5, outcome: 'executed' })).toBeNull()
    expect(parseChatContinuation({ approvalId: 42, chatId: 5 })).toBeNull()
  })

  it('notifies continuation listeners and honors unsubscribe', () => {
    const store = useApprovalsStore()
    const seen: Array<{ approvalId: number; chatId: number }> = []
    const stop = store.onChatContinued((continuation) => {
      seen.push(continuation)
    })
    store.ingestContinuationEvent({ approvalId: 42, chatId: 5, outcome: 'executed' })
    store.ingestContinuationEvent({ bogus: true })
    expect(seen).toEqual([{ approvalId: 42, chatId: 5, outcome: 'executed' }])
    stop()
    store.ingestContinuationEvent({ approvalId: 43, chatId: 5, outcome: 'executed' })
    expect(seen).toHaveLength(1)
  })

  it('waitForOutcome resolves once the worker reaches a terminal state', async () => {
    const store = useApprovalsStore()
    const approved = { ...row, id: 8, status: 'approved' }
    const executed = { ...row, id: 8, status: 'executed' }
    mockList
      .mockResolvedValueOnce({ pendingCount: 0, approvals: [approved] })
      .mockResolvedValue({ pendingCount: 0, approvals: [executed] })
    const outcome = await store.waitForOutcome(8, { timeoutMs: 1000, intervalMs: 5 })
    expect(mockList).toHaveBeenCalledWith('decided')
    expect(outcome).toEqual(executed)
  })

  it('waitForOutcome resolves null when the outcome never lands', async () => {
    const store = useApprovalsStore()
    mockList.mockResolvedValue({ pendingCount: 0, approvals: [] })
    const outcome = await store.waitForOutcome(8, { timeoutMs: 30, intervalMs: 5 })
    expect(outcome).toBeNull()
  })
})
