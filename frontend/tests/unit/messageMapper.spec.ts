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

    it('maps a running background mediaJob from the API row (async video detach)', () => {
      const message = mapApiMessageRow(
        baseRow({
          text: '__VIDEO_GENERATING__',
          mediaJob: {
            job_id: 'job-abc',
            type: 'video',
            state: 'running',
          },
        })
      )

      expect(message.mediaJob).toEqual({
        jobId: 'job-abc',
        type: 'video',
        state: 'running',
      })
    })

    it('rebuilds taskPlan cards from persisted render state (DAG reload #1070)', () => {
      const message = mapApiMessageRow(
        baseRow({
          multitask: true,
          taskPlan: {
            reply_node: 'n2',
            cards: [
              {
                nodeId: 'n1',
                capability: 'summarize',
                kind: 'text',
                state: 'done',
                text: 'Summary content',
              },
            ],
          },
        })
      )

      expect(message.taskPlan).not.toBeNull()
      expect(message.taskPlan?.active).toBe(false)
      expect(message.taskPlan?.replyNode).toBe('n2')
      expect(message.taskPlan?.cards).toHaveLength(1)
      expect(message.taskPlan?.cards[0].nodeId).toBe('n1')
      expect(message.taskPlan?.cards[0].capability).toBe('summarize')
      expect(message.taskPlan?.cards[0].kind).toBe('text')
      expect(message.taskPlan?.cards[0].state).toBe('done')
      expect(message.taskPlan?.cards[0].text).toBe('Summary content')
    })

    it('rebuilds taskPlan card with media url and normalizes it', () => {
      const message = mapApiMessageRow(
        baseRow({
          multitask: true,
          taskPlan: {
            reply_node: 'n2',
            cards: [
              {
                nodeId: 'n1',
                capability: 'text2sound',
                kind: 'audio',
                state: 'done',
                url: '13/000/tts.mp3',
                type: 'audio',
              },
            ],
          },
        })
      )

      const card = message.taskPlan?.cards[0]
      expect(card?.url).toBeTruthy()
      expect(card?.url).toContain('tts.mp3')
    })

    it('deduplicates card media from flat parts (card is primary surface)', () => {
      // file.path and the card url point to the same upload — the flat
      // audio part must be dropped because the card already shows it.
      const message = mapApiMessageRow(
        baseRow({
          multitask: true,
          file: { path: '13/000/dag_audio.mp3', type: 'audio' },
          taskPlan: {
            reply_node: 'n2',
            cards: [
              {
                nodeId: 'n1',
                capability: 'text2sound',
                kind: 'audio',
                state: 'done',
                url: '13/000/dag_audio.mp3',
                type: 'audio',
              },
            ],
          },
        })
      )

      // The card holds the media; no duplicate flat audio part in the bubble.
      const audioParts = message.parts.filter((p) => p.type === 'audio')
      expect(audioParts).toHaveLength(0)
      expect(message.taskPlan?.cards[0].url).toContain('dag_audio.mp3')
    })

    it('returns taskPlan: null when taskPlan is absent from the row', () => {
      const message = mapApiMessageRow(baseRow({ multitask: true }))
      expect(message.taskPlan).toBeNull()
    })

    it('maps query and resultsCount from a search card (QA feedback #1076)', () => {
      const message = mapApiMessageRow(
        baseRow({
          multitask: true,
          taskPlan: {
            reply_node: 'n2',
            cards: [
              {
                nodeId: 'n1',
                capability: 'web_search',
                kind: 'search',
                state: 'done',
                query: 'latest AI news',
                resultsCount: 5,
              },
              {
                nodeId: 'n2',
                capability: 'chat',
                kind: 'text',
                state: 'done',
                text: 'Here is the summary.',
              },
            ],
          },
        })
      )

      const searchCard = message.taskPlan?.cards.find((c) => c.nodeId === 'n1')
      expect(searchCard).toBeDefined()
      expect(searchCard?.kind).toBe('search')
      expect(searchCard?.query).toBe('latest AI news')
      expect(searchCard?.resultsCount).toBe(5)
      // The raw text dump must not appear on a search card
      expect(searchCard?.text).toBe('')
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

    it('does NOT downgrade a terminal mediaJob back to running (anti-flicker)', () => {
      // The live poll has already observed `done`; a stale persisted snapshot
      // still says `running`. Reconcile must keep the terminal state, otherwise
      // the banner reappears, polling re-arms, and the UI flickers endlessly.
      const local = localStreamedMessage({
        mediaJob: { jobId: 'job-abc', type: 'video', state: 'done' },
      })
      const persisted = mapApiMessageRow(
        baseRow({
          text: '__VIDEO_GENERATING__',
          mediaJob: { job_id: 'job-abc', type: 'video', state: 'running' },
        })
      )

      reconcileLocalMessage(local, persisted)

      expect(local.mediaJob?.state).toBe('done')
    })

    it('applies a running→done mediaJob transition from the persisted row', () => {
      const local = localStreamedMessage({
        mediaJob: { jobId: 'job-abc', type: 'video', state: 'running' },
      })
      const persisted = mapApiMessageRow(
        baseRow({
          file: { path: '13/clip.mp4', type: 'video' },
          mediaJob: { job_id: 'job-abc', type: 'video', state: 'done' },
        })
      )

      reconcileLocalMessage(local, persisted)

      expect(local.mediaJob?.state).toBe('done')
    })

    it('applies a terminal failure from the persisted row even over a local terminal state', () => {
      const local = localStreamedMessage({
        mediaJob: { jobId: 'job-abc', type: 'video', state: 'done' },
      })
      const persisted = mapApiMessageRow(
        baseRow({
          text: 'Your video took too long to create and was stopped.',
          mediaJob: { job_id: 'job-abc', type: 'video', state: 'failed' },
        })
      )

      reconcileLocalMessage(local, persisted)

      // Terminal→terminal is allowed; a stale running is the only thing blocked.
      expect(local.mediaJob?.state).toBe('failed')
    })

    it('keeps live taskPlan intact when reconciling (streaming cards must survive)', () => {
      // During live streaming the local message has an active taskPlan;
      // reconcileLocalMessage must not overwrite it with the persisted one.
      const liveTaskPlan = {
        active: true,
        replyNode: 'n2',
        cards: [
          {
            nodeId: 'n1',
            capability: 'summarize',
            kind: 'text' as const,
            state: 'done' as const,
            text: 'Live streamed summary',
          },
        ],
      }
      const local = localStreamedMessage({ taskPlan: liveTaskPlan, wasMultitask: true })
      const persisted = mapApiMessageRow(
        baseRow({
          multitask: true,
          taskPlan: {
            reply_node: 'n2',
            cards: [
              {
                nodeId: 'n1',
                capability: 'summarize',
                kind: 'text',
                state: 'done',
                text: 'Persisted summary (different)',
              },
            ],
          },
        })
      )

      reconcileLocalMessage(local, persisted)

      // Live taskPlan is authoritative during streaming — must stay as-is.
      expect(local.taskPlan?.active).toBe(true)
      expect(local.taskPlan?.cards[0].text).toBe('Live streamed summary')
    })

    it('adopts the persisted file-generation marker when the stream left raw JSON (#1258)', () => {
      // Officemaker/document turn: the stream left the raw BFILEPATH JSON in
      // the bubble (the `generatedFile` replace heuristic missed it), but the
      // persisted row carries the authoritative `__FILE_GENERATED__` marker.
      const local = localStreamedMessage({
        parts: [
          {
            partId: 'p1',
            type: 'code',
            content: '{"BFILEPATH":"report.docx","BFILETEXT":"..."}',
            language: 'json',
          },
        ],
      })
      const persisted = mapApiMessageRow(
        baseRow({
          text: '__FILE_GENERATED__:report.docx',
          files: [{ id: 9, filename: 'report.docx', fileType: 'docx', filePath: '13/report.docx' }],
        })
      )

      reconcileLocalMessage(local, persisted)

      expect(local.parts).toEqual([{ type: 'text', content: '__FILE_GENERATED__:report.docx' }])
      expect(local.files).toHaveLength(1)
      expect(local.files![0].filename).toBe('report.docx')
    })

    it('adopts the persisted file-generation marker when the streamed bubble is empty (#1258)', () => {
      const local = localStreamedMessage({ parts: [{ partId: 'p1', type: 'text', content: '' }] })
      const persisted = mapApiMessageRow(baseRow({ text: '__FILE_GENERATED__:notes.docx' }))

      reconcileLocalMessage(local, persisted)

      expect(local.parts).toEqual([{ type: 'text', content: '__FILE_GENERATED__:notes.docx' }])
    })

    it('replaces already-translated file text with the marker so both paths converge (#1258)', () => {
      // The streaming `generatedFile` branch already swapped in the translated
      // prompt; reconcile normalizes it back to the marker (MessageText
      // re-translates it identically), so the reload and stream paths match.
      const local = localStreamedMessage({
        parts: [
          { partId: 'p1', type: 'text', content: "I've created the file 'report.docx' for you." },
        ],
      })
      const persisted = mapApiMessageRow(baseRow({ text: '__FILE_GENERATED__:report.docx' }))

      reconcileLocalMessage(local, persisted)

      expect(local.parts).toEqual([{ type: 'text', content: '__FILE_GENERATED__:report.docx' }])
    })

    it('preserves generated media parts while adopting the file marker (#1258)', () => {
      // A DAG turn produced both an image (already shown live) and a document;
      // reconcile must keep the image part and only fix the missing text.
      const local = localStreamedMessage({
        parts: [
          { partId: 'p1', type: 'text', content: '{"BFILEPATH":"summary.docx"}' },
          {
            partId: 'p2',
            type: 'image',
            url: 'http://localhost:8000/api/v1/files/uploads/13/cat.png',
          },
        ],
      })
      const persisted = mapApiMessageRow(baseRow({ text: '__FILE_GENERATED__:summary.docx' }))

      reconcileLocalMessage(local, persisted)

      expect(local.parts[0]).toEqual({ type: 'text', content: '__FILE_GENERATED__:summary.docx' })
      const imageParts = local.parts.filter((p) => p.type === 'image')
      expect(imageParts).toHaveLength(1)
      expect(imageParts[0].partId).toBe('p2')
    })

    it('leaves the bubble untouched when it already shows the file marker (#1258)', () => {
      const local = localStreamedMessage({
        parts: [{ partId: 'p1', type: 'text', content: '__FILE_GENERATION_FAILED__' }],
      })
      const persisted = mapApiMessageRow(baseRow({ text: '__FILE_GENERATION_FAILED__' }))

      reconcileLocalMessage(local, persisted)

      // Same marker already shown — the stable partId must survive.
      expect(local.parts).toHaveLength(1)
      expect(local.parts[0].partId).toBe('p1')
    })

    it('does not touch streamed text for a normal (non-file) message (#1258)', () => {
      const local = localStreamedMessage({
        parts: [{ partId: 'p1', type: 'text', content: 'Live streamed answer.' }],
      })
      const persisted = mapApiMessageRow(baseRow({ text: 'Persisted answer (different).' }))

      reconcileLocalMessage(local, persisted)

      // No file marker in play — streamed text stays authoritative.
      expect(local.parts).toEqual([
        { partId: 'p1', type: 'text', content: 'Live streamed answer.' },
      ])
    })
  })
})
