<script setup lang="ts">
import { Icon } from '@iconify/vue'

/**
 * Canonical page header — the ONE way every page starts its content area.
 *
 * Hierarchy (same on every page):
 *   [icon chip]  h1 title            [actions]
 *                subtitle
 *   [tabs / secondary chrome via default slot]
 *
 * Rules:
 * - Rendered directly on the page background, never inside a surface-card.
 * - Exactly one h1 per page, always text-2xl font-semibold.
 * - `icon` takes an Iconify name; heroicon components go into the #icon slot.
 * - Action buttons / filter pills belong in #actions (top-right, wraps under
 *   the title on small screens).
 * - Tab bars (TabNav) go into the default slot so they always sit between the
 *   header and the content cards.
 */
defineProps<{
  title: string
  subtitle?: string
  /** Iconify icon name, e.g. "heroicons:chat-bubble-left-right". */
  icon?: string
}>()
</script>

<template>
  <header class="mb-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
      <div class="flex items-start gap-3 min-w-0">
        <span
          v-if="icon || $slots.icon"
          class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-[var(--brand-alpha-light)] txt-brand"
          aria-hidden="true"
        >
          <slot name="icon">
            <Icon v-if="icon" :icon="icon" class="w-5 h-5" />
          </slot>
        </span>
        <div class="min-w-0">
          <h1 class="text-2xl font-semibold txt-primary">{{ title }}</h1>
          <p v-if="subtitle || $slots.subtitle" class="txt-secondary text-sm mt-1">
            <slot name="subtitle">{{ subtitle }}</slot>
          </p>
        </div>
      </div>
      <div
        v-if="$slots.actions"
        class="flex flex-wrap items-center gap-2 sm:flex-shrink-0 w-full sm:w-auto"
      >
        <slot name="actions" />
      </div>
    </div>
    <div v-if="$slots.default" class="mt-4">
      <slot />
    </div>
  </header>
</template>
