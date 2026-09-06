<template>
  <div
    class="surface-card rounded-xl px-4 py-3"
    :class="compact ? '' : 'p-4'"
    data-testid="banner-shared-conversation"
  >
    <div class="flex items-start gap-3">
      <div
        class="w-9 h-9 rounded-lg bg-[var(--brand-alpha-light)] flex items-center justify-center flex-shrink-0"
        aria-hidden="true"
      >
        <Icon icon="mdi:inbox-arrow-down" class="w-5 h-5 text-[var(--brand)]" />
      </div>
      <div class="min-w-0 flex-1 space-y-1.5">
        <div class="flex items-center gap-2 flex-wrap">
          <ChatKindPill :kind="kind" :label="kindLabel" size="sm" />
          <span
            v-if="ownerName"
            class="text-xs txt-secondary truncate"
            data-testid="text-shared-conversation-owner"
          >
            {{ $t('iam.incoming.owner', { name: ownerName }) }}
          </span>
          <span class="text-xs txt-secondary">·</span>
          <span class="text-xs txt-secondary" data-testid="text-shared-conversation-permission">
            {{ $t(`iam.permission.${access}`) }}
          </span>
        </div>
        <p class="text-sm txt-primary" data-testid="text-shared-conversation-source">
          {{ sourceText }}
        </p>
        <p v-if="!compact" class="text-sm txt-secondary">{{ $t('iam.readOnly') }}</p>
        <button
          v-if="canContinue"
          type="button"
          class="btn-primary mt-1"
          data-testid="btn-continue-as-copy"
          @click="$emit('continue')"
        >
          {{ $t('iam.continueAsCopy') }}
        </button>
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
import type { ConversationSharedVia } from '@/stores/chats'

const props = withDefaults(
  defineProps<{
    ownerName: string | null
    sharedVia: ConversationSharedVia | null
    access: 'read' | 'use'
    canContinue?: boolean
    compact?: boolean
  }>(),
  { canContinue: false, compact: false }
)

defineEmits<{ continue: [] }>()

const { t } = useI18n()

const pill = computed(() => kindOfSharedVia(props.sharedVia, props.ownerName))
const kind = computed(() => pill.value.kind)
const kindLabel = computed(() => pill.value.label)

const sourceText = computed(() => {
  const owner = props.ownerName?.trim() || t('iam.owner')
  if (props.sharedVia?.type === 'group' && props.sharedVia.name.trim()) {
    return t('iam.incoming.sourceGroup', { owner, name: props.sharedVia.name.trim() })
  }
  if (props.sharedVia?.type === 'everyone') {
    return t('iam.incoming.sourceEveryone', { owner })
  }
  return t('iam.incoming.sourceDirect', { owner })
})
</script>
