<template>
  <Transition
    enter-active-class="duration-300 ease-out"
    enter-from-class="translate-y-full opacity-0"
    enter-to-class="translate-y-0 opacity-100"
    leave-active-class="duration-200 ease-in"
    leave-from-class="translate-y-0 opacity-100"
    leave-to-class="translate-y-full opacity-0"
  >
    <div
      v-if="show"
      ref="barEl"
      class="fixed bottom-0 z-40 pointer-events-none"
      :style="barStyle"
      data-testid="section-unsaved-bar"
    >
      <div class="pb-4 md:pb-6">
        <div
          class="surface-card shadow-xl rounded-xl p-4 md:p-6 pointer-events-auto border-2 border-[var(--brand)]"
          data-testid="comp-unsaved-card"
        >
          <div class="flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
            <div class="flex items-start gap-3">
              <div
                class="flex-shrink-0 w-10 h-10 rounded-full bg-[var(--brand)]/10 flex items-center justify-center"
              >
                <ExclamationCircleIcon class="w-6 h-6" style="color: var(--brand)" />
              </div>
              <div>
                <p class="txt-primary font-semibold text-base md:text-lg">
                  {{ $t('unsavedChanges.title') }}
                </p>
                <p class="txt-secondary text-sm mt-0.5">
                  {{ $t('unsavedChanges.description') }}
                </p>
              </div>
            </div>

            <div
              class="flex items-center gap-3 w-full md:w-auto"
              data-testid="section-unsaved-actions"
            >
              <button
                :disabled="isSaving"
                class="flex-1 md:flex-none px-6 py-3 rounded-xl border-2 border-light-border/30 dark:border-dark-border/20 txt-primary hover:bg-black/5 dark:hover:bg-white/5 transition-colors font-medium text-base min-h-[48px] disabled:opacity-50 disabled:cursor-not-allowed focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--brand)] focus-visible:ring-offset-2"
                data-testid="btn-unsaved-discard"
                @click="handleDiscard"
              >
                {{ $t('unsavedChanges.discard') }}
              </button>
              <button
                v-if="showPreview"
                :disabled="isSaving"
                class="flex-1 md:flex-none px-6 py-3 rounded-xl border-2 border-[var(--brand)]/30 txt-primary hover:bg-[var(--brand)]/10 transition-colors font-medium text-base min-h-[48px] disabled:opacity-50 disabled:cursor-not-allowed focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--brand)] focus-visible:ring-offset-2"
                data-testid="btn-unsaved-preview"
                @click="handlePreview"
              >
                {{ $t('widget.previewWidget') }}
              </button>
              <button
                :disabled="isSaving"
                class="flex-1 md:flex-none btn-primary px-8 py-3 rounded-xl font-semibold text-base min-h-[48px] shadow-lg hover:shadow-xl transition-shadow disabled:opacity-70 disabled:cursor-not-allowed focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-[var(--brand)] flex items-center justify-center gap-2"
                data-testid="btn-unsaved-save"
                @click="handleSave"
              >
                <svg
                  v-if="isSaving"
                  class="animate-spin h-5 w-5"
                  xmlns="http://www.w3.org/2000/svg"
                  fill="none"
                  viewBox="0 0 24 24"
                >
                  <circle
                    class="opacity-25"
                    cx="12"
                    cy="12"
                    r="10"
                    stroke="currentColor"
                    stroke-width="4"
                  ></circle>
                  <path
                    class="opacity-75"
                    fill="currentColor"
                    d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
                  ></path>
                </svg>
                <span>{{
                  isSaving ? $t('unsavedChanges.saving') : $t('unsavedChanges.save')
                }}</span>
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </Transition>
</template>

<script setup lang="ts">
import { computed, getCurrentInstance, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import { ExclamationCircleIcon } from '@heroicons/vue/24/outline'
import { matchesPhoneChrome } from '@/composables/usePhoneChrome'

const props = defineProps<{
  show: boolean
  showPreview?: boolean
}>()

const emit = defineEmits<{
  save: []
  discard: []
  preview: []
}>()

const isSaving = ref(false)
const instance = getCurrentInstance()
const barEl = ref<HTMLElement | null>(null)
const frame = ref<{ left: string; width: string } | null>(null)
let resizeObserver: ResizeObserver | null = null

// Fixed to the viewport, this bar used to span the window and cover the
// sidebars. It stays pinned to the bottom, but only as wide as the content
// column it is rendered in. Phone chrome makes that column the containing
// block (will-change: transform), so the offset is relative to it.
const barStyle = computed(() => {
  // Only the rise is animated. Tailwind v4 moves the bar with the `translate`
  // property, not `transform`. Animating `left` (the old transition-all)
  // made it travel in from the bottom-left corner.
  const motion = { transitionProperty: 'translate, opacity' }
  if (!frame.value) {
    return { visibility: 'hidden' as const, ...motion }
  }
  return { left: frame.value.left, width: frame.value.width, ...motion }
})

function contentOriginLeft(): number {
  if (typeof window.matchMedia !== 'function' || !matchesPhoneChrome()) return 0
  const layer = document.querySelector('[data-testid="section-main-shell"]')
  if (!(layer instanceof HTMLElement)) return 0
  const layerRect = layer.getBoundingClientRect()
  const borderLeft = Number.parseFloat(getComputedStyle(layer).borderLeftWidth) || 0
  return layerRect.left + borderLeft
}

function placeBar(): boolean {
  const parent = barEl.value?.parentElement
  if (!parent) return false
  const rect = parent.getBoundingClientRect()
  const style = getComputedStyle(parent)
  const padLeft = Number.parseFloat(style.paddingLeft) || 0
  const padRight = Number.parseFloat(style.paddingRight) || 0
  const borderLeft = Number.parseFloat(style.borderLeftWidth) || 0
  const borderRight = Number.parseFloat(style.borderRightWidth) || 0
  const width = rect.width - borderLeft - borderRight - padLeft - padRight
  if (width <= 0) return false
  const left = rect.left + borderLeft + padLeft - contentOriginLeft()
  frame.value = { left: `${left}px`, width: `${width}px` }
  return true
}

function stopTracking() {
  resizeObserver?.disconnect()
  resizeObserver = null
  window.removeEventListener('resize', placeBar)
}

function startTracking() {
  stopTracking()
  const placed = placeBar()
  if (!placed) requestAnimationFrame(() => placeBar())
  if (typeof ResizeObserver !== 'undefined') {
    resizeObserver = new ResizeObserver(() => placeBar())
    const parent = barEl.value?.parentElement
    if (parent) resizeObserver.observe(parent)
    const main = document.getElementById('main-content')
    if (main && main !== parent) resizeObserver.observe(main)
  }
  window.addEventListener('resize', placeBar)
}

type SaveListener = () => void | Promise<void>

function saveListeners(): SaveListener[] {
  const raw = instance?.vnode.props?.onSave
  if (typeof raw === 'function') return [raw as SaveListener]
  if (Array.isArray(raw)) {
    return raw.filter((listener): listener is SaveListener => typeof listener === 'function')
  }
  return []
}

// emit() drops the listener's promise, so a failed save left isSaving true
// until the bar hid and both buttons stayed disabled. Call the listener and
// release the bar when it is still open after the attempt.
const handleSave = async () => {
  if (isSaving.value) return
  isSaving.value = true
  try {
    for (const listener of saveListeners()) {
      try {
        await listener()
      } catch {
        // One listener failing must not skip the others, and must not
        // leave the bar on Saving. The page shows the failure.
      }
    }
  } finally {
    await nextTick()
    if (props.show) {
      isSaving.value = false
    }
  }
}

const handleDiscard = () => {
  if (isSaving.value) return
  emit('discard')
}

const handlePreview = () => {
  if (isSaving.value) return
  emit('preview')
}

const handleKeydown = (e: KeyboardEvent) => {
  if (!props.show || isSaving.value) return

  // Cmd+S / Ctrl+S to save
  if ((e.metaKey || e.ctrlKey) && e.key === 's') {
    e.preventDefault()
    handleSave()
    return
  }

  // Escape to discard
  if (e.key === 'Escape') {
    e.preventDefault()
    handleDiscard()
  }
}

watch(
  () => props.show,
  async (visible) => {
    if (!visible) {
      isSaving.value = false
      stopTracking()
      frame.value = null
      return
    }
    await nextTick()
    startTracking()
  }
)

onMounted(async () => {
  document.addEventListener('keydown', handleKeydown)
  if (props.show) {
    await nextTick()
    startTracking()
  }
})

onUnmounted(() => {
  document.removeEventListener('keydown', handleKeydown)
  stopTracking()
})
</script>
