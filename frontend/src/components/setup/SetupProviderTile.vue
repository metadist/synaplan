<template>
  <button
    type="button"
    class="surface-chip rounded-xl px-2.5 py-3 flex flex-col items-center gap-2 text-center transition outline-offset-2 outline-[var(--brand)]"
    :class="selected ? 'outline-2' : 'outline-0 hover:outline-1'"
    :aria-pressed="selected"
    :data-testid="`setup-provider-tile-${provider.name}`"
    @click="emit('select')"
  >
    <span class="relative">
      <ServiceIcon :service="provider.name" :size="32" :show-flag="false" />
      <Icon
        v-if="provider.configured"
        icon="mdi:check-circle"
        class="absolute -right-1.5 -top-1.5 w-4 h-4 text-[var(--status-success-text)] bg-[var(--bg-card)] rounded-full"
        aria-hidden="true"
      />
    </span>

    <!-- `.surface-chip` owns background and box-shadow, so the selected state
         cannot use `bg-*` or `ring-*` here: it reads through the outline plus
         the brand-coloured name. -->
    <span
      class="text-sm font-semibold leading-tight break-words"
      :class="selected ? 'txt-brand' : 'txt-primary'"
    >
      {{ provider.displayName }}
    </span>

    <!-- Screen readers get the state the check mark conveys visually. -->
    <span v-if="provider.configured" class="sr-only">{{ $t('adminSetup.connected') }}</span>

    <!-- Both badges use a muted fill. Solid `--brand` with white ink is only
         4.5:1 in light theme; in dark theme `--brand` lightens and the same
         chip drops to 2.9:1, which is unreadable at this size. -->
    <span v-if="hasBadge" class="flex flex-wrap justify-center gap-1">
      <span
        v-if="provider.recommended"
        class="text-[11px] font-medium leading-none px-1.5 py-1 rounded-full bg-[var(--brand-alpha-light)] txt-brand"
      >
        {{ $t('adminSetup.recommended') }}
      </span>
      <span
        v-if="provider.freeTier"
        class="text-[11px] font-medium leading-none px-1.5 py-1 rounded-full bg-[var(--status-success-muted)] text-[var(--status-success-text)]"
        :title="$t('adminSetup.freeTier')"
      >
        {{ $t('setup.provider.freeTierBadge') }}
      </span>
    </span>
  </button>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { Icon } from '@iconify/vue'
import ServiceIcon from '@/components/icons/ServiceIcon.vue'
import type { ProviderKeyStatus } from '@/services/api/providerKeysApi'

const props = defineProps<{
  provider: ProviderKeyStatus
  selected: boolean
}>()

const emit = defineEmits<{
  select: []
}>()

const hasBadge = computed(() => props.provider.recommended || props.provider.freeTier)
</script>
