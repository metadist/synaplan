import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { configApi } from '@/services/api/configApi'
import { useAiConfigStore } from '@/stores/aiConfig'
import { useAuthStore } from '@/stores/auth'
import {
  isModelMixId,
  resolveModelMixes,
  type ModelMixId,
  type ResolvedModelMix,
} from '@/utils/modelMixes'

/**
 * Speed-config state shared by the inline panel (empty chat) and the round
 * top-right button (desktop ChatView + mobile MainLayout).
 *
 * The selected mix id is remembered per user in localStorage; the mix itself
 * is applied server-side through the regular default-model endpoints, so it
 * behaves exactly as if the user had configured the same models on /ai/models.
 */
export const useModelMixStore = defineStore('modelMix', () => {
  const aiConfig = useAiConfigStore()
  const authStore = useAuthStore()

  const activeMixId = ref<ModelMixId>('default')
  const applying = ref(false)
  /**
   * True while the expanded selection card is showing in the empty chat, so
   * the round button stays hidden until the card collapses ("turns into" it).
   */
  const inlinePanelVisible = ref(false)

  let initialized = false

  const resolvedMixes = computed<ResolvedModelMix[]>(() => resolveModelMixes(aiConfig.models))

  const activeMix = computed<ResolvedModelMix | null>(
    () => resolvedMixes.value.find((mix) => mix.id === activeMixId.value) ?? null
  )

  const storageKey = () => `synaplan_model_mix_${authStore.user?.id ?? 'anon'}`

  /** Load the model catalog (once) and restore the remembered selection. */
  const ensureLoaded = async () => {
    if (initialized) return
    initialized = true

    try {
      const stored = localStorage.getItem(storageKey())
      if (isModelMixId(stored)) activeMixId.value = stored
    } catch {
      // Storage can be unavailable (private mode); the default selection is fine.
    }

    if (Object.keys(aiConfig.models).length === 0) {
      await aiConfig.loadModels()
    }
  }

  /**
   * Apply a mix: write the resolved defaults (or reset to the install's
   * recommended set) and remember the choice. Returns false when the mix
   * cannot be applied on this installation.
   */
  const applyMix = async (id: ModelMixId): Promise<boolean> => {
    const mix = resolvedMixes.value.find((entry) => entry.id === id)
    if (!mix || !mix.available || applying.value) return false

    applying.value = true
    try {
      if (mix.resetsToRecommended) {
        await configApi.resetDefaultModels()
      } else {
        await configApi.saveDefaultModels({ defaults: mix.defaults })
      }
      // Refresh the full picture — a mix only writes some capabilities.
      await aiConfig.loadDefaults()

      activeMixId.value = id
      try {
        localStorage.setItem(storageKey(), id)
      } catch {
        // Non-persistent selection still works for this session.
      }
      return true
    } finally {
      applying.value = false
    }
  }

  return {
    activeMixId,
    activeMix,
    resolvedMixes,
    applying,
    inlinePanelVisible,
    ensureLoaded,
    applyMix,
  }
})
