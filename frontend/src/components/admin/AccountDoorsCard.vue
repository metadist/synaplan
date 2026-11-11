<script setup lang="ts">
/**
 * Who can create an account, read from the Authentication settings already
 * on this page. A row links to the existing switch. It does not save.
 */
import { computed, nextTick } from 'vue'
import { Icon } from '@iconify/vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { accountDoors, type AccountDoor, type DoorValue } from '@/utils/accountDoors'

const props = defineProps<{
  values: Record<string, DoorValue | undefined>
  loading: boolean
  failed: boolean
}>()

const route = useRoute()
const router = useRouter()

const doors = computed(() => (props.failed || props.loading ? [] : accountDoors(props.values)))

function stateClass(state: AccountDoor['state']): string {
  if (state === 'open') return 'text-[var(--status-success-text)] bg-[var(--status-success-muted)]'
  if (state === 'closed')
    return 'text-[var(--status-warning-text)] bg-[var(--status-warning-muted)]'
  return 'txt-secondary border border-light-border/30 dark:border-dark-border/20'
}

async function showSwitch(door: AccountDoor): Promise<void> {
  if (!door.section || !door.field) return
  const same =
    route.query.tab === 'auth' &&
    route.query.section === door.section &&
    route.query.highlight === door.field
  if (same) {
    const panel = document.getElementById(`config-section-${door.section}`)
    if (panel?.dataset.open !== 'true') {
      panel
        ?.querySelector<HTMLButtonElement>(
          `[data-testid="btn-config-section-${CSS.escape(door.section)}"]`
        )
        ?.click()
      await nextTick()
    }
    panel
      ?.querySelector<HTMLElement>(`[data-config-field="${CSS.escape(door.field)}"]`)
      ?.scrollIntoView({ behavior: 'smooth', block: 'center' })
    return
  }
  await router.replace({
    query: { ...route.query, tab: 'auth', section: door.section, highlight: door.field },
  })
}
</script>

<template>
  <section
    class="rounded-lg p-4 border border-light-border/30 dark:border-dark-border/20 bg-chat flex flex-col gap-3"
    data-testid="account-doors"
    aria-labelledby="account-doors-title"
  >
    <div>
      <h3 id="account-doors-title" class="text-sm font-medium txt-primary">
        {{ $t('admin.config.accountDoors.title') }}
      </h3>
      <p class="text-xs txt-secondary mt-1">
        {{ $t('admin.config.accountDoors.hint') }}
      </p>
    </div>

    <p
      v-if="failed"
      class="text-sm text-red-600 dark:text-red-400"
      data-testid="account-doors-error"
    >
      {{ $t('admin.config.accountDoors.error') }}
    </p>
    <p v-else-if="loading" class="text-sm txt-secondary" data-testid="account-doors-loading">
      {{ $t('admin.config.accountDoors.loading') }}
    </p>

    <ul v-else class="flex flex-col gap-3">
      <li
        v-for="door in doors"
        :key="door.id"
        class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between"
        :data-testid="`account-door-${door.id}`"
        :data-state="door.state"
        :data-source="door.source"
        :data-reason="door.reason"
      >
        <div class="min-w-0 flex-1">
          <div class="flex flex-wrap items-center gap-2">
            <p class="text-sm font-medium txt-primary">
              {{ $t(`admin.config.accountDoors.doors.${door.id}.title`) }}
            </p>
            <span
              class="text-xs font-medium px-2 py-0.5 rounded-full"
              :class="stateClass(door.state)"
            >
              {{ $t(`admin.config.accountDoors.state.${door.state}`) }}
            </span>
            <span class="text-xs txt-secondary">
              {{
                door.pin
                  ? $t('admin.config.accountDoors.source.env', { key: door.pin })
                  : $t(`admin.config.accountDoors.source.${door.source}`)
              }}
            </span>
          </div>
          <p class="text-sm txt-secondary mt-1">
            {{ $t(`admin.config.accountDoors.doors.${door.id}.${door.reason}`) }}
          </p>
          <p v-if="door.humanCheck" class="text-xs txt-secondary mt-1">
            {{ $t('admin.config.accountDoors.doors.selfSignup.humanCheck') }}
          </p>
        </div>
        <RouterLink
          v-if="door.people"
          to="/admin/people"
          class="btn-secondary px-3 py-2 text-sm font-medium inline-flex items-center gap-1.5 self-start"
          data-testid="account-door-operator-people"
        >
          {{ $t('admin.config.accountDoors.openPeople') }}
          <Icon icon="heroicons:chevron-right" class="w-4 h-4" aria-hidden="true" />
        </RouterLink>
        <button
          v-else
          type="button"
          class="btn-secondary px-3 py-2 text-sm font-medium inline-flex items-center gap-1.5 self-start"
          :data-testid="`account-door-${door.id}-switch`"
          @click="showSwitch(door)"
        >
          {{ $t('admin.config.accountDoors.showSwitch') }}
          <Icon icon="heroicons:chevron-right" class="w-4 h-4" aria-hidden="true" />
        </button>
      </li>
    </ul>
  </section>
</template>
