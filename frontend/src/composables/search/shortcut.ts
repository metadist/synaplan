/**
 * The palette shortcut as the user's keyboard labels it.
 * Apple keyboards show ⌘ in every language. Everywhere else the modifier
 * word is localized (`Ctrl`, or `Strg` on a German keyboard).
 */
export function paletteShortcutLabel(modifier = 'Ctrl', platform = detectPlatform()): string {
  if (/mac|iphone|ipad/i.test(platform)) return '⌘K'
  const word = modifier.trim() || 'Ctrl'
  return `${word} K`
}

function detectPlatform(): string {
  if (typeof navigator === 'undefined') return ''
  const nav = navigator as Navigator & { userAgentData?: { platform?: string } }
  return nav.userAgentData?.platform ?? navigator.platform ?? ''
}
