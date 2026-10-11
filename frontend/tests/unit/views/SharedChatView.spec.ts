import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import SharedChatView from '@/views/SharedChatView.vue'
import { SharedChatPayloadSchema } from '@/utils/sharedChatPayload'
import { httpClient } from '@/services/api/httpClient'

vi.mock('@/services/api/httpClient', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/services/api/httpClient')>()
  return {
    ...actual,
    httpClient: vi.fn(),
  }
})

const source = {
  n: 1,
  chunkId: 'chunk-1',
  fileId: 9,
  fileName: 'handbook.pdf',
  groupKey: 'docs',
  startLine: 4,
  endLine: 8,
}

const payload = {
  success: true,
  chat: { title: 'Shared', createdAt: '2026-10-01T00:00:00+00:00' },
  messages: [
    {
      id: 1,
      text: 'See the handbook.',
      direction: 'OUT',
      timestamp: 1_700_000_000,
      ragSources: [source],
    },
  ],
}

describe('SharedChatView knowledge sources', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.mocked(httpClient).mockResolvedValue(SharedChatPayloadSchema.parse(payload))
  })

  it('keeps ragSources through parse and renders the sources row', async () => {
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/shared/:lang/:token', component: SharedChatView },
        { path: '/register', component: { template: '<div />' } },
      ],
    })
    await router.push('/shared/en/public-token')
    await router.isReady()

    const wrapper = mount(SharedChatView, {
      global: {
        plugins: [router],
        stubs: {
          MessageText: { template: '<p>{{ content }}</p>', props: ['content'] },
          MessageImage: true,
          MessageVideo: true,
          MessageAudio: true,
          MessageCode: true,
          MessageJson: true,
          BrandAttribution: true,
        },
      },
    })
    await flushPromises()

    const data = SharedChatPayloadSchema.parse(payload)
    expect(data.messages?.[0]?.ragSources?.[0]?.chunkId).toBe('chunk-1')
    expect(wrapper.get('[data-testid="knowledge-sources"]').text()).toContain('handbook.pdf')
    expect(wrapper.find('a[href="/files"]').exists()).toBe(false)
  })
})
