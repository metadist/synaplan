<template>
  <div class="space-y-6" data-testid="admin-ai-models-panel">
    <div class="surface-card p-6">
      <h2 class="text-xl font-semibold txt-primary mb-4">
        {{ t('config.aiModels.admin.addModels') }}
      </h2>

      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium txt-secondary mb-2">{{
            t('config.aiModels.admin.urlsLabel')
          }}</label>
          <textarea
            v-model="urlsText"
            class="w-full h-28 px-4 py-3 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
            :placeholder="t('config.aiModels.admin.urlsPlaceholder')"
          />
        </div>
        <div>
          <label class="block text-sm font-medium txt-secondary mb-2">{{
            t('config.aiModels.admin.textDumpLabel')
          }}</label>
          <textarea
            v-model="textDump"
            class="w-full h-28 px-4 py-3 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
            :placeholder="t('config.aiModels.admin.textDumpPlaceholder')"
          />
        </div>
      </div>

      <div class="mt-4 flex flex-wrap items-center gap-3">
        <label class="flex items-center gap-2 text-sm txt-secondary">
          <input
            v-model="allowDelete"
            type="checkbox"
            class="rounded border-light-border/30 dark:border-dark-border/20"
          />
          {{ t('config.aiModels.admin.allowDelete') }}
        </label>

        <button
          type="button"
          class="px-4 py-2 rounded-lg bg-[var(--brand)] text-white text-sm font-medium hover:opacity-90 transition"
          :disabled="importLoading"
          @click="generatePreview"
        >
          {{
            importLoading
              ? t('config.aiModels.admin.generating')
              : t('config.aiModels.admin.generateSql')
          }}
        </button>

        <button
          type="button"
          class="px-4 py-2 rounded-lg border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm font-medium hover:bg-black/5 dark:hover:bg-white/5 transition"
          :disabled="applyLoading || !sqlPreview || !validationOk"
          @click="applySql"
        >
          {{
            applyLoading ? t('config.aiModels.admin.applying') : t('config.aiModels.admin.applySql')
          }}
        </button>
      </div>

      <div class="mt-4">
        <label class="block text-sm font-medium txt-secondary mb-2">{{
          t('config.aiModels.admin.sqlPreview')
        }}</label>
        <textarea
          v-model="sqlPreview"
          class="w-full h-48 px-4 py-3 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 font-mono text-xs txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
          :placeholder="t('config.aiModels.admin.sqlPreviewPlaceholder')"
        />

        <div
          v-if="validationErrors.length > 0"
          class="mt-3 p-3 rounded-lg bg-red-500/5 border border-red-500/20"
        >
          <div class="text-sm font-semibold text-red-500 mb-1">
            {{ t('config.aiModels.admin.validationErrors') }}
          </div>
          <ul class="text-sm txt-primary list-disc pl-5">
            <li v-for="(e, i) in validationErrors" :key="i">{{ e }}</li>
          </ul>
        </div>

        <div
          v-else-if="sqlPreview"
          class="mt-3 p-3 rounded-lg bg-green-500/5 border border-green-500/20"
        >
          <div class="text-sm txt-primary">
            {{ t('config.aiModels.admin.validated') }}:
            <span class="font-semibold">{{ statements.length }}</span>
            {{ t('config.aiModels.admin.statements') }}
          </div>
          <div v-if="aiProvider || aiModel" class="text-xs txt-secondary mt-1">
            {{ t('config.aiModels.admin.generatedBy') }}:
            {{ aiProvider || t('config.aiModels.admin.unknown') }} /
            {{ aiModel || t('config.aiModels.admin.unknown') }}
          </div>
        </div>
      </div>
    </div>

    <div class="surface-card p-6" data-testid="admin-ai-models-editor">
      <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 mb-4">
        <h2 class="text-xl font-semibold txt-primary">
          {{ t('config.aiModels.admin.editModels') }}
        </h2>
        <div class="flex items-center gap-3">
          <input
            v-model="adminSearch"
            type="text"
            class="px-3 py-2 rounded-lg border border-light-border/30 dark:border-dark-border/20 bg-light-surface dark:bg-dark-surface txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
            :placeholder="t('config.aiModels.admin.searchPlaceholder')"
          />
          <button
            type="button"
            class="px-4 py-2 rounded-lg border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm font-medium hover:bg-black/5 dark:hover:bg-white/5 transition"
            :disabled="modelsLoading"
            @click="loadModels"
          >
            {{ t('config.aiModels.admin.refresh') }}
          </button>
        </div>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-4 gap-3 mb-4">
        <input
          v-model="newModel.service"
          class="px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm"
          :placeholder="t('config.aiModels.admin.servicePlaceholder')"
        />
        <input
          v-model="newModel.tag"
          class="px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm"
          :placeholder="t('config.aiModels.admin.tagPlaceholder')"
        />
        <input
          v-model="newModel.providerId"
          class="px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm"
          :placeholder="t('config.aiModels.admin.providerIdPlaceholder')"
        />
        <input
          v-model="newModel.name"
          class="px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm"
          :placeholder="t('config.aiModels.admin.namePlaceholder')"
        />
      </div>

      <div class="flex flex-wrap items-center gap-3 mb-6">
        <button
          type="button"
          class="px-4 py-2 rounded-lg bg-[var(--brand)] text-white text-sm font-medium hover:opacity-90 transition"
          :disabled="createLoading"
          @click="createModel"
        >
          {{
            createLoading
              ? t('config.aiModels.admin.creating')
              : t('config.aiModels.admin.createModel')
          }}
        </button>
        <div class="text-xs txt-secondary">
          {{ t('config.aiModels.admin.uniqueKey') }}:
          <span class="txt-primary font-semibold">BSERVICE + BTAG + BPROVID</span>
        </div>
      </div>

      <div v-if="modelsLoading" class="text-center py-8">
        <div
          class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-[var(--brand)]"
        ></div>
        <p class="mt-2 txt-secondary">{{ t('config.aiModels.admin.loadingModels') }}</p>
      </div>

      <div v-else class="overflow-x-auto scroll-thin">
        <table class="w-full min-w-[980px]">
          <thead>
            <tr class="border-b-2 border-light-border/30 dark:border-dark-border/20">
              <th
                class="text-left py-3 px-2 txt-secondary text-xs font-semibold uppercase tracking-wide"
              >
                {{ t('config.aiModels.admin.id') }}
              </th>
              <th
                class="text-left py-3 px-2 txt-secondary text-xs font-semibold uppercase tracking-wide"
              >
                {{ t('config.aiModels.admin.service') }}
              </th>
              <th
                class="text-left py-3 px-2 txt-secondary text-xs font-semibold uppercase tracking-wide"
              >
                {{ t('config.aiModels.admin.tag') }}
              </th>
              <th
                class="text-left py-3 px-2 txt-secondary text-xs font-semibold uppercase tracking-wide"
              >
                {{ t('config.aiModels.admin.providerId') }}
              </th>
              <th
                class="text-left py-3 px-2 txt-secondary text-xs font-semibold uppercase tracking-wide"
              >
                {{ t('config.aiModels.admin.name') }}
              </th>
              <th
                class="text-left py-3 px-2 txt-secondary text-xs font-semibold uppercase tracking-wide"
              >
                {{ t('config.aiModels.admin.selectable') }}
              </th>
              <th
                class="text-left py-3 px-2 txt-secondary text-xs font-semibold uppercase tracking-wide"
              >
                {{ t('config.aiModels.admin.active') }}
              </th>
              <th
                class="text-left py-3 px-2 txt-secondary text-xs font-semibold uppercase tracking-wide"
              >
                {{ t('config.aiModels.admin.priceIn') }}
              </th>
              <th
                class="text-left py-3 px-2 txt-secondary text-xs font-semibold uppercase tracking-wide"
              >
                {{ t('config.aiModels.admin.unitIn') }}
              </th>
              <th
                class="text-left py-3 px-2 txt-secondary text-xs font-semibold uppercase tracking-wide"
              >
                {{ t('config.aiModels.admin.priceOut') }}
              </th>
              <th
                class="text-left py-3 px-2 txt-secondary text-xs font-semibold uppercase tracking-wide"
              >
                {{ t('config.aiModels.admin.unitOut') }}
              </th>
              <th
                class="text-left py-3 px-2 txt-secondary text-xs font-semibold uppercase tracking-wide"
              >
                {{ t('config.aiModels.admin.actions') }}
              </th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="m in paginatedAdminModels"
              :key="m.id"
              class="border-b border-light-border/10 dark:border-dark-border/10 transition-colors"
              :class="
                editingId === m.id
                  ? 'bg-[var(--brand)]/5'
                  : 'hover:bg-black/5 dark:hover:bg-white/5'
              "
            >
              <td class="py-2 px-2">
                <span class="pill text-xs">{{ m.id }}</span>
              </td>

              <template v-if="editingId === m.id">
                <td class="py-2 px-2">
                  <input
                    v-model="editForm.service"
                    class="w-32 px-2 py-1 rounded surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-xs focus:ring-2 focus:ring-[var(--brand)] focus:outline-none"
                  />
                </td>
                <td class="py-2 px-2">
                  <input
                    v-model="editForm.tag"
                    class="w-24 px-2 py-1 rounded surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-xs focus:ring-2 focus:ring-[var(--brand)] focus:outline-none"
                  />
                </td>
                <td class="py-2 px-2">
                  <input
                    v-model="editForm.providerId"
                    class="w-56 px-2 py-1 rounded surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-xs focus:ring-2 focus:ring-[var(--brand)] focus:outline-none"
                  />
                </td>
                <td class="py-2 px-2">
                  <input
                    v-model="editForm.name"
                    class="w-44 px-2 py-1 rounded surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-xs focus:ring-2 focus:ring-[var(--brand)] focus:outline-none"
                  />
                </td>
                <td class="py-2 px-2">
                  <select
                    v-model.number="editForm.selectable"
                    class="px-2 py-1 rounded surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-xs focus:ring-2 focus:ring-[var(--brand)] focus:outline-none"
                  >
                    <option :value="0">0</option>
                    <option :value="1">1</option>
                  </select>
                </td>
                <td class="py-2 px-2">
                  <select
                    v-model.number="editForm.active"
                    class="px-2 py-1 rounded surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-xs focus:ring-2 focus:ring-[var(--brand)] focus:outline-none"
                  >
                    <option :value="0">0</option>
                    <option :value="1">1</option>
                  </select>
                </td>
                <td class="py-2 px-2">
                  <input
                    v-model.number="editForm.priceIn"
                    type="number"
                    step="0.000001"
                    class="w-24 px-2 py-1 rounded surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-xs focus:ring-2 focus:ring-[var(--brand)] focus:outline-none"
                  />
                </td>
                <td class="py-2 px-2">
                  <input
                    v-model="editForm.inUnit"
                    class="w-24 px-2 py-1 rounded surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-xs focus:ring-2 focus:ring-[var(--brand)] focus:outline-none"
                  />
                </td>
                <td class="py-2 px-2">
                  <input
                    v-model.number="editForm.priceOut"
                    type="number"
                    step="0.000001"
                    class="w-24 px-2 py-1 rounded surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-xs focus:ring-2 focus:ring-[var(--brand)] focus:outline-none"
                  />
                </td>
                <td class="py-2 px-2">
                  <input
                    v-model="editForm.outUnit"
                    class="w-24 px-2 py-1 rounded surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-xs focus:ring-2 focus:ring-[var(--brand)] focus:outline-none"
                  />
                </td>
                <td class="py-2 px-2">
                  <div class="flex items-center gap-1.5">
                    <button
                      type="button"
                      class="px-3 py-1 rounded-lg bg-[var(--brand)] text-white text-xs font-medium hover:opacity-90 transition"
                      :disabled="rowSavingId === m.id"
                      @click="saveEditingModel(m.id)"
                    >
                      {{
                        rowSavingId === m.id
                          ? t('config.aiModels.admin.saving')
                          : t('config.aiModels.admin.save')
                      }}
                    </button>
                    <button
                      type="button"
                      class="px-3 py-1 rounded-lg border border-light-border/30 dark:border-dark-border/20 txt-secondary text-xs font-medium hover:txt-primary transition"
                      @click="cancelEdit"
                    >
                      {{ t('common.cancel') }}
                    </button>
                  </div>
                </td>
              </template>

              <template v-else>
                <td class="py-2 px-2 txt-primary text-xs">{{ m.service }}</td>
                <td class="py-2 px-2 txt-primary text-xs">{{ m.tag }}</td>
                <td class="py-2 px-2 txt-primary text-xs max-w-56 truncate" :title="m.providerId">
                  {{ m.providerId }}
                </td>
                <td class="py-2 px-2 txt-primary text-xs font-medium">{{ m.name }}</td>
                <td class="py-2 px-2 text-center">
                  <span
                    class="inline-block w-2 h-2 rounded-full"
                    :class="m.selectable ? 'bg-green-500' : 'bg-gray-400'"
                    :title="m.selectable ? '1' : '0'"
                  />
                </td>
                <td class="py-2 px-2 text-center">
                  <span
                    class="inline-block w-2 h-2 rounded-full"
                    :class="m.active ? 'bg-green-500' : 'bg-gray-400'"
                    :title="m.active ? '1' : '0'"
                  />
                </td>
                <td class="py-2 px-2 txt-secondary text-xs tabular-nums">{{ m.priceIn }}</td>
                <td class="py-2 px-2 txt-secondary text-xs">{{ m.inUnit }}</td>
                <td class="py-2 px-2 txt-secondary text-xs tabular-nums">{{ m.priceOut }}</td>
                <td class="py-2 px-2 txt-secondary text-xs">{{ m.outUnit }}</td>
                <td class="py-2 px-2">
                  <div class="flex items-center gap-1.5">
                    <button
                      type="button"
                      class="px-3 py-1 rounded-lg border border-light-border/30 dark:border-dark-border/20 txt-secondary text-xs font-medium hover:txt-primary hover:border-[var(--brand)]/50 transition"
                      @click="startEdit(m)"
                    >
                      {{ t('config.aiModels.admin.edit') }}
                    </button>
                    <button
                      type="button"
                      class="px-3 py-1 rounded-lg border border-red-500/40 text-red-500 text-xs font-medium hover:bg-red-500/10 transition"
                      :disabled="rowDeletingId === m.id"
                      @click="deleteModel(m)"
                    >
                      {{
                        rowDeletingId === m.id
                          ? t('config.aiModels.admin.deleting')
                          : t('config.aiModels.admin.delete')
                      }}
                    </button>
                  </div>
                </td>
              </template>
            </tr>
          </tbody>
        </table>
      </div>

      <div
        v-if="adminTotalPages > 1"
        class="flex items-center justify-between pt-4 border-t border-light-border/10 dark:border-dark-border/10 mt-4"
      >
        <span class="text-sm txt-secondary">
          {{
            t('config.aiModels.pagination.showing', {
              start: (adminPage - 1) * ADMIN_PER_PAGE + 1,
              end: Math.min(adminPage * ADMIN_PER_PAGE, filteredAdminModels.length),
              total: filteredAdminModels.length,
            })
          }}
        </span>
        <div class="flex items-center gap-1">
          <button
            class="p-1.5 rounded-lg border border-light-border/30 dark:border-dark-border/20 txt-secondary hover:txt-primary transition-colors disabled:opacity-30 disabled:cursor-not-allowed"
            :disabled="adminPage <= 1"
            @click="adminPage--"
          >
            <ChevronLeftIcon class="w-4 h-4" />
          </button>
          <span class="px-3 text-sm txt-primary font-medium">
            {{ adminPage }} / {{ adminTotalPages }}
          </span>
          <button
            class="p-1.5 rounded-lg border border-light-border/30 dark:border-dark-border/20 txt-secondary hover:txt-primary transition-colors disabled:opacity-30 disabled:cursor-not-allowed"
            :disabled="adminPage >= adminTotalPages"
            @click="adminPage++"
          >
            <ChevronRightIcon class="w-4 h-4" />
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { ChevronLeftIcon, ChevronRightIcon } from '@heroicons/vue/20/solid'
import { useI18n } from 'vue-i18n'
import { useNotification } from '@/composables/useNotification'
import { useDialog } from '@/composables/useDialog'
import { adminModelsApi, type AdminModel } from '@/services/api/adminModelsApi'

const { t } = useI18n()
const dialog = useDialog()

interface NewModel {
  service: string
  tag: string
  providerId: string
  name: string
}

interface EditForm {
  service: string
  tag: string
  providerId: string
  name: string
  selectable: number
  active: number
  priceIn: number
  inUnit: string
  priceOut: number
  outUnit: string
  quality: number
  rating: number
  description: string | null
  json: Record<string, unknown>
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
const adminPage = ref(1)
const ADMIN_PER_PAGE = 20
const adminSearch = ref('')
const editingId = ref<number | null>(null)
const editForm = ref<EditForm>({
  service: '',
  tag: '',
  providerId: '',
  name: '',
  selectable: 1,
  active: 1,
  priceIn: 0,
  inUnit: 'per1M',
  priceOut: 0,
  outUnit: 'per1M',
  quality: 7,
  rating: 0.5,
  description: null,
  json: {},
})

const newModel = ref<NewModel>({
  service: '',
  tag: '',
  providerId: '',
  name: '',
})

const validationOk = computed(
  () => validationErrors.value.length === 0 && statements.value.length > 0
)

const filteredAdminModels = computed(() => {
  const q = adminSearch.value.trim().toLowerCase()
  if (!q) return adminModels.value
  return adminModels.value.filter(
    (m) =>
      m.name.toLowerCase().includes(q) ||
      m.service.toLowerCase().includes(q) ||
      m.providerId.toLowerCase().includes(q) ||
      m.tag.toLowerCase().includes(q) ||
      String(m.id).includes(q)
  )
})

const adminTotalPages = computed(() =>
  Math.max(1, Math.ceil(filteredAdminModels.value.length / ADMIN_PER_PAGE))
)

const paginatedAdminModels = computed(() => {
  const start = (adminPage.value - 1) * ADMIN_PER_PAGE
  return filteredAdminModels.value.slice(start, start + ADMIN_PER_PAGE)
})

watch(adminSearch, () => {
  adminPage.value = 1
})

function startEdit(m: AdminModel) {
  editingId.value = m.id
  editForm.value = {
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
  }
}

function cancelEdit() {
  editingId.value = null
}

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
  } catch (e: unknown) {
    const msg = e instanceof Error ? e.message : t('config.aiModels.admin.failedGenerate')
    showError(msg)
  } finally {
    importLoading.value = false
  }
}

async function applySql() {
  applyLoading.value = true
  try {
    const res = await adminModelsApi.importApply({
      sql: sqlPreview.value,
      allowDelete: allowDelete.value,
    })
    success(t('config.aiModels.admin.appliedStatements', { count: res.applied }))
    await loadModels()
  } catch (e: unknown) {
    const msg = e instanceof Error ? e.message : t('config.aiModels.admin.failedApply')
    showError(msg)
  } finally {
    applyLoading.value = false
  }
}

async function loadModels() {
  modelsLoading.value = true
  editingId.value = null
  try {
    const res = await adminModelsApi.list()
    adminModels.value = res.models
  } catch (e: unknown) {
    const msg = e instanceof Error ? e.message : t('config.aiModels.admin.failedLoad')
    showError(msg)
  } finally {
    modelsLoading.value = false
  }
}

async function createModel() {
  const service = newModel.value.service.trim()
  const tag = newModel.value.tag.trim()
  const providerId = newModel.value.providerId.trim()
  const name = newModel.value.name.trim()

  if (!service || !tag || !providerId || !name) {
    showError(t('config.aiModels.admin.requiredFields'))
    return
  }

  createLoading.value = true
  try {
    await adminModelsApi.create({ service, tag, providerId, name })
    success(t('config.aiModels.admin.modelCreated'))
    newModel.value = { service: '', tag: '', providerId: '', name: '' }
    await loadModels()
  } catch (e: unknown) {
    const msg = e instanceof Error ? e.message : t('config.aiModels.admin.failedCreate')
    showError(msg)
  } finally {
    createLoading.value = false
  }
}

async function saveEditingModel(id: number) {
  rowSavingId.value = id
  try {
    const form = editForm.value
    const res = await adminModelsApi.update(id, {
      service: form.service,
      tag: form.tag,
      providerId: form.providerId,
      name: form.name,
      selectable: form.selectable,
      active: form.active,
      priceIn: form.priceIn,
      inUnit: form.inUnit,
      priceOut: form.priceOut,
      outUnit: form.outUnit,
      quality: form.quality,
      rating: form.rating,
      description: form.description,
      json: form.json,
    })
    const idx = adminModels.value.findIndex((m) => m.id === id)
    if (idx !== -1) {
      adminModels.value[idx] = res.model
    }
    editingId.value = null
    success(t('config.aiModels.admin.modelSaved'))
  } catch (e: unknown) {
    const msg = e instanceof Error ? e.message : t('config.aiModels.admin.failedSave')
    showError(msg)
  } finally {
    rowSavingId.value = null
  }
}

async function deleteModel(m: AdminModel) {
  const confirmed = await dialog.confirm({
    title: t('config.aiModels.admin.deleteModelTitle'),
    message: t('config.aiModels.admin.deleteConfirm', {
      id: m.id,
      service: m.service,
      tag: m.tag,
      providerId: m.providerId,
    }),
    danger: true,
    confirmText: t('common.delete'),
    cancelText: t('common.cancel'),
  })

  if (!confirmed) {
    return
  }

  rowDeletingId.value = m.id
  try {
    await adminModelsApi.delete(m.id)
    success(t('config.aiModels.admin.modelDeleted'))
    await loadModels()
  } catch (e: unknown) {
    const msg = e instanceof Error ? e.message : t('config.aiModels.admin.failedDelete')
    showError(msg)
  } finally {
    rowDeletingId.value = null
  }
}

onMounted(async () => {
  await loadModels()
})
</script>
