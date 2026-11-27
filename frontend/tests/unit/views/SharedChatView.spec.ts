import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import SharedChatView from '@/views/SharedChatView.vue'
import { GetSharedChatResponseSchema } from '@/generated/api-schemas'
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
      direction: 'OUT' as const,
      timestamp: 1_700_000_000,
      ragSources: [source],
    },
  ],
}

const passage = {
  success: true,
  chunkId: 'chunk-1',
  fileId: 9,
  fileName: 'handbook.pdf',
  groupKey: 'docs',
  text: 'The cited passage',
  startLine: 4,
  endLine: 8,
}

describe('SharedChatView generated media', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  it('plays a generated video once, from the public uploads address', async () => {
    const video = {
      success: true,
      chat: { title: 'Luxor', createdAt: '2026-11-26T20:10:35+00:00' },
      messages: [
        {
          id: 2,
          text: '__VIDEO_GENERATED__',
          direction: 'OUT' as const,
          timestamp: 1_700_000_000,
          file: { path: '02/000/clip.mp4', type: 'video' },
          files: [
            {
              id: 9,
              filename: 'clip.mp4',
              fileType: 'video',
              filePath: '02/000/clip.mp4',
              fileSize: 100,
              fileMime: 'video/mp4',
            },
            {
              id: 10,
              filename: 'notes.pdf',
              fileType: 'pdf',
              filePath: '02/000/notes.pdf',
              fileSize: 20,
              fileMime: 'application/pdf',
            },
          ],
        },
      ],
    }
    vi.mocked(httpClient).mockResolvedValue(GetSharedChatResponseSchema.parse(video))

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
          MessageImage: { props: ['url'], template: '<img :src="url" />' },
          MessageVideo: { props: ['url'], template: '<video :src="url" />' },
          MessageAudio: { props: ['url'], template: '<audio :src="url" />' },
          MessageCode: true,
          MessageJson: true,
          BrandAttribution: true,
        },
      },
    })
    await flushPromises()

    const players = wrapper.findAll('video')
    expect(players).toHaveLength(1)
    expect(players[0]?.attributes('src')).toBe('/api/v1/files/uploads/02/000/clip.mp4')
    expect(wrapper.find('img').exists()).toBe(false)
    expect(wrapper.get('[data-testid="link-shared-attachment"]').attributes('href')).toBe(
      '/api/v1/files/uploads/02/000/notes.pdf'
    )
  })

  it('plays a webm recording as video when the attachment says video/webm', async () => {
    const recording = {
      success: true,
      chat: { title: 'Recording', createdAt: '2026-11-26T20:10:35+00:00' },
      messages: [
        {
          id: 3,
          text: '',
          direction: 'OUT' as const,
          timestamp: 1_700_000_000,
          file: { path: '02/000/screen.webm', type: 'webm' },
          files: [
            {
              id: 11,
              filename: 'screen.webm',
              fileType: 'webm',
              filePath: '02/000/screen.webm',
              fileSize: 100,
              fileMime: 'video/webm',
            },
          ],
        },
        {
          id: 4,
          text: '',
          direction: 'IN' as const,
          timestamp: 1_700_000_001,
          file: { path: '02/000/note.webm', type: 'webm' },
          files: [
            {
              id: 12,
              filename: 'note.webm',
              fileType: 'webm',
              filePath: '02/000/note.webm',
              fileSize: 40,
              fileMime: 'audio/webm',
            },
          ],
        },
      ],
    }
    vi.mocked(httpClient).mockResolvedValue(GetSharedChatResponseSchema.parse(recording))

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
          MessageImage: { props: ['url'], template: '<img :src="url" />' },
          MessageVideo: { props: ['url'], template: '<video :src="url" />' },
          MessageAudio: { props: ['url'], template: '<audio :src="url" />' },
          MessageCode: true,
          MessageJson: true,
          BrandAttribution: true,
        },
      },
    })
    await flushPromises()

    const videos = wrapper.findAll('video')
    const audios = wrapper.findAll('audio')
    expect(videos).toHaveLength(1)
    expect(videos[0]?.attributes('src')).toBe('/api/v1/files/uploads/02/000/screen.webm')
    expect(audios).toHaveLength(1)
    expect(audios[0]?.attributes('src')).toBe('/api/v1/files/uploads/02/000/note.webm')
  })
})

describe('SharedChatView knowledge sources', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.mocked(httpClient).mockImplementation(async (endpoint: string) => {
      if (endpoint.includes('/rag/chunks/')) return passage
      return GetSharedChatResponseSchema.parse(payload)
    })
  })

  it('keeps ragSources and opens then hides the cited passage', async () => {
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

    const listed = GetSharedChatResponseSchema.parse(payload)
    expect(listed.messages[0]?.ragSources?.[0]?.chunkId).toBe('chunk-1')
    expect(wrapper.get('[data-testid="knowledge-sources"]').text()).toContain('handbook.pdf')
    expect(wrapper.find('a[href="/files"]').exists()).toBe(false)

    await wrapper.get('[data-testid="knowledge-source-open"]').trigger('click')
    await flushPromises()

    const passageCall = vi
      .mocked(httpClient)
      .mock.calls.find((call) => String(call[0]).includes('/rag/chunks/'))
    expect(passageCall?.[0]).toBe('/api/v1/chats/shared/public-token/rag/chunks/chunk-1')
    expect(wrapper.get('[data-testid="knowledge-source-passage"]').text()).toContain(
      'The cited passage'
    )

    await wrapper.get('[data-testid="knowledge-source-open"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-testid="knowledge-source-passage"]').exists()).toBe(false)
  })

  it.each(['capacitor://localhost', 'https://localhost'])(
    'points canonical and og:url at the configured service from %s',
    async (origin) => {
      vi.stubGlobal('location', {
        ...window.location,
        origin,
        href: `${origin}/shared/en/public-token`,
      })
      vi.stubGlobal('__SYNAPLAN_API_BASE_URL__', 'https://chat.example.test')

      const router = createRouter({
        history: createMemoryHistory(),
        routes: [
          { path: '/shared/:lang/:token', component: SharedChatView },
          { path: '/register', component: { template: '<div />' } },
        ],
      })
      await router.push('/shared/en/public-token')
      await router.isReady()

      mount(SharedChatView, {
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

      const expected = 'https://chat.example.test/shared/en/public-token'
      expect(document.querySelector('link[rel="canonical"]')?.getAttribute('href')).toBe(expected)
      expect(document.querySelector('meta[property="og:url"]')?.getAttribute('content')).toBe(
        expected
      )

      vi.unstubAllGlobals()
    }
  )
})
