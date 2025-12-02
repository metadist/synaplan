<template>
  <div class="relative isolate" data-testid="comp-user-menu">
    <button
      @click="isOpen = !isOpen"
      class="dropdown-trigger w-full"
      data-testid="btn-user-menu-toggle"
    >
      <div class="w-8 h-8 rounded-full surface-chip flex items-center justify-center text-sm font-medium flex-shrink-0">
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
        v-click-outside="() => isOpen = false"
        role="menu"
        class="absolute bottom-full left-0 mb-2 w-full min-w-[220px] max-h-[60vh] overflow-auto scroll-thin dropdown-panel z-[70]"
        data-testid="dropdown-user-menu"
      >
        <button
          @click="handleProfileSettings"
          role="menuitem"
          class="dropdown-item"
          data-testid="btn-user-profile-settings"
        >
          <UserCircleIcon class="w-5 h-5" />
          <span>Profile settings</span>
        </button>
        <button
          @click="handleLogout"
          role="menuitem"
          class="dropdown-item"
          data-testid="btn-user-logout"
        >
          <ArrowRightOnRectangleIcon class="w-5 h-5" />
          <span>Log out</span>
        </button>
      </div>
    </Transition>
  </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import { UserCircleIcon, ArrowRightOnRectangleIcon, ChevronDownIcon } from '@heroicons/vue/24/outline'
import { useAuth } from '@/composables/useAuth'

interface Props {
  email?: string
  collapsed?: boolean
}

const props = withDefaults(defineProps<Props>(), {
  email: 'guest@synaplan.com',
  collapsed: false,
})

const router = useRouter()
const { logout } = useAuth()
const isOpen = ref(false)

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
