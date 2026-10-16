import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, shallowMount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import ChatView from '@/views/ChatView.vue'
import { useAuthStore } from '@/stores/auth'
import { useChatsStore } from '@/stores/chats'
import { useGuestStore } from '@/stores/guest'
import { useHistoryStore } from '@/stores/history'
import { useFeedbackStore } from '@/stores/userFeedback'
import { useMemoriesStore } from '@/stores/userMemories'
import type { StreamUpdatePayload } from '@/types/chatStream'

/**
 * Characterization of the chat view's stream handling: recorded payload
 * sequences are replayed through the real `ChatView` (signed-in, guest,
 * re-attach and continue transports) and the resulting message state is
 * snapshotted. A refactor of the stream handling must leave every snapshot
 * unchanged.
 */

const stream = vi.hoisted(() => ({
  onUpdate: null as ((data: unknown) => void) | null,
  calls: [] as string[],
}))

vi.mock('@/services/api/httpClient', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/services/api/httpClient')>()
  return { ...actual, httpClient: vi.fn(async () => ({})) }
})

vi.mock('@/services/api/chatApi', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/services/api/chatApi')>()
  const capture = (transport: string) =>
    vi.fn((opts: { onUpdate: (data: unknown) => void }) => {
      stream.calls.push(transport)
      stream.onUpdate = opts.onUpdate
      return () => stream.calls.push(`${transport}:closed`)
    })
  return {
    ...actual,
    chatApi: {
      ...actual.chatApi,
      streamMessage: capture('member'),
      streamGuestMessage: capture('guest'),
      attachStream: capture('attach'),
      stopStream: vi.fn(async () => stream.calls.push('stop-stream')),
      stopGuestStream: vi.fn(async () => stream.calls.push('stop-guest-stream')),
    },
  }
})

const VOLATILE_KEYS = new Set(['id', 'timestamp', 'thinkingStartedAt', 'partId', 'trackId'])

type Event = StreamUpdatePayload

const MEMBER_ID = 7
const CHAT_ID = 42

let wrapper: VueWrapper | null = null

async function mountChat(mode: 'member' | 'guest', before?: () => void): Promise<VueWrapper> {
  if (mode === 'member') {
    useAuthStore().user = {
      id: MEMBER_ID,
      email: 'ada@example.test',
      level: 'PRO',
      emailVerified: true,
      isAdmin: false,
    } as ReturnType<typeof useAuthStore>['user']
    useChatsStore().activeChatId = CHAT_ID
    useChatsStore().conversationAccess = 'owner'
    vi.spyOn(useHistoryStore(), 'loadMessages').mockResolvedValue(undefined)
  } else {
    const guest = useGuestStore()
    vi.spyOn(guest, 'initSession').mockImplementation(async () => {
      guest.sessionId = 'guest-session'
    })
    vi.spyOn(guest, 'ensureChat').mockResolvedValue(99)
  }
  before?.()

  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/', component: ChatView }],
  })
  await router.push('/')
  await router.isReady()
  wrapper = shallowMount(ChatView, {
    global: { plugins: [router], stubs: { MainLayout: { template: '<div><slot /></div>' } } },
  })
  await flushPromises()
  // The blanket httpClient double bypasses the response schemas that
  // guarantee these lists in production.
  useMemoriesStore().memories = []
  useFeedbackStore().feedbacks = []

  return wrapper
}

async function send(text: string): Promise<void> {
  wrapper!.findComponent({ name: 'ChatInput' }).vm.$emit('send', text)
  await flushPromises()
}

async function replay(events: Event[]): Promise<void> {
  for (const event of events) {
    stream.onUpdate!(event)
  }
  await flushPromises()
}

function normalized(value: unknown): unknown {
  if (Array.isArray(value)) {
    return value.map(normalized)
  }
  if (value instanceof Date) {
    return '<date>'
  }
  if (value !== null && typeof value === 'object') {
    return Object.fromEntries(
      Object.entries(value)
        .filter(([key, entry]) => !VOLATILE_KEYS.has(key) && entry !== undefined)
        .map(([key, entry]) => [
          key,
          key.endsWith('At') && typeof entry === 'number' ? '<time>' : normalized(entry),
        ])
    )
  }
  return value
}

function transcript(): unknown {
  return normalized({
    transports: stream.calls,
    messages: useHistoryStore().messages,
    memories: useMemoriesStore().memories,
  })
}

const classified: Event[] = [
  { status: 'started' },
  { status: 'classifying', metadata: { provider: 'openai', model_name: 'Sorter' } },
  { status: 'classified', metadata: { language: 'en', topic: 'general' } },
  { status: 'generating', metadata: { provider: 'openai', model_name: 'GPT Answer' } },
]

const answerComplete: Event = {
  status: 'complete',
  messageId: 501,
  provider: 'openai',
  model: 'GPT Answer',
  model_id: 5,
  topic: 'general',
}

const plan: Event = {
  status: 'plan',
  metadata: {
    plan: [
      { node_id: 'n1', capability: 'summarize', kind: 'text' },
      { node_id: 'n2', capability: 'translate', kind: 'text' },
    ],
    reply_node: 'n3',
  },
}

describe('ChatView stream transcripts', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    stream.onUpdate = null
    stream.calls = []
  })

  afterEach(() => {
    wrapper?.unmount()
    wrapper = null
    vi.restoreAllMocks()
  })

  it('a plain answer', async () => {
    await mountChat('member')
    await send('Hello there')
    await replay([
      ...classified,
      { status: 'data', chunk: 'Hello' },
      { status: 'data', chunk: ' world.' },
      answerComplete,
    ])

    expect(transcript()).toMatchSnapshot()
  })

  it('an answer with reasoning', async () => {
    await mountChat('member')
    await send('Think first')
    await replay([
      { status: 'started' },
      { status: 'thinking', message: 'Thinking' },
      { status: 'reasoning', chunk: 'Weighing ' },
      { status: 'reasoning', chunk: 'options.' },
      { status: 'data', chunk: 'Option B.' },
      answerComplete,
    ])

    expect(transcript()).toMatchSnapshot()
  })

  it('a web search with links', async () => {
    const result = {
      query: 'mariadb vector',
      title: 'MariaDB Vector',
      url: 'https://mariadb.org/vector',
    }
    await mountChat('member')
    await send('Search the web')
    await replay([
      { status: 'searching', message: 'Searching the web' },
      { status: 'search_complete', metadata: { query: 'mariadb vector', results: [result] } },
      { status: 'data', chunk: 'MariaDB stores vectors.' },
      {
        status: 'links',
        links: [
          { title: 'MariaDB Vector', url: 'https://mariadb.org/vector' },
          { url: 'not a url' },
        ],
      },
      { ...answerComplete, searchResults: [result] },
    ])

    expect(transcript()).toMatchSnapshot()
  })

  it('a file analysis', async () => {
    await mountChat('member')
    await send('What does the PDF say?')
    await replay([
      { status: 'started' },
      { status: 'analyzing', message: 'Reading the file' },
      { status: 'data', chunk: 'The PDF lists three risks.' },
      { ...answerComplete, topic: 'analyzefile', ragSources: [] },
    ])

    expect(transcript()).toMatchSnapshot()
  })

  it('a generated image', async () => {
    await mountChat('member')
    await send('Draw a lighthouse')
    await replay([
      { status: 'generating', metadata: { provider: 'google', model_name: 'Imagen' } },
      { status: 'file', url: '/api/v1/files/uploads/lighthouse.png', type: 'image' },
      { status: 'data', chunk: 'Generated image:' },
      { ...answerComplete, topic: 'mediamaker' },
    ])

    expect(transcript()).toMatchSnapshot()
  })

  it('a generated file', async () => {
    await mountChat('member')
    await send('Write a report')
    await replay([
      { status: 'generating_file', metadata: { stage: 'writing' } },
      { status: 'data', chunk: '__FILE_GENERATED__' },
      {
        ...answerComplete,
        topic: 'officemaker',
        generatedFile: {
          id: 77,
          filename: 'report.docx',
          path: 'u/7/report.docx',
          size: 2048,
          type: 'docx',
          mime: 'application/msword',
        },
      },
    ])

    expect(transcript()).toMatchSnapshot()
  })

  it('a multi-step task plan', async () => {
    await mountChat('member')
    await send('Summarize and translate')
    await replay([
      plan,
      { status: 'task_update', metadata: { node_id: 'n1', state: 'running' } },
      { status: 'task_chunk', metadata: { node_id: 'n1', chunk: 'Short ' } },
      {
        status: 'task_progress',
        metadata: { node_id: 'n2', percent: 40, provider_status: 'queued', elapsed_seconds: 3 },
      },
      {
        status: 'task_update',
        metadata: { node_id: 'n1', state: 'done', text: 'Short summary', duration_ms: 1200 },
      },
      {
        status: 'task_update',
        metadata: { node_id: 'n2', state: 'done', text: 'Kurze Zusammenfassung' },
      },
      { status: 'data', chunk: 'Here is the summary in German.' },
      answerComplete,
    ])

    expect(transcript()).toMatchSnapshot()
  })

  it('a discarded plan', async () => {
    await mountChat('member')
    await send('Summarize and translate')
    await replay([
      plan,
      { status: 'task_update', metadata: { node_id: 'n1', state: 'failed', error: 'Step failed' } },
      { status: 'plan_discarded' },
      { status: 'data', chunk: 'A single answer instead.' },
      answerComplete,
    ])

    expect(transcript()).toMatchSnapshot()
  })

  it('a provider error', async () => {
    await mountChat('member')
    await send('Hello')
    await replay([
      ...classified,
      {
        status: 'error',
        error: 'The provider did not answer.',
        errorReason: 'provider_error',
        canRetryModel: true,
        messageId: 509,
        topic: 'general',
        provider: 'openai',
        model: 'GPT Answer',
      },
    ])

    expect(transcript()).toMatchSnapshot()
  })

  it('a guest answer with the remaining count', async () => {
    await mountChat('guest')
    await send('Hello as a guest')
    await replay([
      { status: 'guest_remaining', remaining: 3, maxMessages: 5, limitReached: false },
      {
        status: 'memories_loaded',
        metadata: { memories: [{ id: 31, category: 'travel', key: 'city', value: 'Porto' }] },
      },
      ...classified,
      { status: 'data', chunk: 'Welcome, guest.' },
      { ...answerComplete, messageId: 601 },
    ])

    expect({
      transcript: transcript(),
      guest: { remaining: useGuestStore().remainingMessages },
    }).toMatchSnapshot()
  })

  it('a guest at the limit', async () => {
    await mountChat('guest')
    await send('One more question')
    await replay([{ status: 'guest_limit_reached' }])

    expect(transcript()).toMatchSnapshot()
  })

  it('memories loaded and suggested', async () => {
    await mountChat('member')
    await send('Plan my trip')
    await replay([
      {
        status: 'memories_loaded',
        metadata: { memories: [{ id: 11, category: 'travel', key: 'city', value: 'Lisbon' }] },
      },
      {
        status: 'feedback_loaded',
        metadata: { feedbacks: [{ id: 21, type: 'positive', value: 'Short answers' }] },
      },
      { status: 'data', chunk: 'Lisbon again [Memory:11].' },
      {
        status: 'memory_suggested',
        metadata: { id: 12, category: 'travel', key: 'season', value: 'spring' },
      },
      { ...answerComplete, memoryIds: [11], feedbackIds: [21] },
    ])

    expect(transcript()).toMatchSnapshot()
  })

  it('Stop in the middle of an answer', async () => {
    await mountChat('member')
    await send('Tell me a long story')
    await replay([...classified, { status: 'data', chunk: 'Once upon a time' }])
    wrapper!.findComponent({ name: 'ChatInput' }).vm.$emit('stop')
    await replay([{ status: 'data', chunk: ' — never shown.' }, answerComplete])

    expect(transcript()).toMatchSnapshot()
  })

  it('re-attaching to a turn after a reload', async () => {
    await mountChat('member', () => {
      const history = useHistoryStore()
      vi.spyOn(history, 'loadMessages').mockImplementation(async () => {
        history.activeRun = {
          runId: 'run-1',
          partialText: 'Already written',
          trackId: '123',
          lastSeq: 2,
        }
      })
    })
    await replay([
      { status: 'run_started', runId: 'run-1' },
      { status: 'data', chunk: 'Already' },
      { status: 'data', chunk: ' written and finished.' },
      answerComplete,
    ])

    expect(transcript()).toMatchSnapshot()
  })

  it('continuing a truncated answer', async () => {
    await mountChat('member')
    await send('Write a long list')
    await replay([
      ...classified,
      { status: 'data', chunk: '1. First' },
      { ...answerComplete, truncated: true },
    ])
    const assistant = wrapper!
      .findAllComponents({ name: 'ChatMessage' })
      .find((message) => message.props('role') === 'assistant')
    assistant!.vm.$emit('continue')
    await flushPromises()
    await replay([
      { status: 'data', chunk: '\n2. Second' },
      { status: 'complete', messageId: 501 },
    ])

    expect(transcript()).toMatchSnapshot()
  })
})
