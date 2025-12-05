/**
 * Authentication API - Login, Register, Password Management
 */

import { httpClient } from './httpClient'

export const authApi = {
  async login(email: string, password: string): Promise<any> {
    return httpClient<any>('/api/v1/auth/login', {
      method: 'POST',
      body: JSON.stringify({ email, password })
    })
  },

  async register(email: string, password: string): Promise<any> {
    return httpClient<any>('/api/v1/auth/register', {
      method: 'POST',
      body: JSON.stringify({ email, password })
    })
  },

  async logout(): Promise<any> {
    return httpClient<any>('/api/v1/auth/logout', {
      method: 'POST'
    })
  },

  async getCurrentUser(): Promise<any> {
    return httpClient<any>('/api/v1/auth/me', {
      method: 'GET'
    })
  },

  async verifyEmail(token: string): Promise<any> {
    // Email verification should work without authentication
    // Don't use httpClient as it may send expired auth tokens causing 401
    const API_BASE_URL = import.meta.env.VITE_API_BASE_URL || ''
    const response = await fetch(`${API_BASE_URL}/api/v1/auth/verify-email`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({ token })
    })

    if (!response.ok) {
      const errorData = await response.json().catch(() => ({}))
      throw new Error(errorData.error || errorData.message || `HTTP ${response.status}`)
    }

    return response.json()
  },

  async resendVerification(email: string): Promise<any> {
    // Resend verification should work without authentication
    const API_BASE_URL = import.meta.env.VITE_API_BASE_URL || ''
    const response = await fetch(`${API_BASE_URL}/api/v1/auth/resend-verification`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({ email })
    })

    if (!response.ok) {
      const errorData = await response.json().catch(() => ({}))
      throw new Error(errorData.error || errorData.message || `HTTP ${response.status}`)
    }

    return response.json()
  },

  async forgotPassword(email: string): Promise<any> {
    // Forgot password should work without authentication
    const API_BASE_URL = import.meta.env.VITE_API_BASE_URL || ''
    const response = await fetch(`${API_BASE_URL}/api/v1/auth/forgot-password`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({ email })
    })

    if (!response.ok) {
      const errorData = await response.json().catch(() => ({}))
      throw new Error(errorData.error || errorData.message || `HTTP ${response.status}`)
    }

    return response.json()
  },

  async resetPassword(token: string, password: string): Promise<any> {
    // Password reset should work without authentication
    const API_BASE_URL = import.meta.env.VITE_API_BASE_URL || ''
    const response = await fetch(`${API_BASE_URL}/api/v1/auth/reset-password`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({ token, password })
    })

    if (!response.ok) {
      const errorData = await response.json().catch(() => ({}))
      throw new Error(errorData.error || errorData.message || `HTTP ${response.status}`)
    }

    return response.json()
  }
}

