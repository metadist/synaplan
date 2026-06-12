import { describe, it, expect, beforeEach } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import {
  mapApiMessageRow,
  reconcileLocalMessage,
  type ApiLoadedMessageRow,
} from '@/utils/messageMapper'
import type { Message } from '@/stores/history'

/**
 * Issue #1070 — single authoritative mapping + post-stream reconciliation.
 *
 * `mapApiMessageRow` is the one place that turns a persisted API row into
 * the frontend Message shape (used by reload AND by the post-`complete`
 * refetch). `reconcileLocalMessage` merges that authoritative state into
 * the live-streamed message without disturbing streamed text or task-card
 * media.
 */

const baseRow = (overrides: Partial<ApiLoadedMessageRow> = {}): ApiLoadedMessageRow => ({
  id: 77,
  direction: 'OUT',
  text: 'Here is your answer.',
  timestamp: 1765540000,
  ...overrides,
})

const localStreamedMessage = (overrides: Partial<Message> = {}): Message => ({
  id: 'local-uuid',
  role: 'assistant',
  parts: [{ partId: 'p1', type: 'text', content: 'Here is your answer.' }],
  timestamp: new Date(),
  backendMessageId: 77,
  ...overrides,
})

describe('messageMapper (issue #1070)', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  describe('mapApiMessageRow', () => {
    it('maps a generated audio file (TTS) to an audio part', () => {
      const message = mapApiMessageRow(
        baseRow({ file: { path: '13/000/2026/06/tts_reply.mp3', type: 'audio' } })
      )

      const audioParts = message.parts.filter((p) => p.type === 'audio')
      expect(audioParts).toHaveLength(1)
      expect(audioParts[0].url).toContain('tts_reply.mp3')
      expect(audioParts[0].partId).toBeTruthy()
      expect(message.backendMessageId).toBe(77)
    })

    it('maps generated images and videos to media parts', () => {
      const image = mapApiMessageRow(baseRow({ file: { path: '13/img.png', type: 'image' } }))
      expect(image.parts.some((p) => p.type === 'image')).toBe(true)

      const video = mapApiMessageRow(baseRow({ file: { path: '13/clip.mp4', type: 'video' } }))
      expect(video.parts.some((p) => p.type === 'video')).toBe(true)
    })

    it('maps metadata (aiModels, webSearch, multitask, topic)', () => {
      const message = mapApiMessageRow(
        baseRow({
          topic: 'general',
          multitask: true,
          aiModels: {
            chat: { provider: 'ollama', model: 'llama3', model_id: 5 },
            audio: { provider: 'piper', model: 'piper-multi', model_id: 7 },
          },
          webSearch: { query: 'weather', resultsCount: 3 },
        })
      )

      expect(message.wasMultitask).toBe(true)
      expect(message.topic).toBe('general')
      expect(message.aiModels?.audio?.model).toBe('piper-multi')
      expect(message.provider).toBe('ollama')
      expect(message.modelLabel).toBe('llama3')
      expect(message.webSearch?.resultsCount).toBe(3)
    })
  })

  describe('reconcileLocalMessage', () => {
    it('appends persisted media missing from the live bubble (TTS-in-DAG bug)', () => {
      // The `audio` SSE event was suppressed because taskPlan.active was
      // true, so the live bubble has no audio part — but the persisted
      // message carries the TTS file.
      const local = localStreamedMessage({
        wasMultitask: true,
        taskPlan: {
          active: true,
          replyNode: 'n3',
          cards: [
            { nodeId: 'n1', capability: 'summarize', kind: 'text', state: 'done' },
            { nodeId: 'n2', capability: 'translate', kind: 'text', state: 'done' },
          ],
        },
      })
      const persisted = mapApiMessageRow(
        baseRow({ file: { path: '13/000/tts_reply.mp3', type: 'audio' }, multitask: true })
      )

      reconcileLocalMessage(local, persisted)

      const audioParts = local.parts.filter((p) => p.type === 'audio')
      expect(audioParts).toHaveLength(1)
      expect(audioParts[0].url).toContain('tts_reply.mp3')
      // Streamed text stays untouched.
      expect(local.parts[0]).toEqual(
        expect.objectContaining({ partId: 'p1', type: 'text', content: 'Here is your answer.' })
      )
    })

    it('does not duplicate media the live stream already rendered', () => {
      const local = localStreamedMessage()
      const persisted = mapApiMessageRow(
        baseRow({ file: { path: '13/000/tts_reply.mp3', type: 'audio' } })
      )
      // Simulate the live `audio` SSE handler having already pushed the
      // part (same upload path, possibly different absolute form).
      local.parts = [
        ...local.parts,
        {
          partId: 'live-audio',
          type: 'audio',
          url: 'http://localhost:8000/api/v1/files/uploads/13/000/tts_reply.mp3',
          autoplay: true,
        },
      ]

      reconcileLocalMessage(local, persisted)

      const audioParts = local.parts.filter((p) => p.type === 'audio')
      expect(audioParts).toHaveLength(1)
      // The live part (with its autoplay flag and stable partId) survives.
      expect(audioParts[0].partId).toBe('live-audio')
      expect(audioParts[0].autoplay).toBe(true)
    })

    it('does not duplicate media already shown by an active task card', () => {
      const local = localStreamedMessage({
        taskPlan: {
          active: true,
          replyNode: 'n2',
          cards: [
            {
              nodeId: 'n1',
              capability: 'image_generation',
              kind: 'image',
              state: 'done',
              url: 'http://localhost:8000/api/v1/files/uploads/13/dag_image.png',
              mediaType: 'image',
            },
          ],
        },
      })
      const persisted = mapApiMessageRow(
        baseRow({ file: { path: '13/dag_image.png', type: 'image' } })
      )

      reconcileLocalMessage(local, persisted)

      // The card remains the streaming-time surface — no duplicate part.
      expect(local.parts.filter((p) => p.type === 'image')).toHaveLength(0)
    })

    it('treats persisted files and metadata as authoritative', () => {
      const local = localStreamedMessage()
      const persisted = mapApiMessageRow(
        baseRow({
          topic: 'mediamaker',
          multitask: true,
          aiModels: { chat: { provider: 'openai', model: 'gpt-test', model_id: 9 } },
          searchResults: [{ title: 'Result', url: 'https://example.com' }],
          files: [
            {
              id: 5,
              filename: 'report.pdf',
              fileType: 'pdf',
              filePath: '13/report.pdf',
              fileSize: 2048,
            },
          ],
        })
      )

      reconcileLocalMessage(local, persisted)

      expect(local.files).toHaveLength(1)
      expect(local.files![0].filename).toBe('report.pdf')
      expect(local.topic).toBe('mediamaker')
      expect(local.wasMultitask).toBe(true)
      expect(local.aiModels?.chat?.model).toBe('gpt-test')
      expect(local.searchResults).toHaveLength(1)
    })

    it('never wipes live-only state when the persisted row has no value', () => {
      const local = localStreamedMessage({
        topic: 'general',
        aiModels: { chat: { provider: 'ollama', model: 'live-model', model_id: 1 } },
        files: [{ id: 1, filename: 'live.pdf', fileType: 'pdf', filePath: '13/live.pdf' }],
      })
      const persisted = mapApiMessageRow(baseRow())

      reconcileLocalMessage(local, persisted)

      expect(local.topic).toBe('general')
      expect(local.aiModels?.chat?.model).toBe('live-model')
      expect(local.files).toHaveLength(1)
    })
  })
})
