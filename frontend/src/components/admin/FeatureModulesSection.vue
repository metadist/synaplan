<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { Icon } from '@iconify/vue'
import type { FeatureModule } from '@/services/featuresService'

/**
 * Operate → Feature status: the declared feature modules of this installation.
 * One row per module with its state, what configures it (key names only —
 * never values) and the way to the enable guide. Admin-only page, so the
 * env-key names are safe to show here.
 */
const props = defineProps<{
  modules: FeatureModule[]
}>()

const DOCS_URL = 'https://docs.synaplan.com/'

const { t, te } = useI18n()

const sorted = computed(() =>
  [...props.modules].sort((a, b) => {
    const rank = (m: FeatureModule) =>
      m.state === 'needs_setup' ? 0 : m.state === 'available' ? 1 : 2
    return rank(a) - rank(b) || a.id.localeCompare(b.id)
  })
)

const configuredCount = computed(() => props.modules.filter((m) => m.configured).length)

const label = (module: FeatureModule): string =>
  te(module.label_key) ? t(module.label_key) : module.id

const stateClass = (state: FeatureModule['state']): string => {
  switch (state) {
    case 'available':
      return 'bg-[var(--status-success)] text-white shadow-sm'
    case 'needs_setup':
      return 'bg-[var(--status-warning)] text-white shadow-sm'
    default:
      return 'bg-[var(--status-neutral)] text-white shadow-sm'
  }
}

const configuredBy = (module: FeatureModule): string[] => [
  ...module.configured_by.env,
  ...module.configured_by.bconfig,
  ...module.configured_by.providers,
  ...module.configured_by.plugs,
]

const docsHref = (module: FeatureModule): string =>
  `${DOCS_URL}${module.docs_anchor.replace(/^\//, '')}`
</script>

<template>
  <div class="space-y-3" data-testid="section-modules">
    <div class="flex items-center gap-3 px-2 mb-4">
      <h2 class="text-xl font-semibold txt-primary">{{ t('settings.features.modulesTitle') }}</h2>
      <span class="text-sm txt-secondary" data-testid="text-modules-summary">
        {{
          t('settings.features.modulesConfigured', {
            configured: configuredCount,
            total: modules.length,
          })
        }}
      </span>
      <div class="h-px flex-1 bg-[var(--divider)]"></div>
    </div>
    <p class="px-2 text-sm txt-secondary">{{ t('settings.features.modulesSubtitle') }}</p>

    <div
      v-for="module in sorted"
      :key="module.id"
      class="surface-card p-5 hover:shadow-md transition-shadow"
      data-testid="item-module"
      :data-module="module.id"
      :data-state="module.state"
    >
      <div class="flex items-start justify-between gap-4">
        <div class="flex-1 min-w-0">
          <div class="flex items-center gap-2 flex-wrap mb-2">
            <h3 class="text-base font-semibold txt-primary">{{ label(module) }}</h3>
            <code class="text-xs txt-secondary font-mono opacity-60">{{ module.id }}</code>
          </div>
          <p class="txt-secondary text-sm">{{ module.message }}</p>

          <div class="mt-3 flex flex-wrap items-center gap-2">
            <span class="text-xs font-medium txt-primary">{{
              t('settings.features.configuredBy')
            }}</span>
            <code
              v-for="key in configuredBy(module)"
              :key="key"
              class="px-2 py-0.5 rounded-full text-xs font-mono surface-chip txt-secondary"
              data-testid="chip-configured-by"
              >{{ key }}</code
            >
          </div>

          <a
            v-if="module.docs_anchor"
            :href="docsHref(module)"
            target="_blank"
            rel="noopener noreferrer"
            class="mt-3 inline-flex items-center gap-1 text-sm text-[var(--brand)] hover:underline"
            data-testid="link-module-docs"
          >
            {{ t('settings.features.docs') }}
            <Icon icon="mdi:open-in-new" class="w-4 h-4" />
          </a>
        </div>

        <span
          :class="[
            'px-4 py-2 rounded-lg text-xs font-bold uppercase tracking-wide whitespace-nowrap flex-shrink-0',
            stateClass(module.state),
          ]"
          data-testid="badge-module-state"
        >
          {{ t(`modules.state.${module.state}`) }}
        </span>
      </div>
    </div>
  </div>
</template>
