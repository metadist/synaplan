/**
 * Profile API - User Profile Management
 */

import { httpClient } from './httpClient'

export interface Profile {
  email: string
  firstName: string
  lastName: string
  phone: string
  companyName: string
  vatId: string
  street: string
  zipCode: string
  city: string
  country: string
  language: string
  timezone: string
  invoiceEmail: string
  emailKeyword?: string | null
  personalEmailAddress: string
  canChangePassword?: boolean
  authProvider?: string
  isExternalAuth?: boolean
  externalAuthInfo?: {
    lastLogin?: string
  } | null
}

export interface ProfileResponse {
  success: boolean
  profile: Profile
}

export interface EmailKeywordResponse {
  success: boolean
  keyword: string | null
  emailAddress: string
}

export const profileApi = {
  async getProfile(): Promise<ProfileResponse> {
    return httpClient<ProfileResponse>('/api/v1/profile', {
      method: 'GET'
    })
  },

  async updateProfile(profileData: Partial<Profile>): Promise<any> {
    return httpClient<any>('/api/v1/profile', {
      method: 'PUT',
      body: JSON.stringify(profileData)
    })
  },

  async changePassword(currentPassword: string, newPassword: string): Promise<any> {
    return httpClient<any>('/api/v1/profile/password', {
      method: 'PUT',
      body: JSON.stringify({ currentPassword, newPassword })
    })
  },

  async getEmailKeyword(): Promise<EmailKeywordResponse> {
    return httpClient<EmailKeywordResponse>('/api/v1/profile/email-keyword', {
      method: 'GET'
    })
  },

  async setEmailKeyword(keyword: string): Promise<EmailKeywordResponse> {
    return httpClient<EmailKeywordResponse>('/api/v1/profile/email-keyword', {
      method: 'PUT',
      body: JSON.stringify({ keyword })
    })
  }
}

