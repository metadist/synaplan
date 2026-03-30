/**
 * Audio Recording Service
 * Handles microphone access, recording, and transcription
 */

export interface AudioRecorderOptions {
  onDataAvailable?: (blob: Blob) => void
  onError?: (error: AudioRecorderError) => void
  onStart?: () => void
  onStop?: () => void
}

export interface AudioRecorderError {
  type: 'permission' | 'not_found' | 'in_use' | 'not_supported' | 'unknown'
  name: string
  message: string
  userMessage: string
}

export class AudioRecorder {
  private mediaRecorder: MediaRecorder | null = null
  private stream: MediaStream | null = null
  private audioChunks: Blob[] = []
  private options: AudioRecorderOptions

  constructor(options: AudioRecorderOptions = {}) {
    this.options = options
  }

  /**
   * Check if recording is supported and microphone is available
   */
  async checkSupport(): Promise<{
    supported: boolean
    hasDevices: boolean
    error?: AudioRecorderError
  }> {
    try {
      // Check if MediaRecorder API is supported
      if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        return {
          supported: false,
          hasDevices: false,
          error: {
            type: 'not_supported',
            name: 'NotSupported',
            message: 'MediaDevices API not available',
            userMessage:
              '🚫 Your browser does not support audio recording. Please use a modern browser like Chrome, Firefox, or Edge.',
          },
        }
      }

      if (!window.MediaRecorder) {
        return {
          supported: false,
          hasDevices: false,
          error: {
            type: 'not_supported',
            name: 'NotSupported',
            message: 'MediaRecorder not available',
            userMessage: '🚫 Recording is not supported by your browser.',
          },
        }
      }

      // Try to enumerate devices (this might require permission in some browsers)
      try {
        const devices = await navigator.mediaDevices.enumerateDevices()
        const audioInputs = devices.filter((device) => device.kind === 'audioinput')

        console.log('🎤 Available audio input devices:', audioInputs.length)
        audioInputs.forEach((device, i) => {
          console.log(
            `  ${i + 1}. ${device.label || 'Microphone ' + (i + 1)} (${device.deviceId.substring(0, 8)}...)`
          )
        })

        if (audioInputs.length === 0) {
          return {
            supported: true,
            hasDevices: false,
            error: {
              type: 'not_found',
              name: 'NoDevices',
              message: 'No audio input devices found',
              userMessage:
                '🎤 No microphone detected. Please connect a microphone and refresh the page.\n\n💡 Note: On WSL2/Linux, audio devices might not be accessible from the browser.',
            },
          }
        }

        return { supported: true, hasDevices: true }
      } catch (err) {
        // enumerateDevices failed, but we can still try getUserMedia
        console.warn('⚠️ Could not enumerate devices:', err)
        return { supported: true, hasDevices: true } // Assume devices exist
      }
    } catch (err: unknown) {
      return {
        supported: false,
        hasDevices: false,
        error: this.parseError(err),
      }
    }
  }

  /**
   * Start recording
   */
  async startRecording(): Promise<void> {
    try {
      // Check support first
      const support = await this.checkSupport()
      if (!support.supported || !support.hasDevices) {
        throw support.error
      }

      // Request microphone access with basic constraints
      this.stream = await navigator.mediaDevices.getUserMedia({
        audio: {
          echoCancellation: true,
          noiseSuppression: true,
          autoGainControl: true,
        },
      })

      console.log('✅ Microphone access granted!')
      console.log(
        '   Tracks:',
        this.stream.getAudioTracks().map((t) => `${t.label} (${t.kind})`)
      )

      // Find best supported MIME type
      const mimeType = this.getBestMimeType()
      console.log('🎙️ Using MIME type:', mimeType || 'default')

      // Create MediaRecorder
      this.mediaRecorder = new MediaRecorder(this.stream, {
        mimeType: mimeType || undefined,
      })

      this.audioChunks = []

      this.mediaRecorder.ondataavailable = (event) => {
        if (event.data.size > 0) {
          this.audioChunks.push(event.data)
        }
      }

      this.mediaRecorder.onstop = () => {
        const audioBlob = new Blob(this.audioChunks, {
          type: mimeType || 'audio/webm',
        })
        console.log('🎵 Recording stopped. Size:', audioBlob.size, 'bytes')

        if (this.options.onDataAvailable) {
          this.options.onDataAvailable(audioBlob)
        }

        this.cleanup()

        if (this.options.onStop) {
          this.options.onStop()
        }
      }

      this.mediaRecorder.onerror = (event: Event) => {
        const mediaError: unknown =
          'error' in event && (event as { error?: unknown }).error !== undefined
            ? (event as { error: unknown }).error
            : new Error('MediaRecorder error')
        console.error('❌ MediaRecorder error:', mediaError)
        const error = this.parseError(mediaError)
        if (this.options.onError) {
          this.options.onError(error)
        }
        this.cleanup()
      }

      // Start recording
      this.mediaRecorder.start()
      console.log('🔴 Recording started')

      if (this.options.onStart) {
        this.options.onStart()
      }
    } catch (err: unknown) {
      console.error('❌ Recording failed:', err)
      this.cleanup()

      const error = this.parseError(err)
      if (this.options.onError) {
        this.options.onError(error)
      }
      throw error
    }
  }

  /**
   * Stop recording
   */
  stopRecording(): void {
    if (this.mediaRecorder && this.mediaRecorder.state !== 'inactive') {
      console.log('⏹️ Stopping recording...')
      this.mediaRecorder.stop()
    } else {
      console.warn('⚠️ No active recording to stop')
      this.cleanup()
    }
  }

  /**
   * Check if currently recording
   */
  isRecording(): boolean {
    return this.mediaRecorder !== null && this.mediaRecorder.state === 'recording'
  }

  /**
   * Clean up resources
   */
  private cleanup(): void {
    if (this.stream) {
      this.stream.getTracks().forEach((track) => {
        track.stop()
        console.log('🛑 Stopped track:', track.label)
      })
      this.stream = null
    }
    this.mediaRecorder = null
    this.audioChunks = []
  }

  /**
   * Get best supported MIME type
   */
  private getBestMimeType(): string {
    const types = [
      'audio/webm;codecs=opus',
      'audio/webm',
      'audio/ogg;codecs=opus',
      'audio/ogg',
      'audio/mp4',
      'audio/mpeg',
    ]

    for (const type of types) {
      if (MediaRecorder.isTypeSupported(type)) {
        return type
      }
    }

    return '' // Let browser choose
  }

  /**
   * Parse error into structured format
   */
  private parseError(err: unknown): AudioRecorderError {
    const name = err instanceof Error || err instanceof DOMException ? err.name : 'Unknown'
    const message =
      err instanceof Error || err instanceof DOMException ? err.message : 'Unknown error'

    // Permission errors
    if (name === 'NotAllowedError' || name === 'PermissionDeniedError') {
      return {
        type: 'permission',
        name,
        message,
        userMessage:
          '🔒 Microphone permission denied. Please allow microphone access in your browser settings and refresh the page.',
      }
    }

    // Device not found
    if (name === 'NotFoundError' || name === 'DevicesNotFoundError') {
      return {
        type: 'not_found',
        name,
        message,
        userMessage:
          '🎤 No microphone found. Please connect a microphone and try again.\n\n💡 Note: On WSL2/Linux, audio devices might not be accessible from the browser. Try accessing from Windows host.',
      }
    }

    // Device in use
    if (name === 'NotReadableError' || name === 'TrackStartError') {
      return {
        type: 'in_use',
        name,
        message,
        userMessage:
          '⚠️ Microphone is already in use by another application. Please close other apps using the microphone and try again.',
      }
    }

    // Aborted
    if (name === 'AbortError') {
      return {
        type: 'unknown',
        name,
        message,
        userMessage: '⚠️ Microphone access was aborted. Please try again.',
      }
    }

    // Security error
    if (name === 'SecurityError') {
      return {
        type: 'permission',
        name,
        message,
        userMessage:
          '🔒 Microphone access blocked by security settings. Please use HTTPS or allow microphone in browser settings.',
      }
    }

    // Generic error
    return {
      type: 'unknown',
      name,
      message,
      userMessage: `⚠️ Microphone error: ${name}\n${message}`,
    }
  }
}
