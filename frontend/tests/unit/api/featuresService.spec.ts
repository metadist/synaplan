import { describe, it, expect, vi, beforeEach } from 'vitest'
import { ZodError } from 'zod'

vi.mock('@/services/apiService', () => ({
  api: {
    get: vi.fn(),
  },
}))

import { api } from '@/services/apiService'
import { getFeaturesStatus, DevOnlyFeatureError } from '@/services/featuresService'

const mockedApi = vi.mocked(api)

const validFeature = {
  id: 'ollama',
  category: 'ai',
  name: 'Ollama',
  enabled: true,
  status: 'healthy' as const,
  message: 'Running',
  setup_required: false,
  models_available: 3,
  url: 'http://ollama:11434',
  version: '0.1.0',
}

const validResponse = {
  features: { ollama: validFeature },
  summary: { total: 1, healthy: 1, unhealthy: 0, all_ready: true },
}

beforeEach(() => {
  vi.clearAllMocks()
})

describe('getFeaturesStatus', () => {
  it('should parse a valid API response', async () => {
    mockedApi.get.mockResolvedValue({ data: validResponse })

    const result = await getFeaturesStatus()

    expect(result.features.ollama.id).toBe('ollama')
    expect(result.features.ollama.status).toBe('healthy')
    expect(result.summary.all_ready).toBe(true)
  })

  it('should accept null url and version fields', async () => {
    const response = {
      ...validResponse,
      features: {
        test: { ...validFeature, id: 'test', url: null, version: null },
      },
    }
    mockedApi.get.mockResolvedValue({ data: response })

    const result = await getFeaturesStatus()

    expect(result.features.test.url).toBeNull()
    expect(result.features.test.version).toBeNull()
  })

  it('should transform empty array env_vars to undefined', async () => {
    const response = {
      ...validResponse,
      features: {
        test: { ...validFeature, id: 'test', env_vars: [] },
      },
    }
    mockedApi.get.mockResolvedValue({ data: response })

    const result = await getFeaturesStatus()

    expect(result.features.test.env_vars).toBeUndefined()
  })

  it('should accept env_vars as a record', async () => {
    const envVars = {
      API_KEY: { required: true, set: true, hint: 'Your API key' },
    }
    const response = {
      ...validResponse,
      features: {
        test: { ...validFeature, id: 'test', env_vars: envVars },
      },
    }
    mockedApi.get.mockResolvedValue({ data: response })

    const result = await getFeaturesStatus()

    expect(result.features.test.env_vars).toEqual(envVars)
  })

  it('should reject invalid status values', async () => {
    const response = {
      ...validResponse,
      features: {
        test: { ...validFeature, status: 'broken' },
      },
    }
    mockedApi.get.mockResolvedValue({ data: response })

    await expect(getFeaturesStatus()).rejects.toThrow(ZodError)
  })

  it('should throw DevOnlyFeatureError on 403', async () => {
    mockedApi.get.mockRejectedValue(new Error('API Error: 403 Forbidden'))

    await expect(getFeaturesStatus()).rejects.toThrow(DevOnlyFeatureError)
    await expect(getFeaturesStatus()).rejects.toThrow('Feature only available in development mode')
  })

  it('should not match non-403 errors containing "403"', async () => {
    mockedApi.get.mockRejectedValue(new Error('Resource at /path/4032 not found'))

    await expect(getFeaturesStatus()).rejects.not.toThrow(DevOnlyFeatureError)
  })

  it('should re-throw other errors unchanged', async () => {
    const networkError = new Error('Network Error')
    mockedApi.get.mockRejectedValue(networkError)

    await expect(getFeaturesStatus()).rejects.toThrow('Network Error')
    await expect(getFeaturesStatus()).rejects.not.toThrow(DevOnlyFeatureError)
  })
})

describe('DevOnlyFeatureError', () => {
  it('should have correct name and message', () => {
    const error = new DevOnlyFeatureError()

    expect(error.name).toBe('DevOnlyFeatureError')
    expect(error.message).toBe('Feature only available in development mode')
    expect(error).toBeInstanceOf(Error)
  })
})
