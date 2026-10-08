/** Keys under companionLinks.greetings, one per line, same in every locale. */
export const EMPTY_GREETING_COUNT = 10

/**
 * Picks a greeting for this page load. `random` is in [0, 1); the result stays
 * put so a later store update cannot swap the heading mid-visit.
 */
export function pickEmptyGreetingKey(random: number): number {
  const scaled = Math.min(0.999999, Math.max(0, random))
  return Math.floor(scaled * EMPTY_GREETING_COUNT) + 1
}
