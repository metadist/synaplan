import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ModelMixControl from '@/components/chat/ModelMixControl.vue'

// The mix button sits left of the incognito toggle, not at the screen edge.
// Its dropdown hangs right-aligned under it, so on a 320 px phone it used to
// run past the left edge. It must stay on screen at every width.

vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string) => key, locale: { value: 'en' } }),
}))
vi.mock('@/services/api/nativeHaptics', () => ({ triggerHapticImpact: vi.fn() }))
vi.mock('@/services/api/configApi', () => ({
  configApi: {
    getModels: vi.fn().mockResolvedValue({ success: true, models: {}, providers: [] }),
    getDefaultModels: vi.fn().mockResolvedValue({ success: true, defaults: {} }),
  },
}))

const originalWidth = window.innerWidth

const openAt = async (viewportWidth: number, buttonRight: number) => {
  Object.defineProperty(window, 'innerWidth', { configurable: true, value: viewportWidth })
  vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockReturnValue({
    right: buttonRight,
  } as DOMRect)
  const wrapper = mount(ModelMixControl, {
    global: { stubs: { ModelMixPanel: true, ModelMixIcon: true, Transition: false } },
  })
  await wrapper.get('[data-testid="btn-model-mix"]').trigger('click')
  return wrapper.get('[data-testid="dropdown-model-mix"]').element as HTMLElement
}

describe('ModelMixControl dropdown placement', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  afterEach(() => {
    vi.restoreAllMocks()
    Object.defineProperty(window, 'innerWidth', { configurable: true, value: originalWidth })
  })

  it('slides right on a 320 px screen so both sides keep a 12 px gap', async () => {
    const panel = await openAt(320, 260)

    // 296 px wide panel: right edge 260 + 48 = 308, left edge 308 - 296 = 12.
    expect(panel.style.right).toBe('-48px')
  })

  it('stays right-aligned under the button when it fits', async () => {
    const panel = await openAt(1280, 1220)

    expect(panel.style.right).toBe('')
  })

  it('moves only as far as needed', async () => {
    const panel = await openAt(390, 330)

    // 320 px panel needs 12 + 320 - 330 = 2 px; there is room for 48.
    expect(panel.style.right).toBe('-2px')
  })
})
