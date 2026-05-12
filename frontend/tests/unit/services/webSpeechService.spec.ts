/**
 * Regression tests for `WebSpeechService` covering issue #898
 * (Android Chrome duplicates STT transcripts).
 *
 * The bug came from two layers cooperating badly:
 *   1. The service used to read only `event.results[event.resultIndex]` and
 *      hand the consumer a single `(text, isFinal)` pair per event.
 *   2. The consumer (`ChatInput.vue`) used to *append* every "final" emission
 *      to its own buffer.
 *
 * Android Chrome routinely emits multiple events with `event.resultIndex === 0`
 * and a growing final transcript at that index, so step 1 produced duplicates
 * and step 2 doubled them. The fix replaces both halves with a snapshot model:
 * the service now rebuilds the cumulative final string from `event.results`
 * on every event and the consumer assigns (never appends) the snapshot.
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { WebSpeechService, type WebSpeechSnapshot } from '@/services/webSpeechService'

// Minimal in-memory fakes that mimic the parts of the Web Speech API the
// service touches. Anything we don't exercise stays out of these shims.
type ResultListEntry = { transcript: string; isFinal: boolean }

class FakeSpeechRecognitionResult {
  constructor(
    public readonly transcript: string,
    public readonly isFinal: boolean
  ) {}
  [index: number]: { transcript: string; confidence: number }
  get length(): number {
    return 1
  }
}
// Index access used by the service: `result[0].transcript`.
Object.defineProperty(FakeSpeechRecognitionResult.prototype, 0, {
  get() {
    return { transcript: this.transcript, confidence: 1 }
  },
})

class FakeRecognition {
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  onresult: ((e: any) => void) | null = null
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  onstart: ((e: any) => void) | null = null
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  onend: ((e: any) => void) | null = null
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  onerror: ((e: any) => void) | null = null
  lang = ''
  continuous = false
  interimResults = false
  maxAlternatives = 1
  start() {
    this.onstart?.({})
  }
  stop() {}
  abort() {}

  /** Test helper — fire an `onresult` event with the given full result list. */
  fireResults(resultIndex: number, list: ResultListEntry[]) {
    const results = list.map(
      (entry) => new FakeSpeechRecognitionResult(entry.transcript, entry.isFinal)
    )
    // Mimic the SpeechRecognitionResultList shape the service iterates over.
    const resultList = Object.assign(results, {
      length: results.length,
      item: (i: number) => results[i],
    })
    this.onresult?.({ resultIndex, results: resultList })
  }
}

describe('WebSpeechService.onresult — snapshot semantics (issue #898)', () => {
  let recognition: FakeRecognition
  let originalWebkitSpeechRecognition: unknown

  beforeEach(() => {
    recognition = new FakeRecognition()
    originalWebkitSpeechRecognition = (window as unknown as { webkitSpeechRecognition: unknown })
      .webkitSpeechRecognition

    // Install a constructor that returns our shared instance so we can drive it
    // from the test. The service only ever calls `new SpeechRecognitionClass()`
    // once per `start()`.
    ;(window as unknown as { webkitSpeechRecognition: unknown }).webkitSpeechRecognition =
      function () {
        return recognition
      } as unknown
  })

  afterEach(() => {
    if (originalWebkitSpeechRecognition === undefined) {
      delete (window as Partial<{ webkitSpeechRecognition: unknown }>).webkitSpeechRecognition
    } else {
      ;(window as unknown as { webkitSpeechRecognition: unknown }).webkitSpeechRecognition =
        originalWebkitSpeechRecognition
    }
  })

  it('emits a single snapshot per event with cumulative final + latest interim', async () => {
    const calls: WebSpeechSnapshot[] = []
    const service = new WebSpeechService({ onResult: (snap) => calls.push({ ...snap }) })
    await service.start()

    // Standard desktop Chrome pattern: one final at a time, growing the list.
    recognition.fireResults(0, [{ transcript: 'hello', isFinal: false }])
    recognition.fireResults(0, [{ transcript: 'hello', isFinal: true }])
    recognition.fireResults(1, [
      { transcript: 'hello', isFinal: true },
      { transcript: 'world', isFinal: false },
    ])
    recognition.fireResults(1, [
      { transcript: 'hello', isFinal: true },
      { transcript: 'world', isFinal: true },
    ])

    expect(calls).toEqual<WebSpeechSnapshot[]>([
      { final: '', interim: 'hello' },
      { final: 'hello', interim: '' },
      { final: 'hello', interim: 'world' },
      { final: 'hello world', interim: '' },
    ])
  })

  it('does NOT duplicate finals when Android Chrome re-emits the same final at resultIndex=0', async () => {
    // Reproduces the exact pattern from #898: every event re-fires the latest
    // final at resultIndex 0 with a growing transcript. The legacy
    // `text, isFinal` API would feed this straight into a `+=` consumer and
    // produce "hi hi hi wie hi wie wird ..." — the snapshot API must instead
    // produce the simple cumulative final without any duplication.
    const calls: WebSpeechSnapshot[] = []
    const service = new WebSpeechService({ onResult: (snap) => calls.push({ ...snap }) })
    await service.start()

    recognition.fireResults(0, [{ transcript: 'hi', isFinal: true }])
    recognition.fireResults(0, [{ transcript: 'hi wie', isFinal: true }])
    recognition.fireResults(0, [{ transcript: 'hi wie wird', isFinal: true }])
    recognition.fireResults(0, [{ transcript: 'hi wie wird das Wetter', isFinal: true }])
    recognition.fireResults(0, [
      { transcript: 'hi wie wird das Wetter heute in Münster', isFinal: true },
    ])

    // Each snapshot is the full final string at that point in time. None of
    // them ever contain a duplicated word. The last snapshot is the final
    // transcript the user expected to see.
    expect(calls.at(-1)).toEqual({
      final: 'hi wie wird das Wetter heute in Münster',
      interim: '',
    })
    for (const call of calls) {
      expect(call.final).not.toMatch(/\b(\w+)\s+\1\b/)
    }
  })

  it('does NOT duplicate when Android Chrome reports cumulative finals in multiple result entries', async () => {
    // Some Android Chrome versions add a NEW result entry to the list for
    // each progressive recognition, but each entry's transcript already
    // contains ALL previously recognized text (cumulative). Joining them
    // naively produces "hi hi wie hi wie wird..." — the service must detect
    // this pattern and take only the last (most complete) final.
    const calls: WebSpeechSnapshot[] = []
    const service = new WebSpeechService({ onResult: (snap) => calls.push({ ...snap }) })
    await service.start()

    // Simulate: results array grows with cumulative transcripts
    recognition.fireResults(0, [{ transcript: 'hi', isFinal: true }])
    recognition.fireResults(0, [
      { transcript: 'hi', isFinal: true },
      { transcript: 'hi wie', isFinal: true },
    ])
    recognition.fireResults(0, [
      { transcript: 'hi', isFinal: true },
      { transcript: 'hi wie', isFinal: true },
      { transcript: 'hi wie wird', isFinal: true },
    ])
    recognition.fireResults(0, [
      { transcript: 'hi', isFinal: true },
      { transcript: 'hi wie', isFinal: true },
      { transcript: 'hi wie wird', isFinal: true },
      { transcript: 'hi wie wird das Wetter heute in Münster', isFinal: true },
    ])

    // Every snapshot must be the clean cumulative text — no duplication.
    expect(calls.map((c) => c.final)).toEqual([
      'hi',
      'hi wie',
      'hi wie wird',
      'hi wie wird das Wetter heute in Münster',
    ])
    for (const call of calls) {
      expect(call.final).not.toMatch(/\b(\w+)\s+\1\b/)
    }
  })

  it('still joins independent finals correctly (standard W3C desktop pattern)', async () => {
    // On desktop Chrome, each result entry is an independent word/phrase.
    // These must still be joined, NOT collapsed.
    const calls: WebSpeechSnapshot[] = []
    const service = new WebSpeechService({ onResult: (snap) => calls.push({ ...snap }) })
    await service.start()

    recognition.fireResults(1, [
      { transcript: 'hello', isFinal: true },
      { transcript: 'world', isFinal: true },
    ])

    // "world" does NOT start with "hello", so this is independent → join.
    expect(calls.at(-1)).toEqual({ final: 'hello world', interim: '' })
  })


  it('keeps the latest interim only when the engine briefly reports multiple', async () => {
    const calls: WebSpeechSnapshot[] = []
    const service = new WebSpeechService({ onResult: (snap) => calls.push({ ...snap }) })
    await service.start()

    // Some engines report multiple in-progress entries between finals; we
    // must not concatenate them into the visible interim.
    recognition.fireResults(0, [
      { transcript: 'guten', isFinal: false },
      { transcript: 'guten morgen', isFinal: false },
    ])

    expect(calls).toEqual<WebSpeechSnapshot[]>([{ final: '', interim: 'guten morgen' }])
  })

  it('collapses repeated whitespace inside the joined final string', async () => {
    const calls: WebSpeechSnapshot[] = []
    const service = new WebSpeechService({ onResult: (snap) => calls.push({ ...snap }) })
    await service.start()

    recognition.fireResults(0, [
      { transcript: '  hello  ', isFinal: true },
      { transcript: 'world  ', isFinal: true },
    ])

    expect(calls.at(-1)).toEqual({ final: 'hello world', interim: '' })
  })

  it('skips empty / malformed result entries safely', async () => {
    const onResult = vi.fn<(snap: WebSpeechSnapshot) => void>()
    const service = new WebSpeechService({ onResult })
    await service.start()

    // Some engines occasionally emit an event with zero results.
    recognition.fireResults(0, [])

    expect(onResult).toHaveBeenCalledTimes(1)
    expect(onResult).toHaveBeenCalledWith({ final: '', interim: '' })
  })
})
