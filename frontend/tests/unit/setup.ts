import { config } from '@vue/test-utils'
import { vi } from 'vitest'
import { i18n, supportedLanguages } from '@/i18n'
import { loadAllMessages } from '@/i18n/loadAllMessages'
import { markNamespacesLoadedForTests } from '@/i18n/loader'

for (const locale of supportedLanguages) {
  i18n.global.setLocaleMessage(locale, loadAllMessages(locale))
}
markNamespacesLoadedForTests()

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
