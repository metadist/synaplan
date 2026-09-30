/** The palette shortcut as the user's keyboard labels it: `⌘K` on Apple, `Ctrl K` elsewhere. */
export function paletteShortcutLabel(): string {
  if (typeof navigator === 'undefined') return 'Ctrl K'
  const platform =
    (navigator as Navigator & { userAgentData?: { platform?: string } }).userAgentData?.platform ??
    navigator.platform ??
    ''
  return /mac|iphone|ipad/i.test(platform) ? '⌘K' : 'Ctrl K'
}
