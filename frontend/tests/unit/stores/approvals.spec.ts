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

import { useApprovalsStore } from '@/stores/approvals'

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
})
