import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'

const api = vi.hoisted(() => ({ requireVisitorSignature: vi.fn() }))
const notify = vi.hoisted(() => ({ success: vi.fn(), error: vi.fn() }))
const dialog = vi.hoisted(() => ({ confirm: vi.fn() }))

vi.mock('@/services/api/widgetVisitorApi', () => api)
vi.mock('@/composables/useNotification', () => ({ useNotification: () => notify }))
vi.mock('@/composables/useDialog', () => ({ useDialog: () => dialog }))

import WidgetUnsignedVisitorNotice from '@/components/widgets/WidgetUnsignedVisitorNotice.vue'

const unsigned = {
  title: 'This widget still accepts user IDs without a signature',
  text: 'Sign the ID on your server.',
  action: 'Require the signature',
  confirmTitle: 'Require the signature now?',
  confirmText: 'This cannot be turned off again.',
  done: 'The signature is now required.',
  failed: 'The signature could not be turned on.',
}

const NOTICE = '[data-testid="notice-visitor-unsigned"]'
const ACTION = '[data-testid="btn-visitor-require-signature"]'

const mountNotice = (widgetId: string, unsignedAllowed = true) =>
  mount(WidgetUnsignedVisitorNotice, {
    props: { widgetId, unsignedAllowed },
    global: {
      plugins: [
        createI18n({
          legacy: false,
          locale: 'en',
          messages: { en: { widgets: { advancedConfig: { userDataIntegration: { unsigned } } } } },
        }),
      ],
      stubs: { Icon: true },
    },
  })

describe('WidgetUnsignedVisitorNotice', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    api.requireVisitorSignature.mockResolvedValue({ signatureRequired: true })
  })

  it('shows only for a widget from before signing', () => {
    expect(mountNotice('wdg_new', false).find(NOTICE).exists()).toBe(false)
    expect(mountNotice('wdg_old').get(NOTICE).text()).toContain(unsigned.title)
  })

  it('turns the signature on only after the confirmation, then stays gone', async () => {
    const wrapper = mountNotice('wdg_switch')

    dialog.confirm.mockResolvedValue(false)
    await wrapper.get(ACTION).trigger('click')
    await flushPromises()
    expect(api.requireVisitorSignature).not.toHaveBeenCalled()
    expect(wrapper.find(NOTICE).exists()).toBe(true)

    dialog.confirm.mockResolvedValue(true)
    await wrapper.get(ACTION).trigger('click')
    await flushPromises()
    expect(dialog.confirm).toHaveBeenLastCalledWith(expect.objectContaining({ danger: true }))
    expect(api.requireVisitorSignature).toHaveBeenCalledWith('wdg_switch')
    expect(notify.success).toHaveBeenCalledWith(unsigned.done)
    expect(wrapper.find(NOTICE).exists()).toBe(false)
    expect(mountNotice('wdg_switch').find(NOTICE).exists()).toBe(false)
  })

  it('keeps the notice when the switch fails', async () => {
    api.requireVisitorSignature.mockRejectedValue(new Error('403'))
    dialog.confirm.mockResolvedValue(true)
    const wrapper = mountNotice('wdg_fail')

    await wrapper.get(ACTION).trigger('click')
    await flushPromises()

    expect(notify.error).toHaveBeenCalledWith(unsigned.failed)
    expect(wrapper.find(NOTICE).exists()).toBe(true)
  })
})
