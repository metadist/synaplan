<template>
  <article class="surface-card rounded-xl p-5 flex flex-col gap-3" data-testid="card-assistant">
    <div class="flex items-start gap-3">
      <div
        class="w-10 h-10 rounded-lg bg-[var(--brand-alpha-light)] flex items-center justify-center flex-shrink-0"
        aria-hidden="true"
      >
        <Icon :icon="iconName" class="w-5 h-5 text-[var(--brand)]" />
      </div>
      <div class="min-w-0 flex-1">
        <h3 class="txt-primary font-medium truncate">{{ card.name }}</h3>
        <p v-if="card.description" class="txt-secondary text-sm mt-0.5 line-clamp-2">
          {{ card.description }}
        </p>
        <p class="txt-secondary text-xs mt-1">
          {{ $t('assistants.owner') }}: {{ card.ownerName }}
          <span v-if="card.version != null">
            · {{ $t('assistants.version') }} {{ card.version }}</span
          >
          <span v-if="card.status === 'archived'" data-testid="badge-card-archived">
            · {{ $t('assistants.archivedBadge') }}</span
          >
        </p>
      </div>
    </div>
    <div class="flex flex-wrap gap-2 mt-auto">
      <button
        type="button"
        class="btn-primary px-4 py-2.5 rounded-lg text-sm font-medium"
        data-testid="btn-assistant-start-chat"
        :disabled="cardId == null || card.canStartChat === false || card.status === 'archived'"
        @click="emitStartChat"
      >
        {{ $t('assistants.startChat') }}
      </button>
      <button
        type="button"
        class="btn-secondary px-4 py-2.5 rounded-lg text-sm font-medium"
        data-testid="btn-assistant-clone"
        :disabled="cardId == null"
        @click="emitClone"
      >
        {{ $t('assistants.clone') }}
      </button>
      <button
        v-if="card.origin === 'mine' || card.canEdit === true"
        type="button"
        class="btn-secondary px-4 py-2.5 rounded-lg text-sm font-medium"
        data-testid="btn-assistant-edit"
        :disabled="cardId == null"
        @click="emitEdit"
      >
        {{ $t('assistants.edit') }}
      </button>
      <button
        v-if="card.origin === 'mine'"
        type="button"
        class="btn-danger px-4 py-2.5 rounded-lg text-sm font-medium"
        data-testid="btn-assistant-delete"
        :disabled="cardId == null"
        @click="emitDelete"
      >
        {{ $t('assistants.delete') }}
      </button>
    </div>
  </article>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { Icon } from '@iconify/vue'
import type { GalleryCard } from '@/services/api/agentsApi'

const props = defineProps<{
  card: GalleryCard
}>()

const emit = defineEmits<{
  'start-chat': [id: number]
  clone: [id: number]
  edit: [id: number]
  delete: [id: number]
}>()

const cardId = computed(() =>
  typeof props.card.id === 'number' && props.card.id > 0 ? props.card.id : null
)

function emitStartChat(): void {
  if (cardId.value != null) {
    emit('start-chat', cardId.value)
  }
}

function emitClone(): void {
  if (cardId.value != null) {
    emit('clone', cardId.value)
  }
}

function emitEdit(): void {
  if (cardId.value != null) {
    emit('edit', cardId.value)
  }
}

function emitDelete(): void {
  if (cardId.value != null) {
    emit('delete', cardId.value)
  }
}

const iconName = computed(() => {
  const icon = props.card.icon
  return typeof icon === 'string' && icon.startsWith('mdi:') ? icon : 'mdi:robot-outline'
})
</script>
