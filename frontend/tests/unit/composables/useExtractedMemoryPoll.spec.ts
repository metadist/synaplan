import { defineComponent } from 'vue'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import {
  useExtractedMemoryPoll,
  type ExtractedMemoryPoll,
} from '@/composables/useExtractedMemoryPoll'
import { useMemoriesStore } from '@/stores/userMemories'
import type { UserMemory } from '@/services/api/userMemoriesApi'

const getExtractedMemories = vi.fn()

vi.mock('@/services/api', () => ({
  chatApi: { getExtractedMemories: (messageId: number) => getExtractedMemories(messageId) },
}))

const memory = (id: number): UserMemory => ({
  id,
  category: 'travel',
  key: 'city',
  value: 'Porto',
  source: 'auto_detected',
  messageId: 501,
  created: 1,
  updated: 1,
})

function mountPoll() {
  let poll!: ExtractedMemoryPoll
  const wrapper = mount(
    defineComponent({
      setup() {
        poll = useExtractedMemoryPoll()
        return () => null
      },
    }),
    {
      global: {
        plugins: [
          createI18n({
            legacy: false,
            locale: 'en',
            messages: {
              en: {
                processing: {
                  memoriesCompleteTitle: 'Memories saved',
                  analyzingMemoriesTitle: 'Reviewing memories',
                },
              },
            },
          }),
        ],
      },
    }
  )
  return { wrapper, poll }
}

async function after(ms: number): Promise<void> {
  vi.advanceTimersByTime(ms)
  await flushPromises()
}

describe('useExtractedMemoryPoll', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.useFakeTimers()
    getExtractedMemories.mockReset()
  })

  afterEach(() => vi.useRealTimers())

  it('asks again while the extraction is pending, then adds what was saved once', async () => {
    getExtractedMemories
      .mockResolvedValueOnce({ status: 'pending', saved: [], delete_suggestions: [] })
      .mockResolvedValueOnce({
        status: 'complete',
        saved: [memory(1), memory(1)],
        delete_suggestions: [],
      })
    const { poll } = mountPoll()

    poll.scheduleMemoryPoll(501)
    await after(2_000)
    await after(4_000)

    expect(getExtractedMemories).toHaveBeenCalledTimes(2)
    expect(useMemoriesStore().memories.map((saved) => saved.id)).toEqual([1])
    expect(poll.memoryToastMessage.value).toBe('Memories saved')
    expect(poll.memoryToastVisible.value).toBe(true)

    await after(3_000)
    expect(poll.memoryToastVisible.value).toBe(false)
  })

  it('names a review when the extraction only suggests deletions', async () => {
    getExtractedMemories.mockResolvedValueOnce({
      status: 'complete',
      saved: [],
      delete_suggestions: [memory(2)],
    })
    const { poll } = mountPoll()

    poll.scheduleMemoryPoll(501)
    await after(2_000)

    expect(poll.memoryToastMessage.value).toBe('Reviewing memories')
    expect(useMemoriesStore().memories).toEqual([])
  })

  it('retries a failed request and gives up after the last attempt', async () => {
    vi.spyOn(console, 'warn').mockImplementation(() => {})
    getExtractedMemories.mockRejectedValue(new Error('offline'))
    const { poll } = mountPoll()

    poll.scheduleMemoryPoll(501)
    for (const delay of [2_000, 4_000, 7_000, 12_000, 20_000, 60_000]) await after(delay)

    expect(getExtractedMemories).toHaveBeenCalledTimes(5)
  })

  it('stops polling with the component', async () => {
    getExtractedMemories.mockResolvedValue({ status: 'pending', saved: [], delete_suggestions: [] })
    const { wrapper, poll } = mountPoll()

    poll.scheduleMemoryPoll(501)
    wrapper.unmount()
    await after(60_000)

    expect(getExtractedMemories).not.toHaveBeenCalled()
  })
})
