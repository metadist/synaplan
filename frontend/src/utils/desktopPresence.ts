/**
 * Whether a paired computer is actually reachable.
 *
 * Must stay equal to DesktopJobContract::NEXT_CALL_IDLE_SECONDS (and
 * DesktopDevicePresence::CHECK_IN_SECONDS). The app checks in at that
 * interval when it has no work, so anything older is not connected.
 */
export const DESKTOP_CHECK_IN_SECONDS = 180

export const DESKTOP_CHECK_IN_MINUTES = DESKTOP_CHECK_IN_SECONDS / 60

export type DesktopPresence = 'online' | 'away' | 'never' | 'revoked'

export function desktopPresence(status: string, lastSeen: number, nowSec: number): DesktopPresence {
  if (status !== 'active') return 'revoked'
  if (lastSeen <= 0) return 'never'
  if (nowSec - lastSeen <= DESKTOP_CHECK_IN_SECONDS) return 'online'
  return 'away'
}
