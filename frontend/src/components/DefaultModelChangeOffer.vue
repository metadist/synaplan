<template>
  <div
    v-if="visibleOffers.length > 0"
    :class="panelClass"
    role="status"
    data-testid="banner-default-model-offer"
  >
    <div
      v-for="(offer, index) in visibleOffers"
      :key="offer.capability"
      :class="index > 0 ? 'border-t border-light-border/20 pt-4' : ''"
      :data-testid="`offer-default-${offer.capability}`"
    >
      <p class="text-sm txt-primary">
        {{
          t('chatInput.defaultModelOffer.body', {
            name: offer.modelName,
            count: offer.unavailableCount,
          })
        }}
      </p>
      <p class="mt-1 text-sm txt-secondary">
        {{ t('chatInput.defaultModelOffer.prompt', { purpose: purposeLabel(offer.capability) }) }}
      </p>
      <p
        v-if="offer.alternatives.length === 0"
        class="mt-1 text-sm txt-secondary"
        data-testid="text-default-offer-empty"
      >
        {{ t('chatInput.defaultModelOffer.noAlternative') }}
      </p>

      <div class="mt-3 flex flex-wrap items-center gap-2">
        <select
          v-if="offer.alternatives.length > 1"
          :value="selectedId(offer)"
          class="min-w-0 flex-1 px-3 py-2 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
          :aria-label="t('chatInput.defaultModelOffer.choose')"
          :data-testid="`select-default-offer-${offer.capability}`"
          @change="onPick(offer, $event)"
        >
          <option
            v-for="alternative in offer.alternatives"
            :key="alternative.id"
            :value="alternative.id"
          >
            {{ alternative.name }}
          </option>
        </select>
        <button
          v-if="offer.alternatives.length > 0"
          type="button"
          class="btn-primary px-4 py-2.5 text-sm font-medium"
          :disabled="saving === offer.capability"
          :data-testid="`btn-default-offer-use-${offer.capability}`"
          @click="apply(offer)"
        >
          {{ useLabel(offer) }}
        </button>
        <RouterLink
          v-else-if="placement === 'chip'"
          :to="{ name: 'ai-models' }"
          class="btn-secondary inline-flex items-center px-4 py-2.5 text-sm font-medium"
          data-testid="link-default-offer-settings"
        >
          {{ t('chatInput.defaultModelOffer.settings') }}
        </RouterLink>
        <button
          type="button"
          class="btn-secondary px-4 py-2.5 text-sm font-medium"
          data-testid="btn-default-offer-dismiss"
          @click="dismiss(offer)"
        >
          {{ t('chatInput.defaultModelOffer.dismiss') }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useNotification } from '@/composables/useNotification'
import { getDefaultModelOffers, type DefaultModelOffer } from '@/services/api/defaultModelOfferApi'
import { saveDefaultModels, type SaveDefaultsRequest } from '@/services/api/configApi'
import { useAiConfigStore } from '@/stores/aiConfig'
import { useAuthStore } from '@/stores/auth'
import { useHistoryStore } from '@/stores/history'
import type { Capability } from '@/types/ai-models'
import { interpretDefaultSave } from '@/utils/defaultModelOfferResult'

const props = withDefaults(
  defineProps<{
    /** `chip` sits above the chat model button. `page` sits in the defaults list. */
    placement?: 'chip' | 'page'
    /** When set, only this default is offered (the chat composer asks about chat). */
    capability?: string | null
    /** The model the composer will actually send to. A different pick hides the offer. */
    activeModelId?: number | null
  }>(),
  {
    placement: 'chip',
    capability: null,
    activeModelId: null,
  }
)

const emit = defineEmits<{
  applied: [capability: string]
}>()

const { t, te } = useI18n()
const { success, error } = useNotification()
const aiConfig = useAiConfigStore()
const authStore = useAuthStore()
const history = useHistoryStore()

const offers = ref<DefaultModelOffer[]>([])
const dismissed = ref<Record<string, true>>({})
const picked = ref<Record<string, number>>({})
const saving = ref<string | null>(null)
let alive = true

const panelClass = computed(() =>
  props.placement === 'page'
    ? 'surface-card mb-5 space-y-4 p-4'
    : 'dropdown-up left-auto right-0 w-[min(20rem,calc(100vw-2rem))] space-y-3'
)

const visibleOffers = computed(() =>
  offers.value.filter((offer) => {
    if (isDismissed(offer)) return false
    if (props.capability && offer.capability !== props.capability) return false
    if (props.placement === 'chip' && props.activeModelId !== offer.modelId) return false
    return true
  })
)

function storageKey(offer: DefaultModelOffer): string {
  return `synaplan.default-offer.${offer.capability}.${offer.modelId}`
}

function isDismissed(offer: DefaultModelOffer): boolean {
  if (dismissed.value[storageKey(offer)]) return true
  try {
    return sessionStorage.getItem(storageKey(offer)) === '1'
  } catch {
    return false
  }
}

function purposeLabel(capability: string): string {
  const key = `config.aiModels.capabilities.${capability.toLowerCase()}`
  return te(key) ? t(key) : capability
}

function selectedId(offer: DefaultModelOffer): number | null {
  const current = picked.value[offer.capability]
  if (
    current !== undefined &&
    offer.alternatives.some((alternative) => alternative.id === current)
  ) {
    return current
  }
  return offer.alternatives[0]?.id ?? null
}

function useLabel(offer: DefaultModelOffer): string {
  const id = selectedId(offer)
  const alternative = offer.alternatives.find((item) => item.id === id)
  if (offer.alternatives.length === 1 && alternative) {
    return t('chatInput.defaultModelOffer.useNamed', { name: alternative.name })
  }
  return t('chatInput.defaultModelOffer.use')
}

function onPick(offer: DefaultModelOffer, event: Event): void {
  const value = Number((event.target as HTMLSelectElement).value)
  if (!Number.isFinite(value)) return
  picked.value = { ...picked.value, [offer.capability]: value }
}

function dismiss(offer: DefaultModelOffer): void {
  const key = storageKey(offer)
  dismissed.value = { ...dismissed.value, [key]: true }
  try {
    sessionStorage.setItem(key, '1')
  } catch {
    // Private mode keeps the dismissal for this visit only.
  }
}

async function load(): Promise<void> {
  // Guests are unauthenticated for a moment before the trial session exists.
  // This endpoint answers 401, and that tears the trial down.
  if (!authStore.isAuthenticated) {
    if (alive) offers.value = []
    return
  }
  try {
    const response = await getDefaultModelOffers()
    if (!alive) return
    offers.value = response.offers
  } catch {
    if (!alive) return
    offers.value = []
  }
}

async function apply(offer: DefaultModelOffer): Promise<void> {
  const id = selectedId(offer)
  const alternative = offer.alternatives.find((item) => item.id === id)
  if (!alternative) return

  saving.value = offer.capability
  try {
    const response = await saveDefaultModels({
      defaults: { [offer.capability]: alternative.id } as SaveDefaultsRequest['defaults'],
    })
    const outcome = interpretDefaultSave(response, offer.capability, alternative.id)
    if (outcome.kind === 'skipped') {
      error(t('chatInput.defaultModelOffer.saveFailed', { name: offer.modelName }))
      return
    }

    const capability = offer.capability as Capability
    const purpose = purposeLabel(offer.capability)
    aiConfig.defaults[capability] = outcome.modelId
    if (outcome.kind === 'unusable') {
      error(t('chatInput.defaultModelOffer.savedUnusable', { name: alternative.name }))
    } else if (outcome.kind === 'replaced') {
      success(
        t('chatInput.defaultModelOffer.savedOther', {
          picked: alternative.name,
          name: resolvedName(offer, outcome.modelId),
          purpose,
        })
      )
    } else {
      success(t('chatInput.defaultModelOffer.saved', { name: alternative.name, purpose }))
    }
    offers.value = offers.value.filter((item) => item.capability !== offer.capability)
    emit('applied', offer.capability)
  } catch {
    error(t('chatInput.defaultModelOffer.saveFailed', { name: offer.modelName }))
  } finally {
    saving.value = null
  }
}

function resolvedName(offer: DefaultModelOffer, id: number): string {
  const listed = offer.alternatives.find((item) => item.id === id)?.name
  if (listed) return listed
  const models = aiConfig.models[offer.capability as Capability]
  return (
    models?.find((model) => model.id === id)?.name ?? t('chatInput.defaultModelOffer.otherModel')
  )
}

watch(
  () => {
    const last = history.messages[history.messages.length - 1]
    return last?.errorReason ? `${history.messages.length}:${last.errorReason}` : ''
  },
  (token) => {
    if (token !== '') void load()
  }
)

onMounted(() => {
  void load()
})

onUnmounted(() => {
  alive = false
})
</script>
