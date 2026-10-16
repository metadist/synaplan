import { setActivePinia, createPinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { i18n } from '@/i18n'
import type { SchedulerStatus } from '@/services/api/scheduler'

let mockIsAdmin = true
let mockIsNativeApp = false

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    get isAdmin() {
      return mockIsAdmin
    },
  }),
}))

vi.mock('@/services/api/nativeRuntime', () => ({
  isNativeApp: () => mockIsNativeApp,
}))

const getStatus = vi.fn()

vi.mock('@/services/api/scheduler', () => ({
  schedulerApi: {
    getStatus: () => getStatus(),
  },
}))

import { useSchedulerStore } from '@/stores/scheduler'
import SchedulerStaleHint from '@/components/SchedulerStaleHint.vue'

function payload(state: SchedulerStatus['state']): SchedulerStatus {
  return {
    state,
    maxAgeSeconds: 180,
    checkedAt: 1_700_000_200,
    lastRunAt: state === 'never' ? null : 1_700_000_100,
    lanes: [],
  }
}

const RouterLinkStub = {
  props: { to: { type: [String, Object], required: true } },
  template: "<a :href=\"typeof to === 'string' ? to : ''\"><slot /></a>",
}

async function mountHint(compact = false) {
  const wrapper = mount(SchedulerStaleHint, {
    props: { compact },
    global: {
      stubs: { RouterLink: RouterLinkStub },
    },
  })
  await flushPromises()
  return wrapper
}

describe('SchedulerStaleHint', () => {
  beforeEach(() => {
    mockIsAdmin = true
    mockIsNativeApp = false
    getStatus.mockReset()
    i18n.global.locale.value = 'en'
    setActivePinia(createPinia())
  })

  it('shows an internal link only for an admin while jobs are stopped', async () => {
    getStatus.mockResolvedValue(payload('stale'))
    const store = useSchedulerStore()
    await store.load()

    const wrapper = await mountHint()
    const link = wrapper.get('[data-testid="link-sidebar-v2-scheduler"]')
    expect(link.text()).toContain('Background jobs stopped')
    expect(link.attributes('href')).toBe('/admin/features')
    expect(link.attributes('aria-label')).toBe('Background jobs stopped')
    expect(link.attributes('target')).toBeUndefined()
  })

  it('stays hidden while jobs are running', async () => {
    getStatus.mockResolvedValue(payload('running'))
    await useSchedulerStore().load()

    const wrapper = await mountHint()
    expect(wrapper.find('[data-testid="link-sidebar-v2-scheduler"]').exists()).toBe(false)
  })

  it('stays hidden for a non-admin', async () => {
    mockIsAdmin = false
    await useSchedulerStore().load()

    const wrapper = await mountHint()
    expect(getStatus).not.toHaveBeenCalled()
    expect(wrapper.find('[data-testid="link-sidebar-v2-scheduler"]').exists()).toBe(false)
  })

  it('stays hidden in the native app', async () => {
    mockIsNativeApp = true
    await useSchedulerStore().load()

    const wrapper = await mountHint()
    expect(getStatus).not.toHaveBeenCalled()
    expect(wrapper.find('[data-testid="link-sidebar-v2-scheduler"]').exists()).toBe(false)
  })

  it('keeps an accessible label on the collapsed rail', async () => {
    getStatus.mockResolvedValue(payload('stale'))
    await useSchedulerStore().load()

    const wrapper = await mountHint(true)
    const link = wrapper.get('[data-testid="link-sidebar-v2-scheduler"]')
    expect(link.attributes('aria-label')).toBe('Background jobs stopped')
    expect(link.attributes('title')).toBe('Background jobs stopped')
    expect(link.text()).toBe('Jobs stopped')
  })
})
