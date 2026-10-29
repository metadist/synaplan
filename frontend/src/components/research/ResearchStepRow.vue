<template>
  <li
    class="text-sm min-w-0 processing-enter"
    :class="current ? 'txt-primary' : 'txt-secondary'"
    :data-step-kind="kind"
    :data-expanded="open ? 'true' : 'false'"
    :data-testid="current ? 'text-research-activity' : undefined"
  >
    <component
      :is="expandable ? 'button' : 'div'"
      :type="expandable ? 'button' : undefined"
      class="flex items-start gap-2 w-full min-w-0 text-left rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--brand)]"
      :aria-expanded="expandable ? open : undefined"
      :data-testid="expandable ? 'btn-research-step' : undefined"
      @click="expandable && (expanded = !expanded)"
    >
      <component
        :is="icon"
        class="w-4 h-4 flex-shrink-0 mt-0.5"
        :class="[iconClass, current ? 'animate-pulse' : '']"
        aria-hidden="true"
      />
      <span class="flex-1 min-w-0">
        <!-- A thought is the model's own sentence: it reads as prose. -->
        <span v-if="kind === 'thought'" class="block" :class="open ? '' : 'truncate'">{{
          shownTitle
        }}</span>
        <span v-else class="flex items-baseline gap-2 min-w-0">
          <span class="flex-shrink-0" :class="current ? 'font-medium' : ''">{{ title }}</span>
          <span
            v-if="detail"
            class="opacity-80 min-w-0"
            :class="open ? '' : 'truncate'"
            :title="open ? undefined : detail"
            >{{ shownDetail }}</span
          >
        </span>
        <span
          v-if="open && shownAside"
          class="block text-xs txt-tertiary mt-0.5"
          data-testid="text-research-step-aside"
          >{{ shownAside }}</span
        >
      </span>
    </component>
  </li>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRevealText } from './useRevealText'

/**
 * One step of a phase. The step happening right now is open: its words run
 * in as they are written, with the model's sentence about it underneath.
 * Every other step folds to one line once it is done, so a long phase stays
 * readable; a click opens it again.
 */
const props = defineProps<{
  kind: string
  title: string
  detail: string
  aside: string
  icon: unknown
  iconClass: string
  /** The row happening right now in a running phase. */
  current: boolean
}>()

const expanded = ref(false)

const open = computed(() => props.current || expanded.value)
/** A done row only opens when there is more to read than its one line. */
const expandable = computed(
  () =>
    !props.current && (props.aside !== '' || props.kind === 'thought' || props.detail.length > 40)
)

const live = () => props.current
const shownTitle = useRevealText(() => (props.kind === 'thought' ? props.title : ''), live)
const shownDetail = useRevealText(() => props.detail, live)
const shownAside = useRevealText(
  () => (shownDetail.value.length >= props.detail.length ? props.aside : ''),
  live
)
</script>
