<template>
  <div
    class="surface-card rounded-xl p-4"
    :class="compact ? 'p-3' : 'p-4'"
    data-testid="banner-shared-resource"
    :data-kind="kind"
  >
    <div class="flex items-start gap-3">
      <div
        v-if="!compact"
        class="w-9 h-9 rounded-lg bg-[var(--brand-alpha-light)] flex items-center justify-center flex-shrink-0"
        aria-hidden="true"
      >
        <Icon icon="mdi:inbox-arrow-down" class="w-5 h-5 text-[var(--brand)]" />
      </div>
      <div class="min-w-0 flex-1 space-y-1.5">
        <div class="flex items-center gap-2 flex-wrap">
          <ChatKindPill :kind="pill.kind" :label="pill.label" size="sm" />
          <span
            v-if="ownerName"
            class="text-xs txt-secondary truncate"
            data-testid="text-shared-resource-owner"
          >
            {{ $t('iam.incoming.owner', { name: ownerName }) }}
          </span>
          <span v-if="permission" class="text-xs txt-secondary">·</span>
          <span
            v-if="permission"
            class="text-xs txt-secondary"
            data-testid="text-shared-resource-permission"
          >
            {{ $t(`iam.permission.${permission}`) }}
          </span>
        </div>
        <p class="text-sm txt-primary" data-testid="text-shared-resource-source">
          {{ sourceText }}
        </p>
        <p
          v-if="permission"
          class="text-sm txt-secondary"
          data-testid="text-shared-resource-consequence"
        >
          {{ $t(shareConsequenceKey(kind, permission)) }}
        </p>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import ChatKindPill from '@/components/iam/ChatKindPill.vue'
import { kindOfSharedVia } from '@/utils/chatKind'
import { shareBannerSourceKey, shareConsequenceKey, type ShareKind } from '@/utils/shareCopy'

const props = withDefaults(
  defineProps<{
    kind: ShareKind
    ownerName: string | null
    sharedVia?: { type: string; name: string } | null
    permission?: string | null
    compact?: boolean
  }>(),
  { sharedVia: null, permission: null, compact: false }
)

const { t } = useI18n()

const pill = computed(() => kindOfSharedVia(props.sharedVia, props.ownerName))

const sourceText = computed(() => {
  const owner = props.ownerName?.trim() || t('iam.owner')
  const viaType = props.sharedVia?.type || 'user'
  const name = props.sharedVia?.name?.trim() || ''
  return t(shareBannerSourceKey(props.kind, viaType), { owner, name })
})
</script>
