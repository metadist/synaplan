import { describe, expect, it } from 'vitest'
import { paletteShortcutLabel } from '@/composables/search/shortcut'
import de from '@/i18n/locales/de/core.json'
import en from '@/i18n/locales/en/core.json'

describe('paletteShortcutLabel', () => {
  it('shows the command key on Apple, in every language', () => {
    expect(paletteShortcutLabel(en.search.palette.modifier, 'MacIntel')).toBe('⌘K')
    expect(paletteShortcutLabel(de.search.palette.modifier, 'iPad')).toBe('⌘K')
  })

  it('shows Ctrl on Windows and Linux when the locale says Ctrl', () => {
    expect(paletteShortcutLabel(en.search.palette.modifier, 'Win32')).toBe('Ctrl K')
    expect(paletteShortcutLabel(en.search.palette.modifier, 'Linux x86_64')).toBe('Ctrl K')
  })

  it('shows Strg on Windows when the locale is German', () => {
    expect(paletteShortcutLabel(de.search.palette.modifier, 'Win32')).toBe('Strg K')
  })
})
