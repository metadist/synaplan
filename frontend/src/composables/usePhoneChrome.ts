/**
 * Phone chrome vs desktop rail.
 *
 * Tailwind `md` (768px) is width-only, so a phone in landscape (e.g. 844×390)
 * used to flip to the desktop rail. That rail is a fixed 80px column that then
 * adds `padding-left: env(safe-area-inset-left)` for the notch — on landscape
 * the padding eats most of the 80px, the layout root clips overflow, and a
 * sliver of the menu peeks out from behind the chat card.
 *
 * Phone chrome (hamburger + push-drawer) stays on when the viewport is
 * narrow OR short. Short covers landscape phones (height ~375–440) without
 * pulling tablets back into the drawer (iPad landscape height ≥768).
 *
 * Keep the CSS media queries in `style.css` / `MainLayout.vue` in lockstep
 * with these strings.
 */
export const PHONE_CHROME_MQ = '(max-width: 767px), (max-height: 519px)'
export const DESKTOP_CHROME_MQ = '(min-width: 768px) and (min-height: 520px)'

export function isPhoneChromeSize(width: number, height: number): boolean {
  return width < 768 || height < 520
}

export function matchesPhoneChrome(): boolean {
  return window.matchMedia(PHONE_CHROME_MQ).matches
}
