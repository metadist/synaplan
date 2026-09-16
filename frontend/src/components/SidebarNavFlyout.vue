<template>
  <div class="fixed inset-0 z-[200]" data-testid="overlay-sidebar-v2-nav" @click="emit('close')">
    <!-- First level: groups (Manage) or a flat child list (Operate / Plugins). -->
    <div
      ref="primaryRef"
      role="menu"
      class="fixed w-56 dropdown-panel origin-top-left overflow-hidden"
      :style="panelStyle"
      data-testid="dropdown-sidebar-v2-nav"
      @click.stop
    >
      <div class="px-3 py-2 border-b border-light-border/10 dark:border-dark-border/10">
        <p class="text-xs font-semibold txt-secondary uppercase tracking-wider">
          {{ item.label }}
        </p>
      </div>

      <div v-if="nested" class="py-1">
        <button
          v-for="section in groups"
          :key="section.key ?? section.group ?? 'group'"
          :ref="(el) => setGroupBtnRef(el, section.key)"
          type="button"
          role="menuitem"
          class="flex items-center gap-2.5 w-full px-3 py-2 text-sm text-left transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-[var(--brand)] focus-visible:outline-offset-[-2px]"
          :class="groupRowClass(section)"
          :aria-haspopup="section.key ? 'menu' : undefined"
          :aria-expanded="section.key ? activeGroupKey === section.key : undefined"
          :aria-controls="section.key ? 'sidebar-nav-submenu' : undefined"
          :data-testid="`btn-sidebar-v2-group-${section.key}`"
          @pointerenter="openGroup(section.key)"
          @focus="openGroup(section.key)"
          @click="openGroup(section.key)"
        >
          <span
            class="w-1.5 h-1.5 rounded-full flex-shrink-0"
            :class="isGroupCurrent(section) ? 'bg-[var(--brand)]' : 'bg-current opacity-20'"
          />
          <span class="flex-1 truncate">{{ section.group }}</span>
          <ChevronRightIcon class="w-4 h-4 flex-shrink-0 opacity-60" aria-hidden="true" />
        </button>
      </div>

      <div v-else class="py-1 max-h-[60vh] overflow-y-auto scroll-thin">
        <router-link
          v-for="child in item.children"
          :key="child.key"
          :to="child.path"
          role="menuitem"
          :data-testid="`link-sidebar-v2-${child.key}`"
          class="flex items-center gap-2.5 px-3 py-2 text-sm transition-colors"
          :class="leafClass(child.path)"
          @click="emit('close')"
        >
          <span
            class="w-1.5 h-1.5 rounded-full flex-shrink-0"
            :class="currentPath === child.path ? 'bg-[var(--brand)]' : 'bg-current opacity-20'"
          />
          <span class="flex-1 truncate">{{ child.label }}</span>
          <span
            v-if="child.badge"
            class="text-[10px] px-1.5 py-0.5 rounded-full bg-yellow-100 dark:bg-yellow-900/30 text-yellow-700 dark:text-yellow-200 font-medium"
          >
            {{ child.badge }}
          </span>
        </router-link>
      </div>
    </div>

    <!-- Second level: links for the open Manage group. -->
    <div
      v-if="nested && activeGroup"
      id="sidebar-nav-submenu"
      role="menu"
      class="fixed w-64 dropdown-panel origin-top-left overflow-hidden"
      :style="subStyle"
      data-testid="dropdown-sidebar-v2-nav-sub"
      @click.stop
    >
      <div class="px-3 py-2 border-b border-light-border/10 dark:border-dark-border/10">
        <p class="text-xs font-semibold txt-secondary uppercase tracking-wider">
          {{ activeGroup.group }}
        </p>
      </div>
      <div class="py-1">
        <router-link
          v-for="child in activeGroup.items"
          :key="child.key"
          :to="child.path"
          role="menuitem"
          :data-testid="`link-sidebar-v2-${child.key}`"
          class="flex items-center gap-2.5 px-3 py-2 text-sm transition-colors"
          :class="leafClass(child.path)"
          @click="emit('close')"
        >
          <span
            class="w-1.5 h-1.5 rounded-full flex-shrink-0"
            :class="currentPath === child.path ? 'bg-[var(--brand)]' : 'bg-current opacity-20'"
          />
          <span class="flex-1 truncate">{{ child.label }}</span>
          <span
            v-if="child.badge"
            class="text-[10px] px-1.5 py-0.5 rounded-full bg-yellow-100 dark:bg-yellow-900/30 text-yellow-700 dark:text-yellow-200 font-medium"
          >
            {{ child.badge }}
          </span>
        </router-link>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { ChevronRightIcon } from '@heroicons/vue/24/outline'
import {
  groupNavChildren,
  hasNestedNavGroups,
  isNavChildActive,
  type NavChildGroup,
  type NavItem,
} from '../composables/useNavItems'

const props = defineProps<{
  item: NavItem
  panelStyle: Record<string, string>
  currentPath: string
}>()

const emit = defineEmits<{
  close: []
}>()

const primaryRef = ref<HTMLElement | null>(null)
const groupBtnRefs = ref<Record<string, HTMLElement | null>>({})
const activeGroupKey = ref<string | null>(null)
const subStyle = ref<Record<string, string>>({})

const groups = computed(() => groupNavChildren(props.item.children))
const nested = computed(() => hasNestedNavGroups(props.item.children))
const activeGroup = computed(
  () => groups.value.find((section) => section.key === activeGroupKey.value) ?? null
)

const setGroupBtnRef = (el: unknown, key: string | null) => {
  if (!key) return
  groupBtnRefs.value[key] = el as HTMLElement | null
}

const isGroupCurrent = (section: NavChildGroup): boolean =>
  section.items.some((child) => isNavChildActive(child, props.item.path, props.currentPath))

const groupRowClass = (section: NavChildGroup): string => {
  if (activeGroupKey.value === section.key || isGroupCurrent(section)) {
    return 'text-[var(--brand)] bg-[var(--brand)]/[0.06] font-medium'
  }
  return 'txt-secondary hover:txt-primary hover:bg-black/[0.03] dark:hover:bg-white/[0.03]'
}

const leafClass = (path: string): string =>
  props.currentPath === path
    ? 'text-[var(--brand)] bg-[var(--brand)]/[0.06] font-medium'
    : 'txt-secondary hover:txt-primary hover:bg-black/[0.03] dark:hover:bg-white/[0.03]'

const groupKeyForPath = (path: string): string | null => {
  for (const section of groups.value) {
    if (
      section.key &&
      section.items.some((child) => isNavChildActive(child, props.item.path, path))
    ) {
      return section.key
    }
  }
  return null
}

const positionSubmenu = async () => {
  await nextTick()
  const primary = primaryRef.value
  const key = activeGroupKey.value
  const btn = key ? groupBtnRefs.value[key] : null
  if (!primary || !btn || !activeGroup.value) return

  const primaryRect = primary.getBoundingClientRect()
  const btnRect = btn.getBoundingClientRect()
  const width = 256
  const gap = 4
  let left = primaryRect.right + gap
  if (left + width > window.innerWidth - 8) {
    left = primaryRect.left - width - gap
  }
  const estimatedHeight = (activeGroup.value.items.length + 1) * 40 + 16
  const top = Math.max(8, Math.min(btnRect.top, window.innerHeight - estimatedHeight - 8))
  subStyle.value = {
    left: `${Math.max(8, left)}px`,
    top: `${top}px`,
  }
}

const openGroup = (key: string | null) => {
  if (!key || activeGroupKey.value === key) return
  activeGroupKey.value = key
}

watch(
  () => props.item.key,
  () => {
    activeGroupKey.value = nested.value ? groupKeyForPath(props.currentPath) : null
  },
  { immediate: true }
)

watch(activeGroupKey, () => {
  void positionSubmenu()
})

onMounted(() => {
  void positionSubmenu()
})
</script>
