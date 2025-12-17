import { defineStore } from 'pinia'
import { ref, computed } from 'vue'

export type AppMode = 'easy' | 'advanced'

export const useAppModeStore = defineStore('appMode', () => {
  const mode = ref<AppMode>((localStorage.getItem('app_mode') as AppMode) || 'easy')

  const isEasyMode = computed(() => mode.value === 'easy')
  const isAdvancedMode = computed(() => mode.value === 'advanced')

  function setMode(newMode: AppMode) {
    mode.value = newMode
    localStorage.setItem('app_mode', newMode)
  }

  function toggleMode() {
    setMode(mode.value === 'easy' ? 'advanced' : 'easy')
  }

  return {
    mode,
    isEasyMode,
    isAdvancedMode,
    setMode,
    toggleMode,
  }
})
