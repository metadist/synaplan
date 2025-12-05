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
    return httpClient<any>('/api/v1/auth/verify-email', {
      method: 'POST',
      body: JSON.stringify({ token }),
      skipAuth: true
    })
  },

  async resendVerification(email: string): Promise<any> {
    return httpClient<any>('/api/v1/auth/resend-verification', {
      method: 'POST',
      body: JSON.stringify({ email }),
      skipAuth: true
    })
  },

  async forgotPassword(email: string): Promise<any> {
    return httpClient<any>('/api/v1/auth/forgot-password', {
      method: 'POST',
      body: JSON.stringify({ email }),
      skipAuth: true
    })
  },

  async resetPassword(token: string, password: string): Promise<any> {
    // Password reset should work without authentication
    return httpClient<any>('/api/v1/auth/reset-password', {
      method: 'POST',
      body: JSON.stringify({ token, password }),
      skipAuth: true
    })
  }
}

