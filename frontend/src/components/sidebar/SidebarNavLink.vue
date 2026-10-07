<template>
  <router-link
    :to="to"
    class="sidebar-nav-link flex min-h-11 items-center gap-3 rounded-xl pl-2 pr-2.5 text-[15px] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--brand)]"
    :class="[active ? 'is-active font-medium' : 'font-normal', locked && 'opacity-60']"
    :aria-current="active ? 'page' : undefined"
    :data-testid="testId"
  >
    <span
      v-if="icon"
      class="sidebar-nav-link__icon inline-flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-lg"
      :class="danger && 'is-danger'"
    >
      <component :is="icon" class="h-[18px] w-[18px]" aria-hidden="true" />
    </span>
    <span class="min-w-0 flex-1 truncate">{{ label }}</span>
    <span
      v-if="badge"
      class="flex-shrink-0 text-[11px] font-semibold px-1.5 py-0.5 rounded-full bg-[var(--status-warning-muted)] text-[var(--status-warning-text)] tabular-nums"
    >
      {{ badge }}
    </span>
    <LockClosedIcon
      v-if="locked"
      class="h-3.5 w-3.5 flex-shrink-0 text-[var(--status-warning-text)]"
      aria-hidden="true"
    />
  </router-link>
</template>

<script setup lang="ts">
import type { Component } from 'vue'
import type { RouteLocationRaw } from 'vue-router'
import { LockClosedIcon } from '@heroicons/vue/24/outline'

defineProps<{
  to: RouteLocationRaw
  label: string
  active: boolean
  icon?: Component
  badge?: string | number
  locked?: boolean
  /** Red glyph for a destination that leads to a destructive action. */
  danger?: boolean
  testId?: string
}>()
</script>

<style scoped>
/* Same language as the rail icons: a brand tint on hover, a stronger tint on
   the current page. The tile carries the accent so the label stays calm. */
.sidebar-nav-link {
  color: var(--txt-primary);
  transition:
    background-color 0.15s ease,
    color 0.15s ease,
    box-shadow 0.15s ease;
}

.sidebar-nav-link:hover {
  background: rgba(0, 63, 199, 0.07);
}

.sidebar-nav-link.is-active {
  color: var(--brand);
  background: rgba(0, 63, 199, 0.12);
  box-shadow: inset 0 0 0 1px rgba(0, 63, 199, 0.14);
}

.dark .sidebar-nav-link:hover {
  background: rgba(107, 143, 214, 0.1);
}

.dark .sidebar-nav-link.is-active {
  background: rgba(107, 143, 214, 0.14);
  box-shadow: inset 0 0 0 1px rgba(107, 143, 214, 0.22);
}

.sidebar-nav-link__icon {
  background: var(--bg-chip);
  color: var(--txt-secondary);
  transition:
    background-color 0.15s ease,
    color 0.15s ease,
    box-shadow 0.15s ease;
}

.sidebar-nav-link:hover .sidebar-nav-link__icon {
  background: rgba(0, 63, 199, 0.12);
  color: var(--brand);
}

.dark .sidebar-nav-link:hover .sidebar-nav-link__icon {
  background: rgba(107, 143, 214, 0.18);
}

/* White on #003fc7 is 8.3:1. The dark-theme brand is a light blue, so it
   takes dark ink instead (#0b1220 on #6d9ae0 is 6.5:1). */
.sidebar-nav-link.is-active .sidebar-nav-link__icon {
  background: var(--brand);
  color: #fff;
  box-shadow: 0 2px 8px rgba(0, 63, 199, 0.3);
}

.dark .sidebar-nav-link.is-active .sidebar-nav-link__icon {
  color: #0b1220;
  box-shadow: 0 2px 10px rgba(109, 154, 224, 0.25);
}

.sidebar-nav-link:not(.is-active) .sidebar-nav-link__icon.is-danger {
  color: #dc2626;
}

.dark .sidebar-nav-link:not(.is-active) .sidebar-nav-link__icon.is-danger {
  color: #f87171;
}

@media (prefers-reduced-motion: reduce) {
  .sidebar-nav-link,
  .sidebar-nav-link__icon {
    transition: none;
  }
}
</style>
