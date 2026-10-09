<template>
  <div>
    <p class="text-sm txt-secondary mb-4">{{ $t('adminSetup.intro.providers') }}</p>

    <LocalAiDownloadCard class="mb-6" />

    <div
      class="rounded-lg p-4 mb-6 flex items-start gap-3 border"
      :class="
        chatReady
          ? 'bg-[var(--status-success-muted)] border-[var(--status-success)]'
          : 'bg-[var(--status-warning-muted)] border-[var(--status-warning)]'
      "
      data-testid="setup-status"
    >
      <Icon
        :icon="chatReady ? 'mdi:check-circle' : 'mdi:alert-circle-outline'"
        class="w-6 h-6 shrink-0 mt-0.5"
        :class="chatReady ? 'text-[var(--status-success)]' : 'text-[var(--status-warning)]'"
      />
      <div>
        <p class="font-semibold txt-primary">
          {{ chatReady ? $t('adminSetup.statusReadyTitle') : $t('adminSetup.statusNotReadyTitle') }}
        </p>
        <p class="text-sm txt-secondary mt-0.5">
          {{ chatReady ? $t('adminSetup.statusReadyText') : $t('adminSetup.statusNotReadyText') }}
        </p>
      </div>
    </div>

    <div v-if="loading" class="text-center py-12">
      <Icon icon="mdi:loading" class="w-8 h-8 animate-spin mx-auto txt-secondary" />
    </div>

    <div
      v-else-if="loadFailed"
      class="surface-card rounded-lg p-8 text-center"
      data-testid="setup-load-error"
    >
      <Icon icon="mdi:cloud-alert" class="w-8 h-8 mx-auto text-[var(--status-warning)]" />
      <p class="txt-secondary mt-3">{{ $t('adminSetup.loadFailed') }}</p>
      <button
        type="button"
        class="btn-primary px-6 py-2.5 rounded-xl font-medium mt-4"
        data-testid="setup-retry"
        @click="refresh"
      >
        {{ $t('common.retry') }}
      </button>
    </div>

    <template v-else>
      <div class="flex justify-end mb-4">
        <button
          type="button"
          class="btn-secondary px-4 py-2 rounded-xl text-sm font-medium"
          data-testid="btn-setup-accordion-toggle-all"
          @click="allSetupSectionsOpen ? collapseAllSetupSections() : expandAllSetupSections()"
        >
          {{
            allSetupSectionsOpen
              ? $t('admin.config.accordion.collapseAll')
              : $t('admin.config.accordion.expandAll')
          }}
        </button>
      </div>

      <AccordionStack testid="setup-models-accordion">
        <AccordionSection
          panel-id="setup-section-providers"
          :title="$t('adminSetup.cloudProviders')"
          :open="isSetupSectionOpen('providers')"
          header-testid="btn-setup-section-providers"
          @toggle="toggleSetupSection('providers')"
        >
          <p class="text-sm txt-secondary mb-4">{{ $t('adminSetup.cloudProvidersHint') }}</p>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <ProviderKeyCard
              v-for="provider in sortedProviders"
              :key="provider.name"
              :provider="provider"
              :is-default-chat="provider.name === defaultChatProvider"
              @changed="refresh"
            />
          </div>
        </AccordionSection>

        <AccordionSection
          panel-id="setup-section-local-ai"
          :title="$t('adminSetup.localAi.title')"
          :open="isSetupSectionOpen('local-ai')"
          header-testid="btn-setup-section-local-ai"
          @toggle="toggleSetupSection('local-ai')"
        >
          <template #leading>
            <Icon icon="mdi:server-outline" class="w-5 h-5 txt-brand flex-shrink-0" />
          </template>
          <div class="flex items-center gap-2 mb-1">
            <ProviderHelpHint
              help-id="ollama"
              url="https://ollama.com/download"
              :is-download="true"
            />
          </div>
          <p class="text-sm txt-secondary">{{ $t('adminSetup.localAi.description') }}</p>
          <p
            v-if="ollamaState === 'unreachable'"
            class="text-sm mt-2 text-[var(--status-warning)]"
            data-testid="ollama-not-running"
          >
            {{ $t('adminSetup.localAi.notRunning') }}
          </p>
          <div class="flex flex-wrap gap-2 mt-3">
            <button
              v-if="ollamaState === 'unreachable'"
              type="button"
              class="btn-secondary px-4 py-2.5 rounded-xl text-sm font-medium inline-flex items-center gap-2"
              data-testid="ollama-recheck"
              @click="checkOllama"
            >
              {{ $t('common.retry') }}
            </button>
            <button
              type="button"
              class="btn-secondary px-4 py-2.5 rounded-xl text-sm font-medium inline-flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed"
              data-testid="ollama-import-models"
              :disabled="ollamaState === 'unreachable'"
              @click="importOllama = true"
            >
              <Icon icon="mdi:download-outline" class="w-4 h-4" aria-hidden="true" />
              {{ $t('aiInfra.modelImport.importPulled') }}
            </button>
          </div>
          <div v-if="ollamaSettings && config" class="mt-5" data-testid="setup-local-ai-settings">
            <h4 class="text-sm font-semibold txt-primary mb-2">
              {{ $t('adminSetup.localAi.serverTitle') }}
            </h4>
            <ConfigSectionBody :section="ollamaSettings" :config="config" />
          </div>
        </AccordionSection>

        <AccordionSection
          v-for="section in extraSettings"
          :key="section.id"
          :panel-id="`setup-section-${section.id}`"
          :title="section.label"
          :open="isSetupSectionOpen(section.id)"
          :header-testid="`btn-setup-section-${section.id}`"
          @toggle="toggleSetupSection(section.id)"
        >
          <template #leading>
            <Icon icon="mdi:folder-cog" class="w-5 h-5 txt-secondary flex-shrink-0" />
          </template>
          <ConfigSectionBody v-if="config" :section="section" :config="config" />
        </AccordionSection>

        <AccordionSection
          panel-id="setup-section-own-service"
          :title="$t('adminSetup.ownService.title')"
          :open="isSetupSectionOpen('own-service')"
          header-testid="btn-setup-section-own-service"
          @toggle="toggleSetupSection('own-service')"
        >
          <template #leading>
            <Icon icon="mdi:puzzle-plus-outline" class="w-5 h-5 txt-brand flex-shrink-0" />
          </template>
          <p class="text-sm txt-secondary">{{ $t('adminSetup.ownService.description') }}</p>
          <RouterLink
            :to="{ path: '/ai/models', query: { tab: 'edit' } }"
            class="inline-flex items-center gap-1.5 mt-3 text-sm font-medium text-[var(--brand)] hover:underline"
            data-testid="setup-own-service"
          >
            {{ $t('adminSetup.ownService.cta') }}
            <Icon icon="mdi:arrow-right" class="w-4 h-4" aria-hidden="true" />
          </RouterLink>
        </AccordionSection>
      </AccordionStack>
    </template>

    <ModelImportDialog
      v-if="importOllama"
      source="ollama"
      :label="$t('adminSetup.localAi.title')"
      @close="importOllama = false"
      @applied="refresh"
    />
  </div>
</template>

<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { Icon } from '@iconify/vue'
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import AccordionSection from '@/components/AccordionSection.vue'
import AccordionStack from '@/components/AccordionStack.vue'
import ConfigSectionBody from '@/components/admin/ConfigSectionBody.vue'
import ProviderHelpHint from '@/components/admin/ProviderHelpHint.vue'
import ProviderKeyCard from '@/components/admin/ProviderKeyCard.vue'
import ModelImportDialog from '@/components/admin/plugs/ModelImportDialog.vue'
import LocalAiDownloadCard from '@/components/setup/LocalAiDownloadCard.vue'
import { useAccordion } from '@/composables/useAccordion'
import { useNotification } from '@/composables/useNotification'
import type { ResolvedConfigSection, SystemConfigHandle } from '@/composables/useSystemConfig'
import type { ConfigSectionRef } from '@/constants/operateSettings'
import { useConfigStore } from '@/stores/config'
import { listProviderKeys, type ProviderKeyStatus } from '@/services/api/providerKeysApi'
import { adminModelsApi } from '@/services/api/adminModelsApi'

const props = withDefaults(
  defineProps<{
    /** Instance settings shown next to the provider cards (Ollama address, speech output, …). */
    config?: SystemConfigHandle
    settings?: readonly ConfigSectionRef[]
    /** `?section=` of the page: opens and scrolls to that section. */
    focusSection?: string
  }>(),
  {
    config: undefined,
    settings: () => [],
    focusSection: undefined,
  }
)

/** Backend section whose fields belong inside the Local AI card. */
const LOCAL_AI_SECTION = 'ollama'

const { t } = useI18n()
const { error: showError } = useNotification()
const configStore = useConfigStore()

const loading = ref(true)
const loadFailed = ref(false)
const providers = ref<ProviderKeyStatus[]>([])
const defaultChatProvider = ref('')
const importOllama = ref(false)
// Pre-flight for the Ollama import: the compose default does not start Ollama,
// so the import button must not look live when no server answers. Starts
// 'unknown' (button enabled) and only flips on a confirmed answer — a slow or
// failed pre-flight never blocks the page; the dialog reports it with Retry.
const ollamaState = ref<'unknown' | 'ok' | 'unreachable'>('unknown')

const chatReady = computed(() => configStore.setup.chatReady)

// Provider keys are edited on the cards above, so the settings never repeat them.
const resolvedSettings = computed<ResolvedConfigSection[]>(() => {
  const handle = props.config
  if (!handle) return []
  return props.settings
    .map((ref) => handle.resolveSection(ref, { hideManaged: true }))
    .filter((section): section is ResolvedConfigSection => section !== null)
})
const ollamaSettings = computed(
  () => resolvedSettings.value.find((section) => section.id === LOCAL_AI_SECTION) ?? null
)
const extraSettings = computed(() =>
  resolvedSettings.value.filter((section) => section.id !== LOCAL_AI_SECTION)
)

const setupSectionIds = computed(() => [
  'providers',
  'local-ai',
  ...extraSettings.value.map((section) => section.id),
  'own-service',
])
const {
  isOpen: isSetupSectionOpen,
  toggle: toggleSetupSection,
  open: openSetupSection,
  expandAll: expandAllSetupSections,
  collapseAll: collapseAllSetupSections,
  allOpen: allSetupSectionsOpen,
} = useAccordion(setupSectionIds)

async function jumpToSetupSection(id: string) {
  openSetupSection(id)
  await nextTick()
  document.getElementById(`setup-section-${id}`)?.scrollIntoView({
    behavior: 'smooth',
    block: 'start',
  })
}

watch(
  () => [props.focusSection, loading.value, setupSectionIds.value.join('\0')] as const,
  ([wanted, isLoading]) => {
    if (!wanted || isLoading) return
    const id = wanted === LOCAL_AI_SECTION ? 'local-ai' : wanted
    if (setupSectionIds.value.includes(id)) void jumpToSetupSection(id)
  },
  { immediate: true }
)

const sortedProviders = computed(() =>
  [...providers.value].sort((a, b) => {
    if (a.recommended !== b.recommended) return a.recommended ? -1 : 1
    if (a.configured !== b.configured) return a.configured ? -1 : 1
    return a.displayName.localeCompare(b.displayName)
  })
)

const load = async () => {
  try {
    const result = await listProviderKeys()
    providers.value = result.providers
    defaultChatProvider.value = result.defaultChatProvider
    loadFailed.value = false
  } catch (err) {
    loadFailed.value = true
    showError(err instanceof Error ? err.message : t('adminSetup.loadFailed'))
  } finally {
    loading.value = false
  }
}

const checkOllama = async () => {
  try {
    const result = await adminModelsApi.importEndpointPreview('ollama', false)
    ollamaState.value = result.endpointOk ? 'ok' : 'unreachable'
  } catch {
    // Fail open: the import dialog reports reachability itself with Retry.
    ollamaState.value = 'unknown'
  }
}

const refresh = async () => {
  await Promise.all([load(), configStore.reload(), checkOllama()])
}

onMounted(refresh)
</script>
