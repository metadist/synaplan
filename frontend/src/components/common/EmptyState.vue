<script setup lang="ts">
import type { Component } from 'vue'
import type { RouteLocationRaw } from 'vue-router'

/**
 * The one empty state (UX contract U5): one sentence, at most one hint and
 * one primary action. Pass `to` for a link or listen to `action` for a
 * button; without `actionLabel` no action renders.
 */
const props = withDefaults(
  defineProps<{
    title: string
    hint?: string
    icon?: Component
    actionLabel?: string
    actionIcon?: Component
    to?: RouteLocationRaw
    /** Renders inside an existing card without its own surface. */
    bare?: boolean
    testId?: string
  }>(),
  {
    hint: undefined,
    icon: undefined,
    actionLabel: undefined,
    actionIcon: undefined,
    to: undefined,
    bare: false,
    testId: 'empty-state',
  }
)

const emit = defineEmits<{ action: [] }>()
</script>

<template>
  <div
    :class="[
      'flex flex-col items-center text-center gap-3',
      props.bare ? 'py-8 px-4' : 'surface-card rounded-2xl p-8 sm:p-12',
    ]"
    :data-testid="props.testId"
  >
    <span
      v-if="props.icon"
      class="flex h-12 w-12 items-center justify-center rounded-2xl bg-[var(--brand-alpha-light)] txt-brand"
      aria-hidden="true"
    >
      <component :is="props.icon" class="w-6 h-6" />
    </span>
    <p class="txt-primary font-medium max-w-md">{{ props.title }}</p>
    <p v-if="props.hint" class="txt-secondary text-sm max-w-md">{{ props.hint }}</p>
    <slot />
    <template v-if="props.actionLabel">
      <router-link
        v-if="props.to"
        :to="props.to"
        class="btn-primary px-4 py-2.5 text-sm font-medium inline-flex items-center gap-2 mt-1"
        data-testid="btn-empty-state-action"
      >
        <component :is="props.actionIcon" v-if="props.actionIcon" class="w-4 h-4" />
        {{ props.actionLabel }}
      </router-link>
      <button
        v-else
        type="button"
        class="btn-primary px-4 py-2.5 text-sm font-medium inline-flex items-center gap-2 mt-1"
        data-testid="btn-empty-state-action"
        @click="emit('action')"
      >
        <component :is="props.actionIcon" v-if="props.actionIcon" class="w-4 h-4" />
        {{ props.actionLabel }}
      </button>
    </template>
  </div>
</template>
