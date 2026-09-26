<template>
  <div v-if="isDesktopAgentEnabled()" class="space-y-6" data-testid="page-config-desktop">
    <PageHeader
      :title="$t('config.desktop.title')"
      :subtitle="$t('config.desktop.description')"
      icon="heroicons:computer-desktop"
      data-testid="section-header"
    >
      <template #actions>
        <button
          class="btn-primary px-5 py-2.5 rounded-lg font-medium text-sm inline-flex items-center gap-2"
          data-testid="btn-pair"
          @click="openPairing"
        >
          <PlusIcon class="w-5 h-5" />
          {{ $t('config.desktop.pairButton') }}
        </button>
      </template>
    </PageHeader>

    <!-- Get the app: the client is a public beta on GitHub (build from source or
         a beta build from Releases). Links go to the repository, never to a
         binary we do not host. -->
    <div v-if="devices.length === 0" class="surface-card p-5 md:p-6" data-testid="card-get-desktop">
      <div class="grid gap-6 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
        <div class="min-w-0 space-y-4">
          <div class="flex items-start gap-4">
            <div
              class="flex-shrink-0 w-12 h-12 rounded-xl bg-[var(--brand-alpha-light)] flex items-center justify-center"
            >
              <ArrowDownTrayIcon class="w-6 h-6 txt-brand" />
            </div>
            <div class="min-w-0">
              <div class="flex flex-wrap items-center gap-2">
                <h2 class="text-lg font-semibold txt-primary">
                  {{ $t('config.desktop.get.title') }}
                </h2>
                <span
                  class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold uppercase tracking-wider bg-[var(--brand-alpha-light)] txt-brand"
                  data-testid="badge-desktop-beta"
                >
                  {{ $t('config.desktop.get.beta') }}
                </span>
              </div>
              <p class="mt-1 text-sm txt-secondary">{{ $t('config.desktop.get.description') }}</p>
            </div>
          </div>

          <ul class="flex flex-wrap gap-2" :aria-label="$t('config.desktop.get.platforms')">
            <li
              v-for="platform in platforms"
              :key="platform.id"
              class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-sm font-medium surface-chip txt-primary cursor-default select-none"
            >
              <Icon :icon="platform.icon" class="w-4 h-4" aria-hidden="true" />
              {{ platform.label }}
            </li>
          </ul>

          <div class="flex flex-wrap items-center gap-3">
            <a
              :href="DESKTOP_REPO_URL"
              target="_blank"
              rel="noopener noreferrer"
              class="btn-primary px-4 py-2.5 rounded-lg text-sm font-medium inline-flex items-center gap-2"
              data-testid="link-desktop-github"
            >
              <Icon icon="mdi:github" class="w-5 h-5" aria-hidden="true" />
              {{ $t('config.desktop.get.github') }}
            </a>
            <a
              :href="`${DESKTOP_REPO_URL}/releases`"
              target="_blank"
              rel="noopener noreferrer"
              class="btn-secondary px-4 py-2.5 rounded-lg text-sm font-medium inline-flex items-center gap-2"
              data-testid="link-desktop-releases"
            >
              <ArrowTopRightOnSquareIcon class="w-4 h-4" aria-hidden="true" />
              {{ $t('config.desktop.get.releases') }}
            </a>
          </div>

          <p class="text-xs txt-secondary flex items-start gap-2">
            <InformationCircleIcon class="w-4 h-4 mt-0.5 shrink-0" aria-hidden="true" />
            <span>{{ $t('config.desktop.get.betaNote') }}</span>
          </p>
        </div>

        <div
          class="rounded-xl border border-light-border/30 dark:border-dark-border/20 p-4 space-y-3"
          data-testid="section-desktop-steps"
        >
          <h3 class="text-sm font-semibold txt-primary">
            {{ $t('config.desktop.get.stepsTitle') }}
          </h3>
          <ol class="space-y-3 text-sm">
            <li v-for="step in 3" :key="step" class="flex items-start gap-3">
              <span
                class="flex-shrink-0 w-6 h-6 rounded-full flex items-center justify-center text-xs font-semibold bg-[var(--brand)] text-[var(--on-brand)]"
              >
                {{ step }}
              </span>
              <span class="txt-secondary leading-snug">
                {{ $t(`config.desktop.get.step${step}`) }}
              </span>
            </li>
          </ol>
        </div>
      </div>
    </div>
    <div
      v-else
      class="surface-card px-4 py-3 flex flex-wrap items-center justify-between gap-3"
      data-testid="card-get-desktop-compact"
    >
      <p class="text-sm txt-secondary">{{ $t('config.desktop.get.compact') }}</p>
      <a
        :href="DESKTOP_REPO_URL"
        target="_blank"
        rel="noopener noreferrer"
        class="btn-secondary px-4 py-2.5 rounded-lg text-sm font-medium inline-flex items-center gap-2"
        data-testid="link-desktop-github"
      >
        <Icon icon="mdi:github" class="w-5 h-5" aria-hidden="true" />
        {{ $t('config.desktop.get.github') }}
      </a>
    </div>

    <!-- Error Alert -->
    <div
      v-if="error"
      class="bg-red-500/10 border border-red-500/30 rounded-lg p-4 flex items-start gap-3"
      data-testid="alert-error"
    >
      <ExclamationTriangleIcon class="w-5 h-5 text-red-500 mt-0.5 shrink-0" />
      <p class="flex-1 text-red-500 text-sm font-medium">{{ error }}</p>
      <button
        class="text-red-500 hover:text-red-600 text-sm font-medium underline"
        data-testid="btn-alert-retry"
        @click="loadAll"
      >
        {{ $t('common.retry') }}
      </button>
    </div>

    <!-- Loading -->
    <div
      v-if="loading && devices.length === 0"
      class="surface-card p-12 text-center"
      data-testid="section-loading"
    >
      <ArrowPathIcon class="w-10 h-10 mx-auto txt-secondary mb-4 animate-spin" />
      <p class="txt-secondary">{{ $t('common.loading') }}</p>
    </div>

    <!-- Empty -->
    <div
      v-else-if="devices.length === 0"
      class="surface-card p-12 text-center"
      data-testid="section-empty"
    >
      <ComputerDesktopIcon class="w-16 h-16 mx-auto txt-secondary mb-4" />
      <p class="txt-secondary text-lg">{{ $t('config.desktop.devices.empty') }}</p>
      <button
        type="button"
        class="btn-primary mt-4 px-4 py-2.5 rounded-lg text-sm font-medium inline-flex items-center gap-2"
        data-testid="btn-pair-empty"
        @click="openPairing"
      >
        <PlusIcon class="w-5 h-5" />
        {{ $t('config.desktop.pairButton') }}
      </button>
    </div>

    <!-- One card per computer so Disconnect stays on screen at 320px. -->
    <ul v-else class="space-y-3" data-testid="section-devices">
      <li
        v-for="device in devices"
        :key="device.id"
        class="surface-card p-4 space-y-3"
        data-testid="item-device"
      >
        <div class="flex items-start justify-between gap-3">
          <div class="flex items-center gap-2 min-w-0">
            <ComputerDesktopIcon class="w-5 h-5 txt-secondary shrink-0" />
            <span class="text-sm font-medium txt-primary break-words">{{ device.name }}</span>
          </div>
          <span
            class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium shrink-0"
            :class="presenceClass(presenceOf(device))"
            data-testid="text-device-presence"
          >
            <span
              class="w-1.5 h-1.5 rounded-full"
              :class="presenceDotClass(presenceOf(device))"
              aria-hidden="true"
            ></span>
            {{ presenceLabel(presenceOf(device)) }}
          </span>
        </div>
        <dl class="grid grid-cols-2 gap-3 text-sm">
          <div class="min-w-0">
            <dt class="text-xs txt-secondary">{{ $t('config.desktop.devices.lastSeen') }}</dt>
            <dd class="txt-primary break-words">{{ formatLastSeen(device.lastSeen) }}</dd>
          </div>
          <div class="min-w-0">
            <dt class="text-xs txt-secondary">{{ $t('config.desktop.devices.waitingJobs') }}</dt>
            <dd class="txt-primary">{{ waitingCount(device.id) }}</dd>
            <p
              v-if="device.status === 'active'"
              class="text-xs txt-secondary mt-1"
              data-testid="text-check-in-hint"
            >
              {{ $t('config.desktop.devices.checkInHint', { minutes: checkInMinutes }) }}
            </p>
          </div>
        </dl>
        <div>
          <button
            v-if="device.status === 'active'"
            type="button"
            class="btn-danger px-4 py-2.5 rounded-lg text-sm font-medium"
            data-testid="btn-disconnect"
            @click="disconnect(device)"
          >
            {{ $t('config.desktop.disconnect') }}
          </button>
          <button
            v-else
            type="button"
            class="btn-secondary px-4 py-2.5 rounded-lg text-sm font-medium"
            data-testid="btn-remove"
            @click="removeDevice(device)"
          >
            {{ $t('config.desktop.remove') }}
          </button>
        </div>
      </li>
    </ul>

    <!-- Pairing modal -->
    <Teleport to="#app">
      <Transition
        enter-active-class="transition-opacity duration-200"
        leave-active-class="transition-opacity duration-150"
        enter-from-class="opacity-0"
        leave-to-class="opacity-0"
      >
        <div
          v-if="showPairing"
          class="modal-overlay fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm"
          data-testid="modal-pairing"
          @click.self="closePairing"
        >
          <Transition
            enter-active-class="transition-all duration-200"
            leave-active-class="transition-all duration-150"
            enter-from-class="opacity-0 scale-95 translate-y-4"
            leave-to-class="opacity-0 scale-95 translate-y-4"
          >
            <div v-if="showPairing" class="surface-elevated max-w-lg w-full p-6 md:p-8">
              <div class="flex items-start gap-4 mb-6">
                <div
                  class="flex-shrink-0 w-12 h-12 rounded-full bg-[var(--brand-alpha-light)] flex items-center justify-center"
                >
                  <ComputerDesktopIcon class="w-6 h-6 txt-brand" />
                </div>
                <div class="flex-1 min-w-0">
                  <h3 class="text-xl font-semibold txt-primary mb-1">
                    {{ $t('config.desktop.pairing.title') }}
                  </h3>
                  <p class="text-sm txt-secondary">{{ $t('config.desktop.pairing.intro') }}</p>
                </div>
              </div>

              <!-- Creating -->
              <div v-if="pairingLoading" class="py-8 text-center">
                <ArrowPathIcon class="w-8 h-8 mx-auto txt-secondary mb-3 animate-spin" />
                <p class="text-sm txt-secondary">{{ $t('common.loading') }}</p>
              </div>

              <!-- Failed to create -->
              <div v-else-if="pairingError" class="py-6 text-center">
                <p class="text-sm text-red-500 mb-4">{{ pairingError }}</p>
                <button
                  class="btn-primary px-4 py-2.5 rounded-lg font-medium text-sm"
                  @click="createCode"
                >
                  {{ $t('common.retry') }}
                </button>
              </div>

              <template v-else-if="pairingCode">
                <!-- Server address -->
                <div class="mb-4">
                  <label class="block text-sm font-medium txt-primary mb-2">
                    {{ $t('config.desktop.pairing.addressLabel') }}
                  </label>
                  <p class="text-xs txt-secondary mb-2">
                    {{ $t('config.desktop.pairing.addressHint') }}
                  </p>
                  <div class="flex items-center gap-2">
                    <code
                      class="flex-1 min-w-0 truncate text-sm font-mono txt-primary surface-card px-3 py-2.5 rounded"
                    >
                      {{ serverAddress }}
                    </code>
                    <button
                      class="surface-chip px-3 py-2.5 rounded-lg txt-primary hover:bg-black/5 dark:hover:bg-white/10 transition-colors shrink-0"
                      :aria-label="$t('config.desktop.pairing.copyAddress')"
                      @click="copy(serverAddress, 'address')"
                    >
                      <CheckIcon v-if="copiedField === 'address'" class="w-5 h-5" />
                      <ClipboardDocumentIcon v-else class="w-5 h-5" />
                    </button>
                  </div>
                </div>

                <!-- Pairing code -->
                <div class="mb-4">
                  <label class="block text-sm font-medium txt-primary mb-2">
                    {{ $t('config.desktop.pairing.codeLabel') }}
                  </label>
                  <div class="flex items-center gap-2">
                    <code
                      class="flex-1 text-2xl font-mono font-semibold tracking-[0.3em] txt-primary surface-card px-3 py-3 rounded text-center select-all"
                    >
                      {{ pairingCode.code }}
                    </code>
                    <button
                      class="surface-chip px-3 py-3 rounded-lg txt-primary hover:bg-black/5 dark:hover:bg-white/10 transition-colors shrink-0"
                      :aria-label="$t('config.desktop.pairing.copyCode')"
                      @click="copy(pairingCode.code, 'code')"
                    >
                      <CheckIcon v-if="copiedField === 'code'" class="w-5 h-5" />
                      <ClipboardDocumentIcon v-else class="w-5 h-5" />
                    </button>
                  </div>
                </div>

                <!-- Expiry -->
                <div class="text-center">
                  <p v-if="secondsLeft > 0" class="text-xs txt-secondary" data-testid="text-expiry">
                    {{ $t('config.desktop.pairing.expiresIn', { time: countdownLabel }) }}
                  </p>
                  <div v-else class="space-y-3" data-testid="section-expired">
                    <p class="text-xs text-amber-500">{{ $t('config.desktop.pairing.expired') }}</p>
                    <button
                      class="btn-primary px-4 py-2.5 rounded-lg font-medium text-sm"
                      data-testid="btn-new-code"
                      @click="createCode"
                    >
                      {{ $t('config.desktop.pairing.newCode') }}
                    </button>
                  </div>
                </div>
              </template>

              <div class="mt-6">
                <button
                  class="w-full surface-chip px-4 py-3 rounded-lg font-medium txt-primary hover:bg-black/5 dark:hover:bg-white/10 transition-colors"
                  data-testid="btn-pairing-close"
                  @click="closePairing"
                >
                  {{ $t('common.close') }}
                </button>
              </div>
            </div>
          </Transition>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, onActivated, onUnmounted, ref } from 'vue'
import { Icon } from '@iconify/vue'
import {
  PlusIcon,
  CheckIcon,
  ClipboardDocumentIcon,
  ComputerDesktopIcon,
  ArrowPathIcon,
  ArrowDownTrayIcon,
  ArrowTopRightOnSquareIcon,
  InformationCircleIcon,
  ExclamationTriangleIcon,
} from '@heroicons/vue/24/outline'
import PageHeader from '@/components/PageHeader.vue'
import { desktopApi, type DesktopDevice, type PairingCode } from '@/services/api/desktopApi'
import { useDesktopDevices } from '@/composables/useDesktopDevices'
import { useDialog } from '@/composables/useDialog'
import { useNotification } from '@/composables/useNotification'
import { useDateFormat } from '@/composables/useDateFormat'
import { useI18n } from 'vue-i18n'
import { getErrorMessage } from '@/utils/errorMessage'
import { desktopPairingAddress } from '@/utils/desktopPairingAddress'
import {
  DESKTOP_CHECK_IN_MINUTES,
  desktopPresence,
  type DesktopPresence,
} from '@/utils/desktopPresence'
import { isDesktopAgentEnabled } from '@/composables/useDesktopAgentFeature'

const { t } = useI18n()
const dialog = useDialog()
const { success, error: showError } = useNotification()
const { formatRelativeTime } = useDateFormat()
const { devices, reload } = useDesktopDevices()

/** Public source repository of the desktop client (Apache-2.0, build from source or beta builds). */
const DESKTOP_REPO_URL = 'https://github.com/metadist/synaplan-desktop'

// Operating-system names are brand names and stay untranslated.
const platforms = [
  { id: 'macos', label: 'macOS', icon: 'mdi:apple' },
  { id: 'windows', label: 'Windows', icon: 'mdi:microsoft-windows' },
  { id: 'linux', label: 'Linux', icon: 'mdi:linux' },
] as const

const loading = ref(false)
const error = ref<string | null>(null)

// Waiting-job counts per device (queued + leased), shown in the table (§3.1).
const waitingByDevice = ref<Record<number, number>>({})

const showPairing = ref(false)
const pairingLoading = ref(false)
const pairingError = ref<string | null>(null)
const pairingCode = ref<PairingCode | null>(null)
const copiedField = ref<'address' | 'code' | null>(null)

// Desktop talks to the API origin. In local Vite that is :8000 on the same
// host as this page (LAN IP and custom hostnames included), never :5173
// and never Keycloak on :8080.
const serverAddress = desktopPairingAddress()

const now = ref(Math.floor(Date.now() / 1000))
let ticker: number | null = null
const checkInMinutes = DESKTOP_CHECK_IN_MINUTES

// While the pairing dialog is open, poll the device list. A computer that
// was not active when the dialog opened (new row, or a disconnected row
// paired again) closes the dialog. This is a real check, not a delay.
const PAIR_POLL_MS = 3000
let pairPoll: number | null = null
let pairWatch = 0
const activeIdsAtOpen = ref<Set<number>>(new Set())

const secondsLeft = computed(() =>
  pairingCode.value ? Math.max(0, pairingCode.value.expiresAt - now.value) : 0
)

const countdownLabel = computed(() => {
  const total = secondsLeft.value
  const mins = Math.floor(total / 60)
  const secs = total % 60
  return `${mins}:${secs.toString().padStart(2, '0')}`
})

const formatLastSeen = (lastSeen: number): string => {
  if (!lastSeen) return t('config.desktop.devices.never')
  return formatRelativeTime(new Date(lastSeen * 1000))
}

const waitingCount = (deviceId: number): number => waitingByDevice.value[deviceId] ?? 0

const presenceOf = (device: DesktopDevice): DesktopPresence =>
  desktopPresence(device.status, device.lastSeen, now.value)

const presenceLabel = (presence: DesktopPresence): string => {
  if (presence === 'online') return t('config.desktop.devices.statusActive')
  if (presence === 'away') return t('config.desktop.devices.statusAway')
  if (presence === 'never') return t('config.desktop.devices.statusNever')
  return t('config.desktop.devices.statusRevoked')
}

const presenceClass = (presence: DesktopPresence): string => {
  if (presence === 'online') {
    return 'bg-[var(--status-success-muted)] text-[var(--status-success-text)]'
  }
  if (presence === 'away') {
    return 'bg-[var(--status-warning-muted)] text-[var(--status-warning-text)]'
  }
  return 'bg-[var(--status-neutral-muted)] text-[var(--status-neutral-text)]'
}

const presenceDotClass = (presence: DesktopPresence): string => {
  if (presence === 'online') return 'bg-[var(--status-success)]'
  if (presence === 'away') return 'bg-[var(--status-warning)]'
  return 'bg-[var(--status-neutral)]'
}

const loadAll = async () => {
  if (!isDesktopAgentEnabled()) {
    loading.value = false
    error.value = null
    return
  }
  loading.value = true
  error.value = null
  try {
    await reload()
    // Waiting-job counts are a display nicety; a failure here must not blank
    // the device table, so it is caught independently.
    try {
      const jobs = await desktopApi.listJobs()
      const counts: Record<number, number> = {}
      for (const job of jobs) {
        if ((job.status === 'queued' || job.status === 'leased') && job.deviceId) {
          counts[job.deviceId] = (counts[job.deviceId] ?? 0) + 1
        }
      }
      waitingByDevice.value = counts
    } catch {
      waitingByDevice.value = {}
    }
  } catch (err) {
    error.value = getErrorMessage(err) || t('config.desktop.loadFailed')
  } finally {
    loading.value = false
  }
}

const stopPairPoll = () => {
  if (pairPoll) {
    clearInterval(pairPoll)
    pairPoll = null
  }
}

const notePairSuccess = async (device: DesktopDevice) => {
  pairWatch += 1
  stopPairPoll()
  showPairing.value = false
  pairingCode.value = null
  await loadAll()
  if (desktopPresence(device.status, device.lastSeen, now.value) === 'online') {
    success(t('config.desktop.pairing.pairedOnline', { name: device.name }))
    return
  }
  success(t('config.desktop.pairing.paired', { name: device.name }))
}

const pollForPairedDevice = async () => {
  const watch = pairWatch
  if (!showPairing.value) return
  try {
    await reload()
  } catch {
    return
  }
  if (watch !== pairWatch || !showPairing.value) return

  for (const id of [...activeIdsAtOpen.value]) {
    const current = devices.value.find((device) => device.id === id)
    if (!current || current.status !== 'active') activeIdsAtOpen.value.delete(id)
  }

  const appeared = devices.value.find(
    (device) => device.status === 'active' && !activeIdsAtOpen.value.has(device.id)
  )
  if (!appeared) return
  await notePairSuccess(appeared)
}

const openPairing = async () => {
  const watch = pairWatch
  showPairing.value = true
  copiedField.value = null
  try {
    await reload()
  } catch {
    // A failed refresh must not block the code. The next poll tries again.
  }
  // Close during the refresh bumps pairWatch and hides the dialog. Do not
  // mint a code or leave a poll running after that.
  if (watch !== pairWatch || !showPairing.value) return
  activeIdsAtOpen.value = new Set(
    devices.value.filter((device) => device.status === 'active').map((device) => device.id)
  )
  createCode()
  stopPairPoll()
  pairPoll = window.setInterval(() => {
    void pollForPairedDevice()
  }, PAIR_POLL_MS)
}

const createCode = async () => {
  pairingLoading.value = true
  pairingError.value = null
  pairingCode.value = null
  try {
    pairingCode.value = await desktopApi.createPairingCode()
    now.value = Math.floor(Date.now() / 1000)
  } catch (err) {
    pairingError.value = getErrorMessage(err) || t('config.desktop.pairing.createFailed')
  } finally {
    pairingLoading.value = false
  }
}

const closePairing = () => {
  pairWatch += 1
  stopPairPoll()
  showPairing.value = false
  pairingCode.value = null
}

const copy = async (value: string, field: 'address' | 'code') => {
  try {
    await navigator.clipboard.writeText(value)
    copiedField.value = field
    setTimeout(() => {
      if (copiedField.value === field) copiedField.value = null
    }, 2000)
  } catch {
    showError(t('config.desktop.pairing.copyFailed'))
  }
}

const disconnect = async (device: DesktopDevice) => {
  const waiting = waitingCount(device.id)
  const confirmed = await dialog.confirm({
    title: t('config.desktop.confirmDisconnectTitle'),
    message: t('config.desktop.confirmDisconnect', { name: device.name, count: waiting }, waiting),
    confirmText: t('config.desktop.disconnect'),
    cancelText: t('common.cancel'),
    danger: true,
  })
  if (!confirmed) return

  try {
    const { cancelledJobs } = await desktopApi.revokeDevice(device.id)
    success(t('config.desktop.disconnected', { count: cancelledJobs }, cancelledJobs))
    await loadAll()
  } catch (err) {
    showError(getErrorMessage(err) || t('config.desktop.disconnectFailed'))
  }
}

const removeDevice = async (device: DesktopDevice) => {
  const confirmed = await dialog.confirm({
    title: t('config.desktop.confirmRemoveTitle'),
    message: t('config.desktop.confirmRemove', { name: device.name }),
    confirmText: t('config.desktop.remove'),
    cancelText: t('common.cancel'),
  })
  if (!confirmed) return

  try {
    const { cancelledJobs } = await desktopApi.revokeDevice(device.id)
    success(t('config.desktop.removed', { name: device.name, count: cancelledJobs }, cancelledJobs))
    await loadAll()
  } catch (err) {
    showError(getErrorMessage(err) || t('config.desktop.removeFailed'))
  }
}

onMounted(() => {
  loadAll()
  ticker = window.setInterval(() => {
    now.value = Math.floor(Date.now() / 1000)
  }, 1000)
})

onActivated(() => {
  loadAll()
})

onUnmounted(() => {
  if (ticker) clearInterval(ticker)
  stopPairPoll()
})
</script>
