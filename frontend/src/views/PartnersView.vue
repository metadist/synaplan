<template>
  <MainLayout data-testid="view-partners">
    <div class="container mx-auto px-4 sm:px-6 py-8 max-w-3xl">
      <button
        type="button"
        class="text-xs txt-secondary hover:txt-primary transition-colors mb-3 inline-flex items-center gap-1.5"
        data-testid="link-partners-back"
        @click="router.push({ name: 'admin' })"
      >
        <Icon icon="heroicons:arrow-left" class="w-3.5 h-3.5" />
        {{ $t('nav.admin') }}
      </button>
      <PageHeader
        :title="$t('partners.title')"
        :subtitle="$t('partners.subtitle')"
        icon="mdi:handshake-outline"
      />

      <p v-if="loading" class="txt-secondary text-sm">{{ $t('common.loading') }}</p>
      <p
        v-else-if="errorText"
        class="text-sm text-red-600 dark:text-red-400"
        data-testid="partners-error"
      >
        {{ errorText }}
      </p>

      <section
        v-if="membership && !membership.reachable"
        class="surface-card p-6 space-y-3"
        data-testid="partners-unreachable"
      >
        <p class="txt-primary text-sm">{{ $t('partners.unreachable') }}</p>
        <button
          type="button"
          class="btn-primary px-4 py-2.5 rounded-lg text-sm font-medium opacity-50 cursor-not-allowed"
          disabled
          data-testid="btn-partners-open"
        >
          {{ $t('partners.open') }}
        </button>
      </section>

      <section
        v-else-if="membership && !membership.opened"
        class="surface-card p-6 space-y-4"
        data-testid="partners-closed"
      >
        <p class="txt-primary text-sm">{{ $t('partners.empty') }}</p>
        <label class="block text-sm txt-primary">
          {{ $t('partners.companyName') }}
          <input
            v-model="companyName"
            type="text"
            maxlength="80"
            class="mt-1 w-full px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
            data-testid="input-partners-name"
          />
        </label>
        <button
          type="button"
          class="btn-primary px-4 py-2.5 rounded-lg text-sm font-medium"
          :disabled="busy || companyName.trim() === ''"
          data-testid="btn-partners-open"
          @click="openPartners"
        >
          {{ $t('partners.open') }}
        </button>
      </section>

      <template v-else-if="membership?.opened">
        <section class="surface-card p-6 space-y-3 mb-4" data-testid="partners-open">
          <p class="txt-primary text-sm">
            {{ $t('partners.openedLine', { name: membership.name }) }}
          </p>
          <p class="txt-secondary text-sm">
            {{ $t('partners.fingerprint', { fingerprint: membership.fingerprint }) }}
          </p>
          <button
            type="button"
            class="btn-secondary px-4 py-2.5 rounded-lg text-sm font-medium"
            data-testid="btn-partners-close"
            @click="closePartners"
          >
            {{ $t('partners.close') }}
          </button>
        </section>

        <section class="surface-card p-6 space-y-3 mb-4">
          <button
            type="button"
            class="btn-primary px-4 py-2.5 rounded-lg text-sm font-medium"
            :disabled="busy"
            data-testid="btn-partners-invite"
            @click="createInvite"
          >
            {{ $t('partners.invite') }}
          </button>
          <div v-if="pasteUrl" class="space-y-2" data-testid="partners-invite-url">
            <p class="txt-secondary text-sm">{{ $t('partners.inviteHelp') }}</p>
            <p class="txt-primary text-sm break-all">{{ pasteUrl }}</p>
            <button
              type="button"
              class="btn-secondary px-4 py-2.5 rounded-lg text-sm font-medium"
              @click="copyUrl"
            >
              {{ $t('partners.copy') }}
            </button>
          </div>
        </section>

        <section class="surface-card p-6 space-y-3 mb-4">
          <label class="block text-sm txt-primary">
            {{ $t('partners.pasteLabel') }}
            <input
              v-model="inviteUrl"
              type="url"
              class="mt-1 w-full px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
              :placeholder="$t('partners.pastePlaceholder')"
              data-testid="input-partners-invite"
            />
          </label>
          <button
            type="button"
            class="btn-primary px-4 py-2.5 rounded-lg text-sm font-medium"
            :disabled="busy || inviteUrl.trim() === ''"
            data-testid="btn-partners-connect"
            @click="acceptInvite"
          >
            {{ $t('partners.connect') }}
          </button>
        </section>

        <ul class="space-y-3" data-testid="partners-list">
          <li
            v-for="partner in partners"
            :key="partner.id"
            class="surface-card p-4 flex flex-col sm:flex-row sm:items-center gap-3"
            :data-testid="`partners-row-${partner.id}`"
          >
            <div class="min-w-0 flex-1">
              <p class="txt-primary text-sm font-medium">
                {{
                  partner.status === 'invited'
                    ? $t('partners.pending')
                    : partner.name || partner.domain
                }}
              </p>
              <p class="txt-secondary text-sm">
                {{ statusLabel(partner) }}
              </p>
            </div>
            <div class="flex flex-wrap gap-2">
              <button
                v-if="
                  partner.status === 'active' ||
                  partner.pausedBy === 'local' ||
                  partner.pausedBy === 'both'
                "
                type="button"
                class="btn-secondary px-4 py-2.5 rounded-lg text-sm font-medium"
                @click="partner.status === 'paused' ? resume(partner.id) : pause(partner.id)"
              >
                {{ partner.status === 'paused' ? $t('partners.resume') : $t('partners.pause') }}
              </button>
              <button
                type="button"
                class="btn-danger px-4 py-2.5 rounded-lg text-sm font-medium"
                @click="remove(partner)"
              >
                {{
                  partner.status === 'invited'
                    ? $t('partners.removeInvite')
                    : $t('partners.disconnect')
                }}
              </button>
            </div>
          </li>
        </ul>
      </template>
    </div>
  </MainLayout>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { Icon } from '@iconify/vue'
import MainLayout from '@/components/MainLayout.vue'
import PageHeader from '@/components/PageHeader.vue'
import { useDialog } from '@/composables/useDialog'
import { useNotification } from '@/composables/useNotification'
import {
  partnerErrorCode,
  partnersApi,
  type FederationPartner,
  type PartnerAction,
  type PartnerMembership,
} from '@/services/api/partnersApi'

const { t } = useI18n()
const router = useRouter()
const { confirm } = useDialog()
const { success, error: showError } = useNotification()

const loading = ref(true)
const busy = ref(false)
const errorText = ref('')
const companyName = ref('')
const pasteUrl = ref('')
const inviteUrl = ref('')
const membership = ref<PartnerMembership | null>(null)
const partners = ref<FederationPartner[]>([])

onMounted(() => {
  void load()
})

async function load(): Promise<void> {
  loading.value = true
  errorText.value = ''
  try {
    membership.value = await partnersApi.membership()
    if (membership.value.opened) {
      companyName.value = membership.value.name
      partners.value = await partnersApi.list()
    }
  } catch (err) {
    fail(err)
  } finally {
    loading.value = false
  }
}

async function openPartners(): Promise<void> {
  busy.value = true
  try {
    membership.value = await partnersApi.open(companyName.value.trim())
    partners.value = []
    success(t('partners.openedLine', { name: membership.value.name }))
  } catch (err) {
    fail(err)
  } finally {
    busy.value = false
  }
}

async function closePartners(): Promise<void> {
  const ok = await confirm({
    title: t('partners.closeTitle'),
    message: t('partners.closeMessage'),
    danger: true,
  })
  if (!ok) return
  busy.value = true
  try {
    membership.value = await partnersApi.close()
    partners.value = []
    pasteUrl.value = ''
    success(t('partners.closed'))
  } catch (err) {
    fail(err)
  } finally {
    busy.value = false
  }
}

async function createInvite(): Promise<void> {
  busy.value = true
  try {
    const invite = await partnersApi.createInvite()
    pasteUrl.value = invite.pasteUrl
    partners.value = await partnersApi.list()
    success(t('partners.inviteReady'))
  } catch (err) {
    fail(err)
  } finally {
    busy.value = false
  }
}

async function copyUrl(): Promise<void> {
  try {
    await navigator.clipboard.writeText(pasteUrl.value)
    success(t('partners.copied'))
  } catch {
    showError(t('partners.loadFailed'))
  }
}

async function acceptInvite(): Promise<void> {
  busy.value = true
  try {
    const result = await partnersApi.accept(inviteUrl.value.trim())
    inviteUrl.value = ''
    partners.value = await partnersApi.list()
    success(t('partners.connected'))
    notePending(result)
  } catch (err) {
    fail(err)
  } finally {
    busy.value = false
  }
}

async function pause(id: number): Promise<void> {
  await act(() => partnersApi.pause(id), 'pausedHere', 'pausedPending')
}

async function resume(id: number): Promise<void> {
  await act(() => partnersApi.resume(id), 'resumed', 'resumedPending')
}

async function remove(partner: FederationPartner): Promise<void> {
  const invited = partner.status === 'invited'
  const ok = await confirm({
    title: invited
      ? t('partners.removeInviteTitle')
      : t('partners.disconnectTitle', { name: partner.name || partner.domain }),
    message: invited ? t('partners.removeInviteMessage') : t('partners.disconnectMessage'),
    danger: true,
  })
  if (!ok) return
  await act(
    () => partnersApi.disconnect(partner.id),
    invited ? 'inviteRemoved' : 'disconnected',
    invited ? 'inviteRemoved' : 'disconnectedPending'
  )
}

async function act(
  call: () => Promise<PartnerAction>,
  okKey: string,
  pendingKey: string
): Promise<void> {
  busy.value = true
  try {
    const result = await call()
    partners.value = await partnersApi.list()
    success(t(result.peerConfirmed ? `partners.${okKey}` : `partners.${pendingKey}`))
  } catch (err) {
    fail(err)
  } finally {
    busy.value = false
  }
}

function notePending(result: PartnerAction): void {
  if (!result.peerConfirmed) {
    showError(t('partners.pausedPending'))
  }
}

function statusLabel(partner: FederationPartner): string {
  if (partner.status === 'invited') return t('partners.pending')
  if (partner.pausedBy === 'remote') return t('partners.pausedByThem')
  if (partner.status === 'paused') return t('partners.paused')
  return partner.domain
}

function fail(err: unknown): void {
  const code = partnerErrorCode(err)
  const key = code ? `partners.errors.${code}` : ''
  const translated = key ? t(key) : ''
  errorText.value =
    translated && translated !== key
      ? translated
      : err instanceof Error
        ? err.message
        : t('partners.loadFailed')
  showError(errorText.value)
}
</script>
