/**
 * Authentication API - Login, Register, Password Management
 */

import { z } from 'zod'
import { PostApiAuthResendVerificationResponseSchema } from '@/generated/api-schemas'
import { httpClient } from './httpClient'

export type ResendVerificationResult = z.infer<typeof PostApiAuthResendVerificationResponseSchema>

export const authApi = {
  async login(email: string, password: string): Promise<unknown> {
    return httpClient('/api/v1/auth/login', {
      method: 'POST',
      body: JSON.stringify({ email, password }),
    })
  },

  async register(email: string, password: string): Promise<unknown> {
    return httpClient('/api/v1/auth/register', {
      method: 'POST',
      body: JSON.stringify({ email, password }),
    })
  },

  async logout(): Promise<unknown> {
    return httpClient('/api/v1/auth/logout', {
      method: 'POST',
    })
  },

  async getCurrentUser(): Promise<unknown> {
    return httpClient('/api/v1/auth/me', {
      method: 'GET',
    })
  },

  async verifyEmail(token: string): Promise<unknown> {
    return httpClient('/api/v1/auth/verify-email', {
      method: 'POST',
      body: JSON.stringify({ token }),
      skipAuth: true,
    })
  },

  resendVerification(email: string): Promise<ResendVerificationResult> {
    return httpClient('/api/v1/auth/resend-verification', {
      method: 'POST',
      body: JSON.stringify({ email }),
      skipAuth: true,
      schema: PostApiAuthResendVerificationResponseSchema,
    })
  },

  async forgotPassword(email: string): Promise<unknown> {
    return httpClient('/api/v1/auth/forgot-password', {
      method: 'POST',
      body: JSON.stringify({ email }),
      skipAuth: true,
    })
  },

  async resetPassword(token: string, password: string): Promise<unknown> {
    // Password reset should work without authentication
    return httpClient('/api/v1/auth/reset-password', {
      method: 'POST',
      body: JSON.stringify({ token, password }),
      skipAuth: true,
    })
  },
}
