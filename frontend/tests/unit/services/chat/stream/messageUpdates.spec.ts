import { describe, expect, it } from 'vitest'
import {
  applyAnswerSources,
  applyAssistantChatModelFooter,
  applyGeneratedFile,
  applyMediaJobToMessage,
  generatingTokenForMediaJob,
} from '@/services/chat/stream/messageUpdates'
import { assistantMessage } from './fakeTurn'

describe('message updates', () => {
  it('names the model from the server, ignoring channel and error tokens', () => {
    const named = assistantMessage()
    const channel = assistantMessage({ provider: 'openai', modelLabel: 'Earlier' })

    applyAssistantChatModelFooter(named, { provider: 'groq', model: 'Llama', model_id: 3 }, {})
    applyAssistantChatModelFooter(
      channel,
      { provider: 'WHATSAPP', model: 'error' },
      { provider: 'anthropic', model: 'Claude', model_id: 9 }
    )

    expect(named).toMatchObject({
      provider: 'groq',
      modelLabel: 'Llama',
      aiModels: { chat: { provider: 'groq', model: 'Llama', model_id: 3 } },
    })
    expect(channel).toMatchObject({
      provider: 'anthropic',
      modelLabel: 'Claude',
      aiModels: { chat: { model_id: 9 } },
    })
  })

  it('passes the audio model through for the live voice badge', () => {
    const message = assistantMessage()
    const audio = { provider: 'openai', model: 'tts-1', model_id: 4 }

    applyAssistantChatModelFooter(
      message,
      { provider: 'openai', model: 'GPT', aiModels: { audio } },
      {}
    )

    expect(message.aiModels).toEqual({
      chat: { provider: 'openai', model: 'GPT', model_id: null },
      audio,
    })
  })

  it('attaches a media job from the bare payload or the metadata envelope', () => {
    const bare = assistantMessage()
    const enveloped = assistantMessage()

    applyMediaJobToMessage(bare, { job_id: 'job-1', type: 'video', state: 'running' })
    applyMediaJobToMessage(enveloped, {
      media_job: { job_id: 'job-2', type: 'image', state: 'running' },
    })

    expect(bare.mediaJob).toMatchObject({ jobId: 'job-1', type: 'video' })
    expect(enveloped.mediaJob).toMatchObject({ jobId: 'job-2', type: 'image' })
    expect(
      generatingTokenForMediaJob({ mediaJob: { job_id: 'job-3', type: 'image', state: 'running' } })
    ).toBe('__IMAGE_GENERATING__')
    expect(generatingTokenForMediaJob(undefined)).toBe('__VIDEO_GENERATING__')
  })

  it('replaces the raw document envelope with the confirmation, but keeps prose', () => {
    const envelope = assistantMessage({ parts: [{ type: 'text', content: '__FILE_GENERATED__' }] })
    const prose = assistantMessage({ parts: [{ type: 'text', content: 'Here is your report.' }] })
    const file = { id: 1, filename: 'a.docx', path: 'p', size: 1, type: 'docx', mime: 'm' }

    applyGeneratedFile(envelope, file, 'Saved a.docx')
    applyGeneratedFile(prose, file, 'Saved a.docx')

    expect(envelope.parts).toEqual([{ type: 'text', content: 'Saved a.docx' }])
    expect(prose.parts).toEqual([{ type: 'text', content: 'Here is your report.' }])
    expect(prose.files).toHaveLength(1)
  })

  it('keeps the sources and the request the answer leaned on', () => {
    const message = assistantMessage()

    applyAnswerSources(message, {
      searchResults: [{ query: 'q', url: 'https://example.test' }],
      ragSources: [],
      providerMessages: { messages: [{ role: 'user', content: 'hi' }], truncated: true },
    })

    expect(message).toMatchObject({
      webSearch: { query: 'q', resultsCount: 1 },
      providerMessagesTruncated: true,
    })
    expect(message.ragSources).toBeUndefined()
  })
})
