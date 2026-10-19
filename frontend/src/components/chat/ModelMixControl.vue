<template>
  <!-- Hidden while the expanded card is showing in the empty chat: the card
       visually "turns into" this button when it collapses. -->
  <div v-if="!mixStore.inlinePanelVisible" ref="rootEl" class="relative">
    <button
      type="button"
      class="flex items-center justify-center w-10 h-10 rounded-full shadow-lg active:scale-95 transition-transform surface-card txt-primary"
      :aria-label="buttonLabel"
      :title="buttonLabel"
      aria-haspopup="menu"
      :aria-expanded="open"
      data-testid="btn-model-mix"
      @click="toggle"
    >
      <ModelMixIcon :icon="mixStore.activeMix?.icon ?? { kind: 'brand' }" :size="22" />
    </button>

    <Transition name="fade">
      <div
        v-if="open"
        class="absolute right-0 top-12 z-50 w-80 max-w-[calc(100vw-1.5rem)]"
        :style="panelShift > 0 ? { right: `-${panelShift}px` } : undefined"
        data-testid="dropdown-model-mix"
      >
        <ModelMixPanel @select="open = false" />
      </div>
    </Transition>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import ModelMixPanel from '@/components/chat/ModelMixPanel.vue'
import ModelMixIcon from '@/components/chat/ModelMixIcon.vue'
import { useModelMixStore } from '@/stores/modelMix'
import { triggerHapticImpact } from '@/services/api/nativeHaptics'

/**
 * Collapsed speed config: a round button styled exactly like the incognito
 * toggle it sits next to, wearing the active mix's logo so the user always
 * sees which model family answers. Tapping it drops the mix list back down.
 */
const { t } = useI18n()
const mixStore = useModelMixStore()

const open = ref(false)
const rootEl = ref<HTMLElement | null>(null)

const buttonLabel = computed(() =>
  t('modelMix.activeLabel', { name: t(`modelMix.mixes.${mixStore.activeMixId}`) })
)

/** Matches `w-80` and the 0.75rem side gap of `max-w-[calc(100vw-1.5rem)]`. */
const PANEL_WIDTH_PX = 320
const VIEWPORT_GAP_PX = 12

/**
 * The panel hangs right-aligned under the button, but the button is not at
 * the screen edge (the incognito toggle sits to its right). On a narrow
 * screen that pushes the panel past the left edge, so slide it right just
 * far enough to keep the gap on both sides.
 */
const panelShift = ref(0)

const placePanel = () => {
  const buttonRight = rootEl.value?.getBoundingClientRect().right
  if (buttonRight === undefined) return
  const width = Math.min(PANEL_WIDTH_PX, window.innerWidth - 2 * VIEWPORT_GAP_PX)
  const overflowLeft = VIEWPORT_GAP_PX + width - buttonRight
  const roomRight = window.innerWidth - VIEWPORT_GAP_PX - buttonRight
  panelShift.value = Math.max(0, Math.min(overflowLeft, roomRight))
}

const toggle = () => {
  triggerHapticImpact('light')
  if (!open.value) placePanel()
  open.value = !open.value
}

const onWindowResize = () => {
  if (open.value) placePanel()
}

const onDocumentPointerDown = (event: PointerEvent) => {
  if (!open.value) return
  if (rootEl.value && !rootEl.value.contains(event.target as Node)) {
    open.value = false
  }
}

const onDocumentKeydown = (event: KeyboardEvent) => {
  if (event.key === 'Escape') open.value = false
}

onMounted(() => {
  void mixStore.ensureLoaded()
  document.addEventListener('pointerdown', onDocumentPointerDown)
  document.addEventListener('keydown', onDocumentKeydown)
  window.addEventListener('resize', onWindowResize)
})

onUnmounted(() => {
  document.removeEventListener('pointerdown', onDocumentPointerDown)
  document.removeEventListener('keydown', onDocumentKeydown)
  window.removeEventListener('resize', onWindowResize)
})
</script>
