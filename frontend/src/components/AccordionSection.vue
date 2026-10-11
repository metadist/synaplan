<script setup lang="ts">
import { computed } from 'vue'
import { ChevronDownIcon } from '@heroicons/vue/24/outline'

interface Props {
  /** DOM id of the panel wrapper — used for deep links and jump-nav scroll. */
  panelId: string
  title: string
  open: boolean
  highlighted?: boolean
  headerTestid?: string
  testid?: string
}

const props = withDefaults(defineProps<Props>(), {
  highlighted: false,
  headerTestid: undefined,
  testid: undefined,
})

defineEmits<{
  toggle: []
}>()

const bodyId = computed(() => `${props.panelId}-body`)
</script>

<template>
  <section
    :id="panelId"
    class="scroll-mt-6"
    :class="
      highlighted ? 'outline outline-2 outline-offset-[-2px] outline-[var(--brand)]' : undefined
    "
    :data-testid="testid"
    :data-open="open ? 'true' : 'false'"
  >
    <div class="accordion-row flex items-stretch hover-surface transition-colors">
      <h3 class="m-0 flex-1 min-w-0">
        <button
          type="button"
          class="stack-row w-full flex items-center justify-between gap-3 bg-transparent px-5 py-4 text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-[var(--brand)]"
          :aria-expanded="open"
          :aria-controls="bodyId"
          :data-testid="headerTestid"
          @click="$emit('toggle')"
        >
          <span class="flex items-center gap-2 min-w-0">
            <slot name="leading" />
            <span class="text-lg font-semibold txt-primary truncate">{{ title }}</span>
            <slot name="badge" />
          </span>
          <ChevronDownIcon
            v-if="!$slots.actions"
            class="w-5 h-5 txt-secondary flex-shrink-0 transition-transform"
            :class="open && 'rotate-180'"
            aria-hidden="true"
          />
        </button>
      </h3>
      <div v-if="$slots.actions" class="flex items-center gap-1 pr-3 flex-shrink-0">
        <slot name="actions" />
        <button
          type="button"
          class="bg-transparent p-2 rounded-xl focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-[var(--brand)]"
          :aria-expanded="open"
          :aria-controls="bodyId"
          :aria-label="title"
          @click="$emit('toggle')"
        >
          <ChevronDownIcon
            class="w-5 h-5 txt-secondary transition-transform"
            :class="open && 'rotate-180'"
            aria-hidden="true"
          />
        </button>
      </div>
    </div>
    <div v-show="open" :id="bodyId" class="p-5">
      <slot />
    </div>
  </section>
</template>

<style scoped>
/* The row owns the highlight. Action buttons keep their border, but a fill
   of their own would stop the wash short of the right edge — including while
   the pointer is on Edit, refresh, or the chevron. */
.accordion-row:hover :deep(.btn-secondary),
.accordion-row:hover :deep(.btn-secondary:hover) {
  background: transparent;
  box-shadow: none;
}
</style>
