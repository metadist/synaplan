import { describe, expect, it } from 'vitest'
import {
  capturePinnedAgentForSend,
  parseAgentIdQuery,
  resolvePinnedAgentId,
  shouldOpenFreshAssistantChat,
} from '@/composables/usePinnedAssistant'

describe('parseAgentIdQuery', () => {
  it('reads a positive integer from the query', () => {
    expect(parseAgentIdQuery('2')).toBe(2)
    expect(parseAgentIdQuery(2)).toBe(2)
  })

  it('uses the first value when Vue repeats the param', () => {
    expect(parseAgentIdQuery(['4', '9'])).toBe(4)
  })

  it('rejects missing or invalid values', () => {
    expect(parseAgentIdQuery(undefined)).toBeNull()
    expect(parseAgentIdQuery('')).toBeNull()
    expect(parseAgentIdQuery('0')).toBeNull()
    expect(parseAgentIdQuery(-1)).toBeNull()
    expect(parseAgentIdQuery('nope')).toBeNull()
  })
})

describe('resolvePinnedAgentId', () => {
  it('is off when the feature flag is off', () => {
    expect(resolvePinnedAgentId(false, 2, [])).toBeNull()
    expect(resolvePinnedAgentId(false, 2, [{ agentId: 2 }])).toBeNull()
  })

  it('uses the query pin only on an empty chat', () => {
    expect(resolvePinnedAgentId(true, 2, [])).toBe(2)
  })

  it('prefers a pin already stamped on the thread', () => {
    expect(resolvePinnedAgentId(true, 9, [{ agentId: null }, { agentId: 2 }])).toBe(2)
  })

  it('does not pin an unrelated thread just because the URL still has agentId', () => {
    expect(resolvePinnedAgentId(true, 2, [{ agentId: null }])).toBeNull()
  })
})

describe('capturePinnedAgentForSend', () => {
  it('keeps the query pin on the first send when that message carries it', () => {
    const existing: Array<{ agentId?: number | null }> = []
    const captured = capturePinnedAgentForSend(true, 2, existing)
    expect(captured).toBe(2)

    const afterSend = [...existing, { agentId: captured }]
    expect(resolvePinnedAgentId(true, 2, afterSend)).toBe(2)
    expect(resolvePinnedAgentId(true, null, afterSend)).toBe(2)
  })

  it('does not attach the query pin to a thread that already has messages', () => {
    const existing = [{ agentId: null as number | null }]
    const captured = capturePinnedAgentForSend(true, 2, existing)
    expect(captured).toBeNull()

    const afterSend = [...existing, { agentId: captured }]
    expect(resolvePinnedAgentId(true, 2, afterSend)).toBeNull()
  })

  it('is off when the feature flag is off', () => {
    expect(capturePinnedAgentForSend(false, 2, [])).toBeNull()
  })
})

describe('shouldOpenFreshAssistantChat', () => {
  it('stays on an empty chat', () => {
    expect(shouldOpenFreshAssistantChat(2, [])).toBe(false)
  })

  it('stays when the open thread is already this assistant', () => {
    expect(shouldOpenFreshAssistantChat(2, [{ agentId: 2 }])).toBe(false)
  })

  it('opens a new chat when the last thread belongs to someone else', () => {
    expect(shouldOpenFreshAssistantChat(2, [{ agentId: null }])).toBe(true)
    expect(shouldOpenFreshAssistantChat(2, [{ agentId: 5 }])).toBe(true)
  })

  it('does nothing without a query pin', () => {
    expect(shouldOpenFreshAssistantChat(null, [{ agentId: null }])).toBe(false)
  })
})
