<script setup lang="ts">
/**
 * Release notice for admins: which version runs here, whether a newer one
 * exists, and where the instructions are.
 *
 * Deliberately has NO button that changes the installation — Synaplan never
 * updates itself. Everything here either reads the stored check result or
 * records a display preference (acknowledged version, automatic check).
 */
import { computed, onMounted, ref } from 'vue'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import { useDateFormat } from '@/composables/useDateFormat'
import { useNotification } from '@/composables/useNotification'
import { useUpdatesStore } from '@/stores/updates'
import UpdateCheckToggle from '@/components/admin/UpdateCheckToggle.vue'

const { t } = useI18n()
const { formatDate, formatRelativeTime } = useDateFormat()
const { success, error: showError } = useNotification()
const updatesStore = useUpdatesStore()

const checking = ref(false)
const dismissing = ref(false)

const status = computed(() => updatesStore.status)
const isSecurity = computed(() => updatesStore.severity === 'security')

/**
 * A newer release exists. Unlike the sidebar badge this ignores the
 * acknowledged version: this card is the detail view, so it always states the
 * facts — acknowledging only silences the badge.
 */
const hasUpdate = computed(
  () => status.value?.updateAvailable === true && status.value.latestVersion !== null
)

/** The newer release is known, but the admin already acknowledged it. */
const isDismissed = computed(
  () => hasUpdate.value && status.value?.dismissedVersion === status.value?.latestVersion
)

/** A check succeeded and the release it found is not newer than the installed one. */
const isUpToDate = computed(
  () =>
    status.value !== null &&
    !status.value.updateAvailable &&
    status.value.latestVersion !== null &&
    status.value.lastCheckedAt !== null
)

const latestVersionLabel = computed(
  () => updatesStore.latestVersion ?? t('updates.panel.unknownVersion')
)

const releasedAtLabel = computed(() => {
  const releasedAt = status.value?.releasedAt
  if (!releasedAt) return ''

  const date = new Date(releasedAt)

  return Number.isNaN(date.getTime()) ? '' : formatDate(date)
})

const lastCheckedLabel = computed(() => {
  const lastCheckedAt = status.value?.lastCheckedAt
  if (!lastCheckedAt) return t('updates.panel.neverChecked')

  const date = new Date(lastCheckedAt)

  return Number.isNaN(date.getTime())
    ? t('updates.panel.neverChecked')
    : t('updates.panel.lastChecked', { time: formatRelativeTime(date) })
})

const statusToneClass = computed(() => {
  if (hasUpdate.value) {
    return isSecurity.value
      ? 'bg-[var(--status-error-muted)] text-[var(--status-error-text)]'
      : 'bg-[var(--status-warning-muted)] text-[var(--status-warning-text)]'
  }
  if (isUpToDate.value) {
    return 'bg-[var(--status-success-muted)] text-[var(--status-success-text)]'
  }

  return 'bg-[var(--status-neutral-muted)] text-[var(--status-neutral-text)]'
})

const statusLabel = computed(() => {
  if (hasUpdate.value) {
    return isSecurity.value ? t('updates.panel.securityUpdate') : t('updates.panel.updateAvailable')
  }
  if (isUpToDate.value) return t('updates.panel.upToDate')

  return t('updates.panel.unknownState')
})

async function handleCheckNow() {
  if (checking.value) return

  checking.value = true
  try {
    await updatesStore.checkNow()
    success(t('updates.panel.checkDone'))
  } catch {
    showError(t('updates.panel.checkError'))
  } finally {
    checking.value = false
  }
}

async function handleDismiss() {
  if (dismissing.value) return

  dismissing.value = true
  try {
    await updatesStore.dismissLatest()
    success(t('updates.panel.dismissDone'))
  } catch {
    showError(t('updates.panel.saveError'))
  } finally {
    dismissing.value = false
  }
}

onMounted(() => {
  updatesStore.ensureLoaded()
})
</script>

<template>
  <div class="surface-card rounded-xl p-6" data-testid="panel-admin-updates">
    <div class="flex items-start justify-between gap-3 mb-4">
      <div class="min-w-0">
        <h3 class="text-lg font-semibold txt-primary flex items-center gap-2">
          <Icon icon="mdi:package-variant" class="w-5 h-5 txt-secondary" aria-hidden="true" />
          {{ $t('updates.panel.title') }}
        </h3>
        <p class="text-xs txt-secondary mt-1">{{ $t('updates.panel.description') }}</p>
      </div>
      <button
        type="button"
        class="btn-secondary px-3 py-2 rounded-lg flex items-center gap-2 flex-shrink-0"
        :disabled="checking || !updatesStore.checkEnabled"
        :title="
          updatesStore.checkEnabled
            ? $t('updates.panel.checkNow')
            : $t('updates.panel.autoCheckOffHint')
        "
        data-testid="btn-admin-updates-check"
        @click="handleCheckNow"
      >
        <Icon
          :icon="checking ? 'mdi:loading' : 'mdi:refresh'"
          :class="['w-5 h-5', checking && 'animate-spin']"
          aria-hidden="true"
        />
        <span class="hidden sm:inline">{{ $t('updates.panel.checkNow') }}</span>
      </button>
    </div>

    <div v-if="!status" class="flex justify-center py-8" data-testid="state-admin-updates-loading">
      <Icon icon="mdi:loading" class="w-6 h-6 animate-spin txt-secondary" aria-hidden="true" />
    </div>

    <div v-else class="space-y-5">
      <!-- Version tiles -->
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div class="surface-elevated rounded-lg p-4">
          <span class="text-sm txt-secondary">{{ $t('updates.panel.currentVersion') }}</span>
          <div class="text-2xl font-bold txt-primary" data-testid="text-admin-updates-current">
            {{ status.currentVersion }}
          </div>
        </div>
        <div class="surface-elevated rounded-lg p-4">
          <span class="text-sm txt-secondary">{{ $t('updates.panel.latestVersion') }}</span>
          <div class="text-2xl font-bold txt-primary" data-testid="text-admin-updates-latest">
            {{ latestVersionLabel }}
          </div>
          <div v-if="releasedAtLabel" class="text-xs txt-secondary mt-1">
            {{ $t('updates.panel.releasedOn', { date: releasedAtLabel }) }}
          </div>
        </div>
      </div>

      <!-- Status + actions -->
      <div class="flex flex-wrap items-center gap-3">
        <span
          :class="[
            'inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium',
            statusToneClass,
          ]"
          data-testid="badge-admin-updates-status"
        >
          <Icon
            :icon="hasUpdate ? 'mdi:arrow-up-circle' : 'mdi:check-circle'"
            class="w-3.5 h-3.5"
            aria-hidden="true"
          />
          {{ statusLabel }}
        </span>
        <span class="text-xs txt-secondary" data-testid="text-admin-updates-last-checked">
          {{ lastCheckedLabel }}
        </span>
      </div>

      <div v-if="hasUpdate" class="flex flex-wrap items-center gap-3">
        <a
          v-if="status.guideUrl"
          :href="status.guideUrl"
          target="_blank"
          rel="noopener noreferrer"
          class="btn-primary px-4 py-2 rounded-lg inline-flex items-center gap-2 text-sm font-medium"
          data-testid="link-admin-updates-guide"
        >
          <Icon icon="mdi:book-open-variant" class="w-4 h-4" aria-hidden="true" />
          {{ $t('updates.panel.openGuide') }}
        </a>
        <a
          v-if="status.notesUrl"
          :href="status.notesUrl"
          target="_blank"
          rel="noopener noreferrer"
          class="text-sm txt-brand hover:underline inline-flex items-center gap-1"
          data-testid="link-admin-updates-notes"
        >
          <Icon icon="mdi:open-in-new" class="w-4 h-4" aria-hidden="true" />
          {{ $t('updates.panel.releaseNotes') }}
        </a>
        <button
          v-if="!isDismissed"
          type="button"
          class="text-sm txt-secondary hover:txt-primary disabled:opacity-50"
          :disabled="dismissing"
          data-testid="btn-admin-updates-dismiss"
          @click="handleDismiss"
        >
          {{ $t('updates.panel.dismiss') }}
        </button>
        <span
          v-else
          class="text-sm txt-secondary inline-flex items-center gap-1.5"
          data-testid="text-admin-updates-dismissed"
        >
          <Icon icon="mdi:bell-off-outline" class="w-4 h-4" aria-hidden="true" />
          {{ $t('updates.panel.noticeHidden') }}
        </span>
      </div>

      <!-- A failed check is usually just a missing internet connection: keep it
           a hint, never a warning surface. -->
      <p
        v-if="status.lastError"
        class="text-xs txt-secondary flex items-start gap-1.5"
        :title="status.lastError"
        data-testid="hint-admin-updates-error"
      >
        <Icon icon="mdi:information-outline" class="w-4 h-4 flex-shrink-0" aria-hidden="true" />
        {{ $t('updates.panel.checkFailedHint') }}
      </p>

      <UpdateCheckToggle />
    </div>
  </div>
</template>
