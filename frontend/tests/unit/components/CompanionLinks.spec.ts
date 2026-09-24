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
  it('explains model choice and links to the stores and the source', () => {
    const wrapper = mountLinks()

    expect(wrapper.get('[data-testid="card-companion-choice"]').text()).toContain(
      'Your model and assistant'
    )

    const appStore = wrapper.get('[data-testid="link-companion-app-store"]')
    const playStore = wrapper.get('[data-testid="link-companion-play-store"]')
    const source = wrapper.get('[data-testid="link-companion-source"]')

    expect(appStore.attributes('href')).toBe(
      'https://apps.apple.com/app/id6784278288?ct=app-welcome'
    )
    expect(playStore.attributes('href')).toBe(
      'https://play.google.com/store/apps/details?id=com.synaplan.app&referrer=utm_source%3Dapp-welcome'
    )
    expect(source.attributes('href')).toBe('https://github.com/metadist/synaplan')

    for (const link of [appStore, playStore, source]) {
      expect(link.attributes('target')).toBe('_blank')
      expect(link.attributes('rel')).toContain('noopener')
    }

    expect(wrapper.text()).toContain('iPhone and Android')
    expect(wrapper.text()).toContain('App Store')
    expect(wrapper.text()).toContain('Google Play')
    expect(wrapper.text()).toContain('Source code')
  })
})
