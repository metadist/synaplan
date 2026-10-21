<template>
  <div v-if="visible" data-testid="freeapi-banner-host">
    <div
      v-if="!collapsed"
      class="surface-chip rounded-xl px-4 py-3 text-sm flex items-start gap-3"
      data-testid="freeapi-banner"
    >
      <SparklesIcon class="w-5 h-5 shrink-0 mt-0.5 txt-brand" aria-hidden="true" />
      <div class="min-w-0 flex-1 flex flex-col gap-3 sm:flex-row sm:items-center">
        <div class="min-w-0 flex-1">
          <p class="txt-primary">
            <strong>{{ $t('freeApi.banner.title') }}</strong>
            — {{ headline }}
          </p>
          <p class="txt-secondary mt-0.5" :class="{ 'hidden sm:block': canManage }">
            {{ followUp }}
          </p>
        </div>
        <div class="flex items-center gap-2 shrink-0 flex-wrap">
          <RouterLink
            v-if="canManage"
            :to="{ path: '/admin/setup', query: { section: 'providers' } }"
            class="btn-primary inline-flex items-center gap-2 px-4 py-2.5 text-sm font-medium"
            data-testid="freeapi-banner-own-keys"
          >
            <KeyIcon class="w-5 h-5" aria-hidden="true" />
            {{ $t('freeApi.banner.ownKeys') }}
          </RouterLink>
          <button
            type="button"
            class="btn-secondary px-4 py-2.5 text-sm font-medium"
            data-testid="freeapi-banner-details"
            @click="cardOpen = true"
          >
            {{ $t('freeApi.banner.details') }}
          </button>
        </div>
      </div>
      <button
        type="button"
        class="icon-ghost p-2 rounded-lg shrink-0 -my-1 -mr-2 sm:my-0"
        :aria-label="$t('freeApi.banner.hide')"
        :title="$t('freeApi.banner.hide')"
        data-testid="freeapi-banner-hide"
        @click="setCollapsed(true)"
      >
        <XMarkIcon class="w-5 h-5" aria-hidden="true" />
      </button>
    </div>
    <div v-else class="flex justify-center">
      <button
        type="button"
        class="pill text-xs md:text-sm"
        data-testid="freeapi-pill"
        :aria-label="$t('freeApi.banner.openDetails')"
        @click="cardOpen = true"
      >
        <SparklesIcon class="w-4 h-4 shrink-0 txt-brand" aria-hidden="true" />
        <span class="font-medium">{{ pillLabel }}</span>
      </button>
    </div>

    <FreeApiCard
      :open="cardOpen"
      :banner-hidden="collapsed"
      @close="cardOpen = false"
      @show-banner="showBannerAgain"
    />
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import { KeyIcon, SparklesIcon, XMarkIcon } from '@heroicons/vue/24/outline'
import FreeApiCard from '@/components/freeapi/FreeApiCard.vue'
import { useAuthStore } from '@/stores/auth'
import { useFreeApiStore } from '@/stores/freeApi'
import { freeApiEndsSoon } from '@/utils/freeApiCopy'

/**
 * The Synaplan Demo above the composer: a banner that collapses into a pill
 * (remembered per user), both opening the card. Absent unless the Demo
 * answers chat on this install.
 */
const props = defineProps<{ streaming?: boolean }>()

const { t } = useI18n()
const authStore = useAuthStore()
const freeApi = useFreeApiStore()

const collapsed = ref(false)
const cardOpen = ref(false)

const status = computed(() => freeApi.status)
const visible = computed(() => freeApi.isAvailable() && status.value?.active === true)
const canManage = computed(() => status.value?.canManage === true)
const today = computed(() => status.value?.today ?? null)

const headline = computed(() => {
  const current = status.value
  if (current?.refusal === 'freeapi_expired') return t('freeApi.banner.expired')
  if (current?.refusal) return t('freeApi.banner.unavailable')
  const parts = [t('freeApi.banner.shared')]
  if (today.value) {
    parts.push(
      t('freeApi.banner.left', {
        remaining: today.value.messagesRemaining,
        limit: today.value.messagesLimit,
      })
    )
  }
  const days = current?.daysLeft ?? null
  if (freeApiEndsSoon(days)) parts.push(t('freeApi.banner.endsSoon', { days }, days ?? 0))
  return parts.join(' ')
})

const followUp = computed(() =>
  canManage.value ? t('freeApi.banner.ownSetup') : t('freeApi.banner.ownSetupMember')
)

const pillLabel = computed(() =>
  today.value
    ? t('freeApi.banner.pill', { remaining: today.value.messagesRemaining })
    : t('freeApi.banner.title')
)

const storageKey = () => `synaplan_freeapi_banner_hidden_${authStore.user?.id ?? 'anon'}`

function setCollapsed(value: boolean): void {
  collapsed.value = value
  try {
    if (value) localStorage.setItem(storageKey(), '1')
    else localStorage.removeItem(storageKey())
  } catch {
    // Storage can be unavailable (private mode); the choice holds for this visit.
  }
}

function showBannerAgain(): void {
  cardOpen.value = false
  setCollapsed(false)
}

onMounted(() => {
  try {
    collapsed.value = localStorage.getItem(storageKey()) === '1'
  } catch {
    collapsed.value = false
  }
  void freeApi.load()
})

watch(
  () => props.streaming,
  (now, before) => {
    if (before && !now && visible.value) void freeApi.load()
  }
)
</script>
