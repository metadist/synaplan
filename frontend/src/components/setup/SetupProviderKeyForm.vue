<template>
  <div class="surface-chip rounded-xl p-4 flex flex-col gap-3" data-testid="setup-provider-detail">
    <div class="flex items-start gap-2.5">
      <ServiceIcon :service="provider.name" :size="24" :show-flag="false" class="mt-0.5" />
      <div class="min-w-0 flex-1">
        <p class="text-sm font-semibold txt-primary">{{ provider.displayName }}</p>
        <p
          v-if="provider.configured"
          class="text-xs txt-secondary font-mono truncate"
          data-testid="setup-provider-masked-key"
        >
          {{ provider.maskedKey }}
        </p>
      </div>
      <a
        :href="keyUrl"
        target="_blank"
        rel="noopener noreferrer"
        class="text-xs txt-brand hover:underline inline-flex items-center gap-1 whitespace-nowrap"
        data-testid="setup-provider-get-key"
      >
        <Icon icon="mdi:open-in-new" class="w-3.5 h-3.5" />
        {{ $t('adminSetup.getKey') }}
      </a>
    </div>

    <!-- Names the provider, because a bare "free tier available" reads as a claim
         about Synaplan's own pricing. What is free is the key this panel asks
         for, and it is the provider who hands it out. -->
    <p
      v-if="provider.freeTier"
      class="flex items-start gap-1.5 text-xs text-[var(--status-success-text)]"
      data-testid="setup-provider-free-tier"
    >
      <!-- `items-start`, not `items-center`: at 375 px the sentence wraps to two
           lines and a centred icon would float between them. -->
      <Icon icon="mdi:gift-outline" class="w-4 h-4 shrink-0 mt-px" />
      {{ $t('setup.provider.freeKeyHint', { provider: provider.displayName }) }}
    </p>

    <div class="flex flex-col gap-2 sm:flex-row">
      <input
        ref="keyField"
        v-model="keyInput"
        type="password"
        :aria-label="$t('setup.provider.keyLabel', { provider: provider.displayName })"
        :placeholder="$t('adminSetup.keyPlaceholder')"
        class="flex-1 min-w-0 px-3 py-2.5 rounded-lg surface-card txt-primary text-sm font-mono"
        :data-testid="`setup-provider-key-input-${provider.name}`"
        autocomplete="off"
        spellcheck="false"
        @keydown.enter="save"
      />
      <button
        type="button"
        class="btn-primary px-4 py-2.5 rounded-lg text-sm font-semibold whitespace-nowrap"
        :disabled="saving || '' === keyInput.trim()"
        data-testid="setup-provider-save"
        @click="save"
      >
        {{ saving ? $t('setup.provider.saving') : $t('adminSetup.saveAndTest') }}
      </button>
    </div>

    <p v-if="sourceHint" class="text-xs txt-secondary" data-testid="setup-provider-source-hint">
      {{ sourceHint }}
    </p>

    <p class="text-xs txt-secondary" data-testid="setup-provider-default-hint">
      {{
        isDefaultChat
          ? $t('setup.provider.alreadyDefault', { provider: provider.displayName })
          : $t('setup.provider.willBecomeDefault', { provider: provider.displayName })
      }}
    </p>
  </div>
</template>

<script setup lang="ts">
import { computed, nextTick, onMounted, ref } from 'vue'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import ServiceIcon from '@/components/icons/ServiceIcon.vue'
import { useNotification } from '@/composables/useNotification'
import { saveProviderKey, type ProviderKeyStatus } from '@/services/api/providerKeysApi'
import { providerHelpByName } from '@/utils/providerHelp'

const props = defineProps<{
  provider: ProviderKeyStatus
  isDefaultChat: boolean
}>()

const emit = defineEmits<{
  saved: []
}>()

const { t } = useI18n()
const { success, error: showError } = useNotification()

const keyInput = ref('')
const saving = ref(false)
const keyField = ref<HTMLInputElement | null>(null)

const keyUrl = computed(
  () => props.provider.consoleUrl || providerHelpByName(props.provider.name)?.url
)

/**
 * A key already imported from `.env` looks connected but is not yet owned by
 * this installation, so the panel has to say what saving here would change.
 */
const sourceHint = computed(() => {
  if (!props.provider.configured) {
    return ''
  }
  if ('env' === props.provider.source) {
    return t('adminSetup.sourceDbOverridesEnv')
  }
  if ('db' === props.provider.source && 'env' === props.provider.origin) {
    return t('adminSetup.sourceDbFromEnvHint')
  }
  return ''
})

// The panel only exists because the operator just picked this provider, so the
// caret belongs in the field they came for — one tap instead of two on a phone.
onMounted(async () => {
  await nextTick()
  keyField.value?.focus()
})

const save = async (): Promise<void> => {
  const key = keyInput.value.trim()
  if ('' === key || saving.value) {
    return
  }

  saving.value = true
  try {
    // First-run: the provider the operator just connected is the one they want
    // answering, so it takes the default unless it already holds it.
    const result = await saveProviderKey(props.provider.name, key, {
      applyDefaults: !props.isDefaultChat,
    })
    keyInput.value = ''
    success(
      result.defaultsApplied
        ? t('adminSetup.savedWithDefaults', { provider: props.provider.displayName })
        : t('adminSetup.saved', { provider: props.provider.displayName })
    )
    emit('saved')
  } catch (err) {
    showError(err instanceof Error ? err.message : t('adminSetup.saveFailed'))
  } finally {
    saving.value = false
  }
}
</script>
