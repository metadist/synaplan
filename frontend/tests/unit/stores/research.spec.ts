import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useResearchStore } from '@/stores/research'
import type { ResearchRunView } from '@/services/api/researchApi'
import { useChatsStore } from '@/stores/chats'
import { useHistoryStore } from '@/stores/history'
import {
  answerNowResearch,
  fetchResearchEvents,
  fetchResearchRun,
  startResearch,
} from '@/services/api/researchApi'

vi.mock('@/services/api/researchApi', () => ({
  fetchResearchCapabilities: vi.fn(),
  startResearch: vi.fn(),
  fetchResearchRun: vi.fn(),
  fetchResearchEvents: vi.fn(async () => ''),
  answerNowResearch: vi.fn(),
  cancelResearch: vi.fn(),
}))

const CHAT_ID = 7

function run(
  status: ResearchRunView['status'],
  extra: Partial<ResearchRunView> = {}
): ResearchRunView {
  return {
    id: 'rr_0123456789abcdef',
    research_protocol: 1,
    status,
    depth: 'auto',
    ceiling: null,
    question: 'Which visa do I need?',
    parent: null,
    follow_up: null,
    can_add_to_report: false,
    stop_requested: false,
    write_requested: false,
    queued_since: null,
    questions: [],
    plan: null,
    sources: [],
    evidence: [],
    report: null,
    check: null,
    outcome: null,
    models: {},
    budget: {},
    cost: { estimate: 0, spent: 0, currency: 'USD' },
    created_at: '2026-10-28T12:00:00+00:00',
    updated_at: '2026-10-28T12:00:00+00:00',
    ...extra,
  } as ResearchRunView
}

describe('research store · a run the chat started on its own', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    localStorage.clear()
    vi.useFakeTimers()
    vi.mocked(fetchResearchEvents).mockResolvedValue('')
  })

  it('adopts the run for the chat it is bound to and remembers it for a reload', () => {
    const research = useResearchStore()
    research.attachChat(CHAT_ID)

    research.adopt(CHAT_ID, 'rr_0123456789abcdef', 'Which visa do I need?')

    expect(research.progress?.id).toBe('rr_0123456789abcdef')
    expect(research.progress?.question).toBe('Which visa do I need?')
    expect(research.progress?.status).toBe('briefing')
    expect(localStorage.getItem(`synaplan.researchRun.${CHAT_ID}`)).toBe('rr_0123456789abcdef')
  })

  it('remembers a run for another chat without showing it here', () => {
    const research = useResearchStore()
    research.attachChat(CHAT_ID)

    research.adopt(99, 'rr_0123456789abcdef', 'Which visa do I need?')

    expect(research.progress).toBeNull()
    expect(localStorage.getItem('synaplan.researchRun.99')).toBe('rr_0123456789abcdef')
  })

  it('answer now before any source was read ends the run as answered without research', async () => {
    const research = useResearchStore()
    research.attachChat(CHAT_ID)
    research.adopt(CHAT_ID, 'rr_0123456789abcdef', 'Which visa do I need?')
    vi.mocked(answerNowResearch).mockResolvedValue(
      run('complete', { outcome: { code: 'answered_without_research', params: {}, text: '' } })
    )

    await research.answerNow()

    expect(answerNowResearch).toHaveBeenCalledWith('rr_0123456789abcdef')
    expect(research.progress?.status).toBe('complete')
    expect(research.progress?.outcome).toBe('answered_without_research')
  })

  it('answer now while sources were read keeps following the run until the report is written', async () => {
    const research = useResearchStore()
    research.attachChat(CHAT_ID)
    research.adopt(CHAT_ID, 'rr_0123456789abcdef', 'Which visa do I need?')
    vi.mocked(answerNowResearch).mockResolvedValue(run('writing', { write_requested: true }))

    await research.answerNow()

    expect(research.progress?.status).toBe('writing')
    expect(research.startError).toBeNull()
  })

  it('look deeper starts an automatic run told the person wants more', async () => {
    const research = useResearchStore()
    useChatsStore().activeChatId = CHAT_ID
    research.attachChat(CHAT_ID)
    vi.mocked(startResearch).mockResolvedValue({
      id: 'rr_fedcba9876543210',
      status: 'briefing',
      research_protocol: 1,
      events: [],
    })

    await research.lookDeeper({
      question: 'Which visa do I need?',
      context: [{ role: 'user', text: 'Which visa do I need?' }],
      seedSources: [{ url: 'https://example.com/visa', title: 'Visa rules' }],
    })

    expect(startResearch).toHaveBeenCalledWith(
      expect.objectContaining({
        question: 'Which visa do I need?',
        depth: 'auto',
        hint: 'deeper',
        chat_id: CHAT_ID,
        seed_sources: [{ url: 'https://example.com/visa', title: 'Visa rules' }],
      })
    )
    expect(research.progress?.id).toBe('rr_fedcba9876543210')
    const reply = useHistoryStore().messages.at(-1)
    expect(reply?.role).toBe('assistant')
    expect(reply?.researchRunId).toBe('rr_fedcba9876543210')
  })

  it('follows a running run a reply carries after a reload, and leaves a finished one alone', async () => {
    const research = useResearchStore()
    research.attachChat(CHAT_ID)
    vi.mocked(fetchResearchRun).mockResolvedValueOnce(run('complete'))

    await research.follow(CHAT_ID, 'rr_0123456789abcdef')
    expect(research.progress).toBeNull()

    vi.mocked(fetchResearchRun).mockResolvedValueOnce(run('gathering'))
    await research.follow(CHAT_ID, 'rr_0123456789abcdef')
    expect(research.progress?.status).toBe('gathering')
    expect(localStorage.getItem(`synaplan.researchRun.${CHAT_ID}`)).toBe('rr_0123456789abcdef')
  })
})
