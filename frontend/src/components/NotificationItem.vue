<template>
  <div
    ref="rootEl"
    :class="[
      'flex items-start gap-3 p-4 rounded-xl shadow-lg border backdrop-blur-sm transition-all duration-300',
      'min-w-[320px] max-w-[420px] touch-pan-y',
      dragging ? 'cursor-grabbing' : 'cursor-grab',
      typeClasses[notification.type],
    ]"
    :style="dragStyle"
    data-testid="comp-notification-item"
    :data-notification-type="notification.type"
    @pointerdown="onPointerDown"
    @pointermove="onPointerMove"
    @pointerup="onPointerUp"
    @pointercancel="onPointerUp"
    @transitionend="onTransitionEnd"
    @click.capture="onClickCapture"
  >
    <div class="flex-shrink-0 mt-0.5">
      <CheckCircleIcon v-if="notification.type === 'success'" class="w-5 h-5" />
      <XCircleIcon v-else-if="notification.type === 'error'" class="w-5 h-5" />
      <ExclamationTriangleIcon v-else-if="notification.type === 'warning'" class="w-5 h-5" />
      <InformationCircleIcon v-else class="w-5 h-5" />
    </div>

    <img
      v-if="notification.thumbnailUrl"
      :src="notification.thumbnailUrl"
      alt=""
      class="flex-shrink-0 w-10 h-10 rounded object-cover"
    />

    <div class="flex-1 min-w-0">
      <p v-if="notification.title" class="text-sm font-semibold break-words">
        {{ notification.title }}
      </p>
      <p class="text-sm font-medium break-words">{{ notification.message }}</p>
      <button
        v-if="notification.action"
        type="button"
        class="mt-2 inline-flex items-center rounded bg-white/20 px-2 py-1 text-xs font-medium hover:bg-white/30 transition-colors cursor-pointer"
        data-testid="btn-notification-action"
        @click="handleAction"
      >
        {{ notification.action.label }}
      </button>
    </div>

    <button
      class="flex-shrink-0 p-0.5 rounded hover:bg-black/10 dark:hover:bg-white/10 transition-colors cursor-pointer"
      data-testid="btn-notification-close"
      @click="$emit('close')"
    >
      <XMarkIcon class="w-4 h-4" />
    </button>
  </div>
</template>

<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref } from 'vue'
import {
  CheckCircleIcon,
  XCircleIcon,
  ExclamationTriangleIcon,
  InformationCircleIcon,
  XMarkIcon,
} from '@heroicons/vue/24/outline'
import type { Notification } from '@/composables/useNotification'

/** Movement that distinguishes a swipe from a tap. */
const SWIPE_SLOP_PX = 8
/** Rightward travel that dismisses the toast on release. */
const SWIPE_DISMISS_PX = 72
/** Matches `duration-300` so a missed transitionend still closes the toast. */
const SWIPE_DISMISS_MS = 320

const props = defineProps<{
  notification: Notification
}>()

const emit = defineEmits<{
  close: []
}>()

const rootEl = ref<HTMLElement | null>(null)
const offsetX = ref(0)
const dragging = ref(false)
const dismissing = ref(false)
const settling = ref(false)
const travelPx = ref(280)

let pointerId: number | null = null
let startX = 0
let startY = 0
let axis: 'undecided' | 'horizontal' | 'vertical' = 'undecided'
let suppressClick = false
let closed = false
let dismissTimer = 0

const dragStyle = computed(() => {
  if (!dragging.value && !settling.value && !dismissing.value) return undefined
  return {
    transform: `translateX(${offsetX.value}px)`,
    opacity: String(Math.max(0, 1 - offsetX.value / travelPx.value)),
    ...(dragging.value ? { transition: 'none' } : {}),
  }
})

function handleAction(): void {
  props.notification.action?.onClick()
  emit('close')
}

function closeFromSwipe(): void {
  if (closed) return
  closed = true
  window.clearTimeout(dismissTimer)
  emit('close')
}

function capturePointer(event: PointerEvent): void {
  const el = rootEl.value
  if (!el?.setPointerCapture) return
  try {
    el.setPointerCapture(event.pointerId)
  } catch {
    // The pointer can already be gone on a very fast release.
  }
}

function releasePointer(event: PointerEvent): void {
  const el = rootEl.value
  if (!el?.hasPointerCapture?.(event.pointerId)) return
  el.releasePointerCapture(event.pointerId)
}

function onPointerDown(event: PointerEvent): void {
  if (event.button !== 0 || dismissing.value) return
  pointerId = event.pointerId
  startX = event.clientX
  startY = event.clientY
  axis = 'undecided'
  suppressClick = false
  travelPx.value = rootEl.value?.offsetWidth || 280
}

function onPointerMove(event: PointerEvent): void {
  if (event.pointerId !== pointerId || dismissing.value) return
  const dx = event.clientX - startX
  const dy = event.clientY - startY
  if (axis === 'undecided') {
    if (Math.abs(dx) < SWIPE_SLOP_PX && Math.abs(dy) < SWIPE_SLOP_PX) return
    // Only a rightward swipe dismisses. Vertical movement stays a scroll.
    axis = dx > SWIPE_SLOP_PX && Math.abs(dx) > Math.abs(dy) ? 'horizontal' : 'vertical'
    if (axis !== 'horizontal') {
      return
    }
    // Capture only once the gesture is a swipe, so a click on the action
    // still reaches the button.
    capturePointer(event)
    dragging.value = true
    settling.value = true
    suppressClick = true
  }
  if (axis !== 'horizontal') return
  offsetX.value = Math.max(0, dx)
}

function onPointerUp(event: PointerEvent): void {
  if (event.pointerId !== pointerId) return
  pointerId = null
  releasePointer(event)
  if (axis !== 'horizontal') return
  const passed = offsetX.value >= SWIPE_DISMISS_PX
  dragging.value = false
  void nextTick(() => {
    // Commit the dragged position with transitions on, then move to the target
    // so the slide-out or snap-back actually animates.
    void rootEl.value?.offsetWidth
    if (passed) {
      dismissing.value = true
      offsetX.value = travelPx.value + 48
      dismissTimer = window.setTimeout(closeFromSwipe, SWIPE_DISMISS_MS)
      return
    }
    offsetX.value = 0
  })
}

function onTransitionEnd(event: TransitionEvent): void {
  // Dismiss on the slide itself. Opacity ends too, and some environments omit
  // propertyName — ignore only a named transition that is not the transform.
  if (dismissing.value) {
    if (event.propertyName && event.propertyName !== 'transform') return
    closeFromSwipe()
    return
  }
  if (event.propertyName === 'transform' && offsetX.value === 0) settling.value = false
}

function onClickCapture(event: MouseEvent): void {
  if (!suppressClick) return
  event.preventDefault()
  event.stopPropagation()
  suppressClick = false
}

onBeforeUnmount(() => {
  window.clearTimeout(dismissTimer)
})

const typeClasses = {
  success: 'bg-green-500/90 dark:bg-green-600/90 text-white border-green-600',
  error: 'bg-red-500/90 dark:bg-red-600/90 text-white border-red-600',
  warning: 'bg-orange-500/90 dark:bg-orange-600/90 text-white border-orange-600',
  info: 'bg-blue-500/90 dark:bg-blue-600/90 text-white border-blue-600',
}
</script>
