/**
 * Streaming markdown helper — makes an in-progress markdown fragment safe to
 * feed through the full markdown renderer by appending closers for any inline
 * markup that the author has opened but not yet closed.
 *
 * Why: during SSE streaming the trailing in-progress paragraph keeps growing
 * one token at a time. If we render it as-is, an already-closed `**bold**`
 * span looks fine, but the moment a *new* unclosed `**` arrives the markdown
 * parser swallows everything up to it and the earlier formatting visibly
 * "breaks" until the line finishes. By temporarily balancing the open
 * markers we keep every completed span rendered and even show the token that
 * is currently being typed.
 *
 * Guarantees:
 *   - Only APPENDS closers at the very end (plus hides a single trailing,
 *     still-incomplete link/image). Already-typed characters are never
 *     rewritten, so the rendered output for the stable part stays identical
 *     between chunks — no flicker.
 *   - Markup inside inline code spans is treated as literal.
 *   - Uses CommonMark-style flanking rules so `2 * 3`, bullet lists
 *     (`* item`), and `snake_case` are not mistaken for emphasis.
 */

type InlineMarker = '~~' | '**' | '*' | '_'

type Segment =
  | { type: 'text'; value: string }
  | { type: 'code'; value: string }
  | { type: 'codeOpen'; value: string; ticks: number }

export function completeInlineMarkdown(input: string): string {
  if (input.length === 0) return input

  const segments = tokenizeCode(input)
  const last = segments[segments.length - 1]

  // Inside an unterminated inline code span: close the code, then close any
  // emphasis opened in earlier text segments so it wraps the inline code.
  if (last.type === 'codeOpen') {
    const proseText = joinTextSegments(segments)
    return input + '`'.repeat(last.ticks) + computeEmphasisClosers(proseText)
  }

  let trailingWhitespace = ''
  if (last.type === 'text') {
    // Hide a still-incomplete trailing link/image so raw `[text](http…`
    // syntax never flashes on screen.
    last.value = hideIncompleteLink(last.value)
    // Drop the marker the author is still typing (e.g. the lone `*` of a
    // half-arrived `**`, or a fresh `**` with no content yet) plus any
    // trailing whitespace. The open markers are re-derived from the stack
    // below, so a partially-typed closer never leaks raw and an empty opener
    // is hidden until its content arrives.
    const stripped = stripTrailingInProgress(last.value)
    last.value = stripped.core
    trailingWhitespace = stripped.trailingWhitespace
  }

  // Markup opened inside (closed) code spans is literal — only the text
  // segments contribute open emphasis/strong/strikethrough markers.
  const closers = computeEmphasisClosers(joinTextSegments(segments))
  const base = segments.map((segment) => segment.value).join('')

  // Closers MUST land directly after the last non-whitespace character:
  // CommonMark only treats a `**`/`*`/`_`/`~~` run as a closer when it is not
  // preceded by whitespace (right-flanking). Appending after a trailing space
  // would make marked render the whole span as raw text.
  return base + closers + trailingWhitespace
}

function joinTextSegments(segments: Segment[]): string {
  return segments
    .filter((segment) => segment.type === 'text')
    .map((segment) => segment.value)
    .join('')
}

/**
 * Splits off the trailing "in-progress" tail of a streamed fragment: optional
 * whitespace, an optional run of emphasis markers (the closer/opener the
 * author is currently typing), and optional whitespace again. The marker run
 * is discarded (re-derived from the open-delimiter stack) while the whitespace
 * is preserved so spacing between words stays intact.
 */
function stripTrailingInProgress(text: string): { core: string; trailingWhitespace: string } {
  let core = text
  let trailingWhitespace = ''

  const ws1 = core.match(/\s+$/)
  if (ws1) {
    trailingWhitespace = ws1[0] + trailingWhitespace
    core = core.slice(0, -ws1[0].length)
  }

  const markerRun = core.match(/[*_~]+$/)
  if (markerRun) {
    core = core.slice(0, -markerRun[0].length)
  }

  const ws2 = core.match(/\s+$/)
  if (ws2) {
    trailingWhitespace = ws2[0] + trailingWhitespace
    core = core.slice(0, -ws2[0].length)
  }

  return { core, trailingWhitespace }
}

/**
 * Splits the input into text and inline-code segments. A code span opens on a
 * run of N backticks and closes on the next run of exactly N backticks. An
 * unterminated run produces a trailing `codeOpen` segment.
 */
function tokenizeCode(input: string): Segment[] {
  const segments: Segment[] = []
  let textStart = 0
  let i = 0

  while (i < input.length) {
    if (input[i] !== '`') {
      i++
      continue
    }

    let ticks = 1
    while (input[i + ticks] === '`') ticks++

    const closing = findClosingBackticks(input, i + ticks, ticks)

    if (i > textStart) {
      segments.push({ type: 'text', value: input.slice(textStart, i) })
    }

    if (closing === -1) {
      segments.push({ type: 'codeOpen', value: input.slice(i), ticks })
      return segments
    }

    const end = closing + ticks
    segments.push({ type: 'code', value: input.slice(i, end) })
    i = end
    textStart = end
  }

  if (textStart < input.length) {
    segments.push({ type: 'text', value: input.slice(textStart) })
  }

  if (segments.length === 0) {
    segments.push({ type: 'text', value: '' })
  }

  return segments
}

function findClosingBackticks(input: string, from: number, ticks: number): number {
  let j = from
  while (j < input.length) {
    if (input[j] !== '`') {
      j++
      continue
    }
    let run = 1
    while (input[j + run] === '`') run++
    if (run === ticks) return j
    j += run
  }
  return -1
}

function hideIncompleteLink(text: string): string {
  // `[label](dest` / `![alt](dest` — destination started but not closed: hide
  // the whole token so a half-typed URL never appears.
  let result = text.replace(/!?\[[^\]]*\]\([^)]*$/, '')
  // `[label` / `![alt` — opening bracket without a closing one yet: keep only
  // the visible label/alt text.
  result = result.replace(/!?\[([^\]]*)$/, '$1')
  return result
}

function computeEmphasisClosers(text: string): string {
  const stack: InlineMarker[] = []
  let i = 0

  while (i < text.length) {
    const ch = text[i]
    if (ch !== '*' && ch !== '_' && ch !== '~') {
      i++
      continue
    }

    const marker = detectMarker(text, i)
    if (marker === null) {
      i++
      continue
    }

    const prev = i > 0 ? text[i - 1] : ''
    const next = text[i + marker.length] ?? ''
    const { canOpen, canClose } = classifyFlanking(prev, next, marker)

    if (canClose && stack.includes(marker)) {
      popMarker(stack, marker)
    } else if (canOpen) {
      stack.push(marker)
    }

    i += marker.length
  }

  // Close innermost (last opened) first.
  return stack.reverse().join('')
}

function detectMarker(text: string, i: number): InlineMarker | null {
  const ch = text[i]
  if (ch === '~') {
    return text[i + 1] === '~' ? '~~' : null
  }
  if (ch === '*') {
    return text[i + 1] === '*' ? '**' : '*'
  }
  if (ch === '_') {
    return '_'
  }
  return null
}

function classifyFlanking(
  prev: string,
  next: string,
  marker: InlineMarker
): { canOpen: boolean; canClose: boolean } {
  const nextIsWhitespace = next === '' || /\s/.test(next)
  const prevIsWhitespace = prev === '' || /\s/.test(prev)
  const nextIsPunct = isPunctuation(next)
  const prevIsPunct = isPunctuation(prev)

  const leftFlanking = !nextIsWhitespace && (!nextIsPunct || prevIsWhitespace || prevIsPunct)
  const rightFlanking = !prevIsWhitespace && (!prevIsPunct || nextIsWhitespace || nextIsPunct)

  // `_` may not be used for intra-word emphasis (stricter rule than `*`).
  if (marker === '_') {
    return {
      canOpen: leftFlanking && (!rightFlanking || prevIsPunct),
      canClose: rightFlanking && (!leftFlanking || nextIsPunct),
    }
  }

  return { canOpen: leftFlanking, canClose: rightFlanking }
}

function isPunctuation(ch: string): boolean {
  if (ch === '') return false
  if (/\s/.test(ch)) return false
  return !/[\p{L}\p{N}]/u.test(ch)
}

function popMarker(stack: InlineMarker[], marker: InlineMarker): void {
  for (let k = stack.length - 1; k >= 0; k--) {
    if (stack[k] === marker) {
      stack.splice(k, 1)
      return
    }
  }
}
