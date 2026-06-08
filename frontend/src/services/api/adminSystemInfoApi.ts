import { httpClient } from './httpClient'

export interface SystemInfo {
  php: {
    version: string
    sapi: string
    opcacheEnabled: boolean
  }
  memory: {
    limit: string
    limitBytes: number
    currentUsageBytes: number
    peakUsageBytes: number
  }
  limits: {
    uploadMaxFilesize: string
    postMaxSize: string
    maxExecutionTime: number
  }
  disk: {
    freeBytes: number | null
    totalBytes: number | null
    usedBytes: number | null
    usedPercent: number | null
  }
  server: {
    os: string
    software: string | null
    hostname: string | null
  }
  serverTime: string
}

interface SystemInfoResponse {
  success: boolean
  system: SystemInfo
}

export const adminSystemInfoApi = {
  get: async (): Promise<SystemInfoResponse> => {
    return httpClient<SystemInfoResponse>('/api/v1/admin/system-info')
  },
}
