<template>
  <li
    class="surface-card rounded-xl p-4"
    :class="{ 'opacity-60': code.state === 'revoked' }"
    data-testid="item-signup-code"
    :data-code-id="code.id"
  >
    <div class="flex flex-col sm:flex-row sm:items-start gap-3">
      <div class="flex-1 min-w-0 space-y-1">
        <div class="flex flex-wrap items-center gap-2">
          <span
            class="font-mono text-sm txt-primary surface-chip rounded-lg px-2 py-0.5"
            :aria-label="$t('admin.signupCodes.prefixAria', { prefix: code.prefix })"
            data-testid="text-signup-code-prefix"
            >{{ code.prefix }}…</span
          >
          <span class="font-medium txt-primary break-words" data-testid="text-signup-code-label">{{
            code.label
          }}</span>
          <span
            class="text-xs rounded-full surface-chip px-2 py-0.5"
            :class="code.state === 'active' ? 'txt-primary font-medium' : 'txt-secondary'"
            data-testid="text-signup-code-state"
            >{{ $t(`admin.signupCodes.state.${code.state}`) }}</span
          >
        </div>
        <p class="text-sm txt-secondary" data-testid="text-signup-code-facts">
          {{ $t('admin.signupCodes.facts.grants', { level: levelName }) }} · {{ validity }} ·
          <span data-testid="text-signup-code-uses">{{ usage }}</span>
        </p>
        <p class="text-xs txt-secondary break-words">{{ origin }}</p>
      </div>
      <div class="flex items-center gap-1 shrink-0">
        <button
          type="button"
          class="icon-ghost p-1.5 rounded-lg"
          :aria-expanded="showUses"
          :title="$t('admin.signupCodes.actions.uses')"
          :aria-label="$t('admin.signupCodes.actions.uses')"
          data-testid="btn-signup-code-uses"
          @click="showUses = !showUses"
        >
          <EyeIcon class="w-4 h-4" />
        </button>
        <button
          type="button"
          class="icon-ghost p-1.5 rounded-lg"
          :title="$t('admin.signupCodes.actions.edit')"
          :aria-label="$t('admin.signupCodes.actions.edit')"
          data-testid="btn-signup-code-edit"
          @click="emit('edit')"
        >
          <PencilSquareIcon class="w-4 h-4" />
        </button>
        <button
          v-if="code.active"
          type="button"
          class="icon-ghost icon-ghost--danger p-1.5 rounded-lg"
          :title="$t('admin.signupCodes.actions.revoke')"
          :aria-label="$t('admin.signupCodes.actions.revoke')"
          data-testid="btn-signup-code-revoke"
          @click="emit('revoke')"
        >
          <TrashIcon class="w-4 h-4" />
        </button>
        <button
          v-else
          type="button"
          class="btn-secondary px-4 py-2.5 text-sm font-medium"
          data-testid="btn-signup-code-restore"
          @click="emit('restore')"
        >
          {{ $t('admin.signupCodes.actions.restore') }}
        </button>
      </div>
    </div>
    <SignupCodeUses v-if="showUses" :code-id="code.id" />
  </li>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { EyeIcon, PencilSquareIcon, TrashIcon } from '@heroicons/vue/24/outline'
import SignupCodeUses from '@/components/people/SignupCodeUses.vue'
import { useDateFormat } from '@/composables/useDateFormat'
import type { SignupCode } from '@/services/api/signupCodesApi'

const props = defineProps<{ code: SignupCode }>()
const emit = defineEmits<{ edit: []; revoke: []; restore: [] }>()

const { t } = useI18n()
const { formatDate } = useDateFormat()
const showUses = ref(false)

const levelName = computed(() => t(`admin.signupCodes.levels.${props.code.level}`))

const validity = computed(() =>
  props.code.expiresAt === null
    ? t('admin.signupCodes.facts.noEnd')
    : t('admin.signupCodes.facts.validUntil', {
        date: formatDate(new Date(props.code.expiresAt * 1000)),
      })
)

const usage = computed(() =>
  props.code.maxUses === null
    ? t('admin.signupCodes.facts.usesUnlimited', { uses: props.code.uses }, props.code.uses)
    : t('admin.signupCodes.facts.usesOf', { uses: props.code.uses, max: props.code.maxUses })
)

const origin = computed(() => {
  const date = formatDate(new Date(props.code.createdAt * 1000))
  return props.code.createdByEmail
    ? t('admin.signupCodes.facts.createdBy', { email: props.code.createdByEmail, date })
    : t('admin.signupCodes.facts.createdByGone', { date })
})
</script>
