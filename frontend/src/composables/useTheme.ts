import { computed, ref, watchEffect } from 'vue'
type Theme = 'light' | 'dark' | 'system'
// Default to bright (light) mode when the user hasn't picked a theme yet.
// A stored preference (light / dark / system) always wins.
const theme = ref<Theme>((localStorage.getItem('theme') as Theme) || 'light')

const mq = matchMedia('(prefers-color-scheme: dark)')
const systemDark = ref(mq.matches)
mq.addEventListener('change', (e) => (systemDark.value = e.matches))

/** Resolved mode: true when the app is actually rendering the dark theme. */
const isDark = computed(
  () => theme.value === 'dark' || (theme.value === 'system' && systemDark.value)
)

const apply = () => {
  document.documentElement.classList.toggle('dark', isDark.value)
  localStorage.setItem('theme', theme.value)
}

watchEffect(apply)
apply()

export function useTheme() {
  return { theme, isDark, setTheme: (t: Theme) => (theme.value = t) }
}
