/**
 * How a dropped chat stream tries to continue.
 *
 * Three tries, then the turn is a transport drop. A stream that carried
 * `id:` lines resumes after the last one. A stream without ids replays
 * from the start and the bubble is rebuilt, so nothing is appended twice.
 */

export const RECONNECT_ATTEMPTS = 3

export function reconnectDelayMs(attempt: number): number {
  const delays = [300, 800, 1600]
  return delays[attempt] ?? 1600
}

export function resumeFrom(
  sawEventId: boolean,
  lastSeq: number
): { from: number; rebuild: boolean } {
  if (sawEventId && lastSeq > 0) {
    return { from: lastSeq, rebuild: false }
  }
  return { from: 0, rebuild: true }
}
