import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import PhoneVerificationInstructions from '@/components/config/PhoneVerificationInstructions.vue'
import { asI18nSchema, loadAllMessages } from '@/i18n/loadAllMessages'

const en = loadAllMessages('en')

const hosted = [
  { e164: '+4915116038214', wa_me: 'https://wa.me/4915116038214' },
  { e164: '+16282253244', wa_me: 'https://wa.me/16282253244' },
]

function mountCard(numbers: typeof hosted) {
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en: asI18nSchema(en) } })
  return mount(PhoneVerificationInstructions, {
    props: {
      code: 'S4C21',
      timeRemaining: 200,
      inboundNumbers: numbers,
      formatTimeRemaining: (seconds: number) => `${seconds}`,
    },
    global: { plugins: [i18n] },
  })
}

describe('PhoneVerificationInstructions', () => {
  it('lists wa.me links for configured numbers', () => {
    const wrapper = mountCard(hosted)
    const links = wrapper.findAll('[data-testid="link-inbound-number"]')
    expect(links.map((link) => link.attributes('href'))).toEqual([
      'https://wa.me/4915116038214',
      'https://wa.me/16282253244',
    ])
    expect(wrapper.text()).toContain('Send the code S4C21 to one of these numbers')
    expect(wrapper.text()).not.toContain('any of our numbers')
  })

  it('says the numbers are missing instead of inventing them', () => {
    const wrapper = mountCard([])
    expect(wrapper.find('[data-testid="inbound-numbers-missing"]').exists()).toBe(true)
    expect(wrapper.findAll('[data-testid="link-inbound-number"]')).toHaveLength(0)
    expect(wrapper.text()).toContain('Ask the person who runs this Synaplan')
    expect(wrapper.text()).not.toContain('one of these numbers')
    expect(wrapper.text()).not.toContain('any of our numbers')
  })
})
