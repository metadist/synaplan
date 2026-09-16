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

const toggle = () => {
  triggerHapticImpact('light')
  open.value = !open.value
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
})

onUnmounted(() => {
  document.removeEventListener('pointerdown', onDocumentPointerDown)
  document.removeEventListener('keydown', onDocumentKeydown)
})
</script>
