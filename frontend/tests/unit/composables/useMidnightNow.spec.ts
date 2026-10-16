import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { mount } from '@vue/test-utils'
import { useMidnightNow } from '@/composables/useMidnightNow'

const Harness = defineComponent({
  setup() {
    const now = useMidnightNow()
    return () => h('span', { 'data-testid': 'now' }, now.value.toISOString())
  },
})

describe('useMidnightNow', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date(2026, 9, 5, 23, 59, 0))
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('refreshes when the clock passes local midnight', async () => {
    const wrapper = mount(Harness)
    expect(wrapper.get('[data-testid="now"]').text()).toContain('2026-10-05')

    await vi.advanceTimersByTimeAsync(61 * 1000)

    expect(wrapper.get('[data-testid="now"]').text()).toContain('2026-10-06')
    wrapper.unmount()
  })

  it('clears its timer on unmount', () => {
    const clearSpy = vi.spyOn(globalThis, 'clearTimeout')
    const wrapper = mount(Harness)
    wrapper.unmount()

    expect(clearSpy).toHaveBeenCalled()
  })
})
