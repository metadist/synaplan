/**
 * The same decision CarPlay uses while a reply is playing: ignore the start
 * of the reply, then stop it once speech holds above the echo leak.
 */
export interface BargeInDetector {
  speechThresholdDb: number
  calibrateFor: number
  echoMarginDb: number
  sustain: number
  echoPeakDb: number
  speechSince: number | null
}

export function createBargeInDetector(): BargeInDetector {
  return {
    speechThresholdDb: -30,
    calibrateFor: 0.8,
    echoMarginDb: 12,
    sustain: 0.4,
    echoPeakDb: -160,
    speechSince: null,
  }
}

export function feedBargeIn(detector: BargeInDetector, levelDb: number, time: number): boolean {
  if (time < detector.calibrateFor) {
    detector.echoPeakDb = Math.max(detector.echoPeakDb, levelDb)
    detector.speechSince = null
    return false
  }
  const threshold = Math.max(
    detector.speechThresholdDb,
    detector.echoPeakDb + detector.echoMarginDb
  )
  if (levelDb < threshold) {
    detector.speechSince = null
    return false
  }
  const start = detector.speechSince ?? time
  detector.speechSince = start
  return time - start >= detector.sustain
}

export function levelDb(samples: ArrayLike<number>): number {
  if (samples.length === 0) return -160
  let sum = 0
  for (let index = 0; index < samples.length; index += 1) {
    const sample = samples[index] ?? 0
    sum += sample * sample
  }
  const rms = Math.sqrt(sum / samples.length)
  return rms > 0 ? Math.max(-160, 20 * Math.log10(rms)) : -160
}

/**
 * Listens on a microphone the person has already allowed. Talking over a
 * spoken reply calls `onSpeech` once. No permission prompt.
 */
export function startBargeInMonitor(onSpeech: () => void): () => void {
  let stopped = false
  let closed = false
  let stream: MediaStream | null = null
  let context: AudioContext | null = null
  let frame = 0

  const stop = () => {
    stopped = true
    if (frame) cancelAnimationFrame(frame)
    stream?.getTracks().forEach((track) => track.stop())
    stream = null
    if (context && context.state !== 'closed') void context.close()
    context = null
  }

  void (async () => {
    if (stopped || !(await microphoneAlreadyGranted())) return
    try {
      stream = await navigator.mediaDevices.getUserMedia({
        audio: { echoCancellation: true, noiseSuppression: true },
      })
    } catch {
      return
    }
    if (stopped) {
      stop()
      return
    }
    const AudioCtx =
      window.AudioContext ??
      (window as { webkitAudioContext?: typeof AudioContext }).webkitAudioContext
    if (!AudioCtx) {
      stop()
      return
    }
    context = new AudioCtx()
    const source = context.createMediaStreamSource(stream)
    const analyser = context.createAnalyser()
    analyser.fftSize = 1024
    source.connect(analyser)
    const samples = new Float32Array(analyser.fftSize)
    const detector = createBargeInDetector()
    const started = performance.now()
    const tick = () => {
      if (stopped || closed) return
      analyser.getFloatTimeDomainData(samples)
      const seconds = (performance.now() - started) / 1000
      if (feedBargeIn(detector, levelDb(samples), seconds)) {
        closed = true
        stop()
        onSpeech()
        return
      }
      frame = requestAnimationFrame(tick)
    }
    frame = requestAnimationFrame(tick)
  })()

  return stop
}

async function microphoneAlreadyGranted(): Promise<boolean> {
  if (!navigator.permissions?.query) return false
  try {
    const status = await navigator.permissions.query({ name: 'microphone' as PermissionName })
    return status.state === 'granted'
  } catch {
    return false
  }
}
