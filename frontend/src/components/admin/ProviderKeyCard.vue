<template>
  <div
    class="surface-card rounded-lg p-5 flex flex-col gap-3"
    :data-testid="`provider-card-${provider.name}`"
  >
    <!-- Header: name + badges -->
    <div class="flex items-start justify-between gap-2">
      <div class="flex items-center gap-2 min-w-0">
        <h3 class="text-lg font-semibold txt-primary truncate">{{ provider.displayName }}</h3>
        <span
          v-if="provider.recommended"
          class="text-xs font-medium px-2 py-0.5 rounded-full bg-[var(--brand)] text-white whitespace-nowrap"
        >
          {{ $t('adminSetup.recommended') }}
        </span>
      </div>
      <span
        v-if="provider.configured"
        class="inline-flex items-center gap-1 text-xs font-medium px-2 py-0.5 rounded-full bg-green-500/15 text-green-600 dark:text-green-400 whitespace-nowrap"
      >
        <Icon icon="mdi:check-circle" class="w-3.5 h-3.5" />
        {{ $t('adminSetup.connected') }}
      </span>
    </div>

    <!-- Meta line -->
    <div class="flex flex-wrap items-center gap-2 text-xs txt-secondary">
      <span v-if="provider.freeTier" class="inline-flex items-center gap-1">
        <Icon icon="mdi:gift-outline" class="w-3.5 h-3.5" />
        {{ $t('adminSetup.freeTier') }}
      </span>
      <span v-if="provider.configured" class="font-mono">{{ provider.maskedKey }}</span>
      <span v-if="provider.configured && provider.source === 'env'">
        ({{ $t('adminSetup.sourceEnv') }})
      </span>
      <span v-if="isDefaultChat" class="inline-flex items-center gap-1 txt-brand font-medium">
        <Icon icon="mdi:star" class="w-3.5 h-3.5" />
        {{ $t('adminSetup.currentDefault') }}
      </span>
    </div>

    <!-- Key input -->
    <div class="flex flex-col gap-2">
      <div class="flex gap-2">
        <input
          v-model="keyInput"
          type="password"
          :placeholder="
            provider.configured
              ? $t('adminSetup.replaceKeyPlaceholder')
              : $t('adminSetup.keyPlaceholder')
          "
          class="flex-1 min-w-0 px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm font-mono"
          :data-testid="`provider-key-input-${provider.name}`"
          autocomplete="off"
          @keydown.enter="save"
        />
        <button
          class="btn-primary whitespace-nowrap"
          :disabled="saving || keyInput.trim() === ''"
          :data-testid="`provider-key-save-${provider.name}`"
          @click="save"
        >
          <Icon v-if="saving" icon="mdi:loading" class="w-4 h-4 animate-spin" />
          <span v-else>{{ $t('adminSetup.saveAndTest') }}</span>
        </button>
      </div>
      <label
        v-if="keyInput.trim() !== ''"
        class="flex items-center gap-2 text-sm txt-secondary cursor-pointer"
      >
        <input v-model="applyDefaultsChecked" type="checkbox" class="accent-[var(--brand)]" />
        {{ $t('adminSetup.applyDefaultsOnSave') }}
      </label>
    </div>

    <!-- Footer actions -->
    <div class="flex flex-wrap items-center gap-3 text-sm mt-auto">
      <a
        :href="provider.consoleUrl"
        target="_blank"
        rel="noopener noreferrer"
        class="txt-brand hover:underline inline-flex items-center gap-1"
      >
        <Icon icon="mdi:open-in-new" class="w-4 h-4" />
        {{ $t('adminSetup.getKey') }}
      </a>
      <template v-if="provider.configured">
        <button
          class="txt-secondary hover:txt-primary inline-flex items-center gap-1"
          :disabled="testing"
          @click="test"
        >
          <Icon
            :icon="testing ? 'mdi:loading' : 'mdi:connection'"
            class="w-4 h-4"
            :class="{ 'animate-spin': testing }"
          />
          {{ $t('adminSetup.testKey') }}
        </button>
        <button
          v-if="!isDefaultChat"
          class="txt-secondary hover:txt-primary inline-flex items-center gap-1"
          :disabled="applying"
          @click="makeDefault"
        >
          <Icon
            :icon="applying ? 'mdi:loading' : 'mdi:star-outline'"
            class="w-4 h-4"
            :class="{ 'animate-spin': applying }"
          />
          {{ $t('adminSetup.makeDefault') }}
        </button>
        <button
          v-if="provider.source === 'db'"
          class="text-red-600 dark:text-red-400 hover:underline inline-flex items-center gap-1"
          @click="remove"
        >
          <Icon icon="mdi:trash-can-outline" class="w-4 h-4" />
          {{ $t('adminSetup.removeKey') }}
        </button>
      </template>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import { useDialog } from '@/composables/useDialog'
import { useNotification } from '@/composables/useNotification'
import {
  applyProviderDefaults,
  deleteProviderKey,
  saveProviderKey,
  testProviderKey,
  type ProviderKeyStatus,
} from '@/services/api/providerKeysApi'

const props = defineProps<{
  provider: ProviderKeyStatus
  isDefaultChat: boolean
}>()

const emit = defineEmits<{
  changed: []
}>()

const { t } = useI18n()
const { confirm } = useDialog()
const { success, error: showError } = useNotification()

const keyInput = ref('')
const applyDefaultsChecked = ref(!props.isDefaultChat)
const saving = ref(false)
const testing = ref(false)
const applying = ref(false)

const save = async () => {
  const key = keyInput.value.trim()
  if (key === '' || saving.value) return
  saving.value = true
  try {
    const result = await saveProviderKey(props.provider.name, key, {
      applyDefaults: applyDefaultsChecked.value,
    })
    keyInput.value = ''
    success(
      result.defaultsApplied
        ? t('adminSetup.savedWithDefaults', { provider: props.provider.displayName })
        : t('adminSetup.saved', { provider: props.provider.displayName })
    )
    emit('changed')
  } catch (err) {
    showError(err instanceof Error ? err.message : t('adminSetup.saveFailed'))
  } finally {
    saving.value = false
  }
}

const test = async () => {
  testing.value = true
  try {
    const result = await testProviderKey(props.provider.name)
    if (result.ok) {
      success(t('adminSetup.testOk', { provider: props.provider.displayName }))
    } else {
      showError(
        result.error || t('adminSetup.testFailed', { provider: props.provider.displayName })
      )
    }
  } catch (err) {
    showError(
      err instanceof Error
        ? err.message
        : t('adminSetup.testFailed', { provider: props.provider.displayName })
    )
  } finally {
    testing.value = false
  }
}

const makeDefault = async () => {
  applying.value = true
  try {
    await applyProviderDefaults(props.provider.name)
    success(t('adminSetup.defaultsApplied', { provider: props.provider.displayName }))
    emit('changed')
  } catch (err) {
    showError(err instanceof Error ? err.message : t('adminSetup.saveFailed'))
  } finally {
    applying.value = false
  }
}

const remove = async () => {
  const confirmed = await confirm({
    title: t('adminSetup.removeConfirmTitle'),
    message: t('adminSetup.removeConfirmMessage', { provider: props.provider.displayName }),
    danger: true,
  })
  if (!confirmed) return
  try {
    await deleteProviderKey(props.provider.name)
    success(t('adminSetup.removed', { provider: props.provider.displayName }))
    emit('changed')
  } catch (err) {
    showError(err instanceof Error ? err.message : t('adminSetup.saveFailed'))
  }
}
</script>
