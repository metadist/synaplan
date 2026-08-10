<script setup lang="ts">
/**
 * Instance-wide settings for the Anthropic-compatible gateway (admins only).
 *
 * This component owns the working copy and the writes; the sections below are
 * presentational and hand back a patch. Every control saves on change and
 * reports the outcome immediately — there is no "Save all" button to forget.
 * The upstream URL and the model aliases are the exception (see
 * GatewayConnectionSection).
 */
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { Icon } from '@iconify/vue'
import { useNotification } from '@/composables/useNotification'
import {
  saveMessagesGatewayFlags,
  type MessagesGatewaySettings,
  type MessagesGatewayStatus,
  type VisionMode,
  type WebSearchMode,
} from '@/services/api/messagesGatewayApi'
import type { GatewayForm } from './types'
import GatewayAccessSection from './GatewayAccessSection.vue'
import GatewayConnectionSection from './GatewayConnectionSection.vue'
import GatewayImagesSection from './GatewayImagesSection.vue'
import GatewaySessionSection from './GatewaySessionSection.vue'
import GatewayToolsSection from './GatewayToolsSection.vue'

const props = defineProps<{
  status: MessagesGatewayStatus
}>()

const emit = defineEmits<{
  saved: []
}>()

const { t } = useI18n()
const { success, error } = useNotification()

const saving = ref(false)

const form = ref<GatewayForm>({
  enabled: false,
  allow_operator_key: false,
  mcp_tools_enabled: false,
  mcp_tools_with_client_tools: false,
  mcp_max_iterations: 8,
  web_search_mode: 'auto',
  web_fetch_mode: 'auto',
  vision_mode: 'auto',
  vision_image_detail: 'auto',
  vision_max_images: 0,
  context_injection_enabled: false,
  budget_notice_enabled: true,
  session_summary_enabled: true,
})

watch(() => props.status, syncFromStatus, { immediate: true, deep: true })

/**
 * `synaplan` needs a configured provider. A stored mode whose provider has
 * since disappeared would leave the page advertising a capability that never
 * runs, so fall back to `auto` for display and write that back once.
 */
function usableMode<T extends WebSearchMode | VisionMode>(mode: T, available: boolean): T {
  return !available && 'synaplan' === mode ? ('auto' as T) : mode
}

function syncFromStatus() {
  const status = props.status
  form.value = {
    enabled: status.enabled,
    allow_operator_key: status.allow_operator_key ?? false,
    mcp_tools_enabled: status.mcp_tools_enabled ?? false,
    mcp_tools_with_client_tools: status.mcp_tools_with_client_tools ?? false,
    mcp_max_iterations: status.mcp_max_iterations ?? 8,
    web_search_mode: usableMode(
      status.web_search_mode ?? 'auto',
      Boolean(status.web_search_available)
    ),
    web_fetch_mode: status.web_fetch_mode ?? 'auto',
    vision_mode: usableMode(status.vision_mode ?? 'auto', Boolean(status.vision_available)),
    vision_image_detail: status.vision_image_detail ?? 'auto',
    vision_max_images: status.vision_max_images ?? 0,
    context_injection_enabled: status.context_injection_enabled ?? false,
    budget_notice_enabled: status.budget_notice_enabled ?? true,
    session_summary_enabled: status.session_summary_enabled ?? true,
  }
  void persistUnusableModes()
}

async function persistUnusableModes(): Promise<void> {
  const patch: MessagesGatewaySettings = {}
  if (form.value.web_search_mode !== props.status.web_search_mode) {
    patch.web_search_mode = form.value.web_search_mode
  }
  if (form.value.vision_mode !== props.status.vision_mode) {
    patch.vision_mode = form.value.vision_mode
  }
  if (0 === Object.keys(patch).length) return

  try {
    await saveMessagesGatewayFlags(patch)
  } catch {
    // Keep the coerced value on screen; the next explicit save catches up.
  }
}

/**
 * One patch carries exactly the setting that changed: the endpoint leaves
 * omitted settings alone, so a wider patch could reset a neighbour.
 */
async function apply(patch: MessagesGatewaySettings) {
  if (saving.value) return
  saving.value = true
  Object.assign(form.value, patch)
  try {
    await saveMessagesGatewayFlags(patch)
    success(t('messagesGateway.flagsSaved'))
    emit('saved')
  } catch (err) {
    error((err as Error).message || t('messagesGateway.flagsError'))
    syncFromStatus()
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="surface-card p-6" data-testid="section-agents-admin">
    <div class="flex items-start gap-3 mb-2">
      <div class="p-2 rounded-lg bg-[var(--brand)]/10">
        <Icon icon="heroicons:adjustments-horizontal" class="w-5 h-5 text-[var(--brand)]" />
      </div>
      <div class="min-w-0">
        <h3 class="text-lg font-semibold txt-primary">
          {{ $t('messagesGateway.settings.title') }}
        </h3>
        <p class="txt-secondary text-sm mt-0.5">{{ $t('messagesGateway.settings.subtitle') }}</p>
      </div>
    </div>

    <p
      v-if="!form.enabled"
      class="mt-4 flex items-start gap-2 rounded-lg px-3 py-2 text-xs bg-amber-500/10 text-amber-700 dark:text-amber-300"
      data-testid="notice-agents-disabled"
    >
      <Icon icon="heroicons:pause-circle" class="w-4 h-4 flex-shrink-0 mt-0.5" />
      <span>{{ $t('messagesGateway.settings.disabledNotice') }}</span>
    </p>

    <GatewayAccessSection :form="form" @change="apply" />
    <GatewayToolsSection :form="form" :status="status" @change="apply" />
    <GatewayImagesSection :form="form" :status="status" @change="apply" />
    <GatewaySessionSection :form="form" @change="apply" />
    <GatewayConnectionSection :status="status" @saved="emit('saved')" />
  </div>
</template>
