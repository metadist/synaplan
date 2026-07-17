<template>
  <div :data-testid="testid || undefined">
    <!-- Desktop / tablet: horizontal pill tabs -->
    <nav class="hidden md:flex tab-nav" :aria-label="ariaLabel" role="tablist">
      <component
        :is="tab.to ? 'router-link' : 'button'"
        v-for="tab in tabs"
        :key="tab.id"
        :to="tab.to || undefined"
        :type="tab.to ? undefined : 'button'"
        role="tab"
        :aria-selected="modelValue === tab.id"
        :class="['tab-nav-item', modelValue === tab.id && 'tab-nav-item--active']"
        :data-testid="tab.testid"
        @click="onSelect(tab)"
      >
        <Icon v-if="tab.icon" :icon="tab.icon" class="w-4 h-4 flex-shrink-0" aria-hidden="true" />
        <span>{{ tab.label }}</span>
        <span
          v-if="tab.badge != null && tab.badge > 0"
          class="inline-flex items-center justify-center min-w-[1.125rem] h-[1.125rem] px-1 rounded-full text-[10px] font-bold bg-[var(--brand)] text-white"
        >
          {{ tab.badge }}
        </span>
      </component>
    </nav>

    <!-- Mobile: dropdown replaces the tab row -->
    <div ref="dropdownRef" class="md:hidden relative mb-6">
      <button
        type="button"
        class="dropdown-trigger surface-card w-full justify-between border border-light-border/20 dark:border-dark-border/10"
        :aria-expanded="menuOpen"
        aria-haspopup="menu"
        :data-testid="mobileTriggerTestid"
        @click="toggleMenu"
      >
        <span class="flex items-center gap-2 txt-primary font-medium min-w-0">
          <Icon
            v-if="activeTab?.icon"
            :icon="activeTab.icon"
            class="w-5 h-5 flex-shrink-0"
            aria-hidden="true"
          />
          <span class="truncate">{{ activeTab?.label }}</span>
          <span
            v-if="activeTab?.badge != null && activeTab.badge > 0"
            class="inline-flex items-center justify-center min-w-[1.125rem] h-[1.125rem] px-1 rounded-full text-[10px] font-bold bg-[var(--brand)] text-white"
          >
            {{ activeTab.badge }}
          </span>
        </span>
        <Icon
          icon="mdi:chevron-down"
          class="w-5 h-5 flex-shrink-0 transition-transform"
          :class="{ 'rotate-180': menuOpen }"
          aria-hidden="true"
        />
      </button>

      <div
        v-if="menuOpen"
        class="dropdown-panel absolute left-0 right-0 top-full mt-1 z-30 flex flex-col gap-1"
        role="menu"
        :data-testid="mobileMenuTestid"
      >
        <button
          v-for="tab in tabs"
          :key="tab.id"
          type="button"
          role="menuitem"
          :class="['dropdown-item', modelValue === tab.id && 'dropdown-item--active']"
          :data-testid="tab.testid ? `${tab.testid}-mobile` : undefined"
          @click="selectMobile(tab)"
        >
          <Icon v-if="tab.icon" :icon="tab.icon" class="w-5 h-5 flex-shrink-0" aria-hidden="true" />
          <span class="flex-1 text-left">{{ tab.label }}</span>
          <span
            v-if="tab.badge != null && tab.badge > 0"
            class="inline-flex items-center justify-center min-w-[1.125rem] h-[1.125rem] px-1 rounded-full text-[10px] font-bold bg-[var(--brand)] text-white"
          >
            {{ tab.badge }}
          </span>
        </button>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { Icon } from '@iconify/vue'
import { useRouter } from 'vue-router'

export interface TabNavItem {
  id: string
  label: string
  icon?: string
  testid?: string
  /** When set, desktop tabs render as router-link; click still emits update. */
  to?: string
  badge?: number
}

const props = withDefaults(
  defineProps<{
    tabs: TabNavItem[]
    modelValue: string
    ariaLabel?: string
    testid?: string
    mobileTriggerTestid?: string
    mobileMenuTestid?: string
  }>(),
  {
    ariaLabel: 'Tabs',
    testid: '',
    mobileTriggerTestid: 'tab-nav-mobile-trigger',
    mobileMenuTestid: 'tab-nav-mobile-menu',
  }
)

const emit = defineEmits<{
  'update:modelValue': [id: string]
}>()

const router = useRouter()
const menuOpen = ref(false)
const dropdownRef = ref<HTMLElement | null>(null)

const activeTab = computed(
  () => props.tabs.find((tab) => tab.id === props.modelValue) ?? props.tabs[0]
)

function onSelect(tab: TabNavItem) {
  emit('update:modelValue', tab.id)
}

function toggleMenu() {
  menuOpen.value = !menuOpen.value
}

function selectMobile(tab: TabNavItem) {
  menuOpen.value = false
  emit('update:modelValue', tab.id)
  if (tab.to) {
    router.push(tab.to)
  }
}

function handleOutsideClick(event: MouseEvent) {
  if (!menuOpen.value) return
  if (dropdownRef.value && !dropdownRef.value.contains(event.target as Node)) {
    menuOpen.value = false
  }
}

function handleEscape(event: KeyboardEvent) {
  if (event.key === 'Escape') menuOpen.value = false
}

onMounted(() => {
  document.addEventListener('click', handleOutsideClick)
  document.addEventListener('keydown', handleEscape)
})

onBeforeUnmount(() => {
  document.removeEventListener('click', handleOutsideClick)
  document.removeEventListener('keydown', handleEscape)
})
</script>
