import { describe, expect, it } from 'vitest'
import { placeApprovals } from '@/utils/approvalPlacement'

const thread = [
  { id: 'u1', role: 'user', backendMessageId: 10 },
  { id: 'a1', role: 'assistant' },
  { id: 'u2', role: 'user', backendMessageId: 20 },
  { id: 'a2', role: 'assistant' },
]

const row = (id: number, messageId: number | null) => ({ id, requestedBy: { messageId } })

describe('placeApprovals', () => {
  it('puts an approval in the answer to the message that asked for it', () => {
    const placed = placeApprovals(thread, [row(1, 10), row(2, 20)])

    expect(placed.get('a1')?.map((r) => r.id)).toEqual([1])
    expect(placed.get('a2')?.map((r) => r.id)).toEqual([2])
  })

  it('falls back to the last answer when the asking message is not loaded', () => {
    const placed = placeApprovals(thread, [row(3, 99), row(4, null)])

    expect(placed.get('a2')?.map((r) => r.id)).toEqual([3, 4])
    expect(placed.has('a1')).toBe(false)
  })

  it('falls back to the last answer when no answer follows the asking message yet', () => {
    const placed = placeApprovals([...thread, { id: 'u3', role: 'user', backendMessageId: 30 }], [
      row(5, 30),
    ])

    expect(placed.get('a2')?.map((r) => r.id)).toEqual([5])
  })

  it('places nothing without an answer bubble or without approvals', () => {
    expect(placeApprovals([{ id: 'u1', role: 'user', backendMessageId: 10 }], [row(1, 10)]).size).toBe(0)
    expect(placeApprovals(thread, []).size).toBe(0)
  })
})
