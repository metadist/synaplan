import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'

import APIKeysConfiguration from '@/components/config/APIKeysConfiguration.vue'

const { listApiKeys, createApiKey } = vi.hoisted(() => ({
  listApiKeys: vi.fn(),
  createApiKey: vi.fn(),
}))

vi.mock('vue-router', () => ({
  useRoute: () => ({ path: '/channels/api' }),
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ success: vi.fn(), error: vi.fn() }),
}))

vi.mock('@/composables/useDialog', () => ({
  useDialog: () => ({ confirm: vi.fn() }),
}))

vi.mock('@/services/api/httpClient', () => ({
  getConfigSync: () => ({ features: { computeEnabled: false } }),
}))

vi.mock('@/services/api/apiKeysApi', () => ({
  listApiKeys,
  createApiKey,
  updateApiKey: vi.fn(),
  revokeApiKey: vi.fn(),
}))

const SECRET = 'sk_live_once_only_do_not_lose'

describe('APIKeysConfiguration — one-time key dialog', () => {
  let wrapper: VueWrapper | null = null

  beforeEach(() => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout', 'setInterval', 'clearInterval'] })
    listApiKeys.mockResolvedValue({ api_keys: [] })
    createApiKey.mockResolvedValue({
      success: true,
      message: 'created',
      api_key: {
        id: 7,
        name: 'Claude',
        key: SECRET,
        key_prefix: 'sk_live_…',
        scopes: ['webhooks:*'],
        created: 1_700_000_000,
        status: 'active',
        last_used: null,
      },
    })
    const app = document.createElement('div')
    app.id = 'app'
    document.body.appendChild(app)
  })

  afterEach(() => {
    wrapper?.unmount()
    wrapper = null
    document.getElementById('app')?.remove()
    vi.useRealTimers()
    vi.clearAllMocks()
  })

  const mountPage = async () => {
    wrapper = mount(APIKeysConfiguration, {
      attachTo: document.getElementById('app')!,
      global: {
        stubs: {
          PageHeader: { template: '<div />' },
          RouterLink: { template: '<a><slot /></a>' },
        },
      },
    })
    await flushPromises()
    return wrapper
  }

  const openDialog = async () => {
    const page = await mountPage()
    await page.get('[data-testid="input-key-name"]').setValue('Claude')
    await page.get('[data-testid="btn-create"]').trigger('click')
    await flushPromises()
    return page
  }

  const dialog = () => document.querySelector('[data-testid="modal-api-key-created-dialog"]')

  it('keeps the new key on screen until the dialog is closed', async () => {
    await openDialog()

    expect(dialog()?.getAttribute('role')).toBe('dialog')
    expect(dialog()?.getAttribute('aria-modal')).toBe('true')
    expect(dialog()?.textContent).toContain(SECRET)
    expect(document.body.textContent).not.toContain('close automatically')

    await vi.advanceTimersByTimeAsync(60_000)

    expect(dialog()?.textContent).toContain(SECRET)
  })

  const click = async (testId: string) => {
    document.querySelector<HTMLElement>(`[data-testid="${testId}"]`)?.click()
    await flushPromises()
  }

  it('closes from Close, Escape, and the backdrop', async () => {
    const page = await openDialog()

    await click('btn-close')
    expect(dialog()).toBeNull()

    await page.get('[data-testid="input-key-name"]').setValue('Claude again')
    await page.get('[data-testid="btn-create"]').trigger('click')
    await flushPromises()
    expect(dialog()?.textContent).toContain(SECRET)

    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
    await flushPromises()
    expect(dialog()).toBeNull()

    await page.get('[data-testid="input-key-name"]').setValue('Claude once more')
    await page.get('[data-testid="btn-create"]').trigger('click')
    await flushPromises()
    expect(dialog()).not.toBeNull()

    await click('modal-api-key-created')
    expect(dialog()).toBeNull()
    expect(document.body.textContent).not.toContain(SECRET)
  })
})
