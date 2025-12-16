<template>
  <header class="bg-header relative z-50" data-testid="comp-app-header">
    <div class="flex items-center justify-between px-6 py-4" data-testid="section-header-bar">
      <div class="flex items-center gap-3 flex-1" data-testid="section-header-left">
        <button
          @click="sidebarStore.toggleMobile()"
          class="md:hidden icon-ghost h-[44px] min-w-[44px] flex items-center justify-center rounded-lg"
          aria-label="Toggle sidebar"
          data-testid="btn-sidebar-toggle"
        >
          <Bars3Icon class="w-6 h-6" />
        </button>
        <slot name="left" />
      </div>

      <div class="flex items-center gap-3" data-testid="section-header-actions">
        <!-- Mode Switcher -->
        <button
          @click="appModeStore.toggleMode()"
          class="dropdown-trigger"
          :title="appModeStore.isEasyMode ? 'Switch to Advanced Mode' : 'Switch to Easy Mode'"
          data-testid="btn-mode-toggle"
        >
          <AdjustmentsHorizontalIcon class="w-5 h-5" />
          <span class="hidden md:inline text-sm font-medium">{{ appModeStore.isEasyMode ? 'Easy' : 'Advanced' }}</span>
        </button>

        <div class="relative isolate" data-testid="section-language-selector">
          <button
            @click="isLangOpen = !isLangOpen"
            class="dropdown-trigger"
            aria-label="Select language"
            data-testid="btn-language-toggle"
          >
            <GlobeAltIcon class="w-5 h-5" />
            <span class="hidden md:inline text-sm font-medium">{{ selectedLanguage.toUpperCase() }}</span>
          </button>

          <div
            v-if="isLangOpen"
            role="menu"
            class="absolute top-full mt-2 right-0 min-w-[220px] max-h-[60vh] overflow-auto scroll-thin dropdown-panel z-[70]"
            data-testid="dropdown-language-menu"
          >
            <button
              v-for="lang in languages"
              :key="lang.value"
              @click="selectLanguage(lang.value)"
              role="menuitem"
              :class="[
                'dropdown-item',
                selectedLanguage === lang.value
                  ? 'dropdown-item--active'
                  : '',
              ]"
            >
              {{ lang.label }}
            </button>
          </div>
        </div>

        <button
          @click="cycleTheme"
          :aria-label="themeLabel"
          class="icon-ghost h-[44px] min-w-[44px] flex items-center justify-center rounded-lg"
          data-testid="btn-theme-toggle"
        >
          <SunIcon v-if="themeStore.theme.value === 'light'" class="w-5 h-5" />
          <MoonIcon v-else class="w-5 h-5" />
        </button>
      </div>
    </div>
  </header>
</template>

<script setup lang="ts">
import { computed, ref, onMounted, onBeforeUnmount } from 'vue'
import { SunIcon, MoonIcon, GlobeAltIcon, Bars3Icon, AdjustmentsHorizontalIcon } from '@heroicons/vue/24/outline'
import { useTheme } from '../composables/useTheme'
import { useSidebarStore } from '../stores/sidebar'
import { useAppModeStore } from '../stores/appMode'
import { useI18n } from 'vue-i18n'

const themeStore = useTheme()
const sidebarStore = useSidebarStore()
const appModeStore = useAppModeStore()
const { locale } = useI18n()
const isLangOpen = ref(false)

const languages = [
  { value: 'en', label: 'EN' },
  { value: 'de', label: 'DE' },
  { value: 'tr', label: 'TR' },
]

const selectedLanguage = computed({
  get: () => locale.value,
  set: (value) => {
    locale.value = value
    localStorage.setItem('language', value)
  },
})

const themeLabel = computed(() => {
  return themeStore.theme.value === 'light' ? 'Switch to dark mode' : 'Switch to light mode'
})

const selectLanguage = (value: string) => {
  selectedLanguage.value = value
  isLangOpen.value = false
}

const cycleTheme = () => {
  if (themeStore.theme.value === 'light') {
    themeStore.setTheme('dark')
  } else {
    themeStore.setTheme('light')
  }
}

const handleClickOutside = (event: MouseEvent) => {
  const target = event.target as HTMLElement
  const container = target.closest('.relative')
  if (isLangOpen.value && (!container || !container.contains(event.target as Node))) {
    isLangOpen.value = false
  }
}

const handleKeydown = (event: KeyboardEvent) => {
  if (event.key === 'Escape' && isLangOpen.value) {
    isLangOpen.value = false
  }
}

onMounted(() => {
  document.addEventListener('click', handleClickOutside)
  document.addEventListener('keydown', handleKeydown)
})

onBeforeUnmount(() => {
  document.removeEventListener('click', handleClickOutside)
  document.removeEventListener('keydown', handleKeydown)
})
</script>
