import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import type { CatalogProvider } from '@/services/api/providerCatalogApi'
import { useAuthStore } from '@/stores/auth'
import { useProviderCatalogStore } from '@/stores/providerCatalog'

const fetchProviderCatalog = vi.fn()

vi.mock('@/services/api/providerCatalogApi', () => ({
  fetchProviderCatalog: () => fetchProviderCatalog(),
}))

const provider = (overrides: Partial<CatalogProvider>): CatalogProvider => ({
  name: 'acme',
  displayName: 'Acme AI',
  capabilities: ['chat'],
  icon: 'mdi:robot',
  iconComponent: null,
  flag: 'circle-flags:un',
  local: false,
  helpKey: null,
  helpUrl: null,
  helpIsDownload: false,
  helpFields: [],
  key: null,
  ...overrides,
})

describe('providerCatalog store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    fetchProviderCatalog.mockReset()
  })

  it('answers from the bundled maps before anything has loaded', () => {
    const store = useProviderCatalogStore()

    expect(store.iconFor('groq')).toBe('simple-icons:groq')
    expect(store.iconComponentFor('Groq')).toBe('GroqIcon')
    expect(store.flagFor('mistral')).toBe('circle-flags:fr')
    expect(store.isLocal('ollama')).toBe(true)
    expect(store.helpFor('openai')?.url).toBe('https://platform.openai.com/api-keys')
    expect(store.helpForField('OLLAMA_BASE_URL')?.isDownload).toBe(true)
    expect(store.displayName('groq')).toBeNull()
  })

  it('answers from the server once loaded and keeps the fallback for unknown names', async () => {
    fetchProviderCatalog.mockResolvedValue({
      providers: [
        provider({
          name: 'acme',
          icon: 'simple-icons:acme',
          iconComponent: null,
          flag: 'circle-flags:de',
          helpKey: 'acme',
          helpUrl: 'https://acme.example/keys',
          helpFields: ['ACME_API_KEY'],
          key: {
            store: 'platform',
            envVar: 'ACME_API_KEY',
            secretEnvVar: null,
            consoleUrl: 'https://acme.example/keys',
            userCredential: 'none',
            servesChat: true,
          },
        }),
        provider({
          name: 'groq',
          displayName: 'Groq',
          icon: 'simple-icons:groq',
          iconComponent: 'GroqIcon',
          flag: 'circle-flags:us',
        }),
      ],
    })
    const store = useProviderCatalogStore()

    await store.load()

    expect(store.loaded).toBe(true)
    expect(store.displayName('Acme AI')).toBeNull()
    expect(store.displayName('ACME')).toBe('Acme AI')
    expect(store.iconFor('acme')).toBe('simple-icons:acme')
    expect(store.flagFor('acme')).toBe('circle-flags:de')
    expect(store.helpFor('acme')).toEqual({
      id: 'acme',
      url: 'https://acme.example/keys',
      isDownload: false,
    })
    expect(store.helpForField('ACME_API_KEY')?.id).toBe('acme')
    expect(store.iconComponentFor('groq')).toBe('GroqIcon')
    // A server-known provider without a bundled glyph draws its Iconify icon.
    expect(store.iconComponentFor('acme')).toBeNull()
    // A name the server does not know still has the bundled answer.
    expect(store.iconFor('anthropic')).toBe('simple-icons:anthropic')
    expect(store.helpFor('ollama')?.isDownload).toBe(true)
  })

  it('loads once and keeps the bundled maps when the request fails', async () => {
    fetchProviderCatalog.mockRejectedValue(new Error('offline'))
    const store = useProviderCatalogStore()

    await store.load()
    await store.load()

    expect(fetchProviderCatalog).toHaveBeenCalledTimes(2)
    expect(store.loaded).toBe(false)
    expect(store.iconFor('groq')).toBe('simple-icons:groq')
  })

  it('does not fetch for a signed-out visitor and fetches once for a signed-in one', async () => {
    fetchProviderCatalog.mockResolvedValue({ providers: [] })
    const store = useProviderCatalogStore()

    store.ensureLoaded()
    expect(fetchProviderCatalog).not.toHaveBeenCalled()

    const auth = useAuthStore()
    auth.user = { id: 1, email: 'a@b.c' } as never
    store.ensureLoaded()
    store.ensureLoaded()
    await Promise.resolve()
    expect(fetchProviderCatalog).toHaveBeenCalledTimes(1)
  })
})
