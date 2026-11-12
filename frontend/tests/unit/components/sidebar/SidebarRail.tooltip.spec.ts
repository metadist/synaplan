import { defineComponent, onBeforeUnmount, ref } from 'vue'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'

import SidebarRail from '@/components/sidebar/SidebarRail.vue'
import {
  RAIL_TIP_DELAY_MS,
  resetRailTooltipState,
  useRailTooltip,
} from '@/composables/useRailTooltip'

const CHATS = '[data-testid="btn-sidebar-v2-nav-chat"]'
const ASSISTANTS = '[data-testid="btn-sidebar-v2-nav-assistants"]'
const LIBRARY = '[data-testid="btn-sidebar-v2-nav-files"]'

vi.mock('@/components/SchedulerStaleHint.vue', () => ({
  default: { name: 'SchedulerStaleHint', template: '<div />' },
}))

vi.mock('@/stores/config', () => ({
  useConfigStore: () => ({
    branding: { name: 'Synaplan', logoUrl: '', logoDarkUrl: '', iconUrl: '' },
    build: { version: '' },
  }),
}))

vi.mock('@/stores/scheduler', () => ({
  useSchedulerStore: () => ({
    isStale: false,
    canRead: false,
    ensureLoaded: vi.fn(),
    load: vi.fn(),
  }),
}))

vi.mock('@/stores/updates', () => ({
  useUpdatesStore: () => ({
    showBadge: false,
    guideUrl: null,
    severity: null,
    latestVersion: null,
    canRead: false,
    ensureLoaded: vi.fn(),
  }),
}))

vi.mock('@/stores/incoming', () => ({
  useIncomingStore: () => ({
    hasNew: false,
    unseenCount: 0,
  }),
}))

vi.mock('@/composables/useNavSections', () => ({
  useNavSections: () => ({
    sections: ref([
      {
        key: 'chats',
        label: 'Chats',
        description: 'Chat history',
        icon: { template: '<i />' },
        testId: 'btn-sidebar-v2-nav-chat',
        requiresAuth: false,
      },
      {
        key: 'library',
        label: 'Library',
        description: 'Files the AI can use in your chats',
        icon: { template: '<i />' },
        testId: 'btn-sidebar-v2-nav-files',
        requiresAuth: true,
      },
      {
        key: 'assistants',
        label: 'Agents',
        description: 'Agents, shortcuts, tasks and AI settings',
        icon: { template: '<i />' },
        testId: 'btn-sidebar-v2-nav-assistants',
        requiresAuth: true,
      },
    ]),
    activeKey: ref('chats'),
    selectSection: vi.fn(),
    loadFeatureStatus: vi.fn(),
  }),
}))

/**
 * Registers its leave handler after the composable, the same order Vue uses
 * when the browser fires pointerleave while the clicked rail is removed.
 */
const LeaveOnUnmount = defineComponent({
  setup() {
    const api = useRailTooltip()
    onBeforeUnmount(() => {
      api.hideTipSoon()
    })
    return { suppressHover: api.suppressHover }
  },
  template: '<div />',
})

const hoverMedia = (query: string) =>
  ({
    matches: query.includes('hover: hover') || query.includes('min-width: 1024px'),
    media: query,
    onchange: null,
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
    addListener: vi.fn(),
    removeListener: vi.fn(),
    dispatchEvent: () => false,
  }) as unknown as MediaQueryList

const tooltip = (): HTMLElement | null =>
  document.querySelector<HTMLElement>('[data-testid="tooltip-sidebar-rail"]')

async function mountRail() {
  const pinia = createPinia()
  setActivePinia(pinia)
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/', component: { template: '<div />' } }],
  })
  await router.push('/')
  await router.isReady()
  const wrapper = mount(SidebarRail, {
    attachTo: document.body,
    global: { plugins: [pinia, router] },
  })
  await flushPromises()
  return wrapper
}

describe('SidebarRail tooltip', () => {
  beforeEach(() => {
    document.body.innerHTML = '<div id="app"></div>'
    localStorage.clear()
    resetRailTooltipState()
    vi.useRealTimers()
    vi.spyOn(window, 'matchMedia').mockImplementation(hoverMedia)
  })

  it('shows the tooltip after the hover delay', async () => {
    const wrapper = await mountRail()
    vi.useFakeTimers()
    await wrapper.get(ASSISTANTS).trigger('pointerenter')
    await vi.advanceTimersByTimeAsync(RAIL_TIP_DELAY_MS - 1)
    expect(tooltip()).toBeNull()
    await vi.advanceTimersByTimeAsync(1)
    expect(tooltip()?.textContent).toContain('Agents')
    wrapper.unmount()
  })

  it('stays closed when a new rail receives pointerenter for the clicked section', async () => {
    const first = await mountRail()
    await first.get(ASSISTANTS).trigger('click')
    await flushPromises()
    expect(tooltip()).toBeNull()
    first.unmount()

    const second = await mountRail()
    vi.useFakeTimers()
    await second.get(ASSISTANTS).trigger('pointerenter')
    await vi.advanceTimersByTimeAsync(RAIL_TIP_DELAY_MS)
    expect(tooltip()).toBeNull()
    second.unmount()
  })

  it('keeps the click suppression when the panel shift leaves the pointer inside the rail', async () => {
    const first = await mountRail()
    await first.get(ASSISTANTS).trigger('click')
    const rail = first.get('[data-testid="section-sidebar-rail"]').element
    first
      .get(ASSISTANTS)
      .element.dispatchEvent(new PointerEvent('pointerleave', { relatedTarget: rail }))
    first.unmount()

    const second = await mountRail()
    vi.useFakeTimers()
    await second.get(ASSISTANTS).trigger('pointerenter')
    await vi.advanceTimersByTimeAsync(RAIL_TIP_DELAY_MS)
    expect(tooltip()).toBeNull()
    second.unmount()
  })

  it('shows again after the pointer leaves the clicked icon and comes back', async () => {
    const first = await mountRail()
    await first.get(ASSISTANTS).trigger('click')
    await flushPromises()
    first.unmount()

    const second = await mountRail()
    vi.useFakeTimers()
    await second.get(ASSISTANTS).trigger('pointerleave')
    await second.get(ASSISTANTS).trigger('pointerenter')
    await vi.advanceTimersByTimeAsync(RAIL_TIP_DELAY_MS)
    expect(tooltip()?.textContent).toContain('Agents')
    second.unmount()
  })

  it('shows a different icon immediately after the clicked rail is replaced', async () => {
    const first = await mountRail()
    await first.get(ASSISTANTS).trigger('click')
    await flushPromises()
    first.unmount()

    const second = await mountRail()
    vi.useFakeTimers()
    await second.get(LIBRARY).trigger('pointerenter')
    await vi.advanceTimersByTimeAsync(RAIL_TIP_DELAY_MS)
    expect(tooltip()?.textContent).toContain('Library')
    second.unmount()
  })

  it('still shows the tooltip for a keyboard focus after the click', async () => {
    const first = await mountRail()
    await first.get(ASSISTANTS).trigger('click')
    await flushPromises()
    first.unmount()

    const matches = HTMLElement.prototype.matches
    vi.spyOn(HTMLElement.prototype, 'matches').mockImplementation(function (
      this: HTMLElement,
      selector: string
    ) {
      if (selector === ':focus-visible') return true
      return matches.call(this, selector)
    })

    const second = await mountRail()
    await second.get(ASSISTANTS).trigger('focus')
    expect(tooltip()?.textContent).toContain('Agents')
    second.unmount()
  })

  it('keeps the click suppression when pointerleave fires while the rail unmounts', async () => {
    const leaving = mount(LeaveOnUnmount)
    leaving.vm.suppressHover('assistants')
    leaving.unmount()

    const rail = await mountRail()
    vi.useFakeTimers()
    await rail.get(ASSISTANTS).trigger('pointerenter')
    await vi.advanceTimersByTimeAsync(RAIL_TIP_DELAY_MS)
    expect(tooltip()).toBeNull()
    rail.unmount()
  })

  it('suppresses a neighbor icon shifted under the pointer when a folded panel opens', async () => {
    localStorage.setItem('sidebar-panel-collapsed', 'true')
    const first = await mountRail()

    await first.get(LIBRARY).trigger('click')
    const agents = first.get(ASSISTANTS).element
    first
      .get(LIBRARY)
      .element.dispatchEvent(new PointerEvent('pointerleave', { relatedTarget: agents }))
    await first.get(ASSISTANTS).trigger('pointerenter')
    first.unmount()

    const second = await mountRail()
    vi.useFakeTimers()
    await second.get(ASSISTANTS).trigger('pointerenter')
    await vi.advanceTimersByTimeAsync(RAIL_TIP_DELAY_MS)
    expect(tooltip()).toBeNull()

    await second.get(ASSISTANTS).trigger('pointerleave')
    await second.get(ASSISTANTS).trigger('pointerenter')
    await vi.advanceTimersByTimeAsync(RAIL_TIP_DELAY_MS)
    expect(tooltip()?.textContent).toContain('Agents')
    second.unmount()
  })

  it('allows normal hover again once the shift guard expires without a remount', async () => {
    vi.useFakeTimers()
    localStorage.setItem('sidebar-panel-collapsed', 'true')
    const rail = await mountRail()

    await rail.get(CHATS).trigger('click')
    await rail.get(CHATS).trigger('pointerleave')
    await rail.get(LIBRARY).trigger('pointerenter')
    await vi.advanceTimersByTimeAsync(RAIL_TIP_DELAY_MS)
    expect(tooltip()).toBeNull()

    await rail.get(LIBRARY).trigger('pointerleave')
    await rail.get(LIBRARY).trigger('pointerenter')
    await vi.advanceTimersByTimeAsync(RAIL_TIP_DELAY_MS)
    expect(tooltip()?.textContent).toContain('Library')
    rail.unmount()
  })
})
