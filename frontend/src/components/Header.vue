<template>
  <header
    class="bg-header relative z-50 border-b border-black/[0.04] dark:border-white/[0.04]"
    data-testid="comp-app-header"
  >
    <div
      class="flex items-center justify-between px-4 sm:px-6 py-4"
      data-testid="section-header-bar"
    >
      <div class="flex items-center gap-3 flex-1" data-testid="section-header-left">
        <button
          class="md:hidden icon-ghost h-[44px] min-w-[44px] flex items-center justify-center rounded-lg"
          aria-label="Toggle sidebar"
          data-testid="btn-sidebar-toggle"
          @click="sidebarStore.toggleMobile()"
        >
          <Bars3Icon class="w-6 h-6" />
        </button>
        <slot name="left" />
      </div>

      <div class="flex items-center gap-3" data-testid="section-header-actions">
        <!-- Mode Switcher -->
        <button
          class="dropdown-trigger"
          :title="appModeStore.isEasyMode ? 'Switch to Advanced Mode' : 'Switch to Easy Mode'"
          data-testid="btn-mode-toggle"
          @click="appModeStore.toggleMode()"
        >
          <AdjustmentsHorizontalIcon class="w-5 h-5" />
          <span class="hidden md:inline text-sm font-medium">{{
            appModeStore.isEasyMode ? 'Easy' : 'Advanced'
          }}</span>
        </button>

        <div ref="langSelectorRef" class="relative isolate" data-testid="section-language-selector">
          <button
            class="dropdown-trigger"
            aria-label="Select language"
            data-testid="btn-language-toggle"
            @click="isLangOpen = !isLangOpen"
          >
            <GlobeAltIcon class="w-5 h-5" />
            <span class="hidden md:inline text-sm font-medium">{{
              selectedLanguage.toUpperCase()
            }}</span>
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
              role="menuitem"
              :class="[
                'dropdown-item',
                selectedLanguage === lang.value ? 'dropdown-item--active' : '',
              ]"
              @click="selectLanguage(lang.value)"
            >
              {{ lang.label }}
            </button>
          </div>
        </div>

        <button
          :aria-label="themeLabel"
          class="icon-ghost h-[44px] min-w-[44px] flex items-center justify-center rounded-lg"
          data-testid="btn-theme-toggle"
          @click="cycleTheme"
        >
          <SunIcon v-if="themeStore.theme.value === 'light'" class="w-5 h-5" />
          <MoonIcon v-else class="w-5 h-5" />
        </button>

        <button
          :aria-label="designVariant.isV2.value ? $t('header.switchToV1') : $t('header.switchToV2')"
          :title="designVariant.isV2.value ? $t('header.switchToV1') : $t('header.switchToV2')"
          class="icon-ghost h-[44px] min-w-[44px] flex items-center justify-center rounded-lg"
          data-testid="btn-design-toggle"
          @click="designVariant.toggleVariant()"
        >
          <SwatchIcon class="w-5 h-5" />
          <span class="hidden md:inline text-sm font-medium ml-1">
            {{ designVariant.isV2.value ? 'V2' : 'V1' }}
          </span>
        </button>
      </div>
    </div>
  </header>
</template>

<script setup lang="ts">
import { computed, ref, onMounted, onBeforeUnmount } from 'vue'
import {
  SunIcon,
  MoonIcon,
  GlobeAltIcon,
  Bars3Icon,
  AdjustmentsHorizontalIcon,
  SwatchIcon,
} from '@heroicons/vue/24/outline'
import { useTheme } from '../composables/useTheme'
import { useDesignVariant } from '../composables/useDesignVariant'
import { useSidebarStore } from '../stores/sidebar'
import { useAppModeStore } from '../stores/appMode'
import { useI18n } from 'vue-i18n'

const themeStore = useTheme()
const designVariant = useDesignVariant()
const sidebarStore = useSidebarStore()
const appModeStore = useAppModeStore()
const { locale } = useI18n()
const isLangOpen = ref(false)
const langSelectorRef = ref<HTMLElement | null>(null)

const languages = [
  { value: 'de', label: 'DE' },
  { value: 'en', label: 'EN' },
  { value: 'es', label: 'ES' },
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
  if (!isLangOpen.value) return

  const target = event.target as HTMLElement
  if (langSelectorRef.value && !langSelectorRef.value.contains(target)) {
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
