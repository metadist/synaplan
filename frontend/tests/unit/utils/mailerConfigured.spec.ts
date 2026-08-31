import { describe, expect, it, vi } from 'vitest'
import { isMailerConfigured } from '@/utils/mailerConfigured'

const runtime = vi.fn((): { auth?: { mailerConfigured?: boolean } } => ({
  auth: { mailerConfigured: true },
}))

vi.mock('@/services/api/httpClient', () => ({
  getConfigSync: () => runtime(),
}))

describe('isMailerConfigured', () => {
  it('treats a missing flag as configured, so hosted instances never flash a CLI hint', () => {
    runtime.mockReturnValue({ auth: {} })
    expect(isMailerConfigured()).toBe(true)
  })

  it('is false only when the backend says nothing can be delivered', () => {
    runtime.mockReturnValue({ auth: { mailerConfigured: false } })
    expect(isMailerConfigured()).toBe(false)
  })
})
