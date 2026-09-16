<template>
  <div
    class="surface-card rounded-2xl shadow-lg border border-[var(--border)] p-2"
    data-testid="panel-model-mix"
  >
    <p class="px-3 pt-2 pb-1 text-xs font-semibold uppercase tracking-wide txt-secondary">
      {{ t('modelMix.title') }}
    </p>

    <button
      v-for="mix in mixStore.resolvedMixes"
      :key="mix.id"
      type="button"
      class="w-full flex items-start gap-3 px-3 py-2.5 rounded-xl text-left transition-colors"
      :class="[
        mix.id === mixStore.activeMixId
          ? 'bg-[var(--bg-secondary)]'
          : 'hover:bg-[var(--bg-secondary)]',
        !mix.available || mixStore.applying ? 'opacity-50 cursor-not-allowed' : '',
      ]"
      :disabled="!mix.available || mixStore.applying"
      :aria-pressed="mix.id === mixStore.activeMixId"
      :data-testid="`btn-model-mix-${mix.id}`"
      @click="pick(mix)"
    >
      <ModelMixIcon :icon="mix.icon" :size="22" class="mt-0.5" />
      <span class="flex-1 min-w-0">
        <span class="flex items-center gap-2 text-sm font-semibold txt-primary">
          {{ t(`modelMix.mixes.${mix.id}`) }}
          <Icon
            v-if="mix.id === mixStore.activeMixId"
            icon="mdi:check-circle"
            class="w-4 h-4 txt-brand flex-shrink-0"
            aria-hidden="true"
          />
          <svg
            v-else-if="mixStore.applying && pendingId === mix.id"
            class="w-3.5 h-3.5 animate-spin txt-brand flex-shrink-0"
            fill="none"
            viewBox="0 0 24 24"
            aria-hidden="true"
          >
            <circle
              class="opacity-25"
              cx="12"
              cy="12"
              r="10"
              stroke="currentColor"
              stroke-width="4"
            />
            <path
              class="opacity-75"
              fill="currentColor"
              d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"
            />
          </svg>
        </span>
        <span class="block text-xs txt-secondary leading-snug mt-0.5">
          {{ subtitle(mix) }}
        </span>
      </span>
    </button>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Icon } from '@iconify/vue'
import ModelMixIcon from '@/components/chat/ModelMixIcon.vue'
import { useModelMixStore } from '@/stores/modelMix'
import { useNotification } from '@/composables/useNotification'
import type { ModelMixId, ResolvedModelMix } from '@/utils/modelMixes'

/**
 * The speed-config list: six model mixes, each with the provider logo, the
 * mix name, and — in small type — the models it would activate on THIS
 * installation. Rendered inline in the empty chat and inside the round
 * button's dropdown, so all state lives in the modelMix store.
 */
const emit = defineEmits<{ select: [id: ModelMixId] }>()

const { t } = useI18n()
const mixStore = useModelMixStore()
const { success, error } = useNotification()

/** Which row is being applied, so only that row shows the spinner. */
const pendingId = ref<ModelMixId | null>(null)

onMounted(() => {
  void mixStore.ensureLoaded()
})

const subtitle = (mix: ResolvedModelMix): string => {
  if (mix.resetsToRecommended) return t('modelMix.defaultDescription')
  if (!mix.available) return t('modelMix.unavailable')
  return mix.modelNames.join(' · ')
}

const pick = async (mix: ResolvedModelMix) => {
  // Re-applying the active mix is allowed on purpose: it restores the preset
  // after manual tweaks on /ai/models. It still closes the panel either way.
  pendingId.value = mix.id
  try {
    const applied = await mixStore.applyMix(mix.id)
    if (applied) {
      success(t('modelMix.applied', { name: t(`modelMix.mixes.${mix.id}`) }))
      emit('select', mix.id)
    }
  } catch {
    error(t('modelMix.applyError'))
  } finally {
    pendingId.value = null
  }
}
</script>
