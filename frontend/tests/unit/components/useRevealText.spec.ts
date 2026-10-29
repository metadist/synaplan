import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, nextTick, ref, type Ref } from 'vue'
import { mount } from '@vue/test-utils'
import { useRevealText } from '@/components/research/useRevealText'

let frames: FrameRequestCallback[] = []

function runFrames(count: number): void {
  for (let i = 0; i < count; i++) {
    const queued = frames
    frames = []
    for (const frame of queued) frame(0)
  }
}

function setup(text: string, live: boolean) {
  const source = ref(text)
  const isLive = ref(live)
  const holder: { shown: Ref<string> | null } = { shown: null }
  mount(
    defineComponent({
      setup() {
        const shown = useRevealText(
          () => source.value,
          () => isLive.value
        )
        holder.shown = shown
        return () => h('span', shown.value)
      },
    })
  )
  return { source, isLive, shown: () => holder.shown?.value ?? '' }
}

describe('useRevealText', () => {
  beforeEach(() => {
    frames = []
    vi.stubGlobal('requestAnimationFrame', (frame: FrameRequestCallback) => {
      frames.push(frame)
      return frames.length
    })
    vi.stubGlobal('cancelAnimationFrame', () => {
      frames = []
    })
  })
  afterEach(() => vi.unstubAllGlobals())

  it('lets the words that arrived run in and catches up with the text', async () => {
    const reveal = setup('Two shops agree on the price.', true)

    expect(reveal.shown()).toBe('')
    runFrames(5)
    expect(reveal.shown().length).toBeGreaterThan(0)
    expect('Two shops agree on the price.'.startsWith(reveal.shown())).toBe(true)
    runFrames(100)
    expect(reveal.shown()).toBe('Two shops agree on the price.')

    reveal.source.value = 'Two shops agree on the price. I read the maker next.'
    await nextTick()
    runFrames(100)
    expect(reveal.shown()).toBe('Two shops agree on the price. I read the maker next.')
  })

  it('shows the whole text at once when the row is no longer live', async () => {
    const reveal = setup('The fee is 30 EUR.', true)
    runFrames(2)

    reveal.isLive.value = false
    await nextTick()

    expect(reveal.shown()).toBe('The fee is 30 EUR.')
  })

  it('keeps the length already shown when the text changed instead of grew', async () => {
    const reveal = setup('Two shops\nagree.', true)
    runFrames(100)

    reveal.source.value = 'Two shops agree.'
    await nextTick()

    expect(reveal.shown()).toBe('Two shops agree.')
  })
})
