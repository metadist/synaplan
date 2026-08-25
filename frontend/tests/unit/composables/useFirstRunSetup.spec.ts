import { describe, it, expect, beforeEach, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useAuthStore } from '@/stores/auth'
import { useFirstRunSetup } from '@/composables/useFirstRunSetup'
import type { AuthUser } from '@/services/authService'

const mockPush = vi.fn()

vi.mock('vue-router', () => ({
  useRouter: () => ({ push: mockPush }),
}))

function adminUser(): AuthUser {
  return {
    id: 1,
    email: 'admin@synaplan.com',
    level: 'ADMIN',
    isAdmin: true,
  }
}

describe('useFirstRunSetup', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    mockPush.mockReset()
  })

  it('sends an already-signed-in admin straight to setup', async () => {
    const auth = useAuthStore()
    auth.user = adminUser()

    const { goToProviderSetup } = useFirstRunSetup()
    await goToProviderSetup()

    expect(mockPush).toHaveBeenCalledWith('/admin/setup')
  })

  it('logs a guest in as the seeded admin and then redirects to setup', async () => {
    const auth = useAuthStore()
    const login = vi.fn().mockImplementation(async () => {
      auth.user = adminUser()
      return true
    })
    auth.login = login

    const { goToProviderSetup } = useFirstRunSetup()
    await goToProviderSetup()

    expect(login).toHaveBeenCalledWith('admin@synaplan.com', 'admin123')
    expect(mockPush).toHaveBeenCalledWith('/admin/setup')
  })

  it('falls back to the login page when the seeded password no longer works', async () => {
    const auth = useAuthStore()
    auth.login = vi.fn().mockResolvedValue(false)

    const { goToProviderSetup } = useFirstRunSetup()
    await goToProviderSetup()

    expect(mockPush).toHaveBeenCalledWith({
      path: '/login',
      query: { redirect: '/admin/setup', email: 'admin@synaplan.com' },
    })
  })

  it('does not overwrite a signed-in non-admin with the seeded admin', async () => {
    const auth = useAuthStore()
    auth.user = { id: 2, email: 'demo@synaplan.com', level: 'PRO', isAdmin: false }
    const login = vi.fn()
    auth.login = login

    const { goToProviderSetup } = useFirstRunSetup()
    await goToProviderSetup()

    expect(login).not.toHaveBeenCalled()
    expect(mockPush).toHaveBeenCalledWith({
      path: '/login',
      query: { redirect: '/admin/setup', email: 'admin@synaplan.com' },
    })
  })
})
