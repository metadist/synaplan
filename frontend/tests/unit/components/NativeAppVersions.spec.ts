import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const serverVersion = vi.hoisted(() => ({ value: '5.4.1' }))

vi.mock('@/stores/config', () => ({
  useConfigStore: () => ({
    build: {
      get version() {
        return serverVersion.value
      },
    },
  }),
}))

vi.mock('@iconify/vue', () => ({
  Icon: { template: '<i />' },
}))

import NativeAppVersions from '@/components/NativeAppVersions.vue'
import { getNativeAppVersions } from '@/services/api/nativeAppInfo'

type Bridge = { SynaplanAppInfo?: unknown }

function setBridge(getVersions?: () => Promise<unknown>) {
  if (getVersions) {
    ;(globalThis as Bridge).SynaplanAppInfo = { getVersions }
  } else {
    delete (globalThis as Bridge).SynaplanAppInfo
  }
}

async function mountVersions() {
  const wrapper = mount(NativeAppVersions)
  await flushPromises()
  return wrapper
}

afterEach(() => {
  setBridge()
  serverVersion.value = '5.4.1'
})

describe('NativeAppVersions', () => {
  it('shows the installed app, the web bundle and the server version', async () => {
    setBridge(() => Promise.resolve({ app: '5.3.1', build: '214', web: '5.4.0' }))
    const wrapper = await mountVersions()

    expect(wrapper.find('[data-testid="app-version-app"]').text()).toBe('v5.3.1 (build 214)')
    expect(wrapper.find('[data-testid="app-version-web"]').text()).toBe('v5.4.0')
    expect(wrapper.find('[data-testid="app-version-server"]').text()).toBe('v5.4.1')
  })

  it('renders nothing on the web build', async () => {
    setBridge()
    const wrapper = await mountVersions()

    expect(wrapper.find('[data-testid="section-app-version"]').exists()).toBe(false)
  })

  it('leaves out a version that is unknown', async () => {
    serverVersion.value = 'latest'
    setBridge(() => Promise.resolve({ app: '5.3.1', build: '', web: '' }))
    const wrapper = await mountVersions()

    expect(wrapper.find('[data-testid="app-version-app"]').text()).toBe('v5.3.1')
    expect(wrapper.find('[data-testid="app-version-web"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="app-version-server"]').exists()).toBe(false)
  })
})

describe('getNativeAppVersions', () => {
  it('resolves null when the bridge fails or reports nothing', async () => {
    setBridge(() => Promise.reject(new Error('unavailable')))
    expect(await getNativeAppVersions()).toBeNull()

    setBridge(() => Promise.resolve({ app: '', build: '', web: '' }))
    expect(await getNativeAppVersions()).toBeNull()

    setBridge(() => Promise.resolve(null))
    expect(await getNativeAppVersions()).toBeNull()
  })
})
