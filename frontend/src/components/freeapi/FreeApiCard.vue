<template>
  <Teleport to="body">
    <div
      v-if="open && status"
      class="fixed inset-0 z-50 flex items-center justify-center p-4"
      data-testid="freeapi-card"
    >
      <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" @click="emit('close')" />
      <div
        class="relative surface-elevated max-w-lg w-full max-h-[90vh] overflow-y-auto p-6 rounded-2xl"
        role="dialog"
        aria-modal="true"
        aria-labelledby="freeapi-card-title"
      >
        <div class="flex items-start gap-3">
          <SparklesIcon class="w-6 h-6 shrink-0 txt-brand mt-0.5" aria-hidden="true" />
          <h2 id="freeapi-card-title" class="flex-1 text-lg font-semibold txt-primary">
            {{ $t('freeApi.card.title') }}
          </h2>
          <button
            ref="closeRef"
            type="button"
            class="icon-ghost p-2 rounded-lg -mt-1 -mr-2"
            :aria-label="$t('freeApi.card.close')"
            data-testid="freeapi-card-close"
            @click="emit('close')"
          >
            <XMarkIcon class="w-5 h-5" aria-hidden="true" />
          </button>
        </div>

        <p class="mt-3 text-sm txt-secondary leading-relaxed">
          {{ $t('freeApi.card.lead', { server: status.server ?? $t('freeApi.serverFallback') }) }}
        </p>
        <p class="mt-2 text-sm txt-secondary leading-relaxed">{{ $t('freeApi.card.shared') }}</p>

        <dl class="mt-4 grid grid-cols-1 sm:grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
          <template v-for="row in rows" :key="row.id">
            <dt class="font-medium txt-primary">{{ row.label }}</dt>
            <dd class="txt-secondary mb-1 sm:mb-0" :data-testid="`freeapi-card-${row.id}`">
              {{ row.value }}
            </dd>
          </template>
        </dl>

        <p v-if="!status.canManage" class="mt-4 text-sm txt-secondary">
          {{ $t('freeApi.card.memberHint') }}
        </p>

        <div class="mt-6 flex flex-wrap gap-2">
          <template v-if="status.canManage">
            <RouterLink
              :to="{ path: '/admin/setup', query: { section: 'providers' } }"
              class="btn-primary inline-flex items-center gap-2 px-4 py-2.5 text-sm font-medium"
              data-testid="freeapi-card-own-keys"
              @click="emit('close')"
            >
              <KeyIcon class="w-5 h-5" aria-hidden="true" />
              {{ $t('freeApi.card.ownKeys') }}
            </RouterLink>
            <RouterLink
              :to="{ path: '/admin/setup', query: { section: 'local-ai' } }"
              class="btn-secondary inline-flex items-center gap-2 px-4 py-2.5 text-sm font-medium"
              data-testid="freeapi-card-ollama"
              @click="emit('close')"
            >
              <ComputerDesktopIcon class="w-5 h-5" aria-hidden="true" />
              {{ $t('freeApi.card.ollama') }}
            </RouterLink>
            <button
              type="button"
              class="btn-danger inline-flex items-center gap-2 px-4 py-2.5 text-sm font-medium rounded-xl disabled:opacity-50 disabled:cursor-not-allowed"
              :disabled="freeApi.busy"
              data-testid="freeapi-card-leave"
              @click="leave"
            >
              <ArrowRightStartOnRectangleIcon class="w-5 h-5" aria-hidden="true" />
              {{ freeApi.busy ? $t('freeApi.card.leaving') : $t('freeApi.card.leave') }}
            </button>
          </template>
          <button
            v-if="bannerHidden"
            type="button"
            class="btn-secondary px-4 py-2.5 text-sm font-medium"
            data-testid="freeapi-card-show-banner"
            @click="emit('showBanner')"
          >
            {{ $t('freeApi.card.showBanner') }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import {
  ArrowRightStartOnRectangleIcon,
  ComputerDesktopIcon,
  KeyIcon,
  SparklesIcon,
  XMarkIcon,
} from '@heroicons/vue/24/outline'
import { useDialog } from '@/composables/useDialog'
import { useEscapeKey } from '@/composables/useEscapeKey'
import { useNotification } from '@/composables/useNotification'
import { useConfigStore } from '@/stores/config'
import { useFreeApiStore } from '@/stores/freeApi'
import { freeApiCardRows } from '@/utils/freeApiCopy'

/** Everything about the Synaplan Demo on this install, and the way out of it. */
const props = defineProps<{ open: boolean; bannerHidden?: boolean }>()
const emit = defineEmits<{ close: []; showBanner: [] }>()

const { t, locale } = useI18n()
const freeApi = useFreeApiStore()
const configStore = useConfigStore()
const { confirm } = useDialog()
const { success, error } = useNotification()

const closeRef = ref<HTMLButtonElement | null>(null)
const isOpen = computed(() => props.open)
const confirmingLeave = ref(false)

const status = computed(() => freeApi.status)
const rows = computed(() => (status.value ? freeApiCardRows(status.value, t, locale.value) : []))

useEscapeKey(() => {
  if (confirmingLeave.value) return
  emit('close')
}, isOpen)

watch(isOpen, async (open) => {
  if (!open) return
  await nextTick()
  closeRef.value?.focus()
})

async function leave(): Promise<void> {
  confirmingLeave.value = true
  const confirmed = await confirm({
    title: t('freeApi.leave.confirmTitle'),
    message: t('freeApi.leave.confirmMessage'),
    confirmText: t('freeApi.leave.confirm'),
    cancelText: t('freeApi.leave.cancel'),
    danger: true,
  }).finally(() => {
    confirmingLeave.value = false
  })
  if (!confirmed) return
  try {
    const result = await freeApi.leave()
    emit('close')
    const done =
      result.configRefreshed && configStore.setup.chatReady
        ? t('freeApi.leave.doneReady')
        : t('freeApi.leave.done')
    success(result.keyRevoked ? done : `${done} ${t('freeApi.leave.revokeLater')}`)
  } catch {
    error(t('freeApi.leave.failed'))
  }
}
</script>
