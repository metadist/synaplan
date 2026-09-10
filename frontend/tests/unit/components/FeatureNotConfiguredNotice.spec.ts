import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/en.json'

let isAdmin = false
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    get isAdmin() {
      return isAdmin
    },
  }),
}))

import FeatureNotConfiguredNotice from '@/components/common/FeatureNotConfiguredNotice.vue'

const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })

const RouterLinkStub = {
  props: ['to'],
  template: '<a :href="to" data-router-link><slot /></a>',
}

const mountNotice = (props: { module: string; docs?: string | null }) =>
  mount(FeatureNotConfiguredNotice, {
    props,
    global: {
      plugins: [i18n],
      stubs: { RouterLink: RouterLinkStub, Icon: true },
    },
  })

describe('FeatureNotConfiguredNotice', () => {
  beforeEach(() => {
    isAdmin = false
  })

  it('names the module with its translated label', () => {
    const wrapper = mountNotice({ module: 'whatsapp' })

    const notice = wrapper.get('[data-testid="notice-feature-not-configured"]')
    expect(notice.attributes('data-module')).toBe('whatsapp')
    expect(notice.text()).toContain('WhatsApp channel is not available on this installation')
    expect(notice.text()).toContain('switched on by the administrator')
  })

  it('falls back to the raw id for an unknown module', () => {
    const wrapper = mountNotice({ module: 'future_module' })

    expect(wrapper.text()).toContain('future_module is not available on this installation')
  })

  it('links the docs anchor only when one is given', () => {
    const withDocs = mountNotice({ module: 'whatsapp', docs: 'modules/whatsapp' })
    const link = withDocs.get('[data-testid="link-feature-docs"]')
    expect(link.attributes('href')).toBe('https://docs.synaplan.com/modules/whatsapp')
    expect(link.attributes('rel')).toContain('noopener')

    const withoutDocs = mountNotice({ module: 'whatsapp' })
    expect(withoutDocs.find('[data-testid="link-feature-docs"]').exists()).toBe(false)
  })

  it('shows the feature-status link to admins only', () => {
    expect(
      mountNotice({ module: 'whatsapp' }).find('[data-testid="link-feature-status"]').exists()
    ).toBe(false)

    isAdmin = true
    const admin = mountNotice({ module: 'whatsapp' })
    expect(admin.get('[data-testid="link-feature-status"]').attributes('href')).toBe(
      '/admin/features'
    )
  })
})
