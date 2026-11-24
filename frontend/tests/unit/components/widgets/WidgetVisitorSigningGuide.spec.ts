import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { createHmac } from 'node:crypto'

const api = vi.hoisted(() => ({
  getVisitorKey: vi.fn(),
  rotateVisitorKey: vi.fn(),
  requireVisitorSignature: vi.fn(),
}))
const notify = vi.hoisted(() => ({ success: vi.fn(), error: vi.fn() }))
const dialog = vi.hoisted(() => ({ confirm: vi.fn() }))

vi.mock('@/services/api/widgetVisitorApi', () => api)
vi.mock('@/composables/useNotification', () => ({ useNotification: () => notify }))
vi.mock('@/composables/useDialog', () => ({ useDialog: () => dialog }))

import WidgetVisitorSigningGuide from '@/components/widgets/WidgetVisitorSigningGuide.vue'
import { visitorPayload } from '@/services/api/widgetVisitorApi'

const signing = {
  title: 'Signing key',
  help: 'Keep it on your server.',
  keyLabel: 'Signing key for user IDs',
  show: 'Show key',
  hide: 'Hide key',
  copy: 'Copy key',
  copied: 'Signing key copied.',
  rotate: 'Replace key',
  rotateTitle: 'Replace the signing key?',
  rotateConfirm: 'Old signatures stop working.',
  rotated: 'New signing key created.',
  loadFailed: 'The signing key could not be loaded.',
  exampleTitle: 'How to sign on your server',
  exampleHelp: 'Valid for up to {hours} hours.',
  wordpress: 'Enter the key in the plugin settings.',
}

const mountGuide = () =>
  mount(WidgetVisitorSigningGuide, {
    props: { widgetId: 'wdg_1' },
    global: {
      plugins: [
        createI18n({
          legacy: false,
          locale: 'en',
          messages: { en: { widgets: { advancedConfig: { userDataIntegration: { signing } } } } },
        }),
      ],
      stubs: { Icon: true },
    },
  })

describe('WidgetVisitorSigningGuide', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    api.getVisitorKey.mockResolvedValue({ key: 'a'.repeat(64), maxLifetimeSeconds: 86400 })
    api.rotateVisitorKey.mockResolvedValue({ key: 'b'.repeat(64), maxLifetimeSeconds: 86400 })
  })

  it('shows the embed code with a signature and keeps the key out of the page until asked', () => {
    const wrapper = mountGuide()
    const input = wrapper.get('[data-testid="input-visitor-signing-key"]')

    expect(wrapper.get('[data-testid="text-visitor-embed-example"]').text()).toContain(
      "externalUserIdSignature: '<SIGNATURE_FROM_YOUR_SERVER>'"
    )
    expect(api.getVisitorKey).not.toHaveBeenCalled()
    expect((input.element as HTMLInputElement).value).toBe('')
    expect(wrapper.text()).toContain('Valid for up to 24 hours.')
  })

  it('loads and reveals the key once, and hides it again', async () => {
    const wrapper = mountGuide()
    const input = wrapper.get('[data-testid="input-visitor-signing-key"]')
    const toggle = wrapper.get('[data-testid="btn-visitor-key-toggle"]')

    await toggle.trigger('click')
    await flushPromises()
    expect(api.getVisitorKey).toHaveBeenCalledWith('wdg_1')
    expect((input.element as HTMLInputElement).value).toBe('a'.repeat(64))
    expect(input.attributes('type')).toBe('text')

    await toggle.trigger('click')
    expect(input.attributes('type')).toBe('password')
    await toggle.trigger('click')
    await flushPromises()
    expect(api.getVisitorKey).toHaveBeenCalledTimes(1)
  })

  it('says so when the key cannot be loaded', async () => {
    api.getVisitorKey.mockRejectedValue(new Error('403'))
    const wrapper = mountGuide()

    await wrapper.get('[data-testid="btn-visitor-key-toggle"]').trigger('click')
    await flushPromises()

    expect(notify.error).toHaveBeenCalledWith('The signing key could not be loaded.')
    expect(wrapper.get('[data-testid="input-visitor-signing-key"]').attributes('type')).toBe(
      'password'
    )
  })

  it('replaces the key only after the confirmation', async () => {
    const wrapper = mountGuide()
    const rotate = wrapper.get('[data-testid="btn-visitor-key-rotate"]')

    dialog.confirm.mockResolvedValue(false)
    await rotate.trigger('click')
    await flushPromises()
    expect(api.rotateVisitorKey).not.toHaveBeenCalled()

    dialog.confirm.mockResolvedValue(true)
    await rotate.trigger('click')
    await flushPromises()
    expect(api.rotateVisitorKey).toHaveBeenCalledWith('wdg_1')
    expect(
      (wrapper.get('[data-testid="input-visitor-signing-key"]').element as HTMLInputElement).value
    ).toBe('b'.repeat(64))
    expect(notify.success).toHaveBeenCalledWith('New signing key created.')
  })

  it('documents the signature the backend checks', () => {
    const node = mountGuide().get('[data-testid="text-visitor-signing-node"]').text()
    const expiry = 1_800_000_600
    const mac = createHmac('sha256', 'k').update(`wdg_1\nuser-1\n${expiry}`).digest('hex')

    expect(node).toContain('`wdg_1\\n${userId}\\n${expiry}`')
    expect(node).toContain('`${expiry}.${mac}`')
    expect(mac).toMatch(/^[0-9a-f]{64}$/)
  })
})

describe('visitorPayload', () => {
  it('sends the id with its signature and nothing without an id', async () => {
    const actual = await vi.importActual<{ visitorPayload: typeof visitorPayload }>(
      '@/services/api/widgetVisitorApi'
    )

    expect(
      actual.visitorPayload({ externalUserId: 'u1', externalUserIdSignature: '1.ab' })
    ).toEqual({ externalUserId: 'u1', externalUserIdSignature: '1.ab' })
    expect(actual.visitorPayload({ externalUserId: 'u1' })).toEqual({ externalUserId: 'u1' })
    expect(actual.visitorPayload({ externalUserIdSignature: '1.ab' })).toEqual({})
    expect(actual.visitorPayload()).toEqual({})
  })
})
