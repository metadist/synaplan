/**
 * Web Speech API Service
 *
 * Provides real-time speech-to-text using the browser's built-in
 * SpeechRecognition API. This is used as the primary method when
 * WHISPER_ENABLED=false, providing instant transcription without
 * server round-trips.
 *
 * Browser Support:
 * - Chrome/Edge: Full support (uses Google's speech recognition)
 * - Safari: Partial support (on macOS/iOS)
 * - Firefox: Not supported
 *
 * @see https://developer.mozilla.org/en-US/docs/Web/API/SpeechRecognition
 */

// Type alias for the window with vendor-prefixed APIs
type WindowWithSpeech = Window &
  typeof globalThis & {
    SpeechRecognition?: SpeechRecognitionConstructor
    webkitSpeechRecognition?: SpeechRecognitionConstructor
  }

// Web Speech API type declarations (not all browsers have these in their type defs)
interface SpeechRecognitionResultItem {
  transcript: string
  confidence: number
}

interface SpeechRecognitionResult {
  readonly length: number
  readonly isFinal: boolean
  item(index: number): SpeechRecognitionResultItem
  [index: number]: SpeechRecognitionResultItem
}

interface SpeechRecognitionResultList {
  readonly length: number
  item(index: number): SpeechRecognitionResult
  [index: number]: SpeechRecognitionResult
}

interface SpeechRecognitionEventType extends Event {
  readonly resultIndex: number
  readonly results: SpeechRecognitionResultList
}

interface SpeechRecognitionErrorEventType extends Event {
  readonly error: string
  readonly message: string
}

interface SpeechRecognitionInstance extends EventTarget {
  lang: string
  continuous: boolean
  interimResults: boolean
  maxAlternatives: number
  onstart: ((this: SpeechRecognitionInstance, ev: Event) => void) | null
  onend: ((this: SpeechRecognitionInstance, ev: Event) => void) | null
  onresult: ((this: SpeechRecognitionInstance, ev: SpeechRecognitionEventType) => void) | null
  onerror: ((this: SpeechRecognitionInstance, ev: SpeechRecognitionErrorEventType) => void) | null
  start(): void
  stop(): void
  abort(): void
}

interface SpeechRecognitionConstructor {
  new (): SpeechRecognitionInstance
}

/**
 * Snapshot of the entire recognition session at a single point in time.
 *
 * `final` is the concatenation of every result whose `isFinal` flag is true,
 * `interim` is the most recent in-progress phrase (or empty string).
 *
 * Consumers should treat this as "the whole truth right now" and **assign**
 * (`message.value = ...`) rather than append (`message.value += ...`). This
 * is what makes the consumer immune to Android Chrome's broken behaviour
 * where the same final segment can be re-emitted across multiple events with
 * `event.resultIndex === 0` (issue #898).
 */
export interface WebSpeechSnapshot {
  /** All finalized text since `start()`, joined with single spaces. */
  final: string
  /** The current interim (in-progress) phrase. Empty when no interim is pending. */
  interim: string
}

export interface WebSpeechOptions {
  /** Language for recognition (e.g., 'en-US', 'de-DE'). Defaults to browser language. */
  language?: string
  /** Show interim results as user speaks */
  interimResults?: boolean
  /** Keep listening after user stops speaking */
  continuous?: boolean
  /**
   * Called whenever the recognition state changes, with a full snapshot of
   * the session so far. Replaces the legacy per-result `(text, isFinal)`
   * signature, which was unsafe on Android: see `onresult` handler below
   * and the `WebSpeechSnapshot` JSDoc for the rationale.
   */
  onResult?: (snapshot: WebSpeechSnapshot) => void
  /** Called when recognition starts */
  onStart?: () => void
  /** Called when recognition ends */
  onEnd?: () => void
  /** Called on error */
  onError?: (error: WebSpeechError) => void
}

export interface WebSpeechError {
  type: 'not_supported' | 'not_allowed' | 'no_speech' | 'network' | 'unknown'
  message: string
  userMessage: string
}

/**
 * Check if Web Speech API is supported in this browser.
 * Returns true for Chrome, Edge, Safari. False for Firefox.
 */
export function isWebSpeechSupported(): boolean {
  const win = window as WindowWithSpeech
  return !!(win.SpeechRecognition || win.webkitSpeechRecognition)
}

/**
 * Get the SpeechRecognition constructor (handles vendor prefix).
 */
function getSpeechRecognition(): SpeechRecognitionConstructor | null {
  const win = window as WindowWithSpeech
  return win.SpeechRecognition || win.webkitSpeechRecognition || null
}

/**
 * Web Speech API wrapper for real-time speech recognition.
 *
 * Usage:
 * ```typescript
 * const speech = new WebSpeechService({
 *   onResult: ({ final, interim }) => {
 *     // ALWAYS assign the snapshot — never append. The service rebuilds
 *     // the cumulative final string on every event, so appending would
 *     // duplicate text on Android Chrome (issue #898).
 *     messageInput.value = `${final}${final && interim ? ' ' : ''}${interim}`
 *   },
 *   onError: (error) => {
 *     showError(error.userMessage)
 *   }
 * })
 *
 * await speech.start()
 * // ... user speaks ...
 * speech.stop()
 * ```
 */
export class WebSpeechService {
  private recognition: SpeechRecognitionInstance | null = null
  private options: WebSpeechOptions
  private isListening = false

  constructor(options: WebSpeechOptions = {}) {
    this.options = {
      language: navigator.language || 'en-US',
      interimResults: true,
      continuous: true,
      ...options,
    }
  }

  /**
   * Check if Web Speech API is available in this browser.
   */
  static isSupported(): boolean {
    return isWebSpeechSupported()
  }

  /**
   * Start listening for speech input.
   * Resolves when recognition starts, rejects on error.
   */
  async start(): Promise<void> {
    const SpeechRecognitionClass = getSpeechRecognition()

    if (!SpeechRecognitionClass) {
      const error: WebSpeechError = {
        type: 'not_supported',
        message: 'SpeechRecognition not available',
        userMessage:
          'Speech recognition is not supported in this browser. Please use Chrome, Edge, or Safari.',
      }
      this.options.onError?.(error)
      throw error
    }

    if (this.isListening) {
      console.warn('WebSpeechService: Already listening')
      return
    }

    this.recognition = new SpeechRecognitionClass()
    this.recognition.lang = this.options.language || navigator.language
    this.recognition.interimResults = this.options.interimResults ?? true
    this.recognition.continuous = this.options.continuous ?? true
    this.recognition.maxAlternatives = 1

    this.recognition.onstart = () => {
      this.isListening = true
      this.options.onStart?.()
    }

    this.recognition.onend = () => {
      this.isListening = false
      this.options.onEnd?.()
    }

    this.recognition.onresult = (event: SpeechRecognitionEventType) => {
      // The W3C spec says `event.results` is the cumulative list of all
      // recognition results since the recogniser started. Final results are
      // stable once their `isFinal` flag is true; the *last* result may still
      // be interim. `event.resultIndex` is the first changed entry — but
      // Android Chrome (and a handful of other engines) emit multiple events
      // with the same `resultIndex` value and a growing final transcript at
      // that index, which produced the duplicated text in #898.
      //
      // Treat `event.results` as the source of truth: rebuild the full final
      // string on every event and emit a single snapshot. The consumer is
      // then expected to *assign* (not append) the snapshot into the textbox,
      // which makes duplicate emissions a no-op.
      const finals: string[] = []
      let interim = ''
      for (let i = 0; i < event.results.length; i++) {
        const result = event.results[i]
        if (!result || result.length === 0) continue
        const transcript = result[0].transcript
        if (result.isFinal) {
          finals.push(transcript)
        } else {
          // Per spec only one trailing interim is meaningful at a time, but
          // some engines briefly report multiple in-progress entries. Keep
          // the latest one — concatenation would inflate the visible text.
          interim = transcript
        }
      }
      this.options.onResult?.({
        final: finals.join(' ').replace(/\s+/g, ' ').trim(),
        interim: interim.replace(/\s+/g, ' ').trim(),
      })
    }

    this.recognition.onerror = (event: SpeechRecognitionErrorEventType) => {
      console.error('Web Speech error:', event.error, event.message)

      const error = this.parseError(event.error, event.message)
      this.options.onError?.(error)

      // Some errors are recoverable, others stop recognition
      if (['not-allowed', 'service-not-allowed', 'not_supported'].includes(event.error)) {
        this.isListening = false
      }
    }

    // Start recognition
    try {
      this.recognition.start()
    } catch (err: unknown) {
      const message = err instanceof Error ? err.message : 'Unknown error'
      const error: WebSpeechError = {
        type: 'unknown',
        message,
        userMessage: `Failed to start speech recognition: ${message}`,
      }
      this.options.onError?.(error)
      throw error
    }
  }

  /**
   * Stop listening for speech input.
   */
  stop(): void {
    if (this.recognition && this.isListening) {
      this.recognition.stop()
      this.isListening = false
    }
  }

  /**
   * Abort recognition immediately (discards pending results).
   *
   * Detaches all native event handlers BEFORE aborting so that any
   * in-flight result/end events do not invoke user callbacks. This is
   * essential when the caller wants to take ownership of the textbox
   * state (e.g. on send) and not have a late `onresult` write text back.
   */
  abort(): void {
    if (this.recognition) {
      this.recognition.onresult = null
      this.recognition.onend = null
      this.recognition.onerror = null
      this.recognition.onstart = null
      try {
        this.recognition.abort()
      } catch {
        // abort() can throw if recognition was never started; ignore.
      }
      this.recognition = null
      this.isListening = false
    }
  }

  /**
   * Check if currently listening.
   */
  get listening(): boolean {
    return this.isListening
  }

  /**
   * Parse speech recognition error into structured format.
   */
  private parseError(errorCode: string, message?: string): WebSpeechError {
    switch (errorCode) {
      case 'not-allowed':
      case 'service-not-allowed':
        return {
          type: 'not_allowed',
          message: message || 'Permission denied',
          userMessage:
            '🔒 Microphone access denied. Please allow microphone access in your browser settings.',
        }

      case 'no-speech':
        return {
          type: 'no_speech',
          message: message || 'No speech detected',
          userMessage: '🎤 No speech detected. Please speak clearly and try again.',
        }

      case 'network':
        return {
          type: 'network',
          message: message || 'Network error',
          userMessage:
            '🌐 Network error during speech recognition. Please check your internet connection.',
        }

      case 'aborted':
        return {
          type: 'unknown',
          message: message || 'Recognition aborted',
          userMessage: 'Speech recognition was stopped.',
        }

      case 'audio-capture':
        return {
          type: 'not_allowed',
          message: message || 'Audio capture failed',
          userMessage: '🎤 Could not capture audio. Please check your microphone.',
        }

      default:
        return {
          type: 'unknown',
          message: message || errorCode,
          userMessage: `Speech recognition error: ${errorCode}`,
        }
    }
  }
}
