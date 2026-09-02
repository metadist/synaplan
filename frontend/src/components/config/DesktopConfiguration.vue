<template>
  <div class="space-y-6" data-testid="page-config-desktop">
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

    <!-- Honest "not downloadable yet" note (§3.1): the server ships before the
         client, so the app cannot be installed yet. Never a Download button. -->
    <div class="surface-card p-4 flex items-start gap-3" data-testid="note-not-available">
      <InformationCircleIcon class="w-5 h-5 txt-brand mt-0.5 shrink-0" />
      <p class="text-sm txt-secondary">{{ $t('config.desktop.notAvailableYet') }}</p>
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
    </div>

    <!-- Device table -->
    <div v-else class="surface-card overflow-hidden" data-testid="section-devices-table">
      <div class="overflow-x-auto">
        <table class="w-full">
          <thead class="border-b border-light-border/30 dark:border-dark-border/20">
            <tr class="bg-black/5 dark:bg-white/5">
              <th
                class="px-6 py-3 text-left text-xs font-semibold txt-primary uppercase tracking-wider"
              >
                {{ $t('config.desktop.devices.name') }}
              </th>
              <th
                class="px-6 py-3 text-left text-xs font-semibold txt-primary uppercase tracking-wider"
              >
                {{ $t('config.desktop.devices.status') }}
              </th>
              <th
                class="px-6 py-3 text-left text-xs font-semibold txt-primary uppercase tracking-wider"
              >
                {{ $t('config.desktop.devices.lastSeen') }}
              </th>
              <th
                class="px-6 py-3 text-left text-xs font-semibold txt-primary uppercase tracking-wider"
              >
                {{ $t('config.desktop.devices.waitingJobs') }}
              </th>
              <th
                class="px-6 py-3 text-left text-xs font-semibold txt-primary uppercase tracking-wider"
              >
                {{ $t('config.desktop.devices.actions') }}
              </th>
            </tr>
          </thead>
          <tbody class="divide-y divide-light-border/30 dark:divide-dark-border/20">
            <tr
              v-for="device in devices"
              :key="device.id"
              class="hover:bg-black/5 dark:hover:bg-white/5 transition-colors"
              data-testid="item-device"
            >
              <td class="px-6 py-4 whitespace-nowrap">
                <div class="flex items-center gap-2">
                  <ComputerDesktopIcon class="w-5 h-5 txt-secondary shrink-0" />
                  <span class="text-sm font-medium txt-primary">{{ device.name }}</span>
                </div>
              </td>
              <td class="px-6 py-4 whitespace-nowrap">
                <span
                  :class="[
                    'inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium',
                    device.status === 'active'
                      ? 'bg-green-500/10 text-green-500'
                      : 'bg-gray-500/10 text-gray-500',
                  ]"
                >
                  <span
                    class="w-1.5 h-1.5 rounded-full"
                    :class="device.status === 'active' ? 'bg-green-500' : 'bg-gray-500'"
                  ></span>
                  {{
                    device.status === 'active'
                      ? $t('config.desktop.devices.statusActive')
                      : $t('config.desktop.devices.statusRevoked')
                  }}
                </span>
              </td>
              <td class="px-6 py-4 whitespace-nowrap text-sm txt-secondary">
                {{ formatLastSeen(device.lastSeen) }}
              </td>
              <td class="px-6 py-4 whitespace-nowrap text-sm txt-secondary">
                {{ waitingCount(device.id) }}
              </td>
              <td class="px-6 py-4 whitespace-nowrap">
                <button
                  v-if="device.status === 'active'"
                  class="text-sm text-red-500 hover:text-red-600 font-medium"
                  data-testid="btn-disconnect"
                  @click="disconnect(device)"
                >
                  {{ $t('config.desktop.disconnect') }}
                </button>
                <span v-else class="text-sm txt-muted">—</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

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
import {
  PlusIcon,
  CheckIcon,
  ClipboardDocumentIcon,
  ComputerDesktopIcon,
  ArrowPathIcon,
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

const { t } = useI18n()
const dialog = useDialog()
const { success, error: showError } = useNotification()
const { formatRelativeTime } = useDateFormat()
const { devices, reload } = useDesktopDevices()

const loading = ref(false)
const error = ref<string | null>(null)

// Waiting-job counts per device (queued + leased), shown in the table (§3.1).
const waitingByDevice = ref<Record<number, number>>({})

const showPairing = ref(false)
const pairingLoading = ref(false)
const pairingError = ref<string | null>(null)
const pairingCode = ref<PairingCode | null>(null)
const copiedField = ref<'address' | 'code' | null>(null)

// Desktop talks to the API origin. In local Vite that is :8000, not this
// page's :5173 (and never Keycloak on :8080).
const serverAddress = desktopPairingAddress()

const now = ref(Math.floor(Date.now() / 1000))
let ticker: number | null = null

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

const loadAll = async () => {
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

const openPairing = () => {
  showPairing.value = true
  copiedField.value = null
  createCode()
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
  const confirmed = await dialog.confirm({
    title: t('config.desktop.confirmDisconnectTitle'),
    message: t('config.desktop.confirmDisconnect', { name: device.name }),
    confirmText: t('config.desktop.disconnect'),
    cancelText: t('common.cancel'),
    danger: true,
  })
  if (!confirmed) return

  try {
    await desktopApi.revokeDevice(device.id)
    await reload()
    success(t('config.desktop.disconnected'))
  } catch (err) {
    showError(getErrorMessage(err) || t('config.desktop.disconnectFailed'))
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
})
</script>
