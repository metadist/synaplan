import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import CompanionLinks from '@/components/CompanionLinks.vue'
import { asI18nSchema, loadAllMessages } from '@/i18n/loadAllMessages'

const en = loadAllMessages('en')

function mountLinks() {
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en: asI18nSchema(en) } })
  return mount(CompanionLinks, {
    global: { plugins: [i18n], stubs: { Icon: true } },
  })
}

describe('CompanionLinks', () => {
  it('renders three GitHub cards that open in a new tab', () => {
    const wrapper = mountLinks()

    const desktop = wrapper.get('[data-testid="link-companion-desktop"]')
    const mobile = wrapper.get('[data-testid="link-companion-mobile"]')
    const outlook = wrapper.get('[data-testid="link-companion-outlook"]')

    expect(desktop.attributes('href')).toBe('https://github.com/metadist/synaplan-desktop')
    expect(mobile.attributes('href')).toBe('https://github.com/metadist/synaplan-apps')
    expect(outlook.attributes('href')).toBe('https://github.com/metadist/Synamail')

    for (const link of [desktop, mobile, outlook]) {
      expect(link.attributes('target')).toBe('_blank')
      expect(link.attributes('rel')).toContain('noopener')
    }

    expect(wrapper.text()).toContain('Desktop Client')
    expect(wrapper.text()).toContain('Mobile Apps')
    expect(wrapper.text()).toContain('Outlook add-in')
  })
})
