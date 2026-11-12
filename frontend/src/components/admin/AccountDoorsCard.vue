<script setup lang="ts">
/**
 * Who can create an account, read from the Authentication settings already
 * on this page. A row links to the existing switch. It does not save.
 */
import { computed, nextTick } from 'vue'
import { ChevronRightIcon, KeyIcon } from '@heroicons/vue/24/outline'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import {
  accountDoors,
  type AccountDoor,
  type DoorValue,
  type SocialDoorProvider,
} from '@/utils/accountDoors'

const props = defineProps<{
  values: Record<string, DoorValue | undefined>
  loading: boolean
  failed: boolean
}>()

const route = useRoute()
const router = useRouter()

const SOCIAL_LABELS: Record<SocialDoorProvider, string> = {
  google: 'Google',
  github: 'GitHub',
  apple: 'Apple',
}

const doors = computed(() => (props.failed || props.loading ? [] : accountDoors(props.values)))

function stateClass(state: AccountDoor['state']): string {
  if (state === 'open') return 'text-[var(--status-success-text)] bg-[var(--status-success-muted)]'
  if (state === 'closed')
    return 'text-[var(--status-warning-text)] bg-[var(--status-warning-muted)]'
  return 'text-[var(--status-neutral-text)] bg-[var(--status-neutral-muted)]'
}

function socialProvidersLabel(providers: readonly SocialDoorProvider[]): string {
  return providers.map((id) => SOCIAL_LABELS[id]).join(', ')
}

async function showSwitch(door: AccountDoor): Promise<void> {
  if (!door.section || !door.field) return
  const same =
    route.query.tab === 'auth' &&
    route.query.section === door.section &&
    route.query.highlight === door.field
  if (!same) {
    await router.replace({
      query: { ...route.query, tab: 'auth', section: door.section, highlight: door.field },
    })
  }
  await nextTick()
  const panel = document.getElementById(`config-section-${door.section}`)
  if (panel && panel.dataset.open !== 'true') {
    panel
      .querySelector<HTMLButtonElement>(
        `[data-testid="btn-config-section-${CSS.escape(door.section)}"]`
      )
      ?.click()
    await nextTick()
  }
  const fieldEl = panel?.querySelector<HTMLElement>(
    `[data-config-field="${CSS.escape(door.field)}"]`
  )
  fieldEl?.scrollIntoView?.({ behavior: 'smooth', block: 'center' })
  const control = fieldEl?.querySelector<HTMLElement>(
    'button[role="switch"]:not(:disabled), select:not(:disabled), input:not(:disabled)'
  )
  control?.focus?.({ preventScroll: true })
}
</script>

<template>
  <section
    class="surface-card rounded-xl p-5 flex flex-col gap-4"
    data-testid="account-doors"
    aria-labelledby="account-doors-title"
  >
    <div class="flex items-start gap-3">
      <KeyIcon class="w-5 h-5 text-[var(--brand)] shrink-0 mt-0.5" aria-hidden="true" />
      <div class="min-w-0 flex-1">
        <h3 id="account-doors-title" class="text-base font-semibold txt-primary">
          {{ $t('admin.config.accountDoors.title') }}
        </h3>
        <p class="text-xs txt-secondary mt-1">
          {{ $t('admin.config.accountDoors.hint') }}
        </p>
      </div>
    </div>

    <p
      v-if="failed"
      role="alert"
      class="text-sm text-red-600 dark:text-red-400"
      data-testid="account-doors-error"
    >
      {{ $t('admin.config.accountDoors.error') }}
    </p>
    <p
      v-else-if="loading"
      role="status"
      class="text-sm txt-secondary"
      data-testid="account-doors-loading"
    >
      {{ $t('admin.config.accountDoors.loading') }}
    </p>

    <ul v-else class="divide-y divide-light-border/20 dark:divide-dark-border/10">
      <li
        v-for="door in doors"
        :key="door.id"
        class="py-3.5 first:pt-0 last:pb-0 flex flex-col gap-2.5 sm:flex-row sm:items-start sm:justify-between"
        :data-testid="`account-door-${door.id}`"
        :data-state="door.state"
        :data-source="door.source"
        :data-reason="door.reason"
      >
        <div class="min-w-0 flex-1">
          <div class="flex flex-wrap items-center gap-2">
            <p
              :id="`account-door-title-${door.id}`"
              class="text-sm font-medium txt-primary break-words"
            >
              {{ $t(`admin.config.accountDoors.doors.${door.id}.title`) }}
            </p>
            <span
              class="text-xs font-medium px-2.5 py-0.5 rounded-full"
              :class="stateClass(door.state)"
            >
              {{ $t(`admin.config.accountDoors.state.${door.state}`) }}
            </span>
            <span class="text-xs txt-secondary break-all sm:break-normal">
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
          <p
            v-if="door.id === 'selfSignup' && door.state === 'open'"
            class="text-xs txt-secondary mt-1"
            data-testid="account-door-selfSignup-social"
          >
            {{
              door.socialProviders.length > 0
                ? $t('admin.config.accountDoors.doors.selfSignup.socialActive', {
                    providers: socialProvidersLabel(door.socialProviders),
                  })
                : $t('admin.config.accountDoors.doors.selfSignup.emailOnly')
            }}
          </p>
          <p v-if="door.humanCheck" class="text-xs txt-secondary mt-1">
            {{ $t('admin.config.accountDoors.doors.selfSignup.humanCheck') }}
          </p>
          <p
            v-if="door.id === 'directory' && door.state !== 'unset' && door.restricted"
            class="text-xs txt-secondary mt-1"
            data-testid="account-door-directory-restricted"
          >
            {{ $t('admin.config.accountDoors.doors.directory.restricted') }}
          </p>
        </div>
        <RouterLink
          v-if="door.people"
          to="/admin/people"
          :aria-describedby="`account-door-title-${door.id}`"
          class="btn-secondary px-4 py-2.5 text-sm font-medium inline-flex items-center gap-1.5 shrink-0 self-start focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--brand)]"
          data-testid="account-door-operator-people"
        >
          {{ $t('admin.config.accountDoors.openPeople') }}
          <ChevronRightIcon class="w-4 h-4 shrink-0" aria-hidden="true" />
        </RouterLink>
        <button
          v-else
          type="button"
          :aria-describedby="`account-door-title-${door.id}`"
          class="btn-secondary px-4 py-2.5 text-sm font-medium inline-flex items-center gap-1.5 shrink-0 self-start focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--brand)]"
          :data-testid="`account-door-${door.id}-switch`"
          @click="showSwitch(door)"
        >
          {{ $t('admin.config.accountDoors.showSwitch') }}
          <ChevronRightIcon class="w-4 h-4 shrink-0" aria-hidden="true" />
        </button>
      </li>
    </ul>
  </section>
</template>
