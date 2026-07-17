<template>
  <div class="surface-card p-6" data-testid="add-model-form">
    <h2 class="text-xl font-semibold txt-primary mb-1">
      {{ t('config.aiModels.admin.addForm.title') }}
    </h2>
    <p class="text-sm txt-secondary mb-5">
      {{ t('config.aiModels.admin.addForm.subtitle') }}
    </p>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <!-- Endpoint -->
      <div class="md:col-span-2">
        <label class="block text-xs font-medium txt-secondary mb-1">
          {{ t('config.aiModels.admin.addForm.endpointLabel') }}
        </label>
        <select
          v-model="selectedEndpoint"
          class="w-full px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
          data-testid="add-model-endpoint"
        >
          <option :value="null">{{ t('config.aiModels.admin.addForm.endpointBuiltin') }}</option>
          <option v-for="ep in endpoints" :key="ep.name" :value="ep.name">
            {{ ep.label || ep.name }} — {{ ep.base_url }}
          </option>
        </select>
        <p v-if="selectedEndpoint" class="text-xs txt-secondary mt-1">
          {{ t('config.aiModels.admin.addForm.endpointHint') }}
        </p>
        <p
          v-else-if="!endpointsLoading && endpoints.length === 0"
          class="text-xs txt-secondary mt-1"
        >
          {{ t('config.aiModels.admin.addForm.noEndpoints') }}
        </p>
      </div>

      <!-- Service (only when built-in / manual) -->
      <div v-if="!selectedEndpoint">
        <label class="block text-xs font-medium txt-secondary mb-1">
          {{ t('config.aiModels.admin.addForm.serviceLabel') }}
        </label>
        <input
          v-model="service"
          class="w-full px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
          :placeholder="t('config.aiModels.admin.addForm.serviceHint')"
          data-testid="add-model-service"
        />
      </div>

      <!-- Capability -->
      <div>
        <label class="block text-xs font-medium txt-secondary mb-1">
          {{ t('config.aiModels.admin.addForm.capabilityLabel') }}
        </label>
        <select
          v-model="capability"
          class="w-full px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
          data-testid="add-model-capability"
        >
          <option v-for="tag in availableTags" :key="tag" :value="tag">
            {{ capabilityLabel(tag) }}
          </option>
        </select>
      </div>

      <!-- Provider / model id -->
      <div>
        <label class="block text-xs font-medium txt-secondary mb-1">
          {{ t('config.aiModels.admin.addForm.providerIdLabel') }}
        </label>
        <input
          v-model="providerId"
          class="w-full px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
          placeholder="llama3"
          data-testid="add-model-provider-id"
        />
        <p class="text-xs txt-secondary mt-1">
          {{ t('config.aiModels.admin.addForm.providerIdHint') }}
        </p>
      </div>

      <!-- Display name -->
      <div>
        <label class="block text-xs font-medium txt-secondary mb-1">
          {{ t('config.aiModels.admin.addForm.nameLabel') }}
        </label>
        <input
          v-model="name"
          class="w-full px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
          placeholder="Llama 3 (self-hosted)"
          data-testid="add-model-name"
        />
        <p class="text-xs txt-secondary mt-1">
          {{ t('config.aiModels.admin.addForm.nameHint') }}
        </p>
      </div>
    </div>

    <div class="mt-4 flex flex-wrap items-center gap-4">
      <label class="flex items-center gap-2 text-sm txt-secondary">
        <input
          v-model="selectable"
          type="checkbox"
          class="rounded border-light-border/30 dark:border-dark-border/20 text-[var(--brand)] focus:ring-[var(--brand)]"
        />
        {{ t('config.aiModels.admin.addForm.selectable') }}
      </label>
      <label class="flex items-center gap-2 text-sm txt-secondary">
        <input
          v-model="active"
          type="checkbox"
          class="rounded border-light-border/30 dark:border-dark-border/20 text-[var(--brand)] focus:ring-[var(--brand)]"
        />
        {{ t('config.aiModels.admin.addForm.active') }}
      </label>

      <button
        type="button"
        class="btn-primary px-4 py-2 rounded-lg text-sm font-medium disabled:opacity-50"
        :disabled="submitting"
        data-testid="add-model-submit"
        @click="submit"
      >
        {{
          submitting
            ? t('config.aiModels.admin.addForm.submitting')
            : t('config.aiModels.admin.addForm.submit')
        }}
      </button>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useNotification } from '@/composables/useNotification'
import { adminModelsApi, type AdminModelCreateRequest } from '@/services/api/adminModelsApi'
import {
  adminOpenAiEndpointsApi,
  type OpenAiEndpoint,
} from '@/services/api/adminOpenAiEndpointsApi'

const emit = defineEmits<{ created: [] }>()

const { t } = useI18n()
const { success, error: showError } = useNotification()

// BSERVICE value the OpenAI-compatible provider + endpoint registry expect.
const OPENAI_COMPATIBLE_SERVICE = 'OpenAICompatible'

// Full set of BTAG capabilities used across the model catalog. When an
// endpoint is selected we restrict to what that endpoint advertises, since
// the OpenAI-compatible provider only implements chat/vectorize/pic2text.
const ALL_TAGS = [
  'sort',
  'chat',
  'mem',
  'analyze',
  'vectorize',
  'pic2text',
  'text2pic',
  'pic2pic',
  'text2vid',
  'img2vid',
  'sound2text',
  'text2sound',
]

const endpoints = ref<OpenAiEndpoint[]>([])
const endpointsLoading = ref(false)

const selectedEndpoint = ref<string | null>(null)
const service = ref('')
const capability = ref('chat')
const providerId = ref('')
const name = ref('')
const selectable = ref(true)
const active = ref(true)
const submitting = ref(false)

const currentEndpoint = computed<OpenAiEndpoint | null>(
  () => endpoints.value.find((ep) => ep.name === selectedEndpoint.value) ?? null
)

const availableTags = computed<string[]>(() => {
  const ep = currentEndpoint.value
  if (ep && ep.capabilities.length > 0) {
    return ep.capabilities.filter((c) => ALL_TAGS.includes(c))
  }
  return ALL_TAGS
})

function capabilityLabel(tag: string): string {
  const key = `config.aiModels.capabilities.${tag}`
  const label = t(key)
  return label === key ? tag : label
}

// Keep the selected capability valid when the available set changes
// (e.g. switching to an endpoint that only supports chat).
watch(availableTags, (tags) => {
  if (!tags.includes(capability.value)) {
    capability.value = tags[0] ?? 'chat'
  }
})

async function loadEndpoints() {
  endpointsLoading.value = true
  try {
    const res = await adminOpenAiEndpointsApi.list()
    endpoints.value = res.endpoints
  } catch {
    // Endpoints are optional here — a failure just means the built-in
    // (manual) path stays available. The endpoints panel surfaces the error.
    endpoints.value = []
  } finally {
    endpointsLoading.value = false
  }
}

async function submit() {
  const isEndpoint = selectedEndpoint.value !== null
  const serviceValue = isEndpoint ? OPENAI_COMPATIBLE_SERVICE : service.value.trim()
  const providerIdValue = providerId.value.trim()
  const nameValue = name.value.trim()

  if (!serviceValue) {
    showError(t('config.aiModels.admin.addForm.serviceRequired'))
    return
  }
  if (!providerIdValue) {
    showError(t('config.aiModels.admin.addForm.providerIdRequired'))
    return
  }
  if (!nameValue) {
    showError(t('config.aiModels.admin.addForm.nameRequired'))
    return
  }

  const payload: AdminModelCreateRequest = {
    service: serviceValue,
    tag: capability.value,
    providerId: providerIdValue,
    name: nameValue,
    selectable: selectable.value ? 1 : 0,
    active: active.value ? 1 : 0,
  }
  if (isEndpoint && selectedEndpoint.value) {
    payload.json = { endpoint: selectedEndpoint.value }
  }

  submitting.value = true
  try {
    await adminModelsApi.create(payload)
    success(t('config.aiModels.admin.addForm.created'))
    // Keep the endpoint + capability selection so several models can be
    // added to the same backbone in a row; only clear the per-model fields.
    providerId.value = ''
    name.value = ''
    emit('created')
  } catch (e: unknown) {
    showError(e instanceof Error ? e.message : t('config.aiModels.admin.failedCreate'))
  } finally {
    submitting.value = false
  }
}

onMounted(loadEndpoints)
</script>
