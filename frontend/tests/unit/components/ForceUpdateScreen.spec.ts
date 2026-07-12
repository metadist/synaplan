import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'

import ForceUpdateScreen from '@/components/ForceUpdateScreen.vue'

const mobile = {
  updateRequired: true,
  minVersion: '4.1',
  iosAppUrl: 'https://apps.apple.com/app/id1',
  androidAppUrl: 'https://play.google.com/store/apps/details?id=com.synaplan.app',
}

let native = true
let platform = 'ios'

vi.mock('@/stores/config', () => ({
  useConfigStore: () => ({ mobile }),
}))

vi.mock('@/services/api/nativeRuntime', () => ({
  isNativeApp: () => native,
  getNativePlatform: () => platform,
}))

const mountScreen = () =>
  mount(ForceUpdateScreen, {
    global: {
      mocks: {
        $t: (key: string) => key,
      },
      stubs: {
        Icon: true,
        Transition: false,
      },
    },
  })

describe('ForceUpdateScreen', () => {
  beforeEach(() => {
    native = true
    platform = 'ios'
    mobile.updateRequired = true
    mobile.minVersion = '4.1'
    mobile.iosAppUrl = 'https://apps.apple.com/app/id1'
    mobile.androidAppUrl = 'https://play.google.com/store/apps/details?id=com.synaplan.app'
  })

  it('blocks an outdated native client when its store URL is available', () => {
    const wrapper = mountScreen()

    expect(wrapper.find('[data-testid="force-update"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="btn-force-update"]').attributes('href')).toBe(
      mobile.iosAppUrl
    )
  })

  it('fails open when no store URL is configured', () => {
    mobile.iosAppUrl = ''
    mobile.androidAppUrl = ''

    expect(mountScreen().find('[data-testid="force-update"]').exists()).toBe(false)
  })

  it('never blocks the web application', () => {
    native = false

    expect(mountScreen().find('[data-testid="force-update"]').exists()).toBe(false)
  })
})
