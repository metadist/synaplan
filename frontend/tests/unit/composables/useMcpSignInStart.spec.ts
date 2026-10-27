import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useMcpSignInStart } from '@/composables/useMcpSignInStart'
import { ApiError } from '@/services/api/httpClient'

const { mockStart, mockDecide, mockError, mockReload } = vi.hoisted(() => ({
  mockReload: vi.fn(),
  mockStart: vi.fn(),
  mockDecide: vi.fn(),
  mockError: vi.fn(),
}))

vi.mock('@/services/api/mcpServersApi', () => ({ mcpServersApi: { startOAuth: mockStart } }))
vi.mock('@/composables/useMcpSignInTarget', () => ({
  useMcpSignInTarget: () => ({ decide: mockDecide }),
}))
vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ error: mockError, success: vi.fn() }),
}))
vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    t: (key: string, params?: Record<string, string>) =>
      params ? `${key} ${params.name}: ${params.reason}` : key,
  }),
}))

const server = { id: 9, name: 'Wiki', url: 'https://wiki.example.com/mcp' }

describe('useMcpSignInStart', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('shows the sentence for the reason, never the server text', async () => {
    mockStart.mockRejectedValue(new ApiError(400, 'raw server text', 'no_signin'))
    const { start, connectingId } = useMcpSignInStart(mockReload)

    await start(server)

    expect(mockError).toHaveBeenCalledWith('mcpServers.signInError.no_signin')
    expect(connectingId.value).toBeNull()
  })

  it('says both halves after a save: saved, and not signed in', async () => {
    mockStart.mockRejectedValue(new ApiError(400, 'raw', 'https_required'))
    const { start } = useMcpSignInStart(mockReload)

    await start(server, true)

    expect(mockError).toHaveBeenCalledWith(
      'mcpServers.savedNotSignedIn Wiki: mcpServers.signInError.https_required'
    )
  })

  it('stays quietly on the page when the person declines the other host', async () => {
    mockStart.mockResolvedValue('https://auth.other.example/authorize')
    mockDecide.mockResolvedValue('cancel')
    const { start, connectingId } = useMcpSignInStart(mockReload)

    await start(server)

    expect(mockError).not.toHaveBeenCalled()
    expect(connectingId.value).toBeNull()
    expect(mockReload).toHaveBeenCalledTimes(1)
  })

  it('refuses a sign-in link it will not follow with the general sentence', async () => {
    mockStart.mockResolvedValue('http://wiki.example.com/authorize')
    mockDecide.mockResolvedValue('refuse')
    const { start } = useMcpSignInStart(mockReload)

    await start(server)

    expect(mockError).toHaveBeenCalledWith('mcpServers.signInError.unknown')
  })
})
