import { describe, it, expect, beforeEach } from 'vitest'
import { useAuth } from '@/composables/useAuth'

describe('useAuth', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  it('should initialize with no auth', () => {
    const { isAuthenticated, user } = useAuth()
    expect(isAuthenticated.value).toBe(false)
    expect(user.value).toBe(null)
  })

  it('should login successfully', async () => {
    const { login, isAuthenticated, user } = useAuth()
    const result = await login('test@example.com', 'password')
    
    expect(result).toBe(true)
    expect(isAuthenticated.value).toBe(true)
    expect(user.value?.email).toBe('test@example.com')
    expect(localStorage.getItem('auth_token')).toBeTruthy()
  })

  it('should logout and clear state', () => {
    const { login, logout, isAuthenticated, user } = useAuth()
    login('test@example.com', 'password')
    
    logout()
    
    expect(isAuthenticated.value).toBe(false)
    expect(user.value).toBe(null)
    expect(localStorage.getItem('auth_token')).toBe(null)
  })

  it('should detect existing token on checkAuth', () => {
    localStorage.setItem('auth_token', 'existing-token')
    
    const { checkAuth, isAuthenticated } = useAuth()
    const result = checkAuth()
    
    expect(result).toBe(true)
    expect(isAuthenticated.value).toBe(true)
  })
})

