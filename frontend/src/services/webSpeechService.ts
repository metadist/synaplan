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

export interface WebSpeechOptions {
  /** Language for recognition (e.g., 'en-US', 'de-DE'). Defaults to browser language. */
  language?: string
  /** Show interim results as user speaks */
  interimResults?: boolean
  /** Keep listening after user stops speaking */
  continuous?: boolean
  /** Called with transcribed text (interim or final) */
  onResult?: (text: string, isFinal: boolean) => void
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
 *   onResult: (text, isFinal) => {
 *     if (isFinal) {
 *       messageInput.value += text
 *     }
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
      const resultIndex = event.resultIndex
      const result = event.results[resultIndex]

      if (result) {
        const transcript = result[0].transcript
        const isFinal = result.isFinal
        this.options.onResult?.(transcript, isFinal)
      }
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
   */
  abort(): void {
    if (this.recognition) {
      this.recognition.abort()
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
