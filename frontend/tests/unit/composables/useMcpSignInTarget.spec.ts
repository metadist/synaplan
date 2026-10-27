import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useMcpSignInTarget } from '@/composables/useMcpSignInTarget'

const { mockConfirm } = vi.hoisted(() => ({ mockConfirm: vi.fn() }))

vi.mock('@/composables/useDialog', () => ({
  useDialog: () => ({ confirm: mockConfirm }),
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    t: (key: string, params?: Record<string, string>) =>
      params ? `${key} ${JSON.stringify(params)}` : key,
  }),
}))

const server = { name: 'Wiki', url: 'https://mcp.example.com/mcp' }

describe('useMcpSignInTarget', () => {
  beforeEach(() => {
    mockConfirm.mockReset()
  })

  it('goes straight to a sign-in page on the server’s own host', async () => {
    const { decide } = useMcpSignInTarget()

    expect(await decide('https://mcp.example.com/authorize?x=1', server)).toBe('go')
    expect(mockConfirm).not.toHaveBeenCalled()
  })

  it('asks first when the sign-in page is on another host, naming both', async () => {
    mockConfirm.mockResolvedValue(true)
    const { decide } = useMcpSignInTarget()

    expect(await decide('https://auth.other.example/authorize', server)).toBe('go')
    const options = mockConfirm.mock.calls[0][0]
    expect(options.title).toContain('auth.other.example')
    expect(options.message).toContain('mcp.example.com')
    expect(options.message).toContain('auth.other.example')
  })

  it('stays on the page when the person declines', async () => {
    mockConfirm.mockResolvedValue(false)
    const { decide } = useMcpSignInTarget()

    expect(await decide('https://auth.other.example/authorize', server)).toBe('cancel')
  })

  it('never follows a sign-in link that is not https', async () => {
    const { decide } = useMcpSignInTarget()

    expect(await decide('http://mcp.example.com/authorize', server)).toBe('refuse')
    expect(await decide('javascript:alert(1)', server)).toBe('refuse')
    expect(await decide('not a url', server)).toBe('refuse')
    expect(mockConfirm).not.toHaveBeenCalled()
  })
})
