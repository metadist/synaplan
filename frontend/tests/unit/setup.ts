import { config } from '@vue/test-utils'
import { i18n } from '@/i18n'

config.global.plugins = [i18n]

// Suppress console.log in tests
global.console.log = () => {}

// Mock runtime config API
global.fetch = vi.fn((url) => {
  if (url === '/api/v1/config/runtime') {
    return Promise.resolve({
      ok: true,
      json: () =>
        Promise.resolve({
          recaptcha: {
            enabled: false,
            siteKey: '',
          },
          features: {
            help: false,
          },
        }),
    })
  }
  // Return a default mock response for other endpoints
  return Promise.resolve({
    ok: false,
    status: 404,
    json: () => Promise.resolve({}),
  })
}) as unknown as typeof fetch
