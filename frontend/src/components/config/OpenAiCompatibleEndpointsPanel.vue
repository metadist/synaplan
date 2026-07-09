<template>
  <div class="surface-card p-6" data-testid="openai-endpoints-panel">
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 mb-2">
      <h2 class="text-xl font-semibold txt-primary">
        {{ t('config.openaiEndpoints.title') }}
      </h2>
      <button
        type="button"
        class="px-4 py-2 rounded-lg border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm font-medium hover:bg-black/5 dark:hover:bg-white/5 transition"
        :disabled="loading"
        @click="load"
      >
        {{ t('config.openaiEndpoints.refresh') }}
      </button>
    </div>
    <p class="text-sm txt-secondary mb-5">
      {{ t('config.openaiEndpoints.subtitle') }}
    </p>

    <!-- Add / edit form -->
    <div class="rounded-lg border border-light-border/30 dark:border-dark-border/20 p-4 mb-6">
      <div class="text-sm font-semibold txt-primary mb-3">
        {{ editingName ? t('config.openaiEndpoints.editingTitle', { name: editingName }) : t('config.openaiEndpoints.addTitle') }}
      </div>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
        <div>
          <label class="block text-xs font-medium txt-secondary mb-1">{{ t('config.openaiEndpoints.nameLabel') }}</label>
          <input
            v-model="form.name"
            :disabled="editingName !== null"
            class="w-full px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm disabled:opacity-60"
            :placeholder="t('config.openaiEndpoints.namePlaceholder')"
          />
        </div>
        <div>
          <label class="block text-xs font-medium txt-secondary mb-1">{{ t('config.openaiEndpoints.labelLabel') }}</label>
          <input
            v-model="form.label"
            class="w-full px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm"
            :placeholder="t('config.openaiEndpoints.labelPlaceholder')"
          />
        </div>
        <div class="md:col-span-2">
          <label class="block text-xs font-medium txt-secondary mb-1">{{ t('config.openaiEndpoints.baseUrlLabel') }}</label>
          <input
            v-model="form.base_url"
            class="w-full px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm"
            placeholder="https://localai.example.com/v1"
          />
        </div>
        <div class="md:col-span-2">
          <label class="block text-xs font-medium txt-secondary mb-1">{{ t('config.openaiEndpoints.apiKeyLabel') }}</label>
          <input
            v-model="form.api_key"
            type="password"
            autocomplete="off"
            class="w-full px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm"
            :placeholder="editingName ? t('config.openaiEndpoints.apiKeyKeepPlaceholder') : t('config.openaiEndpoints.apiKeyPlaceholder')"
          />
          <p v-if="editingName" class="text-xs txt-secondary mt-1">
            {{ t('config.openaiEndpoints.apiKeyKeepHint') }}
          </p>
        </div>
      </div>

      <div class="mt-3">
        <label class="block text-xs font-medium txt-secondary mb-1">{{ t('config.openaiEndpoints.capabilitiesLabel') }}</label>
        <div class="flex flex-wrap gap-3">
          <label
            v-for="cap in availableCapabilities"
            :key="cap"
            class="flex items-center gap-2 text-sm txt-secondary"
          >
            <input
              v-model="form.capabilities"
              type="checkbox"
              :value="cap"
              class="rounded border-light-border/30 dark:border-dark-border/20 text-[var(--brand)] focus:ring-[var(--brand)]"
            />
            {{ cap }}
          </label>
        </div>
      </div>

      <div class="mt-4 flex flex-wrap items-center gap-3">
        <button
          type="button"
          class="px-4 py-2 rounded-lg bg-[var(--brand)] text-white text-sm font-medium hover:opacity-90 transition"
          :disabled="saving"
          @click="save"
        >
          {{ saving ? t('config.openaiEndpoints.saving') : t('config.openaiEndpoints.save') }}
        </button>
        <button
          type="button"
          class="px-4 py-2 rounded-lg border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm font-medium hover:bg-black/5 dark:hover:bg-white/5 transition"
          :disabled="testingForm"
          @click="testForm"
        >
          {{ testingForm ? t('config.openaiEndpoints.testing') : t('config.openaiEndpoints.test') }}
        </button>
        <button
          v-if="editingName"
          type="button"
          class="px-4 py-2 rounded-lg border border-light-border/30 dark:border-dark-border/20 txt-secondary text-sm font-medium hover:txt-primary transition"
          @click="resetForm"
        >
          {{ t('common.cancel') }}
        </button>

        <span v-if="formTestResult" class="text-sm" :class="formTestResult.ok ? 'text-green-500' : 'text-red-500'">
          {{ formatTestResult(formTestResult) }}
        </span>
      </div>
    </div>

    <!-- Existing endpoints -->
    <div v-if="loading" class="text-center py-8">
      <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-[var(--brand)]"></div>
    </div>

    <div v-else-if="endpoints.length === 0" class="text-sm txt-secondary py-4">
      {{ t('config.openaiEndpoints.empty') }}
    </div>

    <div v-else class="overflow-x-auto scroll-thin">
      <table class="w-full min-w-[720px]">
        <thead>
          <tr class="border-b-2 border-light-border/30 dark:border-dark-border/20">
            <th class="text-left py-3 px-2 txt-secondary text-xs font-semibold uppercase tracking-wide">{{ t('config.openaiEndpoints.colName') }}</th>
            <th class="text-left py-3 px-2 txt-secondary text-xs font-semibold uppercase tracking-wide">{{ t('config.openaiEndpoints.colBaseUrl') }}</th>
            <th class="text-left py-3 px-2 txt-secondary text-xs font-semibold uppercase tracking-wide">{{ t('config.openaiEndpoints.colKey') }}</th>
            <th class="text-left py-3 px-2 txt-secondary text-xs font-semibold uppercase tracking-wide">{{ t('config.openaiEndpoints.colCapabilities') }}</th>
            <th class="text-left py-3 px-2 txt-secondary text-xs font-semibold uppercase tracking-wide">{{ t('config.openaiEndpoints.colActions') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="ep in endpoints"
            :key="ep.name"
            class="border-b border-light-border/10 dark:border-dark-border/10"
          >
            <td class="py-2 px-2">
              <div class="txt-primary text-sm font-medium">{{ ep.label }}</div>
              <div class="txt-secondary text-xs">{{ ep.name }}</div>
            </td>
            <td class="py-2 px-2 txt-secondary text-xs max-w-72 truncate" :title="ep.base_url">{{ ep.base_url }}</td>
            <td class="py-2 px-2">
              <span
                class="inline-block w-2 h-2 rounded-full"
                :class="ep.has_api_key ? 'bg-green-500' : 'bg-gray-400'"
                :title="ep.has_api_key ? t('config.openaiEndpoints.keySet') : t('config.openaiEndpoints.noKey')"
              />
            </td>
            <td class="py-2 px-2">
              <span
                v-for="cap in ep.capabilities"
                :key="cap"
                class="pill text-xs mr-1"
              >{{ cap }}</span>
            </td>
            <td class="py-2 px-2">
              <div class="flex items-center gap-1.5">
                <button
                  type="button"
                  class="px-3 py-1 rounded-lg border border-light-border/30 dark:border-dark-border/20 txt-secondary text-xs font-medium hover:txt-primary hover:border-[var(--brand)]/50 transition"
                  @click="startEdit(ep)"
                >
                  {{ t('config.openaiEndpoints.edit') }}
                </button>
                <button
                  type="button"
                  class="px-3 py-1 rounded-lg border border-light-border/30 dark:border-dark-border/20 txt-secondary text-xs font-medium hover:txt-primary transition"
                  :disabled="rowTestingName === ep.name"
                  @click="testExisting(ep)"
                >
                  {{ rowTestingName === ep.name ? t('config.openaiEndpoints.testing') : t('config.openaiEndpoints.test') }}
                </button>
                <button
                  type="button"
                  class="px-3 py-1 rounded-lg border border-red-500/40 text-red-500 text-xs font-medium hover:bg-red-500/10 transition"
                  :disabled="rowDeletingName === ep.name"
                  @click="remove(ep)"
                >
                  {{ rowDeletingName === ep.name ? t('config.openaiEndpoints.deleting') : t('config.openaiEndpoints.delete') }}
                </button>
              </div>
              <div
                v-if="rowTestResult[ep.name]"
                class="text-xs mt-1"
                :class="rowTestResult[ep.name].ok ? 'text-green-500' : 'text-red-500'"
              >
                {{ formatTestResult(rowTestResult[ep.name]) }}
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useNotification } from '@/composables/useNotification'
import { useDialog } from '@/composables/useDialog'
import {
  adminOpenAiEndpointsApi,
  type OpenAiEndpoint,
  type OpenAiEndpointTestResponse,
} from '@/services/api/adminOpenAiEndpointsApi'

const { t } = useI18n()
const dialog = useDialog()
const { success, error: showError } = useNotification()

const endpoints = ref<OpenAiEndpoint[]>([])
const availableCapabilities = ref<string[]>(['chat', 'vectorize', 'pic2text'])
const loading = ref(false)
const saving = ref(false)
const testingForm = ref(false)
const editingName = ref<string | null>(null)
const rowTestingName = ref<string | null>(null)
const rowDeletingName = ref<string | null>(null)
const formTestResult = ref<OpenAiEndpointTestResponse | null>(null)
const rowTestResult = reactive<Record<string, OpenAiEndpointTestResponse>>({})

interface EndpointForm {
  name: string
  label: string
  base_url: string
  api_key: string
  capabilities: string[]
}

const form = ref<EndpointForm>(emptyForm())

function emptyForm(): EndpointForm {
  return { name: '', label: '', base_url: '', api_key: '', capabilities: ['chat', 'vectorize'] }
}

function resetForm() {
  form.value = emptyForm()
  editingName.value = null
  formTestResult.value = null
}

function startEdit(ep: OpenAiEndpoint) {
  editingName.value = ep.name
  formTestResult.value = null
  form.value = {
    name: ep.name,
    label: ep.label,
    base_url: ep.base_url,
    api_key: '',
    capabilities: [...ep.capabilities],
  }
}

function formatTestResult(r: OpenAiEndpointTestResponse): string {
  if (r.ok) {
    return t('config.openaiEndpoints.testOk', { count: r.model_count ?? 0 })
  }
  return t('config.openaiEndpoints.testFail', { error: r.error ?? `HTTP ${r.status ?? '?'}` })
}

async function load() {
  loading.value = true
  try {
    const res = await adminOpenAiEndpointsApi.list()
    endpoints.value = res.endpoints
    if (res.capabilities?.length) {
      availableCapabilities.value = res.capabilities
    }
  } catch (e: unknown) {
    showError(e instanceof Error ? e.message : t('config.openaiEndpoints.failedLoad'))
  } finally {
    loading.value = false
  }
}

async function save() {
  const name = form.value.name.trim()
  const baseUrl = form.value.base_url.trim()
  if (!name) {
    showError(t('config.openaiEndpoints.nameRequired'))
    return
  }
  if (!baseUrl) {
    showError(t('config.openaiEndpoints.urlRequired'))
    return
  }

  saving.value = true
  try {
    const res = await adminOpenAiEndpointsApi.save({
      name,
      label: form.value.label.trim() || undefined,
      base_url: baseUrl,
      // Empty while editing → keep existing key (send null); empty while
      // creating → no key (send '').
      api_key: form.value.api_key !== '' ? form.value.api_key : editingName.value ? null : '',
      capabilities: form.value.capabilities,
    })
    endpoints.value = res.endpoints
    success(t('config.openaiEndpoints.saved'))
    resetForm()
  } catch (e: unknown) {
    showError(e instanceof Error ? e.message : t('config.openaiEndpoints.failedSave'))
  } finally {
    saving.value = false
  }
}

async function testForm() {
  testingForm.value = true
  formTestResult.value = null
  try {
    formTestResult.value = await adminOpenAiEndpointsApi.test({
      base_url: form.value.base_url.trim(),
      api_key: form.value.api_key !== '' ? form.value.api_key : editingName.value ? undefined : '',
      name: editingName.value ?? undefined,
    })
  } catch (e: unknown) {
    formTestResult.value = { ok: false, error: e instanceof Error ? e.message : 'error' }
  } finally {
    testingForm.value = false
  }
}

async function testExisting(ep: OpenAiEndpoint) {
  rowTestingName.value = ep.name
  try {
    rowTestResult[ep.name] = await adminOpenAiEndpointsApi.test({ name: ep.name })
  } catch (e: unknown) {
    rowTestResult[ep.name] = { ok: false, error: e instanceof Error ? e.message : 'error' }
  } finally {
    rowTestingName.value = null
  }
}

async function remove(ep: OpenAiEndpoint) {
  const confirmed = await dialog.confirm({
    title: t('config.openaiEndpoints.deleteTitle'),
    message: t('config.openaiEndpoints.deleteConfirm', { name: ep.name }),
    danger: true,
    confirmText: t('common.delete'),
    cancelText: t('common.cancel'),
  })
  if (!confirmed) {
    return
  }

  rowDeletingName.value = ep.name
  try {
    await adminOpenAiEndpointsApi.delete(ep.name)
    success(t('config.openaiEndpoints.deleted'))
    if (editingName.value === ep.name) {
      resetForm()
    }
    await load()
  } catch (e: unknown) {
    showError(e instanceof Error ? e.message : t('config.openaiEndpoints.failedDelete'))
  } finally {
    rowDeletingName.value = null
  }
}

onMounted(load)
</script>
