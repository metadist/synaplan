import { beforeEach, describe, expect, it, vi } from 'vitest'
import {
  isAnthropicAccountsEnabled,
  loadGatewayEnabled,
  rememberGatewayEnabled,
  resetAiAccountsGatewayCache,
} from '@/composables/useAiAccounts'

const getMessagesGatewayStatus = vi.fn()

vi.mock('@/services/api/messagesGatewayApi', () => ({
  getMessagesGatewayStatus: () => getMessagesGatewayStatus(),
}))

vi.mock('@/services/api/httpClient', () => ({
  getConfigSync: () => ({ modules: { higgsfield: { configured: false } } }),
}))

describe('rememberGatewayEnabled', () => {
  beforeEach(() => {
    resetAiAccountsGatewayCache()
    getMessagesGatewayStatus.mockReset()
  })

  it('keeps Anthropic accounts available when a later status request fails', async () => {
    rememberGatewayEnabled(true)
    getMessagesGatewayStatus.mockRejectedValue(new Error('offline'))

    expect(isAnthropicAccountsEnabled()).toBe(true)
    await expect(loadGatewayEnabled()).resolves.toBe(true)
    expect(getMessagesGatewayStatus).not.toHaveBeenCalled()
  })

  it('records a disabled gateway without another request', async () => {
    rememberGatewayEnabled(false)

    expect(isAnthropicAccountsEnabled()).toBe(false)
    await expect(loadGatewayEnabled()).resolves.toBe(false)
    expect(getMessagesGatewayStatus).not.toHaveBeenCalled()
  })
})
