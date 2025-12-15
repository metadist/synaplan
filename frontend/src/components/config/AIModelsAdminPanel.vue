<template>
  <div class="space-y-6" data-testid="admin-ai-models-panel">
    <div class="surface-card p-6">
      <h2 class="text-xl font-semibold txt-primary mb-4">{{ t('config.aiModels.admin.addModels') }}</h2>

      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium txt-secondary mb-2">{{ t('config.aiModels.admin.urlsLabel') }}</label>
          <textarea
            v-model="urlsText"
            class="w-full h-28 px-4 py-3 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
            :placeholder="t('config.aiModels.admin.urlsPlaceholder')"
          />
        </div>
        <div>
          <label class="block text-sm font-medium txt-secondary mb-2">{{ t('config.aiModels.admin.textDumpLabel') }}</label>
          <textarea
            v-model="textDump"
            class="w-full h-28 px-4 py-3 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
            :placeholder="t('config.aiModels.admin.textDumpPlaceholder')"
          />
        </div>
      </div>

      <div class="mt-4 flex flex-wrap items-center gap-3">
        <label class="flex items-center gap-2 text-sm txt-secondary">
          <input v-model="allowDelete" type="checkbox" class="rounded border-light-border/30 dark:border-dark-border/20" />
          {{ t('config.aiModels.admin.allowDelete') }}
        </label>

        <button
          type="button"
          class="px-4 py-2 rounded-lg bg-[var(--brand)] text-white text-sm font-medium hover:opacity-90 transition"
          :disabled="importLoading"
          @click="generatePreview"
        >
          {{ importLoading ? t('config.aiModels.admin.generating') : t('config.aiModels.admin.generateSql') }}
        </button>

        <button
          type="button"
          class="px-4 py-2 rounded-lg border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm font-medium hover:bg-black/5 dark:hover:bg-white/5 transition"
          :disabled="applyLoading || !sqlPreview || !validationOk"
          @click="applySql"
        >
          {{ applyLoading ? t('config.aiModels.admin.applying') : t('config.aiModels.admin.applySql') }}
        </button>

      </div>

      <div class="mt-4">
        <label class="block text-sm font-medium txt-secondary mb-2">{{ t('config.aiModels.admin.sqlPreview') }}</label>
        <textarea
          v-model="sqlPreview"
          class="w-full h-48 px-4 py-3 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 font-mono text-xs txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
          :placeholder="t('config.aiModels.admin.sqlPreviewPlaceholder')"
        />

        <div v-if="validationErrors.length > 0" class="mt-3 p-3 rounded-lg bg-red-500/5 border border-red-500/20">
          <div class="text-sm font-semibold text-red-500 mb-1">{{ t('config.aiModels.admin.validationErrors') }}</div>
          <ul class="text-sm txt-primary list-disc pl-5">
            <li v-for="(e, i) in validationErrors" :key="i">{{ e }}</li>
          </ul>
        </div>

        <div v-else-if="sqlPreview" class="mt-3 p-3 rounded-lg bg-green-500/5 border border-green-500/20">
          <div class="text-sm txt-primary">
            {{ t('config.aiModels.admin.validated') }}: <span class="font-semibold">{{ statements.length }}</span> {{ t('config.aiModels.admin.statements') }}
          </div>
          <div class="text-xs txt-secondary mt-1" v-if="aiProvider || aiModel">
            {{ t('config.aiModels.admin.generatedBy') }}: {{ aiProvider || t('config.aiModels.admin.unknown') }} / {{ aiModel || t('config.aiModels.admin.unknown') }}
          </div>
        </div>
      </div>
    </div>

    <div class="surface-card p-6" data-testid="admin-ai-models-editor">
      <div class="flex items-center justify-between gap-3 mb-4">
        <h2 class="text-xl font-semibold txt-primary">{{ t('config.aiModels.admin.editModels') }}</h2>
        <button
          type="button"
          class="px-4 py-2 rounded-lg border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm font-medium hover:bg-black/5 dark:hover:bg-white/5 transition"
          :disabled="modelsLoading"
          @click="loadModels"
        >
          {{ t('config.aiModels.admin.refresh') }}
        </button>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-4 gap-3 mb-4">
        <input v-model="newModel.service" class="px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm" :placeholder="t('config.aiModels.admin.servicePlaceholder')" />
        <input v-model="newModel.tag" class="px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm" :placeholder="t('config.aiModels.admin.tagPlaceholder')" />
        <input v-model="newModel.providerId" class="px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm" :placeholder="t('config.aiModels.admin.providerIdPlaceholder')" />
        <input v-model="newModel.name" class="px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm" :placeholder="t('config.aiModels.admin.namePlaceholder')" />
      </div>

      <div class="flex flex-wrap items-center gap-3 mb-6">
        <button
          type="button"
          class="px-4 py-2 rounded-lg bg-[var(--brand)] text-white text-sm font-medium hover:opacity-90 transition"
          :disabled="createLoading"
          @click="createModel"
        >
          {{ createLoading ? t('config.aiModels.admin.creating') : t('config.aiModels.admin.createModel') }}
        </button>
        <div class="text-xs txt-secondary">
          {{ t('config.aiModels.admin.uniqueKey') }}: <span class="txt-primary font-semibold">BSERVICE + BTAG + BPROVID</span>
        </div>
      </div>

      <div v-if="modelsLoading" class="text-center py-8">
        <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-[var(--brand)]"></div>
        <p class="mt-2 txt-secondary">{{ t('config.aiModels.admin.loadingModels') }}</p>
      </div>

      <div v-else class="overflow-x-auto scroll-thin">
        <table class="w-full min-w-[980px]">
          <thead>
            <tr class="border-b-2 border-light-border/30 dark:border-dark-border/20">
              <th class="text-left py-3 px-2 txt-secondary text-xs font-semibold uppercase tracking-wide">{{ t('config.aiModels.admin.id') }}</th>
              <th class="text-left py-3 px-2 txt-secondary text-xs font-semibold uppercase tracking-wide">{{ t('config.aiModels.admin.service') }}</th>
              <th class="text-left py-3 px-2 txt-secondary text-xs font-semibold uppercase tracking-wide">{{ t('config.aiModels.admin.tag') }}</th>
              <th class="text-left py-3 px-2 txt-secondary text-xs font-semibold uppercase tracking-wide">{{ t('config.aiModels.admin.providerId') }}</th>
              <th class="text-left py-3 px-2 txt-secondary text-xs font-semibold uppercase tracking-wide">{{ t('config.aiModels.admin.name') }}</th>
              <th class="text-left py-3 px-2 txt-secondary text-xs font-semibold uppercase tracking-wide">{{ t('config.aiModels.admin.selectable') }}</th>
              <th class="text-left py-3 px-2 txt-secondary text-xs font-semibold uppercase tracking-wide">{{ t('config.aiModels.admin.active') }}</th>
              <th class="text-left py-3 px-2 txt-secondary text-xs font-semibold uppercase tracking-wide">{{ t('config.aiModels.admin.priceIn') }}</th>
              <th class="text-left py-3 px-2 txt-secondary text-xs font-semibold uppercase tracking-wide">{{ t('config.aiModels.admin.unitIn') }}</th>
              <th class="text-left py-3 px-2 txt-secondary text-xs font-semibold uppercase tracking-wide">{{ t('config.aiModels.admin.priceOut') }}</th>
              <th class="text-left py-3 px-2 txt-secondary text-xs font-semibold uppercase tracking-wide">{{ t('config.aiModels.admin.unitOut') }}</th>
              <th class="text-left py-3 px-2 txt-secondary text-xs font-semibold uppercase tracking-wide">{{ t('config.aiModels.admin.actions') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="m in adminModels"
              :key="m.id"
              class="border-b border-light-border/10 dark:border-dark-border/10 hover:bg-black/5 dark:hover:bg-white/5 transition-colors"
            >
              <td class="py-2 px-2"><span class="pill text-xs">{{ m.id }}</span></td>
              <td class="py-2 px-2"><input v-model="m.service" class="w-32 px-2 py-1 rounded surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-xs" /></td>
              <td class="py-2 px-2"><input v-model="m.tag" class="w-24 px-2 py-1 rounded surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-xs" /></td>
              <td class="py-2 px-2"><input v-model="m.providerId" class="w-56 px-2 py-1 rounded surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-xs" /></td>
              <td class="py-2 px-2"><input v-model="m.name" class="w-44 px-2 py-1 rounded surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-xs" /></td>
              <td class="py-2 px-2">
                <select v-model.number="m.selectable" class="px-2 py-1 rounded surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-xs">
                  <option :value="0">0</option>
                  <option :value="1">1</option>
                </select>
              </td>
              <td class="py-2 px-2">
                <select v-model.number="m.active" class="px-2 py-1 rounded surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-xs">
                  <option :value="0">0</option>
                  <option :value="1">1</option>
                </select>
              </td>
              <td class="py-2 px-2"><input v-model.number="m.priceIn" type="number" step="0.000001" class="w-24 px-2 py-1 rounded surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-xs" /></td>
              <td class="py-2 px-2"><input v-model="m.inUnit" class="w-24 px-2 py-1 rounded surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-xs" /></td>
              <td class="py-2 px-2"><input v-model.number="m.priceOut" type="number" step="0.000001" class="w-24 px-2 py-1 rounded surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-xs" /></td>
              <td class="py-2 px-2"><input v-model="m.outUnit" class="w-24 px-2 py-1 rounded surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-xs" /></td>
              <td class="py-2 px-2">
                <div class="flex items-center gap-2">
                  <button
                    type="button"
                    class="px-3 py-1 rounded-lg bg-[var(--brand)] text-white text-xs font-medium hover:opacity-90 transition"
                    :disabled="rowSavingId === m.id"
                    @click="saveModel(m)"
                  >
                    {{ rowSavingId === m.id ? t('config.aiModels.admin.saving') : t('config.aiModels.admin.save') }}
                  </button>
                  <button
                    type="button"
                    class="px-3 py-1 rounded-lg border border-red-500/40 text-red-500 text-xs font-medium hover:bg-red-500/10 transition"
                    :disabled="rowDeletingId === m.id"
                    @click="deleteModel(m)"
                  >
                    {{ rowDeletingId === m.id ? t('config.aiModels.admin.deleting') : t('config.aiModels.admin.delete') }}
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useNotification } from '@/composables/useNotification'
import { adminModelsApi, type AdminModel } from '@/services/api/adminModelsApi'

const { t } = useI18n()

interface NewModel {
  service: string
  tag: string
  providerId: string
  name: string
}

const { success, error: showError } = useNotification()

const urlsText = ref('')
const textDump = ref('')
const allowDelete = ref(false)

const importLoading = ref(false)
const applyLoading = ref(false)

const sqlPreview = ref('')
const validationErrors = ref<string[]>([])
const statements = ref<string[]>([])
const aiProvider = ref<string | null>(null)
const aiModel = ref<string | null>(null)

const adminModels = ref<AdminModel[]>([])
const modelsLoading = ref(false)
const createLoading = ref(false)
const rowSavingId = ref<number | null>(null)
const rowDeletingId = ref<number | null>(null)

const newModel = ref<NewModel>({
  service: '',
  tag: '',
  providerId: '',
  name: '',
})

const validationOk = computed(() => validationErrors.value.length === 0 && statements.value.length > 0)

function parseUrls(): string[] {
  return urlsText.value
    .split('\n')
    .map((s: string) => s.trim())
    .filter(Boolean)
}

async function generatePreview() {
  importLoading.value = true
  validationErrors.value = []
  statements.value = []
  try {
    const res = await adminModelsApi.importPreview({
      urls: parseUrls(),
      textDump: textDump.value,
      allowDelete: allowDelete.value,
    })
    sqlPreview.value = res.sql
    aiProvider.value = res.ai.provider
    aiModel.value = res.ai.model
    validationErrors.value = res.validation.errors || []
    statements.value = res.validation.statements || []
  } catch (e: any) {
    showError(e?.response?.data?.error || t('config.aiModels.admin.failedGenerate'))
  } finally {
    importLoading.value = false
  }
}

async function applySql() {
  applyLoading.value = true
  try {
    const res = await adminModelsApi.importApply({ sql: sqlPreview.value, allowDelete: allowDelete.value })
    success(t('config.aiModels.admin.appliedStatements', { count: res.applied }))
    await loadModels()
  } catch (e: any) {
    showError(e?.response?.data?.error || t('config.aiModels.admin.failedApply'))
  } finally {
    applyLoading.value = false
  }
}

async function loadModels() {
  modelsLoading.value = true
  try {
    const res = await adminModelsApi.list()
    adminModels.value = res.models
  } catch (e: any) {
    showError(e?.response?.data?.error || t('config.aiModels.admin.failedLoad'))
  } finally {
    modelsLoading.value = false
  }
}

async function createModel() {
  createLoading.value = true
  try {
    const payload = {
      service: newModel.value.service.trim(),
      tag: newModel.value.tag.trim(),
      providerId: newModel.value.providerId.trim(),
      name: newModel.value.name.trim(),
    }
    await adminModelsApi.create(payload)
    success(t('config.aiModels.admin.modelCreated'))
    newModel.value = { service: '', tag: '', providerId: '', name: '' }
    await loadModels()
  } catch (e: any) {
    showError(e?.response?.data?.error || t('config.aiModels.admin.failedCreate'))
  } finally {
    createLoading.value = false
  }
}

async function saveModel(m: AdminModel) {
  rowSavingId.value = m.id
  try {
    await adminModelsApi.update(m.id, {
      service: m.service,
      tag: m.tag,
      providerId: m.providerId,
      name: m.name,
      selectable: m.selectable,
      active: m.active,
      priceIn: m.priceIn,
      inUnit: m.inUnit,
      priceOut: m.priceOut,
      outUnit: m.outUnit,
      quality: m.quality,
      rating: m.rating,
      description: m.description,
      json: m.json,
    })
    success(t('config.aiModels.admin.modelSaved'))
  } catch (e: any) {
    showError(e?.response?.data?.error || t('config.aiModels.admin.failedSave'))
    await loadModels()
  } finally {
    rowSavingId.value = null
  }
}

async function deleteModel(m: AdminModel) {
  if (!confirm(t('config.aiModels.admin.deleteConfirm', { id: m.id, service: m.service, tag: m.tag, providerId: m.providerId }))) return
  rowDeletingId.value = m.id
  try {
    await adminModelsApi.delete(m.id)
    success(t('config.aiModels.admin.modelDeleted'))
    await loadModels()
  } catch (e: any) {
    showError(e?.response?.data?.error || t('config.aiModels.admin.failedDelete'))
  } finally {
    rowDeletingId.value = null
  }
}

onMounted(async () => {
  await loadModels()
})
</script>


