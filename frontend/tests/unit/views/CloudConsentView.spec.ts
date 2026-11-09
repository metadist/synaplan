import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createI18n } from 'vue-i18n'
import { ref } from 'vue'

const getCloudConsent = vi.fn()
const decideCloudConsent = vi.fn()
const isAuthenticated = ref(true)
const logout = vi.fn()
const assign = vi.fn()

vi.mock('@/services/api/platformLinksApi', () => ({
  platformLinksApi: {
    getCloudConsent: (...args: unknown[]) => getCloudConsent(...args),
    decideCloudConsent: (...args: unknown[]) => decideCloudConsent(...args),
  },
}))

vi.mock('@/stores/auth', () => ({
  authReady: Promise.resolve(),
  useAuthStore: () => ({
    get isAuthenticated() {
      return isAuthenticated.value
    },
    get user() {
      return { email: 'demo@synaplan.com' }
    },
    logout,
  }),
}))

import CloudConsentView from '@/views/CloudConsentView.vue'

const REQUEST = 'a'.repeat(48)

async function mountAt(path: string) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/connect/cloud', component: CloudConsentView },
      { path: '/login', component: { template: '<div>login</div>' } },
    ],
  })
  await router.push(path)
  await router.isReady()
  const i18n = createI18n({ legacy: false, locale: 'en', missingWarn: false, fallbackWarn: false })
  const wrapper = mount(CloudConsentView, { global: { plugins: [router, i18n] } })
  await flushPromises()
  return { wrapper, router }
}

describe('CloudConsentView', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    isAuthenticated.value = true
    getCloudConsent.mockReset()
    decideCloudConsent.mockReset()
    assign.mockReset()
    vi.stubGlobal('location', { ...window.location, assign })
  })

  it('shows which cloud account asks and follows the redirect after allowing', async () => {
    getCloudConsent.mockResolvedValue({
      client: 'nextcloud',
      host: 'cloud.example.org',
      externalId: 'ada',
      scopes: [],
    })
    decideCloudConsent.mockResolvedValue({
      redirect: 'https://cloud.example.org/cb?code=x&state=s',
    })
    const { wrapper } = await mountAt(`/connect/cloud?request=${REQUEST}`)

    expect(getCloudConsent).toHaveBeenCalledWith(REQUEST)
    expect(wrapper.text()).toContain('ada')
    expect(wrapper.text()).toContain('cloud.example.org')
    await wrapper.get('[data-testid="btn-connect"]').trigger('click')
    await flushPromises()
    expect(decideCloudConsent).toHaveBeenCalledWith(REQUEST, true)
    expect(assign).toHaveBeenCalledWith('https://cloud.example.org/cb?code=x&state=s')
  })

  it('sends the denial back to the cloud too', async () => {
    getCloudConsent.mockResolvedValue({
      client: 'nextcloud',
      host: 'cloud.example.org',
      externalId: 'ada',
      scopes: [],
    })
    decideCloudConsent.mockResolvedValue({
      redirect: 'https://cloud.example.org/cb?error=access_denied',
    })
    const { wrapper } = await mountAt(`/connect/cloud?request=${REQUEST}`)
    await wrapper.get('[data-testid="btn-deny"]').trigger('click')
    await flushPromises()
    expect(decideCloudConsent).toHaveBeenCalledWith(REQUEST, false)
    expect(assign).toHaveBeenCalledWith('https://cloud.example.org/cb?error=access_denied')
  })

  it('asks a signed-out person to sign in first', async () => {
    isAuthenticated.value = false
    const { router } = await mountAt(`/connect/cloud?request=${REQUEST}`)
    expect(router.currentRoute.value.path).toBe('/login')
    expect(getCloudConsent).not.toHaveBeenCalled()
  })

  it('refuses a malformed request without calling the server', async () => {
    const { wrapper } = await mountAt('/connect/cloud?request=nope')
    expect(wrapper.find('[data-testid="section-error"]').exists()).toBe(true)
    expect(getCloudConsent).not.toHaveBeenCalled()
  })
})
