/**
 * Profile API - User Profile Management
 */

import { httpClient } from './httpClient'

export const profileApi = {
  async getProfile(): Promise<any> {
    return httpClient<any>('/api/v1/profile', {
      method: 'GET'
    })
  },

  async updateProfile(profileData: any): Promise<any> {
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
  }
}

