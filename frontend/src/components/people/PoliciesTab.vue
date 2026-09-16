<template>
  <div data-testid="section-policies">
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <div class="surface-card rounded-lg p-6">
        <label class="block text-sm font-medium txt-primary mb-2" for="policy-group">
          {{ $t('people.policies.group') }}
        </label>
        <select
          id="policy-group"
          v-model="selectedId"
          class="w-full px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
          data-testid="select-policy-group"
        >
          <option :value="null" disabled>{{ $t('people.policies.chooseGroup') }}</option>
          <option v-for="group in groups" :key="group.id" :value="group.id">
            {{ group.name }}
          </option>
        </select>
        <p class="txt-secondary text-sm mt-3">{{ $t('people.policies.helper') }}</p>
      </div>

      <div class="lg:col-span-2 space-y-6">
        <div v-if="!selectedId" class="surface-card rounded-lg p-12 text-center txt-secondary">
          {{ $t('people.policies.pickGroup') }}
        </div>
        <div v-else-if="loading" class="surface-card rounded-lg p-12 text-center">
          <Icon icon="mdi:loading" class="w-8 h-8 animate-spin mx-auto txt-secondary" />
        </div>
        <template v-else>
          <section class="surface-card rounded-lg p-6" data-testid="section-policy-defaults">
            <h3 class="text-lg font-semibold txt-primary mb-4">
              {{ $t('people.policies.defaultModels') }}
            </h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <label
                v-for="cap in defaultCapabilities"
                :key="cap"
                class="block text-sm txt-primary"
              >
                <span class="font-medium">{{ $t(`people.policies.capability.${cap}`) }}</span>
                <select
                  class="w-full mt-1 px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)] disabled:opacity-50 disabled:cursor-not-allowed"
                  :value="stringSetting(`DEFAULTMODEL.${cap}`)"
                  :disabled="isLocked(`DEFAULTMODEL.${cap}`)"
                  :data-testid="`select-default-${cap}`"
                  @change="
                    onSelect(`DEFAULTMODEL.${cap}`, ($event.target as HTMLSelectElement).value)
                  "
                >
                  <option value="">{{ $t('people.policies.useGlobal') }}</option>
                  <option
                    v-for="model in modelsFor(cap)"
                    :key="model.id"
                    :value="catalogKey(model)"
                  >
                    {{ model.name }}
                  </option>
                </select>
                <span
                  v-if="conflicts[`DEFAULTMODEL.${cap}`]"
                  class="block mt-1 text-xs txt-secondary"
                  data-testid="hint-policy-conflict"
                >
                  {{ $t('people.policies.conflict') }}
                </span>
              </label>
            </div>
          </section>

          <section class="surface-card rounded-lg p-6" data-testid="section-policy-allowed">
            <h3 class="text-lg font-semibold txt-primary mb-2">
              {{ $t('people.policies.allowedModels') }}
            </h3>
            <p class="txt-secondary text-sm mb-4">{{ $t('people.policies.allowedHelper') }}</p>
            <div class="max-h-64 overflow-y-auto space-y-4" data-testid="list-allowed-models">
              <template v-for="cap in defaultCapabilities" :key="cap">
                <div v-if="modelsFor(cap).length > 0" class="space-y-2">
                  <p class="text-xs font-medium txt-secondary">
                    {{ $t(`people.policies.capability.${cap}`) }}
                  </p>
                  <label
                    v-for="model in modelsFor(cap)"
                    :key="`${cap}-${model.id}`"
                    class="flex items-center gap-2 text-sm txt-primary"
                  >
                    <input
                      type="checkbox"
                      :checked="allowedKeys.includes(catalogKey(model))"
                      :disabled="isLocked('MODELS.ALLOWED')"
                      :data-testid="`check-allowed-${catalogKey(model)}`"
                      @change="toggleAllowed(catalogKey(model))"
                    />
                    <span>{{ model.name }}</span>
                  </label>
                </div>
              </template>
            </div>
          </section>

          <section class="surface-card rounded-lg p-6" data-testid="section-policy-features">
            <h3 class="text-lg font-semibold txt-primary mb-2">
              {{ $t('people.policies.features') }}
            </h3>
            <p class="txt-secondary text-sm mb-4">{{ $t('people.policies.featuresHelper') }}</p>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <label v-for="key in featureKeys" :key="key" class="block text-sm txt-primary">
                <span class="font-medium">{{
                  $t(`people.policies.feature.${featureI18nKey(key)}`)
                }}</span>
                <select
                  class="w-full mt-1 px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)] disabled:opacity-50 disabled:cursor-not-allowed"
                  :value="featureMode(key)"
                  :disabled="isLocked(key)"
                  :data-testid="`select-feature-${featureI18nKey(key)}`"
                  @change="onFeature(key, ($event.target as HTMLSelectElement).value)"
                >
                  <option value="inherit">{{ $t('people.policies.useGlobal') }}</option>
                  <option value="on">{{ $t('people.policies.featureOn') }}</option>
                  <option value="off">{{ $t('people.policies.featureOff') }}</option>
                </select>
                <span
                  class="block mt-1 text-xs txt-secondary"
                  :data-testid="`hint-feature-${featureI18nKey(key)}`"
                >
                  {{ featureHint(key) }}
                </span>
              </label>
            </div>
          </section>

          <section class="surface-card rounded-lg p-6" data-testid="section-policy-tier">
            <h3 class="text-lg font-semibold txt-primary mb-2">
              {{ $t('people.policies.rateLimit') }}
            </h3>
            <select
              class="w-full px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)] disabled:opacity-50 disabled:cursor-not-allowed"
              :value="stringSetting('RATELIMITS.TIER')"
              :disabled="isLocked('RATELIMITS.TIER')"
              data-testid="select-rate-tier"
              @change="onSelect('RATELIMITS.TIER', ($event.target as HTMLSelectElement).value)"
            >
              <option value="">{{ $t('people.policies.useBillingTier') }}</option>
              <option v-for="tier in tiers" :key="tier" :value="tier">{{ tier }}</option>
            </select>
          </section>

          <section class="surface-card rounded-lg p-6" data-testid="section-policy-locks">
            <h3 class="text-lg font-semibold txt-primary mb-2">
              {{ $t('people.policies.locks') }}
            </h3>
            <p class="txt-secondary text-sm mb-4">{{ $t('people.policies.locksHelper') }}</p>
            <label
              v-for="key in lockableKeys"
              :key="key"
              class="flex items-center justify-between gap-4 py-2 text-sm txt-primary"
            >
              <span>{{ lockLabel(key) }}</span>
              <input
                type="checkbox"
                :checked="locks[key] === true"
                :data-testid="`lock-${key}`"
                @change="onLock(key, ($event.target as HTMLInputElement).checked)"
              />
            </label>
          </section>

          <div class="flex justify-end">
            <button
              type="button"
              class="btn-primary px-4 py-2.5 rounded-lg"
              :disabled="saving"
              data-testid="btn-save-policies"
              @click="save"
            >
              {{ $t('people.policies.save') }}
            </button>
          </div>
        </template>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { Icon } from '@iconify/vue'
import { useNotification } from '@/composables/useNotification'
import { iamApi, type IamGroup, type IamGroupConfigSetting } from '@/services/api/iamApi'
import { getModels } from '@/services/api/configApi'
import type { AIModel, Capability } from '@/types/ai-models'

const { t } = useI18n()
const { success, error } = useNotification()

const defaultCapabilities = ['CHAT', 'VECTORIZE', 'PIC2TEXT', 'SOUND2TEXT', 'MEM', 'TOOLS'] as const
const featureKeys = [
  'SAVEDTASKS.ENABLED',
  'DESKTOP_AGENT.ENABLED',
  'DOCUMENT_TOOLS.ENABLED',
  'MULTITASK.ROUTING_ENABLED',
  'MULTITASK.PARALLEL_ENABLED',
  'MULTITASK.URL_FETCH_ENABLED',
  'MULTITASK.MCP_FETCH_ENABLED',
  'MULTITASK.MCP_ACTION_ENABLED',
  'MULTITASK.EMAIL_SEARCH_ENABLED',
  'TOOLS.REGISTRY_ENABLED',
  'TOOLS.APPROVALS_ENABLED',
  'TOOLS.CUSTOM_HTTP_ENABLED',
  'WORKFLOWS.BUILDER_ENABLED',
] as const
/** Code defaults used when no instance row exists. Matches the PHP resolvers. */
const featureBuiltinOn = new Set<string>([
  'MULTITASK.ROUTING_ENABLED',
  'MULTITASK.URL_FETCH_ENABLED',
  'TOOLS.REGISTRY_ENABLED',
])
const tiers = ['NEW', 'PRO', 'TEAM', 'BUSINESS'] as const
const lockableKeys = [
  'DEFAULTMODEL.CHAT',
  'DEFAULTMODEL.VECTORIZE',
  'DEFAULTMODEL.PIC2TEXT',
  'DEFAULTMODEL.SOUND2TEXT',
  'DEFAULTMODEL.MEM',
  'DEFAULTMODEL.TOOLS',
  'MODELS.ALLOWED',
  ...featureKeys,
  'RATELIMITS.TIER',
] as const

const groups = ref<IamGroup[]>([])
const selectedId = ref<number | null>(null)
const loading = ref(false)
const saving = ref(false)
const settings = ref<Record<string, IamGroupConfigSetting>>({})
const draft = ref<Record<string, unknown>>({})
const conflicts = ref<Record<string, string[]>>({})
const locks = ref<Record<string, boolean>>({})
const modelsByCap = ref<Partial<Record<Capability, AIModel[]>>>({})

onMounted(async () => {
  try {
    const [groupList, modelsRes, lockRes] = await Promise.all([
      iamApi.listAdminGroups(),
      getModels(),
      iamApi.listLocks(),
    ])
    groups.value = groupList
    modelsByCap.value = modelsRes.models ?? {}
    locks.value = lockRes
    if (groupList.length > 0) {
      selectedId.value = groupList[0].id
    }
  } catch {
    error(t('people.policies.loadError'))
  }
})

watch(selectedId, async (id) => {
  if (id === null) return
  loading.value = true
  draft.value = {}
  try {
    const data = await iamApi.getGroupConfig(id)
    settings.value = data.settings
    conflicts.value = data.conflicts
  } catch {
    error(t('people.policies.loadError'))
  } finally {
    loading.value = false
  }
})

function setting(key: string): IamGroupConfigSetting | undefined {
  return settings.value[key]
}

function stringSetting(key: string): string {
  if (key in draft.value) {
    const value = draft.value[key]
    return typeof value === 'string' ? value : ''
  }
  const current = setting(key)
  return current?.source === 'group' && typeof current.value === 'string' ? current.value : ''
}

type FeatureMode = 'inherit' | 'on' | 'off'

function isFeatureOn(value: unknown): boolean {
  return value === true || value === '1' || value === 1
}

function isFeatureOff(value: unknown): boolean {
  return value === false || value === '0' || value === 0
}

function featureMode(key: string): FeatureMode {
  if (key in draft.value) {
    const value = draft.value[key]
    if (isFeatureOn(value)) return 'on'
    if (isFeatureOff(value)) return 'off'
    return 'inherit'
  }
  const current = setting(key)
  if (current?.source === 'group') {
    return isFeatureOn(current.value) ? 'on' : 'off'
  }
  return 'inherit'
}

function featureHint(key: string): string {
  const mode = featureMode(key)
  if (mode === 'on') return t('people.policies.groupForcesOn')
  if (mode === 'off') return t('people.policies.groupForcesOff')
  const current = setting(key)
  if (current?.source === 'group') {
    return t('people.policies.willInherit')
  }
  if (isFeatureOn(current?.value)) return t('people.policies.inheritedOn')
  if (current?.source === 'admin' || isFeatureOff(current?.value)) {
    return t('people.policies.inheritedOff')
  }
  return featureBuiltinOn.has(key)
    ? t('people.policies.inheritedOn')
    : t('people.policies.inheritedOff')
}

const allowedKeys = computed(() => {
  if ('MODELS.ALLOWED' in draft.value && Array.isArray(draft.value['MODELS.ALLOWED'])) {
    return draft.value['MODELS.ALLOWED'] as string[]
  }
  const value = setting('MODELS.ALLOWED')?.value
  return Array.isArray(value) ? (value as string[]) : []
})

function isLocked(key: string): boolean {
  return locks.value[key] === true || setting(key)?.locked === true
}

function catalogKey(model: AIModel): string {
  const service = model.service.toLowerCase()
  const provider = (model.providerId ?? '').toLowerCase().replaceAll(':', '-')
  const tag = (model.tag ?? 'chat').toLowerCase()
  return `${service}:${provider}:${tag}`
}

function modelsFor(cap: string): AIModel[] {
  return modelsByCap.value[cap as Capability] ?? []
}

function featureI18nKey(key: string): string {
  return key.replaceAll('.', '_')
}

function lockLabel(key: string): string {
  if (key.startsWith('DEFAULTMODEL.')) {
    return t(`people.policies.capability.${key.slice('DEFAULTMODEL.'.length)}`)
  }
  if (key === 'MODELS.ALLOWED') return t('people.policies.allowedModels')
  if (key === 'RATELIMITS.TIER') return t('people.policies.rateLimit')
  return t(`people.policies.feature.${featureI18nKey(key)}`)
}

function onSelect(key: string, value: string) {
  draft.value = { ...draft.value, [key]: value }
}

function onFeature(key: string, mode: string) {
  if (mode === 'on') {
    draft.value = { ...draft.value, [key]: true }
    return
  }
  if (mode === 'off') {
    draft.value = { ...draft.value, [key]: false }
    return
  }
  draft.value = { ...draft.value, [key]: null }
}

function toggleAllowed(key: string) {
  const next = allowedKeys.value.includes(key)
    ? allowedKeys.value.filter((item) => item !== key)
    : [...allowedKeys.value, key]
  draft.value = { ...draft.value, 'MODELS.ALLOWED': next }
}

async function onLock(key: string, value: boolean) {
  try {
    const next = await iamApi.patchLocks({ [key]: value })
    locks.value = { ...locks.value, ...next }
    success(t('people.policies.lockSaved'))
  } catch {
    error(t('people.policies.saveError'))
  }
}

async function save() {
  if (selectedId.value === null) return
  saving.value = true
  try {
    const body: Record<string, unknown> = { ...draft.value }
    const data = await iamApi.putGroupConfig(selectedId.value, body)
    settings.value = data.settings
    conflicts.value = data.conflicts
    draft.value = {}
    success(t('people.policies.saved'))
  } catch {
    error(t('people.policies.saveError'))
  } finally {
    saving.value = false
  }
}
</script>
