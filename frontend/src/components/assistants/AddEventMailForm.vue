<template>
  <div class="space-y-4" data-testid="form-add-mail-event">
    <label class="block">
      <span class="txt-secondary text-sm">{{ $t('assistants.triggers.mailbox') }}</span>
      <select
        v-model="mailbox"
        class="mt-1 w-full px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
        data-testid="select-mailbox"
      >
        <option value="">{{ $t('assistants.triggers.chooseMailbox') }}</option>
        <option v-for="box in mailboxes" :key="box.id" :value="box.ref">
          {{ box.name }}
        </option>
      </select>
    </label>
    <fieldset class="space-y-2">
      <legend class="txt-primary text-sm font-medium">
        {{ $t('assistants.triggers.whichMails') }}
      </legend>
      <label class="flex items-start gap-2">
        <input
          v-model="mode"
          type="radio"
          value="rule"
          class="mt-1"
          data-testid="radio-mail-rule"
        />
        <span class="txt-primary text-sm">{{ $t('assistants.triggers.mailsMatchingRule') }}</span>
      </label>
      <div v-if="mode === 'rule'" class="pl-6 space-y-3">
        <label class="block">
          <span class="txt-secondary text-sm">{{ $t('assistants.triggers.from') }}</span>
          <input
            v-model="fromText"
            class="mt-1 w-full px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
            data-testid="input-mail-from"
            :placeholder="$t('assistants.triggers.fromPlaceholder')"
          />
        </label>
        <label class="block">
          <span class="txt-secondary text-sm">{{ $t('assistants.triggers.containing') }}</span>
          <input
            v-model="containsText"
            class="mt-1 w-full px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
            data-testid="input-mail-contains"
            :placeholder="$t('assistants.triggers.containsPlaceholder')"
          />
        </label>
        <label class="block">
          <span class="txt-secondary text-sm">{{ $t('assistants.triggers.match') }}</span>
          <select
            v-model="match"
            class="mt-1 w-full px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
            data-testid="select-mail-match"
          >
            <option value="any">{{ $t('assistants.triggers.matchAny') }}</option>
            <option value="all">{{ $t('assistants.triggers.matchAll') }}</option>
          </select>
        </label>
        <p class="txt-secondary text-sm">{{ $t('assistants.triggers.emptyRuleHint') }}</p>
      </div>
      <label v-if="departments.length > 0" class="flex items-start gap-2">
        <input
          v-model="mode"
          type="radio"
          value="department"
          class="mt-1"
          data-testid="radio-mail-department"
        />
        <span class="txt-primary text-sm">{{ $t('assistants.triggers.mailsInDepartment') }}</span>
      </label>
      <select
        v-if="mode === 'department'"
        v-model="department"
        class="ml-6 w-[calc(100%-1.5rem)] px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
        data-testid="select-mail-department"
      >
        <option v-for="dept in departments" :key="dept" :value="dept">{{ dept }}</option>
      </select>
    </fieldset>
    <label class="block">
      <span class="txt-secondary text-sm">{{ $t('assistants.triggers.then') }}</span>
      <textarea
        v-model="instruction"
        rows="3"
        class="mt-1 w-full px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
        data-testid="input-mail-instruction"
      />
    </label>
    <p class="txt-secondary text-sm">{{ $t('assistants.triggers.mailRunsHint') }}</p>
    <div class="flex flex-wrap gap-2">
      <button
        type="button"
        class="btn-secondary px-4 py-2.5 rounded-lg text-sm font-medium"
        data-testid="btn-cancel-mail-event"
        @click="emit('cancel')"
      >
        {{ $t('common.cancel') }}
      </button>
      <button
        type="button"
        class="btn-primary px-4 py-2.5 rounded-lg text-sm font-medium"
        :disabled="!mailbox"
        data-testid="btn-save-mail-event"
        @click="onSave"
      >
        {{ $t('assistants.triggers.addEvent') }}
      </button>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { inboundEmailHandlersApi } from '@/services/api/inboundEmailHandlersApi'

const emit = defineEmits<{
  cancel: []
  save: [payload: Record<string, unknown>]
}>()

const auth = useAuthStore()
const mailbox = ref('')
const mode = ref<'rule' | 'department'>('rule')
const fromText = ref('')
const containsText = ref('')
const match = ref<'any' | 'all'>('any')
const department = ref('')
const instruction = ref('')
const mailboxes = ref<{ id: string; name: string; ref: string; departments: string[] }[]>([])

const departments = computed(() => {
  const selected = mailboxes.value.find((box) => box.ref === mailbox.value)
  return selected?.departments ?? []
})

onMounted(async () => {
  const ownerId = auth.user?.id
  try {
    const list = await inboundEmailHandlersApi.list()
    mailboxes.value = list.map((handler) => ({
      id: handler.id,
      name: handler.name,
      ref: ownerId ? `${ownerId}:${handler.id}` : String(handler.id),
      departments: (handler.departments ?? []).map((dept) => dept.email || dept.id),
    }))
  } catch {
    mailboxes.value = []
  }
})

function splitList(value: string): string[] {
  return value
    .split(/[,;\n]/)
    .map((item) => item.trim())
    .filter(Boolean)
}

function onSave(): void {
  if (!mailbox.value) {
    return
  }
  const payload: Record<string, unknown> = {
    id: `mail-${Math.random().toString(36).slice(2, 8)}`,
    kind: 'mail',
    mailbox: mailbox.value,
    enabled: true,
  }
  if (instruction.value.trim()) {
    payload.instruction = instruction.value.trim()
  }
  if (mode.value === 'department' && department.value) {
    payload.department = department.value
  } else {
    payload.rule = {
      from: splitList(fromText.value),
      contains: splitList(containsText.value),
      match: match.value,
    }
  }
  emit('save', payload)
}
</script>
