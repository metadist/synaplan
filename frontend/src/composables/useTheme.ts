import { computed, ref, watchEffect } from 'vue'
export type Theme = 'light' | 'dark' | 'oled' | 'system'
const THEMES: Theme[] = ['light', 'dark', 'oled', 'system']

function storedTheme(): Theme {
  const raw = localStorage.getItem('theme')
  return THEMES.includes(raw as Theme) ? (raw as Theme) : 'light'
}

// Default to bright (light) mode when the user hasn't picked a theme yet.
// A stored preference (light / dark / oled / system) always wins.
const theme = ref<Theme>(storedTheme())

const mq = matchMedia('(prefers-color-scheme: dark)')
const systemDark = ref(mq.matches)
mq.addEventListener('change', (e) => (systemDark.value = e.matches))

/** Resolved mode: true when the app is actually rendering a dark palette. */
const isDark = computed(
  () =>
    theme.value === 'dark' ||
    theme.value === 'oled' ||
    (theme.value === 'system' && systemDark.value)
)
const isOled = computed(() => theme.value === 'oled')

const apply = () => {
  document.documentElement.classList.toggle('dark', isDark.value)
  document.documentElement.classList.toggle('theme-oled', isOled.value)
  localStorage.setItem('theme', theme.value)
}

watchEffect(apply)
apply()

const THEME_ORDER: Theme[] = ['light', 'dark', 'oled', 'system']

export function useTheme() {
  return {
    theme,
    isDark,
    isOled,
    setTheme: (t: Theme) => (theme.value = t),
    cycleTheme: () => {
      const index = THEME_ORDER.indexOf(theme.value)
      theme.value = THEME_ORDER[(index + 1) % THEME_ORDER.length]
    },
  }
}
