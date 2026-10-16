/**
 * The guest trial's quota: the remaining-messages banner, and the end of the
 * trial, where the empty answer bubble gives way to the signup offer (#1128).
 * Registered for guest turns only.
 */
import type { GuestTurn, StreamHandlers, StreamStatusHandler } from '../streamTurn'

const guestRemaining: StreamStatusHandler<GuestTurn> = (data, turn) => {
  turn.guest.updateCount(
    data.remaining as number,
    data.maxMessages as number,
    data.limitReached as boolean
  )
  turn.guest.showBanner()
}

const guestLimitReached: StreamStatusHandler<GuestTurn> = (_data, turn) => {
  turn.smoother.cancel()
  turn.history.removeMessage(turn.messageId)
  turn.guest.offerSignup()
  turn.status.clear()
  turn.run.release()
}

export const guestHandlers: StreamHandlers<GuestTurn> = {
  guest_remaining: guestRemaining,
  guest_limit_reached: guestLimitReached,
}
