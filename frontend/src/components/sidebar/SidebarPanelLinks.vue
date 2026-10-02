<template>
  <div class="flex flex-col gap-4 px-2 py-2">
    <section
      v-for="group in groups"
      :key="group.key ?? 'flat'"
      :data-testid="group.key ? `section-sidebar-group-${group.key}` : undefined"
    >
      <h3
        v-if="group.group"
        class="px-2 pt-1 pb-1 text-[13px] font-semibold txt-secondary"
      >
        {{ group.group }}
      </h3>
      <div class="flex flex-col gap-0.5">
        <router-link
          v-for="child in group.items"
          :key="child.key"
          :to="child.path"
          class="flex items-center gap-2 min-h-11 px-2 rounded-lg text-[15px] transition-colors"
          :class="
            child.key === activeChildKey
              ? 'text-[var(--brand)] bg-[var(--brand)]/[0.08] font-medium'
              : 'txt-primary hover:bg-black/[0.04] dark:hover:bg-white/[0.04]'
          "
          :aria-current="child.key === activeChildKey ? 'page' : undefined"
          :data-testid="`link-sidebar-v2-${child.key}`"
        >
          <span class="flex-1 truncate">{{ child.label }}</span>
          <span
            v-if="child.badge"
            class="text-[11px] font-semibold px-1.5 py-0.5 rounded-full bg-[var(--status-warning-muted)] text-[var(--status-warning-text)] tabular-nums"
          >
            {{ child.badge }}
          </span>
        </router-link>
      </div>
    </section>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { isNavChildActive, type NavChildGroup } from '@/composables/useNavItems'

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
