<script setup lang="ts">
/**
 * Read-only status for system-config fields that Models & keys owns (D2,
 * Helm-first). Instance provider keys are never edited here: the card says
 * which keys come from the environment / a chart install, which have a UI
 * override, and links to the one editor. A chart install needs no UI save.
 * It replaces the section body when every field is managed and sits below
 * the remaining inputs in a mixed section — the key list is shown in both
 * cases so "is my key set?" is answered on this page.
 */
import { computed } from 'vue'
import { Icon } from '@iconify/vue'
import { RouterLink } from 'vue-router'
import type { ConfigValue } from '@/services/api/adminConfigApi'

const props = defineProps<{
  fields: { key: string; value: ConfigValue }[]
}>()

type KeyRow = { key: string; state: 'env' | 'db' | 'none' }

const rows = computed<KeyRow[]>(() =>
  props.fields.map((f) => ({
    key: f.key,
    state: !f.value.isSet ? 'none' : f.value.keySource === 'db' ? 'db' : 'env',
  }))
)

const envCount = computed(() => rows.value.filter((r) => r.state === 'env').length)
const dbCount = computed(() => rows.value.filter((r) => r.state === 'db').length)
</script>

<template>
  <div
    class="rounded-lg p-4 border border-light-border/30 dark:border-dark-border/20 bg-chat flex flex-col gap-3"
    data-testid="managed-keys-status-card"
  >
    <div class="flex items-start gap-3">
      <Icon icon="mdi:key-chain-variant" class="w-5 h-5 shrink-0 mt-0.5 text-[var(--brand)]" />
      <div class="min-w-0 flex-1">
        <p class="text-sm font-medium txt-primary">
          {{ $t('admin.config.managedKeys.title') }}
        </p>
        <p class="text-xs txt-secondary mt-1">
          {{ $t('admin.config.managedKeys.helm') }}
        </p>
      </div>
    </div>

    <ul class="flex flex-wrap gap-2" data-testid="managed-keys-list">
      <li
        v-for="row in rows"
        :key="row.key"
        class="inline-flex items-center gap-1.5 text-xs px-2 py-1 rounded-full border border-light-border/30 dark:border-dark-border/20"
        :data-testid="`managed-key-${row.key}`"
        :data-state="row.state"
      >
        <Icon
          :icon="
            row.state === 'none'
              ? 'mdi:circle-outline'
              : row.state === 'db'
                ? 'mdi:lock-check'
                : 'mdi:server-network'
          "
          :class="[
            'w-3.5 h-3.5',
            row.state === 'none' ? 'txt-secondary' : 'text-[var(--status-success)]',
          ]"
        />
        <code class="txt-primary">{{ row.key }}</code>
        <span class="txt-secondary">
          {{
            row.state === 'none'
              ? $t('admin.config.managedKeys.stateNone')
              : row.state === 'db'
                ? $t('admin.config.managedKeys.stateDb')
                : $t('admin.config.managedKeys.stateEnv')
          }}
        </span>
      </li>
    </ul>

    <p class="text-xs txt-secondary" data-testid="managed-keys-summary">
      {{ $t('admin.config.managedKeys.summary', { env: envCount, db: dbCount }) }}
    </p>

    <RouterLink
      to="/admin/setup"
      class="inline-flex items-center gap-1.5 text-sm font-medium text-[var(--brand)] hover:underline self-start"
      data-testid="managed-keys-link"
    >
      {{ $t('admin.config.managedKeys.cta') }}
      <Icon icon="mdi:arrow-right" class="w-4 h-4" aria-hidden="true" />
    </RouterLink>
  </div>
</template>
