import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'

/**
 * The announcement modal interrupts everybody exactly once, so the rules worth
 * pinning are the ones that would be felt if they broke: it must stay away from
 * sign-in and checkout, it must never come back after being closed, and it must
 * not advertise an app on an instance that has none.
 */

const runtime = vi.hoisted(() => ({
  iosAppUrl: 'https://apps.apple.com/app/id6784278288',
  androidAppUrl: 'https://play.google.com/store/apps/details?id=com.synaplan.app',
  isNative: false,
  deviceOs: 'other' as 'ios' | 'android' | 'other',
  path: '/',
}))

vi.mock('@/stores/config', () => ({
  useConfigStore: () => ({
    mobile: {
      get iosAppUrl() {
        return runtime.iosAppUrl
      },
      get androidAppUrl() {
        return runtime.androidAppUrl
      },
    },
  }),
}))

vi.mock('@/services/api/nativeRuntime', () => ({
  isNativeApp: () => runtime.isNative,
}))

vi.mock('@/utils/detectBrowserOs', () => ({
  detectBrowserOs: () => runtime.deviceOs,
}))

vi.mock('vue-router', () => ({
  useRoute: () => ({
    get path() {
      return runtime.path
    },
  }),
}))

vi.mock('@/composables/useTheme', async () => {
  const { ref } = await import('vue')
  return { useTheme: () => ({ isDark: ref(false) }) }
})

vi.mock('@/composables/useBrandLogo', async () => {
  const { computed } = await import('vue')
  return { useBrandLogo: () => ({ iconSrc: computed(() => '/brand.svg') }) }
})

const SEEN_KEY = 'synaplan.announcements.seen.v1'

/** Comfortably past the component's settle delay. */
const AFTER_SETTLE_MS = 2000

/**
 * The composable caches the dismissal list at module scope, which is what keeps
 * the modal from reappearing elsewhere in a session. Tests therefore need a
 * fresh module graph rather than a fresh component.
 */
async function mountFresh() {
  vi.resetModules()
  const { default: AnnouncementModal } = await import('@/components/AnnouncementModal.vue')

  return mount(AnnouncementModal, { global: { stubs: { teleport: true } } })
}

/** Mounts and waits out the pause the modal takes before showing itself. */
async function mountModal() {
  const wrapper = await mountFresh()
  await vi.advanceTimersByTimeAsync(AFTER_SETTLE_MS)
  await nextTick()

  return wrapper
}

const modal = 'modal-announcement'

describe('AnnouncementModal', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    // Pinned so the catalogue's expiry dates cannot turn this suite red on some
    // future date rather than because of a change to the code.
    vi.setSystemTime(new Date('2026-08-17T10:00:00Z'))
    localStorage.clear()
    document.body.style.overflow = ''
    runtime.iosAppUrl = 'https://apps.apple.com/app/id6784278288'
    runtime.androidAppUrl = 'https://play.google.com/store/apps/details?id=com.synaplan.app'
    runtime.isNative = false
    runtime.deviceOs = 'other'
    runtime.path = '/'
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('lets the page appear before interrupting it', async () => {
    const wrapper = await mountFresh()
    expect(wrapper.find(`[data-testid="${modal}"]`).exists()).toBe(false)

    await vi.advanceTimersByTimeAsync(AFTER_SETTLE_MS)
    await nextTick()
    expect(wrapper.find(`[data-testid="${modal}"]`).exists()).toBe(true)
  })

  it('greets a web visitor of an instance that has both apps, App Store leading', async () => {
    const wrapper = await mountModal()

    expect(wrapper.find(`[data-testid="${modal}"]`).exists()).toBe(true)
    expect(
      wrapper.find('[data-testid="link-announcement-action-appStore"]').attributes('href')
    ).toBe('https://apps.apple.com/app/id6784278288?ct=web-announcement')
    expect(
      wrapper.find('[data-testid="link-announcement-action-googlePlay"]').attributes('href')
    ).toBe('https://play.google.com/store/apps/details?id=com.synaplan.app&ct=web-announcement')
  })

  it('leads with Google Play for a visitor on an Android device', async () => {
    runtime.deviceOs = 'android'

    const wrapper = await mountModal()
    const actions = wrapper.findAll('a[data-testid^="link-announcement-action-"]')

    expect(actions.map((action) => action.attributes('data-testid'))).toEqual([
      'link-announcement-action-googlePlay',
      'link-announcement-action-appStore',
    ])
  })

  it('falls back to the brand mark when the announcement has no illustration', async () => {
    const wrapper = await mountModal()

    expect(wrapper.find('[data-testid="img-announcement"]').exists()).toBe(false)
    expect(wrapper.find('img[alt=""]').attributes('src')).toBe('/brand.svg')
  })

  it('stays away from an instance that has no app to offer', async () => {
    runtime.iosAppUrl = ''
    runtime.androidAppUrl = ''

    const wrapper = await mountModal()

    expect(wrapper.find(`[data-testid="${modal}"]`).exists()).toBe(false)
  })

  it('still greets a visitor when only the Android app is published', async () => {
    runtime.iosAppUrl = ''

    const wrapper = await mountModal()

    expect(wrapper.find(`[data-testid="${modal}"]`).exists()).toBe(true)
    expect(wrapper.find('[data-testid="link-announcement-action-appStore"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="link-announcement-action-googlePlay"]').exists()).toBe(true)
  })

  it('does not advertise the app inside the app', async () => {
    runtime.isNative = true

    const wrapper = await mountModal()

    expect(wrapper.find(`[data-testid="${modal}"]`).exists()).toBe(false)
  })

  it.each(['/login', '/register', '/onboarding', '/subscription', '/shared/abc'])(
    'keeps quiet on %s',
    async (path) => {
      runtime.path = path

      const wrapper = await mountModal()

      expect(wrapper.find(`[data-testid="${modal}"]`).exists()).toBe(false)
    }
  )

  it('never returns once closed', async () => {
    const wrapper = await mountModal()
    await wrapper.find('[data-testid="btn-announcement-close"]').trigger('click')

    expect(wrapper.find(`[data-testid="${modal}"]`).exists()).toBe(false)
    expect(JSON.parse(localStorage.getItem(SEEN_KEY) ?? '[]')).toContain('mobile-apps-launch')

    expect((await mountModal()).find(`[data-testid="${modal}"]`).exists()).toBe(false)
  })

  it('counts following a store link as having seen it', async () => {
    const wrapper = await mountModal()
    await wrapper.find('[data-testid="link-announcement-action-appStore"]').trigger('click')

    expect(JSON.parse(localStorage.getItem(SEEN_KEY) ?? '[]')).toContain('mobile-apps-launch')
  })

  it('closes when the backdrop is clicked', async () => {
    const wrapper = await mountModal()
    await wrapper.find('[data-testid="modal-announcement-backdrop"]').trigger('click')

    expect(wrapper.find(`[data-testid="${modal}"]`).exists()).toBe(false)
  })

  it('releases the page scroll it locked', async () => {
    const wrapper = await mountModal()
    expect(document.body.style.overflow).toBe('hidden')

    await wrapper.find('[data-testid="btn-announcement-close"]').trigger('click')
    expect(document.body.style.overflow).toBe('')
  })

  it('leaves a scroll lock alone when another overlay is already holding it', async () => {
    document.body.style.overflow = 'hidden'

    const wrapper = await mountModal()
    await wrapper.find('[data-testid="btn-announcement-close"]').trigger('click')

    // The dialog underneath is still open, so the page must stay put.
    expect(document.body.style.overflow).toBe('hidden')
  })

  it('ignores a corrupt dismissal list instead of breaking', async () => {
    localStorage.setItem(SEEN_KEY, 'not json')

    expect((await mountModal()).find(`[data-testid="${modal}"]`).exists()).toBe(true)
  })
})
