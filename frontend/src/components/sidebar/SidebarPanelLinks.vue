<template>
  <div class="flex flex-col gap-5 px-3 pt-3 pb-3">
    <section
      v-for="group in groups"
      :key="group.key ?? 'flat'"
      :data-testid="group.key ? `section-sidebar-group-${group.key}` : undefined"
    >
      <h3
        v-if="group.group"
        class="px-2.5 pb-1.5 text-[11px] font-semibold uppercase tracking-wider txt-secondary"
      >
        {{ group.group }}
      </h3>
      <div class="flex flex-col gap-0.5">
        <SidebarNavLink
          v-for="child in group.items"
          :key="child.key"
          :to="child.path"
          :label="child.label"
          :icon="child.icon"
          :badge="child.badge"
          :active="child.key === activeChildKey"
          :test-id="`link-sidebar-v2-${child.key}`"
        />
      </div>
    </section>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { isNavChildActive, type NavChildGroup } from '@/composables/useNavItems'
import SidebarNavLink from './SidebarNavLink.vue'

const props = defineProps<{
  groups: NavChildGroup[]
  sectionPath: string
}>()

const route = useRoute()

const activeChildKey = computed(() => {
  const matches = props.groups
    .flatMap((group) => group.items)
    .filter((child) => isNavChildActive(child, props.sectionPath, route.path))
  matches.sort((a, b) => b.path.length - a.path.length)
  return matches[0]?.key ?? null
})
</script>
