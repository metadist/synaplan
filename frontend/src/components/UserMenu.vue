<template>
  <div class="relative isolate" data-testid="comp-user-menu">
    <button
      class="dropdown-trigger w-full"
      data-testid="btn-user-menu-toggle"
      @click="isOpen = !isOpen"
    >
      <div
        class="w-8 h-8 rounded-full surface-chip flex items-center justify-center text-sm font-medium flex-shrink-0"
      >
        <span class="txt-primary">{{ initials }}</span>
      </div>
      <span v-if="!collapsed" class="text-sm truncate flex-1 text-left">{{ email }}</span>
      <ChevronDownIcon v-if="!collapsed" class="w-4 h-4 flex-shrink-0" />
    </button>

    <Transition
      enter-active-class="transition ease-out duration-100"
      enter-from-class="transform opacity-0 scale-95"
      enter-to-class="transform opacity-100 scale-100"
      leave-active-class="transition ease-in duration-75"
      leave-from-class="transform opacity-100 scale-100"
      leave-to-class="transform opacity-0 scale-95"
    >
      <div
        v-if="isOpen"
        v-click-outside="() => (isOpen = false)"
        role="menu"
        class="absolute bottom-full left-0 mb-2 w-full min-w-[220px] max-h-[60vh] overflow-auto scroll-thin dropdown-panel z-[70]"
        data-testid="dropdown-user-menu"
      >
        <button
          role="menuitem"
          class="dropdown-item"
          data-testid="btn-user-profile-settings"
          @click="handleProfileSettings"
        >
          <UserCircleIcon class="w-5 h-5" />
          <span>{{ $t('nav.profile') }}</span>
        </button>
        <button
          v-if="isMemoryServiceAvailable"
          role="menuitem"
          class="dropdown-item"
          :class="{ 'opacity-50 cursor-wait': isMemoriesLoading }"
          :disabled="isMemoriesLoading"
          data-testid="btn-user-memories"
          @click="handleOpenMemories"
        >
          <Icon icon="mdi:brain" class="w-5 h-5" />
          <span>{{ $t('pageTitles.memories') }}</span>
          <Icon v-if="isMemoriesLoading" icon="mdi:loading" class="w-4 h-4 animate-spin ml-auto" />
        </button>
        <button
          role="menuitem"
          class="dropdown-item"
          data-testid="btn-user-logout"
          @click="handleLogout"
        >
          <ArrowRightOnRectangleIcon class="w-5 h-5" />
          <span>{{ $t('settings.logout') }}</span>
        </button>
      </div>
    </Transition>
  </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import {
  UserCircleIcon,
  ArrowRightOnRectangleIcon,
  ChevronDownIcon,
} from '@heroicons/vue/24/outline'
import { Icon } from '@iconify/vue'
import { useAuth } from '@/composables/useAuth'
import { useConfigStore } from '@/stores/config'
import { useMemoriesStore } from '@/stores/userMemories'

interface Props {
  email?: string
  collapsed?: boolean
}

interface Emits {
  (e: 'openMemories'): void
}

const props = withDefaults(defineProps<Props>(), {
  email: 'guest@synaplan.com',
  collapsed: false,
})

const emit = defineEmits<Emits>()

const router = useRouter()
const { logout } = useAuth()
const configStore = useConfigStore()
const memoriesStore = useMemoriesStore()
const isOpen = ref(false)

// Check if memory service is available
const isMemoryServiceAvailable = computed(() => configStore.features?.memoryService ?? false)
const isMemoriesLoading = computed(() => {
  // Loading if either config store is checking service OR memories store is loading
  return configStore.features?.memoryServiceLoading || memoriesStore.loading
})

const initials = computed(() => {
  const parts = props.email.split('@')[0].split('.')
  if (parts.length >= 2) {
    return (parts[0][0] + parts[1][0]).toUpperCase()
  }
  return props.email.slice(0, 2).toUpperCase()
})

const handleProfileSettings = () => {
  isOpen.value = false
  router.push('/profile')
}

const handleOpenMemories = () => {
  isOpen.value = false
  emit('openMemories')
}

const handleLogout = async () => {
  isOpen.value = false
  await logout()
  router.push('/login')
}

const vClickOutside = {
  mounted(el: any, binding: any) {
    el.clickOutsideEvent = (event: Event) => {
      if (!(el === event.target || el.contains(event.target as Node))) {
        binding.value()
      }
    }
    el.keydownEvent = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        binding.value()
      }
    }
    setTimeout(() => {
      document.addEventListener('click', el.clickOutsideEvent)
      document.addEventListener('keydown', el.keydownEvent)
    }, 0)
  },
  unmounted(el: any) {
    document.removeEventListener('click', el.clickOutsideEvent)
    document.removeEventListener('keydown', el.keydownEvent)
  },
}
</script>
