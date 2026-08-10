<script setup lang="ts">
/**
 * Where the gateway forwards to, and under which model names.
 *
 * Unlike every other setting on this page, these two are free text: they are
 * submitted on demand rather than on change, so a half-typed URL is never
 * persisted.
 */
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useNotification } from '@/composables/useNotification'
import {
  saveMessagesGatewayAliases,
  saveMessagesGatewayUpstream,
  type MessagesGatewayStatus,
} from '@/services/api/messagesGatewayApi'
import GatewaySettingSection from './GatewaySettingSection.vue'

const props = defineProps<{
  status: MessagesGatewayStatus
}>()

const emit = defineEmits<{
  saved: []
}>()

const { t } = useI18n()
const { success, error } = useNotification()

const upstreamUrl = ref('')
const aliasesJson = ref('{}')
const savingUpstream = ref(false)
const savingAliases = ref(false)

watch(
  () => props.status,
  (status) => {
    upstreamUrl.value = status.upstream_url
    aliasesJson.value = JSON.stringify(status.model_aliases ?? {}, null, 2)
  },
  { immediate: true, deep: true }
)

async function onSaveUpstream() {
  savingUpstream.value = true
  try {
    await saveMessagesGatewayUpstream(upstreamUrl.value.trim())
    success(t('messagesGateway.upstreamSaved'))
    emit('saved')
  } catch (err) {
    error((err as Error).message || t('messagesGateway.upstreamError'))
  } finally {
    savingUpstream.value = false
  }
}

async function onSaveAliases() {
  savingAliases.value = true
  try {
    const parsed = JSON.parse(aliasesJson.value) as Record<string, string>
    await saveMessagesGatewayAliases(parsed)
    success(t('messagesGateway.aliasesSaved'))
    emit('saved')
  } catch (err) {
    error((err as Error).message || t('messagesGateway.aliasesError'))
  } finally {
    savingAliases.value = false
  }
}
</script>

<template>
  <GatewaySettingSection
    :title="$t('messagesGateway.settings.sections.connection.title')"
    :description="$t('messagesGateway.settings.sections.connection.description')"
  >
    <div class="mt-4">
      <label class="block text-sm font-medium txt-primary mb-1" for="agents-upstream">
        {{ $t('messagesGateway.upstreamLabel') }}
      </label>
      <p class="txt-secondary text-xs mb-2">{{ $t('messagesGateway.upstreamWarning') }}</p>
      <div class="flex flex-wrap gap-2">
        <input
          id="agents-upstream"
          v-model="upstreamUrl"
          type="url"
          class="flex-1 min-w-[16rem] px-3 py-2 rounded surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm font-mono"
          data-testid="input-agents-upstream"
        />
        <button
          type="button"
          class="btn-primary px-4 py-2 rounded-lg text-sm font-medium disabled:opacity-50"
          :disabled="savingUpstream"
          data-testid="btn-agents-save-upstream"
          @click="onSaveUpstream"
        >
          {{ $t('messagesGateway.saveUpstream') }}
        </button>
      </div>
    </div>

    <div class="mt-5">
      <label class="block text-sm font-medium txt-primary mb-1" for="agents-aliases">
        {{ $t('messagesGateway.aliasesLabel') }}
      </label>
      <p class="txt-secondary text-xs mb-2">{{ $t('messagesGateway.aliasesHint') }}</p>
      <textarea
        id="agents-aliases"
        v-model="aliasesJson"
        rows="4"
        class="w-full px-3 py-2 rounded surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-xs font-mono"
        data-testid="input-agents-aliases"
      />
      <button
        type="button"
        class="btn-primary mt-2 px-4 py-2 rounded-lg text-sm font-medium disabled:opacity-50"
        :disabled="savingAliases"
        data-testid="btn-agents-save-aliases"
        @click="onSaveAliases"
      >
        {{ $t('messagesGateway.saveAliases') }}
      </button>
    </div>
  </GatewaySettingSection>
</template>
