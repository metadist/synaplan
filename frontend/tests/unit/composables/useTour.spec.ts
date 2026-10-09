import { beforeEach, describe, expect, it, vi } from 'vitest'

const driveSpy = vi.fn()
let lastConfig: { steps: Array<{ element?: Element }>; onDestroyed?: () => void } | null = null
vi.mock('driver.js', () => ({
  driver: (config: typeof lastConfig) => {
    lastConfig = config
    return { drive: driveSpy }
  },
}))
vi.mock('driver.js/dist/driver.css', () => ({}))

let authenticated = false
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    get isAuthenticated() {
      return authenticated
    },
    get user() {
      return authenticated ? { id: 7 } : null
    },
  }),
}))

const httpClient = vi.fn()
vi.mock('@/services/api/httpClient', () => ({
  httpClient: (...args: unknown[]) => httpClient(...args),
}))

vi.mock('@/tours', () => ({
  getTour: (id: string) =>
    id === 'demo'
      ? {
          id: 'demo',
          steps: [{ target: 'missing', stepKey: 'missing' }, { stepKey: 'intro' }],
        }
      : undefined,
}))

import { useTour } from '@/composables/useTour'

describe('useTour', () => {
  beforeEach(() => {
    localStorage.clear()
    driveSpy.mockReset()
    httpClient.mockReset()
    lastConfig = null
    authenticated = false
    useTour().resetTourCache()
  })

  it('starts a known tour and skips steps whose target is absent', () => {
    expect(useTour().startTour('demo')).toBe(true)
    expect(driveSpy).toHaveBeenCalledOnce()
    expect(lastConfig?.steps).toHaveLength(1)
    lastConfig?.onDestroyed?.()
  })

  it('refuses an unknown tour', () => {
    expect(useTour().startTour('nope')).toBe(false)
    expect(useTour().hasTour('nope')).toBe(false)
  })

  it('stores a finished tour locally for guests', async () => {
    await useTour().markSeen('demo')
    expect(JSON.parse(localStorage.getItem('synaplan.toursSeen') ?? '[]')).toEqual(['demo'])
    expect(httpClient).not.toHaveBeenCalled()
  })

  it('loads and saves the tour state on the account when signed in', async () => {
    authenticated = true
    httpClient.mockResolvedValueOnce({ profile: { toursSeen: ['library'] } })
    httpClient.mockResolvedValueOnce({ success: true })

    await useTour().markSeen('demo')

    expect(httpClient).toHaveBeenLastCalledWith('/api/v1/profile', {
      method: 'PUT',
      body: JSON.stringify({ toursSeen: ['library', 'demo'] }),
    })
    expect(useTour().seen.value).toEqual(['library', 'demo'])
  })

  it('does not auto-start a tour the account has already seen', async () => {
    authenticated = true
    httpClient.mockResolvedValueOnce({ profile: { toursSeen: ['demo'] } })
    await useTour().maybeAutoStart('demo')
    expect(driveSpy).not.toHaveBeenCalled()
  })
})
