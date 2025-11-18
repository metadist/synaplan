import { ref, watchEffect } from 'vue'
type Theme = 'light' | 'dark' | 'system'
const theme = ref<Theme>((localStorage.getItem('theme') as Theme) || 'system')

const apply = () => {
  const root = document.documentElement
  const systemDark = matchMedia('(prefers-color-scheme: dark)').matches
  const isDark = theme.value === 'dark' || (theme.value === 'system' && systemDark)
  root.classList.toggle('dark', isDark)
  localStorage.setItem('theme', theme.value)
}

const mq = matchMedia('(prefers-color-scheme: dark)')
mq.addEventListener('change', () => theme.value === 'system' && apply())

watchEffect(apply)
apply()

export function useTheme() {
  return { theme, setTheme: (t: Theme) => (theme.value = t) }
}
