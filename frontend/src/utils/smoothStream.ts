/**
 * Reveals streamed assistant text a few characters per frame.
 *
 * Providers emit chunks of several tokens at once. Painting each chunk whole
 * makes the reply stutter. This buffer keeps the bubble moving forward and
 * catches a large backlog up within {@link CATCH_UP_MS}, so the display never
 * falls noticeably behind the server.
 */

/** A one-shot backlog is drained inside this window. */
export const CATCH_UP_MS = 150

/** Slowest reveal while text is still outstanding. About one character a frame. */
export const MIN_CHARS_PER_SECOND = 60

const FRAME_MS = 1000 / 60

const THINK_OPEN = '<think>'
const THINK_CLOSE = '</think>'

export interface SmoothStreamOptions {
  onRender: (text: string) => void
  /** Text already on screen. Later pushes never paint something shorter. */
  initialShown?: string
}

export interface SmoothStream {
  /** New full text received so far. Starts the frame loop when there is more to show. */
  push: (fullText: string) => void
  /** Paint everything received so far, right now. */
  flush: () => void
  /** Stop. Does not paint anything further. */
  cancel: () => void
}

export function createSmoothStream(options: SmoothStreamOptions): SmoothStream {
  const { onRender } = options
  let target = options.initialShown ?? ''
  let shown = target
  let rafId = 0
  let lastTime = 0
  let catchUpDeadline = 0
  let stopped = false
  let listening = false

  const now = (): number =>
    typeof performance !== 'undefined' && typeof performance.now === 'function'
      ? performance.now()
      : Date.now()

  const reducedMotion = (): boolean => {
    if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') return false
    return window.matchMedia('(prefers-reduced-motion: reduce)').matches
  }

  const tabHidden = (): boolean => typeof document !== 'undefined' && document.hidden

  const onVisibility = (): void => {
    if (document.hidden) revealAll()
  }

  const ensureVisibility = (): void => {
    if (listening || typeof document === 'undefined') return
    document.addEventListener('visibilitychange', onVisibility)
    listening = true
  }

  const detachVisibility = (): void => {
    if (!listening || typeof document === 'undefined') return
    document.removeEventListener('visibilitychange', onVisibility)
    listening = false
  }

  const cancelFrame = (): void => {
    if (rafId === 0) return
    cancelAnimationFrame(rafId)
    rafId = 0
    lastTime = 0
  }

  const paint = (text: string): void => {
    if (text === shown) return
    shown = text
    onRender(shown)
  }

  const revealAll = (): void => {
    cancelFrame()
    catchUpDeadline = 0
    if (stopped) return
    if (target.length > shown.length && target.startsWith(shown)) {
      paint(target)
    }
  }

  const schedule = (time: number): void => {
    if (stopped || rafId !== 0) return
    if (shown.length >= target.length) return
    if (catchUpDeadline <= time) catchUpDeadline = time + CATCH_UP_MS
    ensureVisibility()
    rafId = requestAnimationFrame((frameTime) => {
      rafId = 0
      step(frameTime)
    })
  }

  const step = (time: number): void => {
    if (stopped) return
    if (shown.length >= target.length || !target.startsWith(shown)) {
      catchUpDeadline = 0
      lastTime = 0
      return
    }

    // A clock that does not move (a synchronous rAF stub) would schedule
    // forever. Show the rest instead of spinning.
    if (lastTime !== 0 && time <= lastTime) {
      paint(target)
      catchUpDeadline = 0
      lastTime = 0
      return
    }

    const dt = lastTime === 0 ? FRAME_MS : Math.max(0, time - lastTime)
    lastTime = time
    const backlog = target.length - shown.length
    const timeLeft = catchUpDeadline - time

    let count: number
    if (timeLeft <= dt) {
      count = backlog
    } else {
      const catchUp = Math.ceil((backlog * dt) / timeLeft)
      const floor = Math.ceil((MIN_CHARS_PER_SECOND * dt) / 1000)
      count = Math.min(backlog, Math.max(1, catchUp, floor))
    }

    const next = clampReveal(target, shown.length, count)
    if (next <= shown.length) {
      catchUpDeadline = 0
      lastTime = 0
      return
    }

    paint(target.slice(0, next))
    if (shown.length < target.length) {
      schedule(time)
    } else {
      catchUpDeadline = 0
      lastTime = 0
    }
  }

  return {
    push(fullText: string): void {
      if (stopped) return
      if (fullText.length < shown.length) return
      if (shown.length > 0 && !fullText.startsWith(shown)) {
        target = fullText
        paint(fullText)
        return
      }
      target = fullText
      if (shown.length >= target.length) return
      if (reducedMotion() || tabHidden()) {
        revealAll()
        return
      }
      schedule(now())
    },

    flush(): void {
      if (stopped) return
      revealAll()
      detachVisibility()
    },

    cancel(): void {
      stopped = true
      cancelFrame()
      catchUpDeadline = 0
      detachVisibility()
    },
  }
}

/**
 * Advance `count` code points from `from`, then keep a `<think>` / `</think>`
 * tag whole. An unfinished tag is held back so `<th` never flashes as text.
 */
function clampReveal(text: string, from: number, count: number): number {
  let end = from
  let taken = 0
  while (end < text.length && taken < count) {
    end = stepCodePoint(text, end)
    taken += 1
  }
  return extendThinkTag(text, from, end)
}

function stepCodePoint(text: string, index: number): number {
  const code = text.charCodeAt(index)
  if (code >= 0xd800 && code <= 0xdbff && index + 1 < text.length) return index + 2
  return index + 1
}

function extendThinkTag(text: string, from: number, end: number): number {
  const shown = text.slice(0, end)
  const mark = shown.lastIndexOf('<')
  if (mark === -1) return end
  const tail = shown.slice(mark)
  if (tail.includes('>')) return end

  const lower = tail.toLowerCase()
  const open = THINK_OPEN
  const close = THINK_CLOSE
  const isPrefix = (tag: string): boolean => tag.startsWith(lower) && lower.length < tag.length
  if (!isPrefix(open) && !isPrefix(close)) return end

  const rest = text.slice(mark).toLowerCase()
  if (rest.startsWith(open)) return Math.max(end, mark + open.length)
  if (rest.startsWith(close)) return Math.max(end, mark + close.length)
  return mark >= from ? mark : end
}
